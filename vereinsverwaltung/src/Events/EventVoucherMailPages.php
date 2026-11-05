<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;

/** Current private metadata pages and explicitly selected immutable delivery originals. */
trait EventVoucherMailPages
{
    private function mailWindow(array $rows,array $query,array $fields):array
    {
        $search=$query['query']??'';$page=$query['page']??1;
        if(!is_string($search)||preg_match('//u',$search)!==1||mb_strlen($search)>240||preg_match('/[\x00-\x1f\x7f]/',$search))throw new DomainException('Gutscheinsuche mit höchstens 240 Zeichen eingeben.',422);
        if((!is_int($page)&&!is_string($page))||!preg_match('/^[1-9][0-9]{0,8}$/D',(string)$page))throw new DomainException('Gültige Gutscheinseite auswählen.',422);
        if($search!==''){$needle=mb_strtolower($search);$rows=array_values(array_filter($rows,static function(array $row)use($needle,$fields):bool{$values=[];foreach($fields as$field)$values[]=(string)($row[$field]??'');return str_contains(mb_strtolower(implode(' ',$values)),$needle);}));}
        $total=count($rows);$pages=max(1,(int)ceil($total/25));$page=(int)$page;
        if($page>$pages)throw new DomainException('Diese Gutscheinseite ist nicht verfügbar.',422);
        return ['rows'=>array_slice($rows,($page-1)*25,25),'total'=>$total,'page'=>$page,'pages'=>$pages,'per_page'=>25,'query'=>$search];
    }

    public function pendingDelivery(string $eventId,string $person):?array
    {
        return$this->readContext(function()use($eventId,$person):?array{
            $actor=BoardAccess::require($this->db,$this->actorId);$this->vouchers->event($eventId);
            $row=$this->q("SELECT id,state,error,updated_at FROM event_voucher_outbox WHERE club_id=? AND event_id=? AND contact_id=? AND state IN ('pending','sending','uncertain') ORDER BY updated_at DESC,id DESC LIMIT 1".$this->currentLock(),[$actor['club_id'],$eventId,$person])->fetch();
            return$row?:null;
        });
    }

    public function peoplePage(string $eventId,array $query=[]):array
    {
        return$this->readContext(function()use($eventId,$query):array{
            $actor=BoardAccess::require($this->db,$this->actorId);$this->vouchers->event($eventId);$people=[];
            foreach($this->q("SELECT c.id,c.name,c.email,v.unit,v.remaining FROM contacts c JOIN event_vouchers v ON v.club_id=c.club_id AND v.contact_id=c.id WHERE c.club_id=? AND v.event_id=? AND c.kind='person' AND c.archived_at IS NULL AND v.state='active' AND v.remaining>0 ORDER BY c.name,c.id,v.id".$this->currentLock(),[$actor['club_id'],$eventId])->fetchAll()as$row){
                $people[$row['id']]??=['id'=>$row['id'],'name'=>$row['name'],'email'=>$row['email'],'count'=>0];
                $people[$row['id']]['count']+=$row['unit']==='piece'?(int)$row['remaining']:1;
            }
            return$this->mailWindow(array_values($people),$query,['id','name','email']);
        });
    }

    public function previewPage(array $preview,array $query=[]):array
    {
        return$this->mailWindow($preview['snapshot']['vouchers'],$query,['label','code']);
    }

    public function batchPages(string $eventId,array $query=[]):array
    {
        $plan=$this->batchReview($eventId);
        $result=['review'=>$plan,'hash'=>$plan['hash'],'ready_count'=>count($plan['ready']),'skipped_count'=>count($plan['skipped']),'ready'=>null,'skipped'=>null,'errors'=>[],'http_status'=>0];
        foreach(['ready','skipped']as$section){
            try{$result[$section]=$this->mailWindow($plan[$section],['query'=>$query[$section.'_query']??'','page'=>$query[$section.'_page']??1],['id','name','email','reason']);}
            catch(DomainException $failure){$result['errors'][$section]=$failure->getMessage();$result['http_status']=422;}
        }
        return$result;
    }

