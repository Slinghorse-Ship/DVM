<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
require_once __DIR__.'/DocumentCheckCreations.php';
final class DocumentChecks
{
 use DocumentCheckCreations;
 public const CATEGORIES=['hygiene'=>'Hygiene / Belehrung','alcohol'=>'Ausschank / Gaststättenanzeige','first_aid'=>'Erste-Hilfe-Ausbildung','kit'=>'Erste-Hilfe-Tasche / Material','liability'=>'Vereinshaftpflicht','event_insurance'=>'Veranstaltungsversicherung','insurance'=>'Weitere Versicherung','permit'=>'Weitere Genehmigung','other'=>'Sonstiger Nachweis'];
 public function __construct(private PDO $db,private string $actor,private Registry $registry){}
 public static function defaults():array{return ['id'=>'','version'=>0,'title'=>'','category'=>'other','document_id'=>'','contact_id'=>'','event_id'=>'','responsible'=>'','valid_until'=>'','review_on'=>'','reminder_days'=>30,'notes'=>'','state'=>'active'];}
 private function authority():array{return BoardAccess::require($this->db,$this->actor);}
 public function actor():array{return $this->authority();}
 public function get(string $id,bool $lock=false):array{$a=$this->authority();$r=DocumentCatalog::query($this->db,'SELECT * FROM document_checks WHERE club_id=? AND id=?'.($lock&&$this->db->inTransaction()?' FOR UPDATE':''),[$a['club_id'],$id])->fetch();if(!$r)throw new DomainException('Nachweis nicht verfügbar.');return $r;}
 public static function status(array $r,string $today,bool $available):array
 {
  if($r['state']==='archived')return ['key'=>'archived','label'=>'Archiviert','due'=>'','alert'=>false];
  $dates=array_values(array_filter([$r['valid_until'],$r['review_on']]));sort($dates);$due=$dates[0]??'';
  if($r['valid_until']!==''&&$r['valid_until']<$today)return ['key'=>'expired','label'=>'Abgelaufen','due'=>$due,'alert'=>true];
  if(!$available)return ['key'=>'missing','label'=>'Nachweis fehlt / nicht verfügbar','due'=>$due,'alert'=>true];
  if($r['review_on']!==''&&$r['review_on']<=$today)return ['key'=>'review','label'=>'Prüfung fällig','due'=>$due,'alert'=>true];
  if($due==='')return ['key'=>'undated','label'=>'Ohne Frist – Prüftermin festlegen','due'=>'','alert'=>true];
  if($due<=(new \DateTimeImmutable($today))->modify('+'.(int)$r['reminder_days'].' days')->format('Y-m-d'))return ['key'=>'soon','label'=>'Bald fällig','due'=>$due,'alert'=>true];
  return ['key'=>'valid','label'=>'Nachweis vorhanden / aktuell','due'=>$due,'alert'=>false];
 }
 public function listing(bool $archived=false):array
 {
  return $this->readRows($archived,false);
 }
 private function readRows(bool $archived,bool $metadata):array
 {
  $owned=!$this->db->inTransaction();if($owned)$this->db->beginTransaction();
  try{
   $a=$this->authority();$columns=$metadata?'club_id,id,version,title,category,document_id,contact_id,event_id,responsible,valid_until,review_on,reminder_days,state,updated_at':'*';
   $rows=DocumentCatalog::query($this->db,'SELECT '.$columns.' FROM document_checks WHERE club_id=? AND state=?',[$a['club_id'],$archived?'archived':'active'])->fetchAll();
   $available=[];if(array_filter(array_column($rows,'document_id'),static fn(string $id):bool=>$id!==''))$available=array_fill_keys(array_column((new Documents($this->db,$this->actor))->libraryIndex(['state'=>'active']),'id'),true);
   $today=(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Berlin')))->format('Y-m-d');
   foreach($rows as &$row){$row['document_available']=isset($available[$row['document_id']]);$row['status']=self::status($row,$today,$row['document_available']);}unset($row);
   usort($rows,static fn(array $left,array $right):int=>[!$left['status']['alert'],$left['status']['due']?:'9999',$left['title'],$left['id']]<=>[!$right['status']['alert'],$right['status']['due']?:'9999',$right['title'],$right['id']]);
   if($owned)$this->db->commit();return $rows;
  }catch(\Throwable $failure){if($owned&&$this->db->inTransaction())$this->db->rollBack();throw $failure;}
 }
 public static function pageNumber(mixed $number):int
 {
  if((!is_string($number)&&!is_int($number))||!preg_match('/\A[1-9][0-9]{0,8}\z/D',(string)$number))throw new DomainException('Gültige Nachweisseite angeben.');return (int)$number;
 }
 /** Counted original metadata; private notes and document bodies never enter the list. */
 public function page(array $input=[]):array
 {
  $this->authority();$page=self::pageNumber(array_key_exists('check_page',$input)?$input['check_page']:1);$values=[];
  foreach(['q'=>'','state'=>'active','attention'=>'','check_category'=>'']as$key=>$default){$value=array_key_exists($key,$input)?$input[$key]:$default;if(!is_string($value)||preg_match('//u',$value)!==1||mb_strlen($value,'UTF-8')>240||preg_match('/[\x00-\x1f\x7f]/',$value))throw new DomainException('Nachweissuche und Filter prüfen.');$values[$key]=$value;}
  if(!in_array($values['state'],['active','archived'],true)||!in_array($values['attention'],['','due'],true)||($values['check_category']!==''&&!isset(self::CATEGORIES[$values['check_category']])))throw new DomainException('Nachweissuche und Filter prüfen.');
  $rows=$this->readRows($values['state']==='archived',true);$all=count($rows);$needle=trim($values['q']);
  $rows=array_values(array_filter($rows,static fn(array $row):bool=>($values['attention']!== 'due'||$row['status']['alert'])&&($values['check_category']===''||$row['category']===$values['check_category'])&&($needle===''||mb_stripos($row['title'].' '.$row['responsible'],$needle,0,'UTF-8')!==false)));
  return Pagination::slice($rows,$page,25)+['all_total'=>$all,'filters'=>$values];
 }
 public function summary():array
 {
  $rows=$this->readRows(false,true);$alerts=array_values(array_filter($rows,static fn(array $row):bool=>$row['status']['alert']));return ['rows'=>array_slice($alerts,0,8),'open'=>count($alerts),'total'=>count($rows)];
 }
 public function saveWithUpload(string $id,int $version,array $data,string $name,string $bytes,string $key):string
 {
  $a=$this->authority();$saved='';
  $doc=(new Documents($this->db,$this->actor))->upload($name,$bytes,['title'=>$data['title']??$name,'kind'=>'other','scope'=>!empty($data['contact_id'])?'members':'orga','document_date'=>date('Y-m-d'),'notes'=>'Nachweis: '.($data['category']??'other'),'links'=>[]],$key,function(string $doc)use($id,$version,$data,&$saved){$saved=$this->save($id,$version,array_replace($data,['document_id'=>$doc]));});
  if($saved!=='')return $saved;
  if($id!=='')throw new DomainException('Nachweis inzwischen geändert. Bitte neu laden.',409);
  $saved=DocumentCatalog::query($this->db,'SELECT id FROM document_checks WHERE club_id=? AND document_id=?',[$a['club_id'],$doc])->fetchColumn();
  if(!$saved)throw new DomainException('Upload bereits vorhanden. Bitte Datei aus der Ablage auswählen.');return $saved;
 }
 public function save(string $id,int $version,array $data):string
 {
  $ownsTransaction=!$this->db->inTransaction();if($ownsTransaction)$this->db->beginTransaction();$this->db->exec('SAVEPOINT document_check_save');try{$a=$this->authority();$old=$id!==''?$this->get($id,true):self::defaults();if((int)$old['version']!==$version)throw new DomainException('Nachweis inzwischen geändert. Bitte neu laden.',409);$r=[];
   foreach(self::defaults() as $key=>$default){if(in_array($key,['id','version','reminder_days'],true))continue;$v=$data[$key]??$default;if(!is_string($v)||preg_match('//u',$v)!==1||mb_strlen($v,'UTF-8')>($key==='notes'?5000:300)||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$v))throw new DomainException('Nachweisangaben prüfen.');$r[$key]=$version&&is_string($old[$key]??null)&&str_replace(["\r\n","\r"],"\n",$v)===str_replace(["\r\n","\r"],"\n",$old[$key])?$old[$key]:trim($v);}
   if($r['title']===''||!isset(self::CATEGORIES[$r['category']])||!in_array($r['state'],['active','archived'],true))throw new DomainException('Titel, Kategorie und Status prüfen.');
   foreach(['valid_until','review_on'] as $key)if($r[$key]!==''){$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$r[$key]);if(!$d||$d->format('Y-m-d')!==$r[$key])throw new DomainException('Gültiges Kalenderdatum angeben.');}
   $days=filter_var($data['reminder_days']??30,FILTER_VALIDATE_INT);if($days===false||$days<0||$days>365)throw new DomainException('Erinnerung zwischen 0 und 365 Tagen wählen.');$r['reminder_days']=$days;
   if($r['document_id']!==''&&$r['document_id']!==$old['document_id'])(new Documents($this->db,$this->actor))->document($r['document_id']);
   if($r['contact_id']!==''&&$r['contact_id']!==$old['contact_id'])$this->registry->contact($r['contact_id']);
   if($r['event_id']!==''&&$r['event_id']!==$old['event_id'])$this->registry->appointment($r['event_id']);
   $id=$id!==''?$id:bin2hex(random_bytes(16));$r['version']=$version+1;$r['updated_at']=gmdate('c');
   if($version){$sets=implode(',',array_map(fn($k)=>$k.'=?',array_keys($r)));if(DocumentCatalog::query($this->db,'UPDATE document_checks SET '.$sets.' WHERE club_id=? AND id=? AND version=?',[...array_values($r),$a['club_id'],$id,$version])->rowCount()!==1)throw new DomainException('Nachweis inzwischen geändert. Bitte neu laden.',409);}
   else DocumentCatalog::query($this->db,'INSERT INTO document_checks(club_id,id,'.implode(',',array_keys($r)).') VALUES('.implode(',',array_fill(0,count($r)+2,'?')).')',[$a['club_id'],$id,...array_values($r)]);
   DocumentCatalog::query($this->db,'INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'document.check.saved',$id,$version+1,gmdate('c')]);$this->db->exec('RELEASE SAVEPOINT document_check_save');if($ownsTransaction)$this->db->commit();return $id;
  }catch(\Throwable $e){if($ownsTransaction){if($this->db->inTransaction())$this->db->rollBack();}elseif($this->db->inTransaction()){$this->db->exec('ROLLBACK TO SAVEPOINT document_check_save');$this->db->exec('RELEASE SAVEPOINT document_check_save');}throw $e;}
 }
}
