<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
require_once __DIR__.'/GemaSetlist.php';
final class EventGema
{
    public const STATES=['check'=>'Noch prüfen','not_required'=>'Keine Anmeldung erforderlich (begründet)','register'=>'Anmeldung offen','registered'=>'Angemeldet','requested'=>'Kostenübernahme beantragt','exempt'=>'Kostenübernahme bestätigt'];
    public function __construct(private PDO $db,private string $actor,private Registry $registry){}
    public function get(string $event):array{$a=BoardAccess::require($this->db,$this->actor);$this->registry->appointment($event);$r=DocumentCatalog::query($this->db,'SELECT * FROM event_gema WHERE club_id=? AND event_id=?',[$a['club_id'],$event])->fetch();return array_replace(self::defaults(),$r?:['version'=>0,'status'=>'check','reference'=>'','notes'=>'','setlist'=>'check','entry_id'=>''],json_decode($r['details']??'{}',true,512,JSON_THROW_ON_ERROR));}
    public static function defaults():array{return ['songs'=>[],'nonprofit'=>'unknown','voluntary'=>'unknown','lower_saxony'=>'unknown','free_entry'=>'unknown','quota'=>'unknown','event_type'=>'unknown','area'=>'','live_music'=>'unknown','planned'=>'0','registered_at'=>'','setlist_sent_on'=>'','confirmed_on'=>'','registration_document'=>'','confirmation_document'=>'','setlist_document'=>'','registration_reason'=>''];}
    public static function eligibility(array $data,string $date):array
    {
        $reasons=[];foreach(['nonprofit'=>'Gemeinnützigkeit','voluntary'=>'Überwiegendes Ehrenamt','lower_saxony'=>'Vereinssitz Niedersachsen','free_entry'=>'Eintrittsfreiheit','quota'=>'Verfügbares Landeskontingent'] as $key=>$label)if(($data[$key]??'unknown')!=='yes')$reasons[]=$label.': '.(($data[$key]??'unknown')==='no'?'nicht erfüllt':'zu prüfen');
        if(($data['event_type']??'unknown')!=='club_festival')$reasons[]='Begünstigtes Vereinsfest prüfen; Konzerte, Festivals und weitere ausgeschlossene Formate sind nicht begünstigt.';
        if(($data['area']??'')===''||(float)$data['area']>500||(float)$data['area']<=0)$reasons[]='Veranstaltungsfläche muss bekannt und höchstens 500 m² sein.';
        if($date<'2024-11-01')$reasons[]='Pauschale gilt erst ab 01.11.2024.';
        return $reasons;
    }
    public function deadlines(string $event):array
    {
        $e=$this->registry->appointment($event);$g=$this->get($event);$date=(new \DateTimeImmutable($e['starts_at']))->setTimezone(new \DateTimeZone('Europe/Berlin'));
        $due=$date->modify('+42 days')->format('Y-m-d');$past=$date->getTimestamp()<time();
        return ['registration_before'=>$date->format('d.m.Y. H:i'),'setlist_due'=>$due,'registration_overdue'=>$past&&in_array($g['status'],['check','register'],true),'setlist_overdue'=>$g['live_music']==='yes'&&$g['setlist']!=='submitted'&&$due<date('Y-m-d'),'eligibility'=>self::eligibility($g,$date->format('Y-m-d'))];
    }
    public function save(string $event,int $version,array $data):void
    {
        $ownsTransaction=!$this->db->inTransaction();if($ownsTransaction)$this->db->beginTransaction();$this->db->exec('SAVEPOINT gema_save');try{$a=BoardAccess::require($this->db,$this->actor);$e=$this->registry->appointment($event);$old=$this->get($event);if((int)$old['version']!==$version)throw new DomainException('GEMA-Vorgang inzwischen geändert.');
            $status=(string)($data['status']??'check');$reference=trim((string)($data['reference']??''));$notes=trim((string)($data['notes']??''));$setlist=(string)($data['setlist']??'check');$entry=(string)($data['entry_id']??'');
            if(!isset(self::STATES[$status])||!in_array($setlist,['check','not_required','pending','submitted'],true)||mb_strlen($reference)>200||mb_strlen($notes)>5000)throw new DomainException('GEMA-Angaben prüfen.');
            if($status==='exempt'&&$notes==='')throw new DomainException('Bestätigung oder Begründung dokumentieren.');
            if($entry!==($old['entry_id']??'')&&$entry!==''){$invoice=(new Finance($this->db,$this->actor))->entry($entry);if($invoice['kind']!=='expense'||in_array($invoice['payment_state'],['cancelled','reversed'],true))throw new DomainException('Aktiven Ausgabebeleg auswählen.');}
            if($entry!==''&&DocumentCatalog::query($this->db,'SELECT 1 FROM event_gema WHERE club_id=? AND entry_id=? AND event_id!=?',[$a['club_id'],$entry,$event])->fetchColumn())throw new DomainException('Diese Rechnung ist bereits einem anderen GEMA-Vorgang zugeordnet.');
            if($status==='exempt'&&$entry!=='')throw new DomainException('Kostenübernahme und verknüpfte Rechnung widersprechen sich; Vorgang zuerst klären.');
            $date=(new \DateTimeImmutable($e['starts_at']))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d');
            $details=array_intersect_key(array_replace(self::defaults(),$old,$data),self::defaults());
            if(!is_string($details['registration_reason'])||mb_strlen($details['registration_reason'])>5000)throw new DomainException('Begründung für die nicht erforderliche Anmeldung eingeben (höchstens 5000 Zeichen).');$details['registration_reason']=trim($details['registration_reason']);
            if($status==='not_required'&&$notes===''&&$details['registration_reason']==='')throw new DomainException('Bestätigung oder Begründung dokumentieren.');
            if(!is_array($details['songs']))throw new DomainException('Setlist prüfen.');
            $details['songs']=GemaSetlist::normalize($details['songs']);
            foreach(['nonprofit','voluntary','lower_saxony','free_entry','quota','live_music'] as $field)if(!in_array($details[$field],['unknown','yes','no'],true))throw new DomainException('Prüfangaben ungültig.');
            if(!in_array($details['planned'],['0','1'],true)||!in_array($details['event_type'],['unknown','club_festival','concert','festival','other'],true))throw new DomainException('Veranstaltungsart prüfen.');
            if($details['area']!==''&&(!preg_match('/^[0-9]{1,7}(?:[.,][0-9]{1,2})?$/D',$details['area'])||(float)str_replace(',','.',$details['area'])<=0))throw new DomainException('Fläche prüfen.');$details['area']=str_replace(',','.',$details['area']);
            foreach(['registered_at','setlist_sent_on','confirmed_on'] as $field)if($details[$field]!==''){$format=$field==='registered_at'?'Y-m-d\TH:i':'Y-m-d';$dt=\DateTimeImmutable::createFromFormat('!'.$format,$details[$field],new \DateTimeZone('Europe/Berlin'));if(!$dt||$dt->format($format)!==$details[$field]||$dt->getTimestamp()>time())throw new DomainException('Nachweisdatum prüfen.');}
            foreach(['registration_document','confirmation_document','setlist_document'] as $field)if($details[$field]!==''&&$details[$field]!==$old[$field])(new Documents($this->db,$this->actor))->document($details[$field]);
            if($status==='exempt'){
                if(self::eligibility($details,$date)||$reference===''||$details['confirmed_on']===''||$details['registered_at']===''||(new \DateTimeImmutable($details['registered_at'],new \DateTimeZone('Europe/Berlin')))->getTimestamp()>=(new \DateTimeImmutable($e['starts_at']))->getTimestamp())throw new DomainException('Bestätigte Kostenübernahme benötigt erfüllte Voraussetzungen, rechtzeitige Anmeldung, Vorgangsnummer und Bestätigungsdatum.');
                $count=(int)DocumentCatalog::query($this->db,"SELECT COUNT(*) FROM event_gema WHERE club_id=? AND event_id!=? AND substr(event_date,1,4)=? AND status='exempt'",[$a['club_id'],$event,substr($date,0,4)])->fetchColumn();if($count>=4)throw new DomainException('Für dieses Jahr sind bereits vier Kostenübernahmen bestätigt.');
            }
            DocumentCatalog::query($this->db,'INSERT INTO event_gema(club_id,event_id,event_title,event_date,status,reference,notes,setlist,entry_id,version) VALUES(?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE event_title=VALUES(event_title),event_date=VALUES(event_date),status=VALUES(status),reference=VALUES(reference),notes=VALUES(notes),setlist=VALUES(setlist),entry_id=VALUES(entry_id),version=VALUES(version)',[$a['club_id'],$event,$e['title'],$date,$status,$reference,$notes,$setlist,$entry!==''?$entry:null,$version+1]);
            DocumentCatalog::query($this->db,'UPDATE event_gema SET details=? WHERE club_id=? AND event_id=?',[json_encode($details,JSON_THROW_ON_ERROR),$a['club_id'],$event]);
            DocumentCatalog::query($this->db,'INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'event.gema.saved',$event,$version+1,gmdate('c')]);$this->db->exec('RELEASE SAVEPOINT gema_save');if($ownsTransaction)$this->db->commit();
        }catch(\Throwable $ex){if($ownsTransaction){if($this->db->inTransaction())$this->db->rollBack();}elseif($this->db->inTransaction()){$this->db->exec('ROLLBACK TO SAVEPOINT gema_save');$this->db->exec('RELEASE SAVEPOINT gema_save');}throw $ex;}
    }
    public function changeSong(string $event,int $version,string $operation,string $index,array $song=[]):void
    {
        $data=$this->get($event);$songs=$data['songs'];
        if($index==='new'&&$operation==='save'){$songs[]=$song;}
        else{
            if(!preg_match('/^(0|[1-9][0-9]{0,2})$/D',$index)||!isset($songs[(int)$index]))throw new DomainException('Titel inzwischen geändert.');$i=(int)$index;
            if($operation==='save')$songs[$i]=$song;
            elseif($operation==='remove')array_splice($songs,$i,1);
            elseif(in_array($operation,['up','down'],true)){$j=$i+($operation==='up'?-1:1);if(isset($songs[$j]))[$songs[$i],$songs[$j]]=[$songs[$j],$songs[$i]];}
            else throw new DomainException('Titelaktion ungültig.');
        }
        $data['songs']=GemaSetlist::normalize($songs);$this->save($event,$version,$data);
    }
    public function linkInvoice(string $event,int $version,string $entry):void
    {
        if(!(new Finance($this->db,$this->actor))->canWrite())throw new DomainException('Finanz-Schreibrecht erforderlich.');
        $old=$this->get($event);if($old['entry_id']===$entry&&$entry!=='')return;
        $this->save($event,$version,array_replace($old,['entry_id'=>$entry]));
    }
    public function uploadInvoice(string $event,string $entry,string $name,string $bytes,string $key,?CameraDocumentUploads $prepared=null):string
    {
        $g=$this->get($event);if($entry===''||$g['entry_id']!==$entry)throw new DomainException('Zuerst die Rechnung zuordnen.');
        $f=new Finance($this->db,$this->actor);if(!$f->canWrite())throw new DomainException('Finanz-Schreibrecht erforderlich.');$r=$f->entry($entry);
        if(in_array($r['payment_state'],['cancelled','reversed'],true))throw new DomainException('Aktive Rechnung auswählen.');
        return (new Documents($this->db,$this->actor))->upload($name,$bytes,['title'=>'GEMA-Rechnung · '.$r['description'],'kind'=>'receipt','scope'=>'finance','document_date'=>$r['document_date'],'links'=>['entry:'.$entry]],$key,function()use($event,$entry){if($this->get($event)['entry_id']!==$entry)throw new DomainException('Rechnungszuordnung inzwischen geändert.');},$prepared);
    }
    public function checklist(string $event,int $version,string $needed,bool $done,string $setlist,string $reason=''):void
    {
        if(!in_array($needed,['unknown','yes','no'],true)||!in_array($setlist,['check','not_required','pending','submitted'],true))throw new DomainException('Erledigungsstand auswählen.');
        $data=$this->get($event);
        $data['status']=$needed==='no'?'not_required':($needed==='unknown'?'check':($done?(in_array($data['status'],['exempt','requested'],true)?$data['status']:'registered'):'register'));
        if($needed==='no'){$reason=trim($reason);if($reason===''||mb_strlen($reason)>5000)throw new DomainException('Begründung für die nicht erforderliche Anmeldung eingeben (höchstens 5000 Zeichen).');$data['registration_reason']=$reason;}
        $data['setlist']=$setlist;$this->save($event,$version,$data);
    }
 public static function isMusicCandidate(array $row):bool
 {
  if(($row['live_music']??'')==='yes')return true;
  return in_array($row['event_type']??'', ['club_festival','concert','festival'], true);
 }
 /** Calendar and website events, without requiring a GEMA classification first. */
 public function yearEvents(int $year):array
 {
  BoardAccess::require($this->db,$this->actor);
  if($year<2000||$year>2100)throw new DomainException('Jahr prüfen.');
  // A rescheduled event keeps its existing GEMA record even across a year boundary.
  $saved=array_column($this->savedRows(null),null,'event_id');$rows=[];
  foreach($this->registry->appointments($year.'-01-01',($year+1).'-01-01') as $event){
   $rows[$event['id']]=array_replace(self::defaults(),['event_id'=>$event['id'],'status'=>'check','setlist'=>'check','payment_state'=>'Keine Rechnung verknüpft'],$saved[$event['id']]??[],['event_title'=>$event['title'],'starts_at'=>$event['starts_at'],'event_date'=>(new \DateTimeImmutable($event['starts_at']))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d')]);
  }
  usort($rows,static fn($a,$b)=>[$a['starts_at'],$a['event_id']]<=>[$b['starts_at'],$b['event_id']]);return $rows;
 }
 private function upcomingRows(int $year):array{return array_values(array_filter($this->yearEvents($year),static fn(array $row):bool=>(new \DateTimeImmutable($row['starts_at']))->getTimestamp()>=time()));}
 public function upcoming(int $year):array{return array_values(array_filter($this->upcomingRows($year),self::isMusicCandidate(...)));}
 public function upcomingReview(int $year):array{return array_values(array_filter($this->upcomingRows($year),static fn(array $row):bool=>!self::isMusicCandidate($row)));}
    public function overview(int $year):array
    {
        if($year<2000||$year>2100)throw new DomainException('Jahr prüfen.');return $this->savedRows($year);
    }
    private function savedRows(?int $year):array
    {
        $a=BoardAccess::require($this->db,$this->actor);$params=[$a['club_id']];if($year!==null)$params[]=(string)$year;
        $rows=DocumentCatalog::query($this->db,"SELECT g.*,e.display_number,e.amount_cents,e.due_date,e.status invoice_status,r.reversed_on reversal_id,".PaymentFunding::paidSql($this->db,'e')." allocated_cents FROM event_gema g LEFT JOIN finance_entries e ON e.club_id=g.club_id AND e.id=g.entry_id LEFT JOIN finance_reversals r ON r.club_id=e.club_id AND r.entry_id=e.id WHERE g.club_id=?".($year!==null?' AND substr(g.event_date,1,4)=?':'')." ORDER BY g.event_date,g.event_id",$params)->fetchAll();
        foreach($rows as &$r){$r=array_replace(self::defaults(),$r,json_decode($r['details']??'{}',true,512,JSON_THROW_ON_ERROR));$r['payment_state']=!$r['entry_id']?'Keine Rechnung verknüpft':($r['reversal_id']||$r['invoice_status']==='cancelled'?'Storniert':((int)$r['allocated_cents']===(int)$r['amount_cents']?'Bezahlt':((int)$r['allocated_cents']>0?'Teilbezahlt':($r['due_date']<date('Y-m-d')?'Unbezahlt / überfällig':'Unbezahlt'))));}return $rows;
    }
}
