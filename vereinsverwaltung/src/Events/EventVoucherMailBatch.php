<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;

/** One canonical batch fingerprint; native reviews and writers need only one person's cards at once. */
trait EventVoucherMailBatch
{
    private function batchContext(string $eventId):array
    {
        $actor=BoardAccess::require($this->db,$this->actorId);
        $event=$this->vouchers->event($eventId);$source=$this->vouchers->liveEvent($event,true);
        $template=$this->template($eventId)['data'];$members=$this->vouchers->members();
        if(count($members)>1000)throw new DomainException('Höchstens 1.000 Mitglieder pro Sammelversand.');
        $counts=[];
        foreach($this->q("SELECT contact_id,unit,remaining FROM event_vouchers WHERE club_id=? AND event_id=? AND state='active' AND remaining>0 ORDER BY contact_id,id".$this->currentLock(),[$actor['club_id'],$eventId])->fetchAll()as$row)$counts[$row['contact_id']]=($counts[$row['contact_id']]??0)+($row['unit']==='piece'?(int)$row['remaining']:1);
        $deliveries=[];
        foreach($this->q('SELECT id,contact_id,email,state FROM event_voucher_outbox WHERE club_id=? AND event_id=? ORDER BY id'.$this->currentLock(),[$actor['club_id'],$eventId])->fetchAll()as$row)$deliveries[$row['contact_id']][]=$row;
        return ['actor'=>$actor,'event'=>$event,'source'=>$source,'template'=>$template,'members'=>$members,'counts'=>$counts,'deliveries'=>$deliveries];
    }

    /** Read code metadata from only relevant sent originals, without hydrating their bodies or templates. */
    private function sentCodes(array $context,array $person,array $deliveries):array
    {
        $codes=[];
        foreach($deliveries as$old){
            if($old['state']!=='sent'||$old['email']!==$person['email'])continue;
            $row=$this->q("SELECT CASE WHEN JSON_VALID(snapshot_json) THEN JSON_TYPE(JSON_EXTRACT(snapshot_json,'$.vouchers'))='ARRAY' ELSE 0 END AS shape_valid,CASE WHEN JSON_VALID(snapshot_json) THEN COALESCE(JSON_EXTRACT(snapshot_json,'$.vouchers[*].code'),JSON_ARRAY()) ELSE JSON_ARRAY() END AS codes_json FROM event_voucher_outbox WHERE club_id=? AND event_id=? AND contact_id=? AND id=?".$this->currentLock(),[$context['actor']['club_id'],$context['event']['id'],$person['id'],$old['id']])->fetch();
            if(!$row||!(bool)$row['shape_valid'])return ['valid'=>false,'codes'=>[]];
            $values=json_decode($row['codes_json'],true,512,JSON_THROW_ON_ERROR);
            foreach($values as$code)if(is_string($code))$codes[$code]=true;
        }
        return ['valid'=>true,'codes'=>$codes];
    }

    private function batchPass(array $context,?callable $accept=null):array
    {
        $ready=[];$skipped=[];
        foreach($context['members']as$person){
            $count=$context['counts'][$person['id']]??0;$reason='';
            if($count===0)$reason='Keine offenen Gutscheine';
            elseif(!filter_var($person['email'],FILTER_VALIDATE_EMAIL))$reason='E-Mail-Adresse fehlt oder ist ungültig';
            elseif($count>200)$reason='Mehr als 200 Gutscheine – einzeln prüfen';
            else{
                $deliveries=$context['deliveries'][$person['id']]??[];$states=array_column($deliveries,'state');
                if(array_intersect($states,['uncertain','blocked']))$reason='Bisherigen Versand prüfen';
                elseif(array_intersect($states,['pending','sending']))$reason='Bereits für den Versand vorgemerkt';
                else{
                    $sent=$this->sentCodes($context,$person,$deliveries);
                    if(!$sent['valid'])$reason='Bisherigen Versand prüfen';
                    else{
                        $snapshot=$this->vouchers->managementPrintSnapshot($context['event']['id'],$person['id']);
                        $preview=$this->personalPreview($context['event'],$context['source'],$person,$snapshot['vouchers'],$context['template']);
                        if(!array_diff(array_column($preview['snapshot']['vouchers'],'code'),array_keys($sent['codes'])))$reason='Gutscheine bereits versendet';
                        else{
                            $ready[]=['id'=>$person['id'],'name'=>$person['name'],'email'=>$person['email'],'count'=>count($preview['snapshot']['vouchers']),'hash'=>$preview['hash']];
                            if($accept)$accept($preview);
                        }
                    }
                }
            }
            if($reason!=='')$skipped[]=['id'=>$person['id'],'name'=>$person['name'],'reason'=>$reason];
        }
        $fingerprint=['event'=>$context['event']['id'],'ready'=>array_map(static fn(array $row):array=>[$row['id'],$row['hash']],$ready),'skipped'=>$skipped];
        return ['ready'=>$ready,'skipped'=>$skipped,'hash'=>hash('sha256',json_encode($fingerprint,JSON_THROW_ON_ERROR))];
    }

    /** Retain the original full API response for existing callers and approved old fingerprints. */
    public function batchPreview(string $eventId):array
    {
        return$this->readContext(function()use($eventId):array{
            $full=[];$plan=$this->batchPass($this->batchContext($eventId),static function(array $preview)use(&$full):void{$full[]=$preview;});
            return ['ready'=>$full,'skipped'=>$plan['skipped'],'hash'=>$plan['hash']];
        });
    }

    /** Native review contains small person/count metadata, never every person's complete code list. */
    public function batchReview(string $eventId):array
    {
        return$this->readContext(fn():array=>$this->batchPass($this->batchContext($eventId)));
    }

    public function queueBatch(string $eventId,string $hash,string $key):array
    {
        BoardAccess::require($this->db,$this->actorId);
        if(!MailPolicy::allowed((string)getenv('VEREIN_PUBLIC_URL')))throw new DomainException('Mailversand ist in dieser Umgebung ausgeschaltet.');
        $created=[];
        return$this->vouchers->request($key,['mail-all',$eventId,$hash],function()use($eventId,$hash,&$created):array{
            $context=$this->batchContext($eventId);$plan=$this->batchPass($context);
            if(!hash_equals($plan['hash'],$hash))throw new DomainException('Empfänger, Gutscheine oder Versandstatus geändert. Bitte Sammelversand erneut prüfen.',409);
            if(!$plan['ready'])throw new DomainException('Keine neuen Mails für den Versand vorhanden.',422);
            $accepted=$this->batchPass($context,function(array $preview)use($context,$eventId,&$created):void{
                $person=$preview['snapshot']['person'];$id=$this->enqueue($context['actor'],$eventId,$person['id'],$preview);
                $created[]=['id'=>$id,'contact_id'=>$person['id'],'name'=>$person['name'],'email'=>$person['email'],'snapshot_sha256'=>$preview['hash']];
            });
            if(!hash_equals($plan['hash'],$accepted['hash']))throw new DomainException('Empfänger, Gutscheine oder Versandstatus geändert. Bitte Sammelversand erneut prüfen.',409);
            return ['queued'=>count($plan['ready']),'skipped'=>count($plan['skipped'])];
        },static function(array $result)use(&$created):array{
            if(count($created)!==$result['queued'])throw new \LogicException('Accepted native batch originals are incomplete.');
            return ['deliveries'=>$created];
        });
    }
}