    public function deliveriesPage(string $eventId,array $query=[]):array
    {
        return$this->readContext(function()use($eventId,$query):array{
            $actor=BoardAccess::require($this->db,$this->actorId);$this->vouchers->event($eventId);
            $state=$query['state']??'all';
            if(!is_string($state)||!in_array($state,['all','pending','sending','sent','uncertain','blocked','cancelled'],true))throw new DomainException('Gültigen Versandstand auswählen.',422);
            $rows=$this->q("SELECT o.id,o.contact_id,c.name AS current_name,o.email,o.state,o.error,o.updated_at,CASE WHEN JSON_VALID(o.snapshot_json) THEN CASE WHEN JSON_TYPE(JSON_EXTRACT(o.snapshot_json,'$.person.name'))='STRING' THEN JSON_UNQUOTE(JSON_EXTRACT(o.snapshot_json,'$.person.name')) ELSE NULL END ELSE NULL END AS original_name FROM event_voucher_outbox o JOIN contacts c ON c.club_id=o.club_id AND c.id=o.contact_id WHERE o.club_id=? AND o.event_id=? AND (?='all' OR o.state=?) ORDER BY o.updated_at DESC,o.id DESC".$this->currentLock(),[$actor['club_id'],$eventId,$state,$state])->fetchAll();
            return$this->mailWindow($rows,$query,['id','current_name','original_name','email','state','error'])+['state'=>$state];
        });
    }

    /** The JSON download uses original_json verbatim; no current profile can replace its accepted name. */
    public function original(string $eventId,string $id):array
    {
        return$this->readContext(function()use($eventId,$id):array{
            $actor=BoardAccess::require($this->db,$this->actorId);$this->vouchers->event($eventId);
            if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Versandvorgang nicht verfügbar.',403);
            $row=$this->q('SELECT * FROM event_voucher_outbox WHERE club_id=? AND event_id=? AND id=?'.$this->currentLock(),[$actor['club_id'],$eventId,$id])->fetch();
            if(!$row)throw new DomainException('Versandvorgang nicht verfügbar.',403);
            try{$snapshot=json_decode($row['snapshot_json'],true,512,JSON_THROW_ON_ERROR);}
            catch(\JsonException $failure){throw new DomainException('Ursprüngliche Versandvorschau bitte prüfen lassen.',409,$failure);}
            if(!is_array($snapshot)||!hash_equals($row['fingerprint'],hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR)))||($snapshot['event_id']??null)!==$eventId||($snapshot['person']['id']??null)!==$row['contact_id']||($snapshot['person']['email']??null)!==$row['email'])throw new DomainException('Ursprüngliche Versandvorschau bitte prüfen lassen.',409);
            foreach(['title','date','location','text']as$field)if(!is_string($snapshot[$field]??null))throw new DomainException('Ursprüngliche Versandvorschau bitte prüfen lassen.',409);
            if(!is_string($snapshot['person']['name']??null)||!is_array($snapshot['vouchers']??null)||!array_is_list($snapshot['vouchers'])||!$snapshot['vouchers']||count($snapshot['vouchers'])>200)throw new DomainException('Ursprüngliche Versandvorschau bitte prüfen lassen.',409);
            foreach($snapshot['vouchers']as$voucher)if(!is_array($voucher)||!is_string($voucher['id']??null)||!is_string($voucher['label']??null)||!in_array($voucher['unit']??null,['piece','eur'],true)||!is_int($voucher['remaining']??null)||$voucher['remaining']<1||!is_string($voucher['code']??null)||preg_match('/^[A-F0-9]{16}$/D',$voucher['code'])!==1)throw new DomainException('Ursprüngliche Versandvorschau bitte prüfen lassen.',409);
            return ['delivery'=>$row,'snapshot'=>$snapshot,'original_json'=>$row['snapshot_json'],'original_sha256'=>hash('sha256',$row['snapshot_json'])];
        });
    }
}
