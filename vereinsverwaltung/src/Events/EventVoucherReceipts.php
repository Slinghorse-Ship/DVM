<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

/** Accepted normalized inputs remain separate from current balances and later profile labels. */
trait EventVoucherReceipts
{
    private function receiptOriginal(array $actor,array $payload,mixed $result,array $mail=[]):string
    {
        $operation=$payload[0]??'';$event=$operation==='open'?$result:($payload[1]??null);
        $context=['event'=>null,'people'=>[],'helpers'=>[],'vouchers'=>[]];$voucherIds=[];
        if(in_array($operation,['open','grant','redeem','revoke','reverse','configure','mail-template','mail','mail-all','cancel-mail'],true)&&is_string($event)){
            $display=$this->sourceForDisplay($this->event($event));$row=$display['event'];
            $context['event']=['id'=>$row['id'],'title'=>$row['title'],'starts_at'=>$row['starts_at'],'location'=>$row['location'],'available'=>$display['available'],'notice'=>$display['notice']];
            if($operation==='grant'&&is_array($result))$voucherIds=$result;
            if(in_array($operation,['redeem','revoke'],true))$voucherIds=[$payload[2]];
            $people=$operation==='grant'?($payload[2]??[]):($operation==='mail'?[$payload[2]]:[]);
            if(in_array($operation,['redeem','revoke'],true)){
                $person=$this->q('SELECT contact_id FROM event_vouchers WHERE club_id=? AND event_id=? AND id=?'.$this->currentLock(),[$actor['club_id'],$event,$payload[2]])->fetchColumn();if($person!==false)$people=[$person];
            }elseif($operation==='reverse'){
                $person=$this->q('SELECT v.contact_id,v.id FROM event_voucher_moves m JOIN event_vouchers v ON v.club_id=m.club_id AND v.id=m.voucher_id WHERE m.club_id=? AND v.event_id=? AND m.id=?'.$this->currentLock(),[$actor['club_id'],$event,$payload[2]])->fetch();if($person!==false){$people=[$person['contact_id']];$voucherIds=[$person['id']];}
            }
            if($people){
                $rows=$this->q('SELECT id,name FROM contacts WHERE club_id=? AND id IN ('.implode(',',array_fill(0,count($people),'?')).') ORDER BY id'.$this->currentLock(),[$actor['club_id'],...$people])->fetchAll();
                foreach($rows as$person)$context['people'][$person['id']]=$person['name'];
            }
            if($voucherIds)$context['vouchers']=$this->q('SELECT id,contact_id,label,unit,granted,remaining,code,state,version FROM event_vouchers WHERE club_id=? AND event_id=? AND id IN ('.implode(',',array_fill(0,count($voucherIds),'?')).') ORDER BY id'.$this->currentLock(),[$actor['club_id'],$event,...$voucherIds])->fetchAll();
            if($operation==='configure'){
                $choices=array_column($this->helperChoices(),null,'id');foreach($payload[4]as$id)if(isset($choices[$id]))$context['helpers'][$id]=$choices[$id]['name'];
            }
        }
        if($mail!==[])$context['mail']=$mail;
        return json_encode(['format'=>'dvm-event-voucher-original/1','input'=>$payload,'context'=>$context,'context_sha256'=>hash('sha256',json_encode($context,JSON_THROW_ON_ERROR))],JSON_THROW_ON_ERROR);
    }

