<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
require_once __DIR__.'/DonationAuthority.php';
final class DonationWaivers
{
 public function __construct(private PDO $db,private string $actor){}
 private function access(bool $write=false):array{return Permissions::finance($this->db,$this->actor,$write);}
 private function q(string $sql,array $args=[]):\PDOStatement{return DocumentCatalog::query($this->db,$sql,$args);}
 public function contacts():array
 {
  $a=$this->access();if($a['members_access']!=='none')return array_map(static fn($r)=>['id'=>$r['id'],'name'=>$r['name']],(new Registry($this->db,$this->actor))->contacts());
  return $this->q("SELECT c.id,c.name FROM contacts c WHERE c.club_id=? AND c.archived_at IS NULL AND (EXISTS(SELECT 1 FROM donation_contacts d WHERE d.club_id=c.club_id AND d.contact_id=c.id) OR EXISTS(SELECT 1 FROM donation_waivers w WHERE w.club_id=c.club_id AND w.contact_id=c.id)) ORDER BY c.name,c.id LIMIT 1000",[$a['club_id']])->fetchAll();
 }
 public function listing():array{$a=$this->access();return $this->q('SELECT w.*,c.name FROM donation_waivers w JOIN contacts c ON c.club_id=w.club_id AND c.id=w.contact_id WHERE w.club_id=? ORDER BY w.created_at DESC LIMIT 200',[$a['club_id']])->fetchAll();}
 public function entry(string $id):array{$a=$this->access();$r=$this->q('SELECT * FROM donation_waivers WHERE club_id=? AND id=?',[$a['club_id'],$id])->fetch();if(!$r||$r['cancelled_at'])throw new DomainException('Aufwandsverzicht nicht verfügbar.');$r['data']=json_decode($r['snapshot'],true,512,JSON_THROW_ON_ERROR);return $r;}
 private function document(string $id):array{$d=(new Documents($this->db,$this->actor))->document($id);if($d['state']!=='active'||!in_array('finance',$d['scopes'],true))throw new DomainException('Aktiven Finanznachweis auswählen.');return ['id'=>$id,'revision'=>$d['revision'],'sha256'=>$d['sha256'],'version'=>$d['version']];}
 public function build(array $input):array
 {
  $a=$this->access(true);$allowed=['contact_id','amount','agreed_on','incurred_on','due_on','waived_on','regular','claim_document_id','waiver_document_id','description','unconditional','payable_when_granted','payable_when_waived','reviewed'];if(array_diff(array_keys($input),$allowed))throw new DomainException('Unbekannte Angaben zum Aufwandsverzicht.');
  foreach($allowed as $key)if(!is_string($input[$key]??''))throw new DomainException('Angaben zum Aufwandsverzicht prüfen.');
  foreach(['unconditional','payable_when_granted','payable_when_waived','reviewed'] as $key)if(($input[$key]??'')!=='1')throw new DomainException('Anspruch, wirtschaftliche Leistungsfähigkeit und freiwilligen Verzicht ausdrücklich prüfen.');
  if(!in_array($input['contact_id']??'',array_column($this->contacts(),'id'),true))throw new DomainException('Berechtigten tatsächlichen Zuwender auswählen.');
  $contact=DocumentCatalog::raw($this->db,$a['club_id'],'contact',$input['contact_id']??'');if(!$contact||$contact['archived_at'])throw new DomainException('Aktiven tatsächlichen Zuwender auswählen.');
  $data=['contact_id'=>$contact['id'],'donor'=>$contact['name']."\n".$contact['address']];$data['amount_cents']=Finance::cents($input['amount']??'');if($data['amount_cents']<=0)throw new DomainException('Positiven Anspruchsbetrag angeben.');
  foreach(['agreed_on','incurred_on','due_on','waived_on'] as $key){$v=$input[$key]??'';$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$d||$d->format('Y-m-d')!==$v||$v>date('Y-m-d'))throw new DomainException('Datum des Anspruchs und Verzichts prüfen.');$data[$key]=$v;}
  if($data['agreed_on']>$data['incurred_on']||$data['incurred_on']>$data['due_on']||$data['due_on']>$data['waived_on'])throw new DomainException('Anspruch muss vor dem Aufwand vereinbart sein; Fälligkeit und späteren Verzicht prüfen.');
  if(!in_array($input['regular']??'',['0','1'],true))throw new DomainException('Einmalige oder regelmäßig monatliche Tätigkeit auswählen.');$data['regular']=$input['regular']==='1';
  $due=new \DateTimeImmutable($data['due_on']);$months=$data['regular']?12:3;$target=$due->modify('first day of this month')->modify('+'.$months.' months');$day=min((int)$due->format('d'),(int)$target->format('t'));$limit=$target->setDate((int)$target->format('Y'),(int)$target->format('m'),$day);
  if($data['waived_on']>$limit->format('Y-m-d'))throw new DomainException('Verzicht nicht innerhalb von drei Monaten bzw. einem Jahr dokumentiert. Gesonderte fachliche Klärung erforderlich.');
  $data['description']=trim($input['description']??'');if($data['description']===''||strlen($data['description'])>2000)throw new DomainException('Anspruch und tatsächlichen Aufwand beschreiben (max. 2000 Zeichen).');
  $data['claim_document']=$this->document($input['claim_document_id']??'');$data['waiver_document']=$this->document($input['waiver_document_id']??'');$data['reviewed_by']=$a['id'];$data['checks']=['unconditional'=>true,'payable_when_granted'=>true,'payable_when_waived'=>true,'reviewed'=>true];return $data;
 }
 public function save(array $input,string $key):string
 {
  $a=$this->access(true);if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new DomainException('Formular neu öffnen.');ksort($input);$hash=hash('sha256',json_encode($input,JSON_THROW_ON_ERROR));
  $this->db->beginTransaction();try{
   $old=$this->q('SELECT * FROM donation_waivers WHERE club_id=? AND request_key=?',[$a['club_id'],$key])->fetch();if($old){if($old['input_hash']!==$hash||$old['created_by']!==$a['id'])throw new DomainException('Vorgangskennung bereits anders verwendet.');$this->db->commit();return $old['id'];}
   $d=$this->build($input);if($this->q('SELECT 1 FROM donation_waivers WHERE club_id=? AND contact_id=? AND claim_document_id=?',[$a['club_id'],$d['contact_id'],$d['claim_document']['id']])->fetchColumn())throw new DomainException('Dieser Anspruchsnachweis wurde bereits erfasst. Ein Anspruch darf nicht mit mehreren Verzichtserklärungen erneut bescheinigt werden.');$id=bin2hex(random_bytes(16));
   $this->q('INSERT INTO donation_waivers(club_id,id,contact_id,amount_cents,agreed_on,incurred_on,due_on,waived_on,regular,claim_document_id,waiver_document_id,snapshot,request_key,input_hash,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$a['club_id'],$id,$d['contact_id'],$d['amount_cents'],$d['agreed_on'],$d['incurred_on'],$d['due_on'],$d['waived_on'],(int)$d['regular'],$d['claim_document']['id'],$d['waiver_document']['id'],json_encode($d,JSON_THROW_ON_ERROR),$key,$hash,$a['id'],gmdate('c')]);
   $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,1,?)',[$a['club_id'],$a['id'],'donation.waiver.recorded',$id,gmdate('c')]);$this->db->commit();return $id;
  }catch(\Throwable $e){$this->db->rollBack();throw $e;}
 }
 public function cancel(string $id,int $version,string $reason):void
 {
  $a=$this->access(true);$reason=trim($reason);if($reason===''||strlen($reason)>1000)throw new DomainException('Stornogrund angeben.');$this->db->beginTransaction();try{$r=$this->entry($id);if($r['version']!==$version)throw new DomainException('Aufwandsverzicht wurde geändert.');if($this->q('SELECT 1 FROM donation_waiver_receipt_claims WHERE club_id=? AND waiver_id=?',[$a['club_id'],$id])->fetchColumn())throw new DomainException('Zuerst die Bescheinigung stornieren.');$this->q('UPDATE donation_waivers SET cancelled_at=?,cancel_reason=?,version=version+1 WHERE club_id=? AND id=?',[gmdate('c'),$reason,$a['club_id'],$id]);$this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'donation.waiver.cancelled',$id,$version+1,gmdate('c')]);$this->db->commit();}catch(\Throwable $e){$this->db->rollBack();throw $e;}
 }
}
