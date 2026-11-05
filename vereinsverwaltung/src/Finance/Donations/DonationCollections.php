<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
require_once __DIR__.'/DonationWaivers.php';
/** A single donor, explicit sources, official collective form and atomically claimed evidence. */
final class DonationCollections
{
 public function __construct(private PDO $db,private string $actor){}
 private function access(bool $write=false):array{return Permissions::finance($this->db,$this->actor,$write);}
 private function q(string $sql,array $args=[]):\PDOStatement{return DocumentCatalog::query($this->db,$sql,$args);}
 public function receipts():array{$a=$this->access();return $this->q('SELECT r.* FROM donation_receipts r WHERE r.club_id=? AND EXISTS(SELECT 1 FROM donation_receipt_sources s WHERE s.club_id=r.club_id AND s.receipt_id=r.id) ORDER BY r.issued_at DESC,r.id DESC LIMIT 200',[$a['club_id']])->fetchAll();}
 public function cancel(string $id,string $reason):void{$a=$this->access(true);if(!$this->q('SELECT 1 FROM donation_receipt_sources WHERE club_id=? AND receipt_id=? LIMIT 1',[$a['club_id'],$id])->fetchColumn())throw new DomainException('Sammelbestätigung nicht verfügbar.');(new DonationReceipts($this->db,$this->actor))->cancel($id,$reason);}
 public function donors():array
 {
  $a=$this->access();
  // Finance readers only see already linked financial donors, not the entire member address book.
  return $this->q("SELECT DISTINCT c.id,c.name FROM contacts c WHERE c.club_id=? AND c.archived_at IS NULL AND (EXISTS(SELECT 1 FROM donation_contacts d WHERE d.club_id=c.club_id AND d.contact_id=c.id) OR EXISTS(SELECT 1 FROM donation_waivers w WHERE w.club_id=c.club_id AND w.contact_id=c.id) OR EXISTS(SELECT 1 FROM contribution_invoices i WHERE i.club_id=c.club_id AND i.recipient_contact_id=c.id)) ORDER BY c.name,c.id LIMIT 1000",[$a['club_id']])->fetchAll();
 }
 public function choices(string $contact,int $year):array
 {
  $a=$this->access();if($year<2000||$year>(int)date('Y'))throw new DomainException('Jahr prüfen.');$allowed=array_column($this->donors(),'id');if(!in_array($contact,$allowed,true))throw new DomainException('Zugeordneten Spender auswählen.');
  $rows=$this->q("SELECT l.id,l.entry_id,e.kind,e.description,t.booked_on,".PaymentFunding::remainingSql($this->db,'l')." cents,c.receipt_id FROM finance_allocations l JOIN finance_entries e ON e.club_id=l.club_id AND e.id=l.entry_id JOIN finance_transactions t ON t.club_id=l.club_id AND t.id=l.transaction_id LEFT JOIN donation_receipt_claims c ON c.club_id=l.club_id AND c.source_type='payment' AND c.source_id=l.id WHERE l.club_id=? AND e.kind IN('donation','contribution') AND e.status!='cancelled' AND l.voided_at IS NULL AND ".PaymentFunding::remainingSql($this->db,'l').">0 AND t.cents>0 AND t.reversal_of IS NULL AND t.booked_on BETWEEN ? AND ? AND NOT EXISTS(SELECT 1 FROM finance_transactions r WHERE r.club_id=t.club_id AND r.reversal_of=t.id) ORDER BY t.booked_on,l.id LIMIT 2001",[$a['club_id'],$year.'-01-01',$year.'-12-31'])->fetchAll();
  if(count($rows)>2000)throw new DomainException('Mehr als 2000 Spendenzahlungen im Jahr; Auswahl eingrenzen lassen.');$forms=new DonationForms($this->db,$this->actor);$finance=new Finance($this->db,$this->actor);$out=[];$deductible=((new Settings($this->db,$this->actor))->section('tax')['data']['membership_deductible']??'')==='yes';
  foreach($rows as $r){if($r['kind']==='contribution'&&!$deductible)continue;$p=$r['kind']==='donation'?$finance->donationContact('entry',$r['entry_id']):$forms->contributionPayer($r['entry_id']);if(($p['id']??'')===$contact)$out[]=$r;}
  $waivers=$this->q('SELECT w.*,c.receipt_id FROM donation_waivers w LEFT JOIN donation_waiver_receipt_claims c ON c.club_id=w.club_id AND c.waiver_id=w.id WHERE w.club_id=? AND w.contact_id=? AND w.cancelled_at IS NULL AND w.waived_on BETWEEN ? AND ? ORDER BY w.waived_on,w.id',[$a['club_id'],$contact,$year.'-01-01',$year.'-12-31'])->fetchAll();
  return ['payments'=>$out,'waivers'=>$waivers];
 }
 private static function ids(mixed $rows):array{if(!is_array($rows)||!array_is_list($rows)||count($rows)>100)throw new DomainException('Höchstens 100 Zuwendungen auswählen.');foreach($rows as $id)if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Zuwendungsauswahl prüfen.');if(count(array_unique($rows))!==count($rows))throw new DomainException('Zuwendung doppelt ausgewählt.');sort($rows);return $rows;}
 public function build(array $input):array
 {
  $a=$this->access(true);if(array_diff(array_keys($input),['contact_id','from','to','payment_ids','waiver_ids','reviewed']))throw new DomainException('Unbekannte Angaben zur Sammelbestätigung.');
  if(($input['reviewed']??'')!=='1')throw new DomainException('Zuwendungen, Zeitraum und Ausstellungsberechtigung ausdrücklich prüfen.');
  foreach(['from','to'] as $key){$v=$input[$key]??'';if(!is_string($v))throw new DomainException('Zeitraum prüfen.');$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$d||$d->format('Y-m-d')!==$v||$v>date('Y-m-d'))throw new DomainException('Zeitraum prüfen.');}
  if($input['from']>$input['to']||substr($input['from'],0,4)!==substr($input['to'],0,4))throw new DomainException('Zeitraum innerhalb eines Kalenderjahres auswählen.');
  $person=DocumentCatalog::raw($this->db,$a['club_id'],'contact',is_string($input['contact_id']??null)?$input['contact_id']:'');if(!$person||$person['archived_at']||count(array_filter(explode("\n",$person['name']."\n".$person['address'])))<3)throw new DomainException('Tatsächlichen Zuwender mit vollständiger Anschrift auswählen.');
  $payments=self::ids($input['payment_ids']??[]);$waivers=self::ids($input['waiver_ids']??[]);if(!$payments&&!$waivers||count($payments)+count($waivers)>100)throw new DomainException('Eine bis 100 tatsächliche Zuwendungen auswählen.');
  $forms=new DonationForms($this->db,$this->actor);$receipts=new DonationReceipts($this->db,$this->actor);$rows=[];$children=[];$links=[];$total=0;$base=null;
  foreach($payments as $id){
   $allocation=$this->q('SELECT entry_id FROM finance_allocations WHERE club_id=? AND id=?',[$a['club_id'],$id])->fetch();if(!$allocation)throw new DomainException('Zahlung nicht verfügbar.');
   $details=$forms->sourceDetails('money',$allocation['entry_id'],$id);$source=$details['source'];$donor=$source['kind']==='contribution'?$forms->contributionPayer($allocation['entry_id']):['id'=>$source['linked_donor_id']??''];
   if(($donor['id']??'')!==$person['id'])throw new DomainException('Alle Zuwendungen müssen demselben tatsächlich zugeordneten Kontakt gehören.');if($details['claimed'])throw new DomainException('Eine ausgewählte Zahlung ist bereits bescheinigt.');
   $values=array_replace($forms->defaults('money',$allocation['entry_id'],$id),['waiver'=>'no','receipt_reviewed'=>'1']);$child=$receipts->build($values);$date=$source['payment_date'];$amount=(int)$source['payment_amount'];
   $rows[]=['source_type'=>'entry','source_id'=>$allocation['entry_id'],'claim_id'=>$id,'date'=>$date,'type'=>$source['kind']==='contribution'?'Mitgliedsbeitrag':'Geldzuwendung','waiver'=>false,'amount_cents'=>$amount];$children[]=$child;$base??=$child;$links[]='entry:'.$allocation['entry_id'];$total+=$amount;
  }
  foreach($waivers as $id){
   $w=(new DonationWaivers($this->db,$this->actor))->entry($id);if($w['contact_id']!==$person['id'])throw new DomainException('Aufwandsverzicht gehört einem anderen Zuwender.');if($this->q('SELECT 1 FROM donation_waiver_receipt_claims WHERE club_id=? AND waiver_id=?',[$a['club_id'],$id])->fetchColumn())throw new DomainException('Aufwandsverzicht bereits bescheinigt.');
   foreach(['claim_document','waiver_document'] as $key){$saved=$w['data'][$key];$d=(new Documents($this->db,$this->actor))->document($saved['id']);if($d['state']!=='active'||$d['revision']!==$saved['revision']||$d['sha256']!==$saved['sha256']||$d['version']!==$saved['version'])throw new DomainException('Anspruchs- oder Verzichtsnachweis wurde verändert.');}
   $authority=DonationAuthority::read($this->db,$this->actor);$values=array_replace($forms->defaults('money'),['donor'=>$person['name']."\n".$person['address'],'amount'=>number_format($w['amount_cents']/100,2,',',''),'donated_on'=>$w['waived_on'],'waiver'=>'yes']);$child=$forms->build($values);if($child['missing'])throw new DomainException('Steuerangaben fehlen.');$child['authority']=$authority['authority'];$child['waiver']=$w['data'];$base??=$child;$children[]=$child;
   $rows[]=['source_type'=>'waiver','source_id'=>$id,'claim_id'=>$id,'date'=>$w['waived_on'],'type'=>'Geldzuwendung','waiver'=>true,'amount_cents'=>(int)$w['amount_cents']];$total+=(int)$w['amount_cents'];
  }
  foreach($rows as $row)if($row['date']<$input['from']||$row['date']>$input['to'])throw new DomainException('Zuwendung liegt außerhalb des gewählten Zeitraums.');usort($rows,static fn($a,$b)=>[$a['date'],$a['claim_id']]<=>[$b['date'],$b['claim_id']]);
  if(count(array_unique($links))>30)throw new DomainException('Höchstens 30 unterschiedliche Spendenbelege pro Sammelbestätigung auswählen.');
  if($total>9999999999)throw new DomainException('Gesamtbetrag zu groß.');
  return ['kind'=>'donation_collection','collection'=>['donor'=>$person['name']."\n".$person['address'],'contact_id'=>$person['id'],'from'=>$input['from'],'to'=>$input['to'],'amount_cents'=>$total,'rows'=>$rows,'form'=>$base['donation_form'],'non_deductible_memberships'=>((new Settings($this->db,$this->actor))->section('tax')['data']['membership_deductible']??'')==='no'],'children'=>$children,'links'=>array_values(array_unique($links)),'issued_by'=>$a['id'],'issued_on'=>date('Y-m-d'),'signature_mode'=>'handwritten','template_sha256'=>hash_file('sha256',dirname(__DIR__, 2).'/../resources/donation-forms/collective-original.pdf')];
 }
 public function issue(array $input,string $key,?string $expectedHash=null):string
 {
  $a=$this->access(true);if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new DomainException('Formular neu öffnen.');ksort($input);$hash=hash('sha256',json_encode($input,JSON_THROW_ON_ERROR));$old=$this->q('SELECT * FROM donation_receipts WHERE club_id=? AND request_key=?',[$a['club_id'],$key])->fetch();
  if($old){if($old['input_hash']!==$hash||$old['issued_by']!==$a['id'])throw new DomainException('Vorgangskennung anders verwendet.');(new Documents($this->db,$this->actor))->document($old['document_id']);return $old['document_id'];}
  $snapshot=$this->build($input);if($expectedHash===null||!hash_equals(hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR)),$expectedHash))throw new DomainException('Zuerst aktuelle Sammelvorschau prüfen.');$id='ZB-'.date('Y').'-'.strtoupper(bin2hex(random_bytes(6)));$snapshot['receipt_number']=$id;$bytes=DocumentPreview::pdf($snapshot);
  $before=function()use($input,$snapshot){$current=$this->build($input);unset($snapshot['receipt_number']);if($current!==$snapshot)throw new DomainException('Zuwendungen oder Nachweise wurden seit der Vorschau geändert.');};
  $after=function(string $doc)use($snapshot,$a,$id,$key,$hash){
   $this->q('INSERT INTO donation_receipts(club_id,id,document_id,request_key,input_hash,kind,source_id,issued_at,issued_by) VALUES(?,?,?,?,?,?,?,?,?)',[$a['club_id'],$id,$doc,$key,$hash,'money',$snapshot['collection']['rows'][0]['source_id'],gmdate('c'),$a['id']]);
   $sources=[];foreach($snapshot['collection']['rows'] as $r){if($r['source_type']==='waiver')$this->q('INSERT INTO donation_waiver_receipt_claims VALUES(?,?,?)',[$a['club_id'],$r['source_id'],$id]);else $this->q("INSERT INTO donation_receipt_claims VALUES(?,'payment',?,?)",[$a['club_id'],$r['claim_id'],$id]);$k=$r['source_type'].'|'.$r['source_id'].'|'.$r['date'];if(!isset($sources[$k]))$sources[$k]=$r;else $sources[$k]['amount_cents']+=$r['amount_cents'];}
   foreach($sources as $r)$this->q('INSERT INTO donation_receipt_sources VALUES(?,?,?,?,?,?)',[$a['club_id'],$id,$r['source_type'],$r['source_id'],$r['amount_cents'],$r['date']]);
   $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,1,?)',[$a['club_id'],$a['id'],'donation.collection.issued',$id,gmdate('c')]);
  };
  return (new Documents($this->db,$this->actor))->generated(strtolower($id).'.pdf',$bytes,['title'=>'Sammelzuwendungsbestätigung '.$id,'kind'=>'certificate','scope'=>'finance','document_date'=>$snapshot['issued_on'],'links'=>$snapshot['links'],'notes'=>'Sammelbestätigung mit Anlage. Zur eigenhändigen Unterschrift durch eine berechtigte Person. Unterschriebenes Doppel aufbewahren. Kein Versand.'],$key,$snapshot,$before,$after);
 }
}