    public function confirmation(string $key): array
    {
        return $this->readContext(function()use($key):array{
            $actor=$this->actor();
            if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new DomainException('Gutscheinbestätigung nicht verfügbar.',404);
            $row=$this->q('SELECT r.*,i.payload_json FROM event_voucher_requests r LEFT JOIN event_voucher_receipts i ON i.club_id=r.club_id AND i.request_key=r.request_key WHERE r.club_id=? AND r.actor_id=? AND r.request_key=?'.$this->currentLock(),[$actor['club_id'],$actor['id'],$key])->fetch();
            if(!$row)throw new DomainException('Gutscheinbestätigung nicht verfügbar.',404);
            $payload=null;$context=null;
            if($row['payload_json']!==null){
                $stored=json_decode($row['payload_json'],true,512,JSON_THROW_ON_ERROR);
                if(is_array($stored)&&($stored['format']??null)==='dvm-event-voucher-original/1'){
                    $payload=$stored['input']??null;$context=$stored['context']??null;
                    if(!is_array($context)||!is_string($stored['context_sha256']??null)||!hash_equals($stored['context_sha256'],hash('sha256',json_encode($context,JSON_THROW_ON_ERROR))))throw new DomainException('Ursprüngliche Gutscheinbestätigung bitte prüfen lassen.');
                }else $payload=$stored;
                if(!is_array($payload)||!is_string($payload[0]??null)||!hash_equals($row['fingerprint'],hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR))))throw new DomainException('Ursprüngliche Gutscheinbestätigung bitte prüfen lassen.');
            }
            if(($payload[0]??'')==='redeem'){
                $event=$payload[1]??null;
                if(!is_string($event)||!$this->canRedeem($event))throw new DomainException('Keine Einlöseberechtigung für diese Veranstaltung.');
                $this->event($event);
            }else $this->manager();
            return ['key'=>$key,'actor'=>$row['actor_id'],'created_at'=>$row['created_at'],'input_available'=>$payload!==null,'payload'=>$payload,'context'=>$context,'original_json'=>$row['payload_json'],'result'=>json_decode($row['result_json'],true,512,JSON_THROW_ON_ERROR)];
        });
    }

    /** New batch acceptances retain exact job identities; older counts never invent a mapping. */
    public function confirmationMailPage(string $key,array $query=[]):array
    {
        return$this->readContext(function()use($key,$query):array{
            [$search,$page]=$this->pageInput($query);$receipt=$this->confirmation($key);
            $stored=$receipt['context']['mail']['deliveries']??null;$available=is_array($stored);
            $rows=$available?$stored:[];$needle=mb_strtolower($search);
            if($search!=='')$rows=array_values(array_filter($rows,static fn(array $row):bool=>str_contains(mb_strtolower($row['id'].' '.$row['name'].' '.$row['email']),$needle)));
            return$this->window($rows,$search,$page)+['available'=>$available];
        });
    }

    /** Shallow current creator index: large original inputs open only on explicit selection. */
    public function confirmationsPage(array $query=[]): array
    {
        return $this->readContext(function()use($query):array{
            [$search,$page]=$this->pageInput($query);$actor=$this->actor();$manage=$this->canManage();$helpers=$manage?[]:$this->currentHelpers($actor);
            if(!$manage&&!$helpers)throw new DomainException('Keine Berechtigung für die Gutscheinverwaltung.');
            $operation="COALESCE(JSON_UNQUOTE(JSON_EXTRACT(i.payload_json,'$.input[0]')),JSON_UNQUOTE(JSON_EXTRACT(i.payload_json,'$[0]')))";
            $event="COALESCE(JSON_UNQUOTE(JSON_EXTRACT(i.payload_json,'$.input[1]')),JSON_UNQUOTE(JSON_EXTRACT(i.payload_json,'$[1]')))";
            $rows=$this->q("SELECT r.request_key,r.created_at,i.request_key IS NOT NULL AS input_available,".$operation." AS operation,CASE WHEN ".$operation."='open' THEN JSON_UNQUOTE(r.result_json) ELSE ".$event." END AS event_id FROM event_voucher_requests r LEFT JOIN event_voucher_receipts i ON i.club_id=r.club_id AND i.request_key=r.request_key WHERE r.club_id=? AND r.actor_id=? ORDER BY r.created_at DESC,r.request_key DESC".$this->currentLock(),[$actor['club_id'],$actor['id']])->fetchAll();
            $needle=mb_strtolower($search);$rows=array_values(array_filter($rows,static function(array $row)use($manage,$helpers,$search,$needle):bool{
                if(!$manage&&($row['operation']!=='redeem'||!in_array($row['event_id'],$helpers,true)))return false;
                return $search===''||str_contains(mb_strtolower($row['request_key'].' '.$row['created_at'].' '.$row['operation']),$needle);
            }));
            return$this->window($rows,$search,$page);
        });
    }
}
