<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

require_once __DIR__.'/EventVoucherReads.php';
require_once __DIR__.'/EventVoucherPages.php';
require_once __DIR__.'/EventVoucherReceipts.php';
require_once __DIR__.'/EventVoucherManagement.php';
require_once __DIR__.'/EventVoucherError.php';
/** Personal event benefits, separate from cash, bank transactions and invoices. */
final class EventVouchers
{
    use EventVoucherReads, EventVoucherPages, EventVoucherReceipts, EventVoucherManagement;
    public function __construct(private PDO $db, private string $actorId, private ?WebsiteCalendar $website=null) {}
    private function q(string $sql,array $args=[]):\PDOStatement { return DocumentCatalog::query($this->db,$sql,$args); }
    private function actor():array {
        $a=Permissions::actor($this->db,$this->actorId);
        if($a['role']==='editor')throw new DomainException('Keine Berechtigung für Gutscheine.');
        return $a;
    }
    public function canManage():bool { return BoardAccess::allowed($this->db,$this->actorId); }
    private function manager():array { return BoardAccess::require($this->db,$this->actorId); }
    public function canRead():bool {
        try{return $this->readContext(function():bool{$a=$this->actor();return $this->canManage()||$this->currentHelpers($a)!==[]||$this->availableProfile($a,false);});}
        catch(DomainException $failure){if($failure->getCode()===503)throw $failure;return false;}
    }
    public function canRedeem(string $eventId):bool {
        try{return $this->readContext(function()use($eventId):bool{$a=$this->actor();return $this->canManage()||(bool)$this->q('SELECT 1 FROM event_voucher_helpers WHERE club_id=? AND event_id=? AND actor_id=?'.$this->currentLock(),[$a['club_id'],$eventId,$a['id']])->fetchColumn();});}
        catch(DomainException $failure){if($failure->getCode()===503)throw $failure;return false;}
    }
    public function members():array {
        return $this->readContext(function():array{
            $a=$this->manager();$today=date('Y-m-d');
            return $this->q("SELECT c.id,c.name,c.email FROM contacts c WHERE c.club_id=? AND c.kind='person' AND c.archived_at IS NULL AND EXISTS(SELECT 1 FROM memberships m WHERE m.club_id=c.club_id AND m.contact_id=c.id AND m.status IN ('aktiv','passiv') AND m.starts_on<=? AND (m.ends_on IS NULL OR m.ends_on>=?)".$this->currentLock().") ORDER BY c.name,c.id".$this->currentLock(),[$a['club_id'],$today,$today])->fetchAll();
        });
    }
    public function helperChoices():array {
        return $this->readContext(function():array{
            $a=$this->manager();return $this->q("SELECT a.id,COALESCE(c.name,a.id) name FROM actors a LEFT JOIN contacts c ON c.club_id=a.club_id AND c.id=a.contact_id WHERE a.club_id=? AND a.active=1 AND a.role<>'editor' ORDER BY name,a.id".$this->currentLock(),[$a['club_id']])->fetchAll();
        });
    }
    public function helpers(string $eventId):array {
        return $this->readContext(function()use($eventId):array{
            $a=$this->manager();$this->event($eventId);return $this->q('SELECT actor_id FROM event_voucher_helpers WHERE club_id=? AND event_id=? ORDER BY actor_id'.$this->currentLock(),[$a['club_id'],$eventId])->fetchAll(PDO::FETCH_COLUMN);
        });
    }
    /** Only explicitly selected internal/website events become voucher events. */
    public function open(string $sourceId,string $key):string {
        $a=$this->manager();
        return $this->request($key,['open',$sourceId],function()use($a,$sourceId){
            $this->manager();$source=(new Registry($this->db,$this->actorId,$this->website))->appointment($sourceId);$source=array_replace($source,$this->currentSource(['club_id'=>$a['club_id'],'source_id'=>$sourceId]));$this->notCancelled($source);
            $existing=$this->q('SELECT id FROM event_voucher_events WHERE club_id=? AND source_id=?',[$a['club_id'],$sourceId])->fetchColumn();if($existing)return $existing;
            $id=bin2hex(random_bytes(16));$this->q('INSERT INTO event_voucher_events(club_id,id,source_id,title,starts_at,location) VALUES(?,?,?,?,?,?)',[$a['club_id'],$id,$sourceId,mb_substr($source['title'],0,255),$source['starts_at'],$source['location']]);return $id;
        });
    }
    private function notCancelled(array $source):void {
        if(!empty($source['cancelled'])||($source['activity_state']??'')==='cancelled')throw new DomainException('Die Veranstaltung wurde abgesagt. Gutscheine können nicht ausgegeben oder eingelöst werden.');
    }
    /** Called after scoped event authorization, including for helpers without calendar access. */
    public function liveEvent(array $event,bool $metadata=false):array {
        return $this->readContext(function()use($event,$metadata):array{
            $event=$this->event($event['id']);
            if(!(int)$event['enabled'])throw new DomainException('Die Gutscheinausgabe für diese Veranstaltung ist geschlossen.');
            $current=$this->currentSource($event);$this->notCancelled($current);
            if($metadata)return$current;
            $source=str_starts_with($event['source_id'],'website:')
                ?$this->website->appointment($event['club_id'],$event['source_id'])
                :$this->q('SELECT * FROM appointments WHERE club_id=? AND id=?'.$this->currentLock(),[$event['club_id'],$event['source_id']])->fetch();
            if(!$source)throw new DomainException('Die Veranstaltung ist nicht mehr vorhanden.');
            return array_replace($source,$current);
        });
    }
    public function events():array {return $this->readContext(fn():array=>$this->currentEvents());}
    private function currentEvents():array {
        $a=$this->actor();$manage=$this->canManage();$helpers=$manage?[]:$this->currentHelpers($a);$profile=$manage?false:$this->availableProfile($a,false);if(!$manage&&!$helpers&&!$profile)throw new DomainException('Eigenes Mitgliederprofil für Gutscheine nicht verfügbar.');return $this->q('SELECT e.* FROM event_voucher_events e WHERE e.club_id=? AND (?=1 OR EXISTS(SELECT 1 FROM event_vouchers v WHERE v.club_id=e.club_id AND v.event_id=e.id AND v.contact_id=?'.$this->currentLock().') OR EXISTS(SELECT 1 FROM event_voucher_helpers h WHERE h.club_id=e.club_id AND h.event_id=e.id AND h.actor_id=?'.$this->currentLock().')) ORDER BY e.starts_at DESC,e.id'.$this->currentLock(),[$a['club_id'],(int)$manage,$profile?$a['contact_id']:null,$a['id']])->fetchAll();
    }
    public function event(string $id):array {return $this->readContext(fn():array=>$this->currentEvent($id));}
    private function currentEvent(string $id):array {
        $a=$this->actor();$e=$this->q('SELECT * FROM event_voucher_events WHERE club_id=? AND id=?'.$this->currentLock(),[$a['club_id'],$id])->fetch();
        if(!$e)throw new DomainException('Veranstaltung nicht verfügbar.');$redeem=$this->canRedeem($id);if(!$redeem)$this->availableProfile($a);if(!$redeem&&!$this->q('SELECT 1 FROM event_vouchers WHERE club_id=? AND event_id=? AND contact_id=?'.$this->currentLock(),[$a['club_id'],$id,$a['contact_id']])->fetchColumn())throw new DomainException('Veranstaltung nicht verfügbar.');return $e;
    }
    public function vouchers(string $eventId,string $search=''):array {return $this->readContext(fn():array=>$this->currentVouchers($eventId,$search));}
    private function currentVouchers(string $eventId,string $search=''):array {
        $a=$this->actor();$this->event($eventId);if(mb_strlen($search)>120)throw new DomainException('Suchtext ist zu lang.');
        $code=EventVoucherQr::searchCode($search,$eventId);
        $rows=$this->q("SELECT v.*,c.name FROM event_vouchers v JOIN contacts c ON c.club_id=v.club_id AND c.id=v.contact_id WHERE v.club_id=? AND v.event_id=? AND (?=1 OR v.contact_id=?) AND (?='' OR INSTR(LOWER(c.name),LOWER(?))>0 OR v.code=? OR EXISTS(SELECT 1 FROM event_voucher_tickets t WHERE t.club_id=v.club_id AND t.voucher_id=v.id AND t.code=?".$this->currentLock().")) ORDER BY c.name,c.id,v.created_at,v.id".$this->currentLock(),[$a['club_id'],$eventId,(int)$this->canRedeem($eventId),$a['contact_id'],$search,$search,$code,$code])->fetchAll();
        foreach($rows as &$v)if($v['unit']==='piece'){$v['tickets']=EventVoucherTickets::list($this->db,$v,true);$match=array_values(array_filter($v['tickets'],static fn($t)=>$t['code']===$code));if($match)$v['tickets']=$match;}unset($v);return $rows;
    }
    private function lockEvent(string $id):array {
        $e=$this->event($id);$this->q('SELECT id FROM event_voucher_events WHERE club_id=? AND id=? FOR UPDATE',[$e['club_id'],$id])->fetch();return $e;
    }
    public static function amount(string $value,string $unit):int {
        if(!in_array($unit,['piece','eur'],true))throw new DomainException('Stück oder Euro auswählen.');
        $value=str_replace(',','.',trim($value));
        if(!preg_match($unit==='piece'?'/^[0-9]{1,7}$/D':'/^[0-9]{1,5}(?:\.[0-9]{1,2})?$/D',$value))throw new DomainException('Eine positive Menge oder einen Eurobetrag mit höchstens zwei Nachkommastellen eingeben.');
        $parts=explode('.',$value);$n=$unit==='piece'?(int)$value:(int)$parts[0]*100+(int)str_pad($parts[1]??'',2,'0');
        if($n<1||$n>($unit==='piece'?1000:1000000))throw new DomainException('Menge oder Betrag liegt außerhalb des erlaubten Bereichs.');return $n;
    }
    public static function display(int $value,string $unit):string { return $unit==='eur'?number_format($value/100,2,',','.').' €':$value.' Stück'; }
    public function grant(string $eventId,array $contacts,array $benefits,string $key):array {
        $a=$this->manager();$contacts=array_values(array_unique($contacts));sort($contacts);
        if(!$contacts||count($contacts)>250||!$benefits||count($benefits)>10||count($contacts)*count($benefits)>1000)throw new DomainException('Mitglieder und mindestens einen Gutschein auswählen (höchstens 1.000 Gutscheine pro Vorgang).');
        $clean=[];foreach($benefits as $b){$label=trim((string)($b['label']??''));$kind=(string)($b['kind']??'');$unit=(string)($b['unit']??'');
            if($label===''||mb_strlen($label)>120||preg_match('/[\x00-\x1f]/',$label)||!in_array($kind,['food','drink','other'],true))throw new DomainException('Gutscheinbezeichnung prüfen.');
            $clean[]=['label'=>$label,'kind'=>$kind,'unit'=>$unit,'amount'=>self::amount((string)($b['amount']??''),$unit)];}
        if(count($contacts)*array_sum(array_map(static fn($b)=>$b['unit']==='piece'?$b['amount']:1,$clean))>1000)throw new DomainException('Höchstens 1.000 Einzelgutscheine pro Vorgang.');
        return $this->request($key,['grant',$eventId,$contacts,$clean],function()use($a,$eventId,$contacts,$clean){
            $this->manager();$e=$this->lockEvent($eventId);$this->liveEvent($e,true);$valid=array_column($this->members(),null,'id');
            if(array_diff($contacts,array_keys($valid)))throw new DomainException('Bitte nur aktuelle Mitglieder auswählen.');
            $ids=[];foreach($contacts as $contact)foreach($clean as $b){
                $existing=$this->q("SELECT * FROM event_vouchers WHERE club_id=? AND event_id=? AND contact_id=? AND label=? AND state='active'",[$a['club_id'],$eventId,$contact,$b['label']])->fetchAll();
                // Older deletions left their parent active after the last ticket was revoked.
                // Recognise only fully evidenced deletions; never reopen a spent entitlement.
                foreach($existing as $previous)if(!EventVoucherTickets::fullyRevoked($this->db,$previous))
                    throw new EventVoucherError('Für {name} ist „{label}“ bereits zugeteilt.',['name'=>$valid[$contact]['name'],'label'=>$b['label']],409);
                $id=bin2hex(random_bytes(16));$this->q('INSERT INTO event_vouchers(club_id,id,event_id,contact_id,label,kind,unit,granted,remaining,code,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$a['club_id'],$id,$eventId,$contact,$b['label'],$b['kind'],$b['unit'],$b['amount'],$b['amount'],strtoupper(bin2hex(random_bytes(8))),gmdate('c')]);
                $this->move($a,$id,'grant',$b['amount'],'Zuteilung');
                EventVoucherTickets::create($this->db,['club_id'=>$a['club_id'],'id'=>$id,'unit'=>$b['unit'],'granted'=>$b['amount'],'remaining'=>$b['amount'],'state'=>'active']);$ids[]=$id;
            }return $ids;
        });
    }
    private function move(array $a,string $voucher,string $kind,int $amount,string $reason,?string $reverse=null):string {
        $id=bin2hex(random_bytes(16));$this->q('INSERT INTO event_voucher_moves(club_id,id,voucher_id,actor_id,kind,amount,reversal_of,reason,created_at) VALUES(?,?,?,?,?,?,?,?,?)',[$a['club_id'],$id,$voucher,$a['id'],$kind,$amount,$reverse,$reason,gmdate('c')]);return $id;
    }
    public function change(string $eventId,string $id,int $version,string $kind,string $amount,string $reason,string $key,string $ticketId=''):string {
        $a=$this->actor();if($kind==='revoke')$this->manager();elseif($kind!=='redeem'||!$this->canRedeem($eventId))throw new DomainException('Keine Einlöseberechtigung für diese Veranstaltung.');
        $reason=trim($reason);if($kind==='revoke'&&($reason===''||mb_strlen($reason)>255))throw new DomainException('Grund für das Sperren angeben.');
        $payload=[$kind,$eventId,$id,$version,$amount,$reason];if($ticketId!=='')$payload[]=$ticketId;
        return $this->request($key,$payload,function()use($a,$eventId,$id,$version,$kind,$amount,$reason,$ticketId){
            if($kind==='revoke')$this->manager();elseif(!$this->canRedeem($eventId))throw new DomainException('Keine Einlöseberechtigung.');
            $e=$this->lockEvent($eventId);if($kind==='redeem')$this->liveEvent($e,true);
            $v=$this->q('SELECT * FROM event_vouchers WHERE club_id=? AND event_id=? AND id=? FOR UPDATE',[$a['club_id'],$eventId,$id])->fetch();
            if(!$v||$v['state']!=='active'||(int)$v['version']!==$version)throw new DomainException('Gutschein geändert oder gesperrt. Bitte neu laden.',409);
            $n=$kind==='revoke'?($ticketId!==''?1:(int)$v['remaining']):self::amount($amount,$v['unit']);if($n>(int)$v['remaining'])throw EventVoucherError::balance((int)$v['remaining'],$v['unit']);
            $move=$this->move($a,$id,$kind,-$n,$kind==='redeem'?'Einlösung':$reason);EventVoucherTickets::debit($this->db,$v,$n,$ticketId,$kind,$move);
            $revoked=$kind==='revoke'&&($ticketId===''||EventVoucherTickets::fullyRevoked($this->db,array_replace($v,['remaining'=>(int)$v['remaining']-$n])));
            $this->q('UPDATE event_vouchers SET remaining=remaining-?,version=version+1,state=? WHERE club_id=? AND id=?',[$n,$revoked?'revoked':'active',$a['club_id'],$id]);
            return $move;
        });
    }
    public function history(string $eventId):array {return $this->readContext(fn():array=>$this->currentHistory($eventId));}
    private function currentHistory(string $eventId):array {
        $a=$this->actor();$this->event($eventId);return $this->q('SELECT m.*,v.label,v.unit,c.name,EXISTS(SELECT 1 FROM event_voucher_moves r WHERE r.club_id=m.club_id AND r.reversal_of=m.id'.$this->currentLock().') reversed FROM event_voucher_moves m JOIN event_vouchers v ON v.club_id=m.club_id AND v.id=m.voucher_id JOIN contacts c ON c.club_id=v.club_id AND c.id=v.contact_id WHERE v.club_id=? AND v.event_id=? AND (?=1 OR v.contact_id=?) ORDER BY m.created_at DESC,m.id DESC LIMIT 200'.$this->currentLock(),[$a['club_id'],$eventId,(int)$this->canRedeem($eventId),$a['contact_id']])->fetchAll();
    }
    public function reverse(string $eventId,string $move,string $reason,string $key):string {
        $a=$this->manager();$reason=trim($reason);if($reason===''||mb_strlen($reason)>255)throw new DomainException('Korrekturgrund angeben.');
        return $this->request($key,['reverse',$eventId,$move,$reason],function()use($a,$eventId,$move,$reason){
            $this->manager();$this->lockEvent($eventId);
            $m=$this->q('SELECT m.*,v.remaining,v.granted,v.state,v.unit FROM event_voucher_moves m JOIN event_vouchers v ON v.club_id=m.club_id AND v.id=m.voucher_id WHERE m.club_id=? AND v.event_id=? AND m.id=? FOR UPDATE',[$a['club_id'],$eventId,$move])->fetch();
            if(!$m||$m['kind']!=='redeem'||$m['state']!=='active'||$this->q('SELECT 1 FROM event_voucher_moves WHERE club_id=? AND reversal_of=?',[$a['club_id'],$move])->fetchColumn())throw new DomainException('Diese Einlösung kann nicht zurückgenommen werden.');
            $n=-(int)$m['amount'];if((int)$m['remaining']+$n>(int)$m['granted'])throw new DomainException('Gutscheinbestand prüfen.');
            EventVoucherTickets::reverse($this->db,['club_id'=>$a['club_id'],'id'=>$m['voucher_id'],'unit'=>$m['unit']],$move,$n);
            $this->q('UPDATE event_vouchers SET remaining=remaining+?,version=version+1 WHERE club_id=? AND id=?',[$n,$a['club_id'],$m['voucher_id']]);return $this->move($a,$m['voucher_id'],'reverse',$n,$reason,$move);
        });
    }
    public function configure(string $eventId,int $version,bool $enabled,array $helpers,string $key):void {
        $a=$this->manager();$helpers=array_values(array_unique($helpers));sort($helpers);
        $this->request($key,['configure',$eventId,$version,$enabled,$helpers],function()use($a,$eventId,$version,$enabled,$helpers){
            $this->manager();$e=$this->lockEvent($eventId);if((int)$e['version']!==$version)throw new DomainException('Veranstaltung geändert. Bitte neu laden.',409);
            foreach($helpers as $id){$h=Permissions::actor($this->db,$id);if($h['club_id']!==$a['club_id']||$h['role']==='editor')throw new DomainException('Helferzugang nicht verfügbar.');}
            $this->q('DELETE FROM event_voucher_helpers WHERE club_id=? AND event_id=?',[$a['club_id'],$eventId]);
            foreach($helpers as $id)$this->q('INSERT INTO event_voucher_helpers(club_id,event_id,actor_id) VALUES(?,?,?)',[$a['club_id'],$eventId,$id]);
            $this->q('UPDATE event_voucher_events SET enabled=?,version=version+1 WHERE club_id=? AND id=?',[(int)$enabled,$a['club_id'],$eventId]);return 'saved';
        });
    }
    /** Transactions serialize operations; retries reuse a result only for the exact same actor/input. */
    public function request(string $key,array $payload,callable $work,?callable $originalContext=null):mixed {
        $a=$this->actor();if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new DomainException('Formular abgelaufen. Bitte neu laden.');
        $encoded=json_encode($payload,JSON_THROW_ON_ERROR);$hash=hash('sha256',$encoded);
        try{return \Kulturhus\Verein\Infrastructure\Database\Transactions::run($this->db,function()use($a,$key,$payload,$encoded,$hash,$work,$originalContext){
            // Ordinary PDO callers and composed transactions use the same current serialization.
            $this->q('SELECT id FROM clubs WHERE id=? FOR UPDATE',[$a['club_id']])->fetch();
            $current=$this->actor();if($current['club_id']!==$a['club_id'])throw new DomainException('Vereinszuordnung geändert. Bitte die Gutscheinverwaltung erneut öffnen.',503);
            $old=$this->q('SELECT * FROM event_voucher_requests WHERE club_id=? AND request_key=? FOR UPDATE',[$a['club_id'],$key])->fetch();
            if($old){
                if($old['actor_id']!==$a['id']||!hash_equals($old['fingerprint'],$hash))throw new DomainException('Formular wurde bereits anders verwendet. Bitte neu laden.',409);
                return json_decode($old['result_json'],true,512,JSON_THROW_ON_ERROR);
            }
            $result=$work();
            $this->q('INSERT INTO event_voucher_requests(club_id,request_key,actor_id,fingerprint,result_json,created_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$key,$a['id'],$hash,json_encode($result,JSON_THROW_ON_ERROR),gmdate('c')]);
            $extra=$originalContext===null?[]:$originalContext($result);
            if(!is_array($extra))throw new \LogicException('Original voucher context must be structured metadata.');
            $this->q('INSERT INTO event_voucher_receipts(club_id,request_key,payload_json) VALUES(?,?,?)',[$a['club_id'],$key,$this->receiptOriginal($a,$payload,$result,$extra)]);
            return$result;
        });}catch(\PDOException $failure){
            if(in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Gutscheine werden gerade bearbeitet. Bitte erneut öffnen.',503,$failure);
            throw$failure;
        }
    }
}
