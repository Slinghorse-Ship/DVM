<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
final class InvoiceSupplierContacts
{
 public function __construct(private PDO $db,private string $actor){}
 public function create(array $data,string $key):array
 {
  $bank=self::bankFields($data);
  $company=(new Registry($this->db,$this->actor))->createOrganisationOnce($data,$key,function(){if(!(new Finance($this->db,$this->actor))->canWrite())throw new DomainException('Finanz-Bearbeitungsrecht erforderlich.');},function(array $created)use($bank){if($bank['iban']!==''||$bank['bic']!=='')$this->db->prepare('INSERT INTO supplier_bank_details VALUES(?,?,?,?,?,?)')->execute([$created['club_id'],$created['id'],$bank['iban'],$bank['bic'],$this->actor,gmdate('c')]);},array_filter($bank,static fn($v)=>$v!==''));
  return ['id'=>$company['id'],'name'=>$company['name'],'version'=>(int)$company['version'],'iban'=>$bank['iban'],'bic'=>$bank['bic']];
 }
 public static function bankFields(array $data):array
 {
  $out=[];foreach(['iban','bic'] as $field){if(isset($data[$field])&&!is_string($data[$field]))throw new DomainException('Bankangaben prüfen.');$out[$field]=$field==='iban'?($data[$field]??''):strtoupper(preg_replace('/\s+/u','',trim($data[$field]??'')));}
  $out['iban']=Iban::normalize($data['iban']??'');
  if($out['bic']!==''&&!preg_match('/^[A-Z]{6}[A-Z0-9]{2}(?:[A-Z0-9]{3})?$/D',$out['bic']))throw new DomainException('BIC prüfen.');
  return $out;
 }
 public function bank(string $contact):array
 {
  if(!(new Finance($this->db,$this->actor))->canRead())throw new DomainException('Finanz-Leserecht erforderlich.');
  $c=(new Registry($this->db,$this->actor))->contact($contact);if($c['kind']!=='organisation')throw new DomainException('Firmenkontakt erforderlich.');
  $q=$this->db->prepare('SELECT iban,bic FROM supplier_bank_details WHERE club_id=? AND contact_id=?');$q->execute([$c['club_id'],$contact]);return $q->fetch(PDO::FETCH_ASSOC)?:['iban'=>'','bic'=>''];
 }
 /** Update current supplier master data; original invoice snapshots are never rewritten. */
 public function saveBank(string $contact,int $version,array $data):int
 {
  return $this->writeBank($contact,$version,$data);
 }
 public function removeBank(string $contact,int $version):int
 {
  return $this->writeBank($contact,$version,null);
 }
 private function writeBank(string $contact,int $version,?array $data):int
 {
  $this->db->beginTransaction();
  try{
   $registry=new Registry($this->db,$this->actor);
   if(!(new Finance($this->db,$this->actor))->canWrite()||!$registry->canWrite())throw new DomainException('Zum Ändern der Lieferantenbankdaten sind Finanz- und Kontakt-Bearbeitungsrechte erforderlich.');
   $company=$registry->contact($contact);if($company['kind']!=='organisation')throw new DomainException('Bankdaten dieser Art gehören zu einem Firmenkontakt.');
   if((int)$company['version']!==$version)throw new DomainException('Der Firmenkontakt wurde inzwischen geändert. Bitte neu laden und Ihre Bankangaben vergleichen.');
   $bank=$data===null?null:self::bankFields($data);
   if($bank!==null&&$bank['iban']==='')throw new DomainException('Bitte eine IBAN eingeben. Zum Entfernen verwenden Sie „Bankverbindung entfernen“.');
   if($bank===null){$q=$this->db->prepare('DELETE FROM supplier_bank_details WHERE club_id=? AND contact_id=?');$q->execute([$company['club_id'],$contact]);if($q->rowCount()!==1)throw new DomainException('Für diesen Firmenkontakt sind keine Bankdaten hinterlegt.');}
   else{$q=$this->db->prepare('INSERT INTO supplier_bank_details(club_id,contact_id,iban,bic,actor_id,created_at) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE iban=VALUES(iban),bic=VALUES(bic),actor_id=VALUES(actor_id),created_at=VALUES(created_at)');$q->execute([$company['club_id'],$contact,$bank['iban'],$bank['bic'],$this->actor,gmdate('c')]);}
   $q=$this->db->prepare("UPDATE contacts SET version=version+1 WHERE club_id=? AND id=? AND kind='organisation' AND archived_at IS NULL AND version=?");$q->execute([$company['club_id'],$contact,$version]);
   if($q->rowCount()!==1)throw new DomainException('Der Firmenkontakt wurde inzwischen geändert. Bitte neu laden und Ihre Bankangaben vergleichen.');
   $this->db->prepare('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)')->execute([$company['club_id'],$this->actor,$bank===null?'supplier.bank.removed':'supplier.bank.saved',$contact,$version+1,gmdate('c')]);
   $this->db->commit();return $version+1;
  }catch(\Throwable $error){$this->db->rollBack();throw $error;}
 }
 /** Complete six-row company search with an independently retained selected original. */
 public function page(array $input=[]):array
 {
  if(!(new Finance($this->db,$this->actor))->canWrite())throw new DomainException('Finanz-Bearbeitungsrecht erforderlich.');
  $page=(new Registry($this->db,$this->actor))->contactChoicePage(array_replace($input,['contact_kind'=>'organisation']));
  $club=Permissions::actor($this->db,$this->actor)['club_id'];$query=$this->db->prepare('SELECT iban,bic FROM supplier_bank_details WHERE club_id=? AND contact_id=?');
  $project=static function(array $row)use($query,$club):array{$query->execute([$club,$row['id']]);$bank=$query->fetch(PDO::FETCH_ASSOC)?:['iban'=>'','bic'=>''];return ['id'=>$row['id'],'name'=>$row['name'],'version'=>(int)$row['version'],'iban'=>(string)$bank['iban'],'bic'=>(string)$bank['bic']];};
  $page['rows']=array_map($project,$page['rows']);if($page['selected']!==null)$page['selected']=$project($page['selected']);
  return $page;
 }
 public function options():array
 {
  if(!(new Finance($this->db,$this->actor))->canWrite())throw new DomainException('Finanz-Bearbeitungsrecht erforderlich.');
  $out=[];$query=$this->db->prepare('SELECT iban,bic FROM supplier_bank_details WHERE club_id=? AND contact_id=?');
  foreach(array_filter((new Registry($this->db,$this->actor))->contacts(),static fn(array $c):bool=>$c['kind']==='organisation') as $company){$query->execute([$company['club_id'],$company['id']]);$bank=$query->fetch(PDO::FETCH_ASSOC)?:['iban'=>'','bic'=>''];$out[]=['id'=>$company['id'],'name'=>$company['name'],'version'=>(int)$company['version'],'iban'=>(string)($bank['iban']??''),'bic'=>(string)($bank['bic']??'')];}
  return $out;
 }
 public function checked(string $id,int $version):array
 {
  if(!(new Finance($this->db,$this->actor))->canWrite())throw new DomainException('Finanz-Bearbeitungsrecht erforderlich.');
  $c=(new Registry($this->db,$this->actor))->contact($id);
  if($c['kind']!=='organisation')throw new DomainException('Bitte einen Firmenkontakt auswählen.');
  if((int)$c['version']!==$version)throw new DomainException('Firmenkontakt inzwischen geändert. Bitte Firmenliste aktualisieren und erneut auswählen.');
  return $c;
 }
 public function link(string $entry,string $id,int $version):void
 {
  $c=$this->checked($id,$version);$e=(new Finance($this->db,$this->actor))->entry($entry);if($e['kind']!=='expense')throw new DomainException('Ausgabe erforderlich.');
  $this->db->prepare('INSERT INTO finance_supplier_contacts VALUES(?,?,?,?,?,?,?)')->execute([$e['club_id'],$entry,$id,$version,$c['name'],$this->actor,gmdate('c')]);
  $this->db->prepare('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)')->execute([$e['club_id'],$this->actor,'finance.supplier.linked',$entry,1,gmdate('c')]);
 }
 public function forEntry(string $id):?array
 {
  $e=(new Finance($this->db,$this->actor))->entry($id);
  $q=$this->db->prepare('SELECT * FROM finance_supplier_contacts WHERE club_id=? AND entry_id=?');$q->execute([$e['club_id'],$id]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!$r)return null;
  try{(new Registry($this->db,$this->actor))->contact($r['contact_id']);$r['can_open']=true;}catch(DomainException){$r['can_open']=false;}
  return $r;
 }
}
