<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
/** No collection or grant issuance. Step-up grants are issued by the authentication boundary. */
final class MemberBankVault
{
 public const TEMPLATE='sepa-core-recurring-v1';
 public const TEXT='Ich ermächtige den genannten Zahlungsempfänger, wiederkehrende Zahlungen von meinem Konto mittels SEPA-Basislastschrift einzuziehen. Zugleich weise ich mein Kreditinstitut an, die vom Zahlungsempfänger auf mein Konto gezogenen Lastschriften einzulösen. Ich kann innerhalb von acht Wochen, beginnend mit dem Belastungsdatum, die Erstattung des belasteten Betrages verlangen. Es gelten dabei die mit meinem Kreditinstitut vereinbarten Bedingungen.';
 public function __construct(private PDO $db,private string $actor,private string $keyPath,private int $keyVersion=1,private ?\Closure $identityGuard=null){}
 public static function fromEnvironment(PDO $db,string $actor,bool $production=false,?\Closure $identityGuard=null):self
 {
  $path=getenv('VEREIN_MEMBER_BANK_KEY_PATH')?:($production?(getenv('VEREIN_PRIVATE_ROOT')?:throw new DomainException('Privaten Bankdatenschlüssel konfigurieren.')).'/member-bank-keys/v1.key':dirname(dirname(__DIR__, 2),2).'/.local/vereinsverwaltung/member-bank-keys/v1.key');
  $version=getenv('VEREIN_MEMBER_BANK_KEY_VERSION')?:'1';if(!ctype_digit($version)||(int)$version<1)throw new DomainException('Bankdatenschlüsselversion prüfen.');
  return new self($db,$actor,$path,(int)$version,$identityGuard);
 }
 private function q(string $sql,array $args=[]):\PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
 private static function membershipDay():string{return (new \DateTimeImmutable('now',new \DateTimeZone('Europe/Berlin')))->format('Y-m-d');}
 private function access(string $contact):array
 {
  if($this->identityGuard!==null)($this->identityGuard)();
  $a=Permissions::actor($this->db,$this->actor);if($a['role']==='editor')throw new DomainException('Kein Zugriff auf Mitgliedsbankdaten.');
  $today=self::membershipDay();$c=$this->q("SELECT c.id FROM contacts c WHERE c.club_id=? AND c.id=? AND c.kind='person' AND c.archived_at IS NULL AND EXISTS(SELECT 1 FROM memberships m WHERE m.club_id=c.club_id AND m.contact_id=c.id AND m.status IN ('aktiv','passiv','ehemalig') AND m.starts_on<=? AND (m.ends_on IS NULL OR m.ends_on>=?))",[$a['club_id'],$contact,$today,$today])->fetch();
  if(!$c)throw new DomainException('Aktuelle Mitgliedschaft erforderlich.');
  if($a['contact_id']!==$contact&&!$this->mayManage($a))throw new DomainException('Nur eigene Bankdaten oder Verwaltung durch Superuser, Kassenwart oder bestätigtes Vorstandsamt erlaubt.');
  return $a;
 }
 private function mayManage(array $actor):bool
 {
  if($actor['role']==='editor')return false;
  if(!empty($actor['is_superuser']))return true;
  return $actor['finance_access']==='write'&&FinancePaymentAuthority::find($this->db,$actor['id'])!==null;
 }
 /** The import reuses the private bank key and never supplies or transfers a SEPA mandate. */
 public function importAvailable():void
 {
  if(!$this->canManageMembers())throw new DomainException('Mitglieder- und Bankverwaltungsrecht für den Bankdatenimport erforderlich.',403);
  $this->key();
 }
 public function importNew(string $contact,#[\SensitiveParameter] array $input,MemberImportBankPermit $permit,string $source):void
 {
  if(!$this->db->inTransaction())throw new DomainException('Bankimport nur gemeinsam mit der geprüften Mitgliedschaft übernehmen.',409);
  $permit->assert($this->db,$this->actor,$source);$a=$this->access($contact);$data=self::canonicalPayload('save',$input);$this->key();
  if($this->q('SELECT 1 FROM member_bank_records WHERE club_id=? AND contact_id=? FOR UPDATE NOWAIT',[$a['club_id'],$contact])->fetchColumn())throw new DomainException('Vorhandene Bankdaten bleiben unverändert. Bankangaben im Profil prüfen.',409);
  $now=gmdate('c');$value=['bank'=>$data,'status'=>'saved','mandate_ref'=>'','terms'=>null];$operation=bin2hex(random_bytes(16));
  $this->q('INSERT INTO member_bank_records VALUES(?,?,?,?,?,?,?,?)',[$a['club_id'],$contact,1,$this->keyVersion,$this->encrypt($value,$a['club_id'],$contact,1,'record'),'saved','',$now]);
  $snapshot=['value'=>$value,'request'=>$data,'previous_mandate_ref'=>'','created_at'=>$now,'source'=>'member-excel','preview_hash'=>$source];
  $this->q('INSERT INTO member_bank_history VALUES(?,?,?,?,?,?,?,?,?)',[$operation,$a['club_id'],$contact,1,'save',$this->keyVersion,$this->encrypt($snapshot,$a['club_id'],$contact,1,'history:save'),$a['id'],$now]);
  $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'member-bank.imported',$operation,1,$now]);
 }
 /** Private spreadsheet read after explicit native confirmation; mandate terms are never exported. */
 public function exportReviewed(#[\SensitiveParameter] array $records,MemberImportBankPermit $permit,string $source):array
 {
  if(!$this->db->inTransaction())throw new DomainException('Bankexport nur für die gemeinsam geprüfte Mitgliederauswahl öffnen.',409);
  $permit->assert($this->db,$this->actor,$source,'export');$this->importAvailable();$a=Permissions::actor($this->db,$this->actor);
  foreach($records as&$record){
   $contact=$record['_contact_id']??null;if(!is_string($contact)||$contact==='')throw new DomainException('Ursprüngliche Mitgliederauswahl für Bankexport prüfen.',422);
   $row=$this->q('SELECT * FROM member_bank_records WHERE club_id=? AND contact_id=? FOR SHARE NOWAIT',[$a['club_id'],$contact])->fetch();
   $record['iban']=$record['holder']=$record['bic']='';if(!$row||$row['status']==='removed')continue;
   $this->access($contact);$value=$this->decrypt($row);if(empty($value['bank']))continue;$bank=self::canonicalPayload('save',$value['bank']);
   $record['iban']=$bank['iban'];$record['holder']=$bank['holder'];$record['bic']=$bank['bic'];
  }unset($record);
  $permit->assert($this->db,$this->actor,$source,'export');return$records;
 }
 public function canManageMembers():bool
 {
  try{return $this->mayManage(Permissions::actor($this->db,$this->actor));}catch(DomainException){return false;}
 }
 /** Collection decisions must see current authority and mandates even in an older caller transaction. */
 private function collectionRead(callable $work,string $busy='Mandat oder Zahlungsberechtigung wird gerade bearbeitet. Erneut prüfen.'):mixed
 {
  $own=!$this->db->inTransaction();
  try{if($own)$this->db->exec('START TRANSACTION');$result=$work();if($own)$this->db->commit();return $result;}
  catch(\Throwable $failure){if($own&&$this->db->inTransaction())$this->db->rollBack();if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException($busy,503,$failure);throw $failure;}
 }
 private function collectionActor():array
 {
  if($this->identityGuard!==null)($this->identityGuard)();
  $a=Permissions::finance($this->db,$this->actor,true);FinancePaymentAuthority::require($this->db,$this->actor);return $a;
 }
 private function collectionQuery(string $sql,array $args=[]):\PDOStatement
 {
  return $this->q($sql.' FOR SHARE NOWAIT',$args);
 }
 private static function collectionSignedOn(string $instant):string{return (new \DateTimeImmutable($instant,new \DateTimeZone('Europe/Berlin')))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d');}
 /** Internal SEPA preparation boundary; only a confirmed payment authority may read the mandate snapshot. */
 public function mandateForCollection(string $contact):array
 {
  return $this->collectionRead(function()use($contact):array{
  $a=$this->collectionActor();
  $person=$this->collectionQuery("SELECT id FROM contacts WHERE club_id=? AND id=? AND kind='person' AND archived_at IS NULL",[$a['club_id'],$contact])->fetchColumn();
  $today=self::membershipDay();$member=$this->collectionQuery("SELECT id FROM memberships WHERE club_id=? AND contact_id=? AND status IN ('aktiv','passiv','ehemalig') AND starts_on<=? AND (ends_on IS NULL OR ends_on>=?)",[$a['club_id'],$contact,$today,$today])->fetchColumn();
  if(!$person||!$member)throw new DomainException('Aktuelle Mitgliedschaft erforderlich.');
  $row=$this->collectionQuery('SELECT * FROM member_bank_records WHERE club_id=? AND contact_id=?',[$a['club_id'],$contact])->fetch();if(!$row)throw new DomainException('Kein gespeichertes Mandat.');$value=$this->decrypt($row);
  $terms=self::terms($this->collectionQuery('SELECT * FROM member_bank_config WHERE club_id=?',[$a['club_id']])->fetch()?:null);
  if($value['status']!=='mandated'||!$terms['enabled']||empty($value['bank'])||!hash_equals(self::payloadHash('mandate',$value['terms']),self::payloadHash('mandate',$terms['payload'])))throw new DomainException('Aktuelles aktives Mandat mit unveränderten Gläubigerangaben erforderlich.');
  return ['contact_id'=>$contact,'version'=>(int)$row['version'],'bank'=>$value['bank'],'reference'=>$value['mandate_ref'],'signed_on'=>self::collectionSignedOn($row['updated_at']),'terms'=>$value['terms']];
  });
 }
 /** Read a member's current account only at the reviewed reimbursement-payment boundary. */
 public function bankForReimbursement(string $contact):array
 {
  $a=$this->access($contact);FinancePaymentAuthority::require($this->db,$this->actor);
  $row=$this->q('SELECT * FROM member_bank_records WHERE club_id=? AND contact_id=?',[$a['club_id'],$contact])->fetch();
  if(!$row)throw new DomainException('Für das Mitglied sind keine Bankdaten hinterlegt.');
  $value=$this->decrypt($row);if(empty($value['bank']))throw new DomainException('Für das Mitglied sind keine Bankdaten hinterlegt.');
  $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'member-bank.reimbursement-read',$contact,(int)$row['version'],gmdate('c')]);
  return ['contact_id'=>$contact,'version'=>(int)$row['version'],'bank'=>self::canonicalPayload('save',$value['bank'])];
 }
 /** Current payment-source read; a preview must neither reuse an older account nor write an audit event. */
 public function currentBankForReimbursement(string $contact):array
 {
  return $this->collectionRead(function()use($contact):array{
   $a=$this->collectionActor();$person=$this->collectionQuery("SELECT id FROM contacts WHERE club_id=? AND id=? AND kind='person' AND archived_at IS NULL",[$a['club_id'],$contact])->fetchColumn();
   $today=self::membershipDay();$member=$this->collectionQuery("SELECT id FROM memberships WHERE club_id=? AND contact_id=? AND status IN ('aktiv','passiv','ehemalig') AND starts_on<=? AND (ends_on IS NULL OR ends_on>=?)",[$a['club_id'],$contact,$today,$today])->fetchColumn();
   if(!$person||!$member)throw new DomainException('Aktuelle Mitgliedschaft erforderlich.');
   $row=$this->collectionQuery('SELECT * FROM member_bank_records WHERE club_id=? AND contact_id=?',[$a['club_id'],$contact])->fetch();if(!$row)throw new DomainException('Für das Mitglied sind keine Bankdaten hinterlegt.');$value=$this->decrypt($row);if(empty($value['bank']))throw new DomainException('Für das Mitglied sind keine Bankdaten hinterlegt.');
   return ['contact_id'=>$contact,'version'=>(int)$row['version'],'bank'=>self::canonicalPayload('save',$value['bank'])];
  },'Bankdaten oder Zahlungsberechtigungen werden gerade bearbeitet. Erneut prüfen.');
 }
 /** An approved private debt survives membership expiry; this read stays bound to that invoice. */
 public function currentBankForInvoiceReimbursement(string $entry,string $contact):array
 {
  return $this->collectionRead(function()use($entry,$contact):array{
   $a=$this->collectionActor();
   $claim=$this->collectionQuery("SELECT r.id,r.entry_id,r.contact_id,r.amount_cents FROM finance_reimbursement_claims r JOIN contacts c ON c.club_id=r.club_id AND c.id=r.contact_id AND c.kind='person' AND c.archived_at IS NULL JOIN finance_entries e ON e.club_id=r.club_id AND e.id=r.entry_id WHERE r.club_id=? AND r.entry_id=? AND r.contact_id=? AND r.state='approved' AND e.kind='expense' AND e.status<>'cancelled'",[$a['club_id'],$entry,$contact])->fetch();
   $member=$this->collectionQuery('SELECT id FROM memberships WHERE club_id=? AND contact_id=? ORDER BY id LIMIT 1',[$a['club_id'],$contact])->fetchColumn();
   if(!$claim||$claim['entry_id']!==$entry||$claim['contact_id']!==$contact||$member===false)throw new DomainException('Gespeicherte Bankdaten nur für den ursprünglichen Empfänger einer freigegebenen privaten Erstattung lesen.',403);
   $current=(new Finance($this->db,$this->actor))->currentEntry($entry);
   if($current['id']!==$entry||$current['reversed_on']||!in_array($current['payment_state'],['open','partial'],true)||(int)$current['remaining_cents']<=0||(int)$claim['amount_cents']!==(int)$current['amount_cents']||(new ExpenseReimbursements($this->db,$this->actor))->isCredit($entry))throw new DomainException('Die freigegebene private Erstattung und den aktuellen offenen Rechnungsbetrag prüfen.',409);
   (new InvoiceApprovals($this->db,$this->actor))->assertApproved($entry,true);
   $row=$this->q('SELECT * FROM member_bank_records WHERE club_id=? AND contact_id=? FOR UPDATE NOWAIT',[$a['club_id'],$contact])->fetch();
   if(!$row)throw new DomainException('Gespeicherte Bankverbindung des ursprünglichen Erstattungsempfängers fehlt.',409);
   $value=$this->decrypt($row);if(empty($value['bank']))throw new DomainException('Gespeicherte Bankverbindung des ursprünglichen Erstattungsempfängers fehlt.',409);
   return ['contact_id'=>$contact,'version'=>(int)$row['version'],'bank'=>self::canonicalPayload('save',$value['bank'])];
  },'Bankdaten oder Zahlungsberechtigungen werden gerade bearbeitet. Erneut prüfen.');
 }
 /** Refund debt survives membership expiry; only an issued credit's original buyer is readable here. */
 public function currentBankForCreditRefund(string $entry,string $contact):array
 {
  return $this->collectionRead(function()use($entry,$contact):array{
   $a=$this->collectionActor();
   $source=$this->collectionQuery("SELECT c.id FROM outgoing_invoices c JOIN outgoing_invoices p ON p.club_id=c.club_id AND p.id=c.parent_id JOIN finance_entries e ON e.club_id=c.club_id AND e.id=c.entry_id JOIN contacts b ON b.club_id=c.club_id AND b.id=? AND b.kind='person' AND b.archived_at IS NULL WHERE c.club_id=? AND c.entry_id=? AND c.kind='credit' AND c.state='issued' AND p.kind='invoice' AND p.state='issued' AND e.kind='expense' AND e.status<>'cancelled' AND JSON_UNQUOTE(JSON_EXTRACT(c.payload,'$.contact_id'))=b.id AND JSON_UNQUOTE(JSON_EXTRACT(p.payload,'$.contact_id'))=b.id",[$contact,$a['club_id'],$entry])->fetchColumn();
   if(!$source)throw new DomainException('Gespeicherte Bankdaten nur für den ursprünglichen Empfänger einer ausgegebenen Gutschrift lesen.',403);
   $row=$this->q('SELECT * FROM member_bank_records WHERE club_id=? AND contact_id=? FOR UPDATE NOWAIT',[$a['club_id'],$contact])->fetch();
   if(!$row)throw new DomainException('Gespeicherte Bankverbindung des ursprünglichen Rechnungsempfängers fehlt.',409);
   $value=$this->decrypt($row);if(empty($value['bank']))throw new DomainException('Gespeicherte Bankverbindung des ursprünglichen Rechnungsempfängers fehlt.',409);
   return ['contact_id'=>$contact,'version'=>(int)$row['version'],'bank'=>self::canonicalPayload('save',$value['bank'])];
  },'Bankdaten oder Zahlungsberechtigungen werden gerade bearbeitet. Erneut prüfen.');
 }
 /** Sensitive XML snapshots stay encrypted with the separately protected bank key. */
 public function sealCollection(string $order,array $payload):string
 {
  return $this->collectionRead(function()use($order,$payload):string{
  $a=$this->collectionActor();if(!preg_match('/^[a-f0-9]{32}$/D',$order))throw new DomainException('Auftragskennung prüfen.');$iv=random_bytes(12);$cipher=openssl_encrypt(json_encode($payload,JSON_THROW_ON_ERROR),'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,$iv,$tag,'sepa-order-v1|'.$a['club_id'].'|'.$order);if($cipher===false)throw new DomainException('Auftrag konnte nicht geschützt gespeichert werden.');return base64_encode($iv.$tag.$cipher);
  });
 }
  public function openCollection(string $order,string $envelope):array
  {
   return $this->collectionRead(function()use($order,$envelope):array{
   $a=$this->collectionActor();if(!preg_match('/^[a-f0-9]{32}$/D',$order))throw new DomainException('Auftragskennung prüfen.');$raw=base64_decode($envelope,true);if($raw===false||strlen($raw)<29)throw new DomainException('SEPA-Auftrag unlesbar.');$plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'sepa-order-v1|'.$a['club_id'].'|'.$order);if($plain===false)throw new DomainException('SEPA-Auftrag konnte nicht authentifiziert werden.');$payload=json_decode($plain,true,128,JSON_THROW_ON_ERROR);if(!is_array($payload))throw new DomainException('SEPA-Auftrag unlesbar.');return $payload;
   });
  }
  /** Sensitive outgoing transfer snapshots use the same separately protected bank key. */
  public function sealTransfer(string $order,array $payload):string
  {
   $a=Permissions::actor($this->db,$this->actor);if($a['finance_access']!=='write'||!preg_match('/^[a-f0-9]{32}$/D',$order))throw new DomainException('Finanz-Schreibrecht erforderlich.');$iv=random_bytes(12);$cipher=openssl_encrypt(json_encode($payload,JSON_THROW_ON_ERROR),'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,$iv,$tag,'payment-order-v1|'.$a['club_id'].'|'.$order);if($cipher===false)throw new DomainException('Überweisungsauftrag konnte nicht geschützt gespeichert werden.');return base64_encode($iv.$tag.$cipher);
  }
  public function openTransfer(string $order,string $envelope):array
  {
   $a=Permissions::actor($this->db,$this->actor);if($a['finance_access']!=='write'||!preg_match('/^[a-f0-9]{32}$/D',$order))throw new DomainException('Finanz-Schreibrecht erforderlich.');$raw=base64_decode($envelope,true);if($raw===false||strlen($raw)<29)throw new DomainException('Überweisungsauftrag unlesbar.');$plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'payment-order-v1|'.$a['club_id'].'|'.$order);if($plain===false)throw new DomainException('Überweisungsauftrag konnte nicht authentifiziert werden.');$value=json_decode($plain,true,128,JSON_THROW_ON_ERROR);if(!is_array($value))throw new DomainException('Überweisungsauftrag ist beschädigt.');return $value;
  }
 public function canAccess(?string $contact=null):bool
 {
  try{if($contact===null){if($this->canManageMembers())return true;$a=Permissions::actor($this->db,$this->actor);$contact=(string)($a['contact_id']??'');}$this->access($contact);return true;}catch(DomainException){return false;}
 }
 public function availableMembers():array
 {
  if(!$this->canManageMembers())throw new DomainException('Mitgliederauswahl nur für Superuser, Kassenwart oder bestätigtes Vorstandsamt.');
  $a=Permissions::actor($this->db,$this->actor);$today=self::membershipDay();$rows=$this->q("SELECT c.id,c.name FROM contacts c WHERE c.club_id=? AND c.kind='person' AND c.archived_at IS NULL AND EXISTS(SELECT 1 FROM memberships m WHERE m.club_id=c.club_id AND m.contact_id=c.id AND m.status IN ('aktiv','passiv','ehemalig') AND m.starts_on<=? AND (m.ends_on IS NULL OR m.ends_on>=?)) ORDER BY c.name,c.id",[$a['club_id'],$today,$today])->fetchAll();
  return array_map(fn(array $c):array=>['id'=>$c['id'],'name'=>$c['name'],'summary'=>$this->summary($c['id'])],$rows);
 }
 /** Six actual current people per page; no implicit choice or whole-club decrypt. */
 public function memberChoices(string $search='',int $page=1):array
 {
  if($this->identityGuard!==null)($this->identityGuard)();
  if(!$this->canManageMembers())throw new DomainException('Mitgliederauswahl nur für Superuser, Kassenwart oder bestätigtes Vorstandsamt.',403);
  if(!mb_check_encoding($search,'UTF-8')||mb_strlen($search)>200||preg_match('/[\x00-\x1f\x7f]/',$search)||$page<1||$page>1000000)throw new DomainException('Suche und Seite der Mitgliederauswahl prüfen.',422);
  $a=Permissions::actor($this->db,$this->actor);$day=self::membershipDay();$where=" FROM contacts c WHERE c.club_id=? AND c.kind='person' AND c.archived_at IS NULL AND EXISTS(SELECT 1 FROM memberships m WHERE m.club_id=c.club_id AND m.contact_id=c.id AND m.status IN ('aktiv','passiv','ehemalig') AND m.starts_on<=? AND (m.ends_on IS NULL OR m.ends_on>=?))";$args=[$a['club_id'],$day,$day];
  if($search!==''){$where.=" AND (INSTR(LOWER(c.name),LOWER(?))>0 OR EXISTS(SELECT 1 FROM memberships n WHERE n.club_id=c.club_id AND n.contact_id=c.id AND INSTR(LOWER(n.member_number),LOWER(?))>0))";$args[]=$search;$args[]=$search;}
  $total=(int)$this->q('SELECT COUNT(*)'.$where,$args)->fetchColumn();$pages=max(1,(int)ceil($total/6));if($page>$pages)throw new DomainException('Seite der Mitgliederauswahl nicht verfügbar. Bitte die erste Seite öffnen.',422);
  $rows=$this->q('SELECT c.id,c.name'.$where.' ORDER BY c.name,c.id LIMIT 6 OFFSET '.(($page-1)*6),$args)->fetchAll();
  return ['rows'=>array_map(fn(array$c):array=>$c+['summary'=>$this->summary($c['id'])],$rows),'page'=>$page,'pages'=>$pages,'total'=>$total,'search'=>$search];
 }
 /** Read an accepted mutation after a lost response; never reuses a proof or reveals bank data. */
 public function operationResult(string $contact,string $action,int $version,string $payloadHash,#[\SensitiveParameter]string $grant,string $sessionHash):?array
 {
  if(!in_array($action,['save','mandate','revoke','delete'],true)||$version<0||!preg_match('/\A[a-f0-9]{64}\z/D',$payloadHash)||!preg_match('/\A[a-f0-9]{64}\z/D',$grant)||!preg_match('/\A[a-f0-9]{64}\z/D',$sessionHash))throw new DomainException('Ursprünglichen Bankvorgang erneut prüfen.',422);
  $a=$this->access($contact);$row=$this->q('SELECT * FROM member_bank_grants WHERE token_hash=?',[hash('sha256',$grant)])->fetch();
  if(!$row||$row['club_id']!==$a['club_id']||$row['actor_id']!==$a['id']||$row['contact_id']!==$contact||$row['action']!==$action||(int)$row['expected_version']!==$version||!hash_equals($row['payload_hash'],$payloadHash)||!hash_equals($row['session_hash'],$sessionHash))throw new DomainException('Ursprünglichen Bankvorgang erneut prüfen.',403);
  if($row['used_at']===null)return null;
  $history=$this->q('SELECT * FROM member_bank_history WHERE club_id=? AND contact_id=? AND version=?',[$a['club_id'],$contact,$version+1])->fetch();
  if(!$history||$history['actor_id']!==$a['id']||$history['action']!==$action)throw new DomainException('Gespeicherten Bankvorgang derzeit nicht sicher prüfbar.',503);
  $original=$this->decrypt($history,'history:'.$action);if(!hash_equals($payloadHash,self::payloadHash($action,$original['request'])))throw new DomainException('Gespeicherten Bankvorgang derzeit nicht sicher prüfbar.',503);
  return ['action'=>$action,'stored'=>true];
 }
 private function key():string
 {
  $path=realpath($this->keyPath);if($path===false||!is_file($path)||$this->keyVersion<1)throw new DomainException('Bankdatentresor nicht betriebsbereit.');
  $normalized=strtolower(str_replace('\\','/',$path));$roots=[dirname(dirname(__DIR__, 2)).'/public'];if(!ProductionRuntime::nativeManager())$roots[]=dirname(dirname(__DIR__, 2),2).'/processwire';if(getenv('VEREIN_WEB_ROOT'))$roots[]=getenv('VEREIN_WEB_ROOT');if(!empty($_SERVER['DOCUMENT_ROOT']))$roots[]=$_SERVER['DOCUMENT_ROOT'];
  foreach($roots as $root){$resolved=realpath($root);if($resolved&&str_starts_with($normalized,rtrim(strtolower(str_replace('\\','/',$resolved)),'/').'/'))throw new DomainException('Bankdatenschlüssel muss außerhalb des Webbereichs liegen.');}
  if(PHP_OS_FAMILY!=='Windows'&&(((fileperms($path)&0077)!==0)||((fileperms(dirname($path))&0077)!==0)))throw new DomainException('Bankdatenschlüssel ist nicht privat geschützt.');
  $key=file_get_contents($path);if($key===false||strlen($key)!==32)throw new DomainException('Bankdatenschlüssel fehlt oder ist beschädigt.');return $key;
 }
 private function aad(string $club,string $contact,int $version,string $type,int $keyVersion):string{return json_encode(['member-bank-v1',$club,$contact,$version,$type,$keyVersion],JSON_THROW_ON_ERROR);}
 private function encrypt(array $data,string $club,string $contact,int $version,string $type):string
 {
  $nonce=random_bytes(12);$tag='';$cipher=openssl_encrypt(json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,$nonce,$tag,$this->aad($club,$contact,$version,$type,$this->keyVersion),16);if($cipher===false)throw new DomainException('Bankdaten konnten nicht verschlüsselt werden.');return base64_encode($nonce.$tag.$cipher);
 }
 private function decrypt(array $row,string $type='record'):array
 {
  if((int)$row['key_version']!==$this->keyVersion)throw new DomainException('Passende Bankdatenschlüsselversion erforderlich.');$raw=base64_decode($row['ciphertext'],true);if($raw===false||strlen($raw)<29)throw new DomainException('Bankdatentresor ist beschädigt.');
  $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),$this->aad($row['club_id'],$row['contact_id'],(int)$row['version'],$type,(int)$row['key_version']));
  if($plain===false)throw new DomainException('Bankdatentresor konnte nicht authentifiziert werden.');try{$data=json_decode($plain,true,512,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new DomainException('Bankdatentresor ist beschädigt.');}if(!is_array($data))throw new DomainException('Bankdatentresor ist beschädigt.');return $data;
 }
 public static function canonicalPayload(string $action,array $data):array
 {
  if($action==='reveal'){if($data!==[])throw new DomainException('Die Anzeige benötigt keine geänderten Bankangaben.');return [];}
  $text=static function(string $field,int $max,bool $required=true)use($data):string{if(!is_string($data[$field]??null))throw new DomainException('Bankformular prüfen.');$v=trim($data[$field]);if(($required&&$v==='')||mb_strlen($v)>$max||preg_match('/[\x00-\x1f\x7f]/',$v))throw new DomainException('Bankformular prüfen.');return $v;};
  if($action==='save'){$holder=$text('holder',200);$bank=InvoiceSupplierContacts::bankFields(['iban'=>$text('iban',42),'bic'=>$text('bic',11,false)]);if($bank['iban']==='')throw new DomainException('IBAN erforderlich.');return ['holder'=>$holder,'iban'=>$bank['iban'],'bic'=>$bank['bic']];}
  if(in_array($action,['revoke','delete'],true))return ['reason'=>$text('reason',500)];
  if($action==='mandate'){if(($data['consent']??'')!=='1'||($data['template_id']??'')!==self::TEMPLATE||($data['text']??'')!==self::TEXT)throw new DomainException('Vollständigen Mandatstext ausdrücklich bestätigen.');return ['template_id'=>self::TEMPLATE,'text'=>self::TEXT,'creditor_id'=>$text('creditor_id',35),'creditor_name'=>$text('creditor_name',200),'creditor_address'=>$text('creditor_address',500),'consent'=>'1'];}
  throw new DomainException('Bankvorgang ungültig.');
 }
 public static function payloadHash(string $action,array $data):string{return hash('sha256',json_encode([$action,self::canonicalPayload($action,$data)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));}
 public function mandateTerms(string $contact):array
 {
  $a=$this->access($contact);$c=$this->q('SELECT * FROM member_bank_config WHERE club_id=?',[$a['club_id']])->fetch();
  return self::terms($c?:null);
 }
 private static function terms(?array $c):array
 {
  $enabled=$c&&(bool)$c['sepa_enabled']&&trim($c['creditor_id'])!==''&&trim($c['creditor_name'])!==''&&trim($c['creditor_address'])!=='';
  return ['enabled'=>(bool)$enabled,'payload'=>['template_id'=>self::TEMPLATE,'text'=>self::TEXT,'creditor_id'=>$c['creditor_id']??'','creditor_name'=>$c['creditor_name']??'','creditor_address'=>$c['creditor_address']??'','consent'=>'1']];
 }
 public function summary(string $contact):array
 {
  $a=$this->access($contact);$this->key();$row=$this->q('SELECT * FROM member_bank_records WHERE club_id=? AND contact_id=? FOR UPDATE NOWAIT',[$a['club_id'],$contact])->fetch();if(!$row)return ['version'=>0,'status'=>'empty','iban_masked'=>'','holder_masked'=>'','mandate_ref'=>''];$data=$this->decrypt($row);
  return ['version'=>(int)$row['version'],'status'=>$data['status'],'iban_masked'=>empty($data['bank'])?'':substr($data['bank']['iban'],0,2).' •••• '.substr($data['bank']['iban'],-4),'holder_masked'=>empty($data['bank'])?'':mb_substr($data['bank']['holder'],0,1).'…','mandate_ref'=>$data['mandate_ref']];
 }
 public function sealPending(string $contact,string $action,int $version,array $data,string $sessionHash,string $challenge,int $expires):array
 {
  $a=$this->access($contact);if($expires<=time()||$expires>time()+300||!preg_match('/^[a-f0-9]{64}$/D',$sessionHash)||$challenge===''||strlen($challenge)>200)throw new DomainException('Sicherheitsentwurf ungültig.');
  $canonical=self::canonicalPayload($action,$data);if(strlen(json_encode($canonical,JSON_THROW_ON_ERROR))>10240)throw new DomainException('Bankentwurf ist zu groß.');
  $type='pending:'.hash('sha256',json_encode([$a['id'],$action,$sessionHash,$challenge,$expires],JSON_THROW_ON_ERROR));
  return ['expires'=>$expires,'key_version'=>$this->keyVersion,'ciphertext'=>$this->encrypt($canonical,$a['club_id'],$contact,$version,$type)];
 }
 public function openPending(string $contact,string $action,int $version,array $envelope,string $sessionHash,string $challenge):array
 {
  $a=$this->access($contact);$expires=$envelope['expires']??0;if(!is_int($expires)||$expires<=time()||$expires>time()+300||!is_string($envelope['ciphertext']??null)||strlen($envelope['ciphertext'])>15000||!preg_match('/^[a-f0-9]{64}$/D',$sessionHash)||$challenge===''||strlen($challenge)>200)throw new DomainException('Sicherheitsentwurf abgelaufen oder ungültig.');
  $type='pending:'.hash('sha256',json_encode([$a['id'],$action,$sessionHash,$challenge,$expires],JSON_THROW_ON_ERROR));
  return self::canonicalPayload($action,$this->decrypt(['club_id'=>$a['club_id'],'contact_id'=>$contact,'version'=>$version,'key_version'=>$envelope['key_version']??0,'ciphertext'=>$envelope['ciphertext']],$type));
 }
 public function delete(string $contact,int $version,array $data,string $grant,string $sessionHash):array{return $this->mutate('delete',$contact,$version,$data,$grant,$sessionHash);}
 public function save(string $contact,int $version,array $data,string $grant,string $sessionHash):array{return $this->mutate('save',$contact,$version,$data,$grant,$sessionHash);}
 public function mandate(string $contact,int $version,array $data,string $grant,string $sessionHash):array{return $this->mutate('mandate',$contact,$version,$data,$grant,$sessionHash);}
 public function revoke(string $contact,int $version,array $data,string $grant,string $sessionHash):array{return $this->mutate('revoke',$contact,$version,$data,$grant,$sessionHash);}
 /** Full values are released once for an authenticated POST; no reusable read capability. */
 public function reveal(string $contact,int $version,array $data,string $grant,string $sessionHash):array
 {
  $hash=self::payloadHash('reveal',$data);$this->db->beginTransaction();
  try{
   $a=$this->access($contact);$row=$this->q('SELECT * FROM member_bank_records WHERE club_id=? AND contact_id=? FOR UPDATE NOWAIT',[$a['club_id'],$contact])->fetch();
   if(!$row||(int)$row['version']!==$version)throw new DomainException('Bankdaten fehlen oder wurden inzwischen geändert. Bitte neu öffnen.');
   $value=$this->decrypt($row);if(empty($value['bank']))throw new DomainException('Aktuell sind keine Bankdaten hinterlegt.');
   $this->consumeGrant($a,$contact,'reveal',$version,$hash,$grant,$sessionHash);
   $result=self::canonicalPayload('save',$value['bank']);
   $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'member-bank.revealed',$contact,$version,gmdate('c')]);
   $this->db->commit();return $result;
  }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 private function consumeGrant(array $a,string $contact,string $action,int $version,string $hash,string $grant,string $sessionHash):void
 {
  if(!preg_match('/^[a-f0-9]{64}$/D',$grant)||!preg_match('/^[a-f0-9]{64}$/D',$sessionHash))throw new DomainException('Erneute Sicherheitsbestätigung erforderlich.');
  $g=$this->q('UPDATE member_bank_grants SET used_at=? WHERE token_hash=? AND club_id=? AND actor_id=? AND contact_id=? AND action=? AND expected_version=? AND payload_hash=? AND session_hash=? AND expires_at>? AND used_at IS NULL',[time(),hash('sha256',$grant),$a['club_id'],$a['id'],$contact,$action,$version,$hash,$sessionHash,time()]);
  if($g->rowCount()!==1)throw new DomainException('Sicherheitsbestätigung abgelaufen, verwendet oder für andere Angaben.');
 }
 private function mutate(string $action,string $contact,int $version,array $input,string $grant,string $sessionHash):array
 {
  $data=self::canonicalPayload($action,$input);$hash=self::payloadHash($action,$input);if(!preg_match('/^[a-f0-9]{64}$/D',$grant)||!preg_match('/^[a-f0-9]{64}$/D',$sessionHash))throw new DomainException('Erneute Sicherheitsbestätigung erforderlich.');
  $this->db->beginTransaction();try{
   $a=$this->access($contact);if($action==='mandate'&&$a['contact_id']!==$contact)throw new DomainException('Ein SEPA-Mandat darf nur das Mitglied selbst erteilen.');$this->key();$row=$this->q('SELECT * FROM member_bank_records WHERE club_id=? AND contact_id=? FOR UPDATE NOWAIT',[$a['club_id'],$contact])->fetch();if((int)($row['version']??0)!==$version)throw new DomainException('Bankdaten inzwischen geändert. Bitte neu öffnen.');
   $this->consumeGrant($a,$contact,$action,$version,$hash,$grant,$sessionHash);
   $current=$row?$this->decrypt($row):null;$next=$version+1;
   if($action==='save')$value=['bank'=>$data,'status'=>'saved','mandate_ref'=>'','terms'=>null];
   else{if(!$current||empty($current['bank']))throw new DomainException('Zuerst Bankdaten hinterlegen.');$value=$current;if($action==='delete'){$value=['bank'=>null,'status'=>'removed','mandate_ref'=>'','terms'=>null];}elseif($action==='mandate'){$terms=$this->mandateTerms($contact);if(!$terms['enabled']||self::payloadHash('mandate',$terms['payload'])!==$hash)throw new DomainException('SEPA ist nicht freigeschaltet oder die Mandatsangaben wurden geändert.');$value['status']='mandated';$value['mandate_ref']='KH-'.strtoupper(bin2hex(random_bytes(16)));$value['terms']=$data;}else{if($value['status']!=='mandated')throw new DomainException('Kein aktives Mandat vorhanden.');$value['status']='revoked';}}
   $operation=bin2hex(random_bytes(16));$now=gmdate('c');$cipher=$this->encrypt($value,$a['club_id'],$contact,$next,'record');
   $this->q('INSERT INTO member_bank_records VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE version=VALUES(version),key_version=VALUES(key_version),ciphertext=VALUES(ciphertext),status=VALUES(status),mandate_ref=VALUES(mandate_ref),updated_at=VALUES(updated_at)',[$a['club_id'],$contact,$next,$this->keyVersion,$cipher,$value['status'],$value['mandate_ref'],$now]);
   $snapshot=['value'=>$value,'request'=>$data,'previous_mandate_ref'=>$current['mandate_ref']??'','created_at'=>$now];$this->q('INSERT INTO member_bank_history VALUES(?,?,?,?,?,?,?,?,?)',[$operation,$a['club_id'],$contact,$next,$action,$this->keyVersion,$this->encrypt($snapshot,$a['club_id'],$contact,$next,'history:'.$action),$a['id'],$now]);
   $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'member-bank.'.$action,$operation,$next,$now]);$this->db->commit();
  }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
  return $this->summary($contact);
 }
}


