<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
require_once __DIR__.'/VolunteerFormReads.php';
/** A narrowly authorized personal form, never a general document-write permission. */
final class VolunteerForm {
 use VolunteerFormReads;
 public const FIELDS=['last_name','first_name','birthday','street','city'];
 public function __construct(private PDO $db,private string $actor,private string $kind='hygiene'){
  if(!in_array($kind,['hygiene','general'],true))throw new DomainException('Art der Ehrenamtsbestätigung prüfen.');
 }
 public function kind():string{return $this->kind;}
 public function fields():array{return $this->kind==='general'?array_merge(self::FIELDS,['activity','effort']):self::FIELDS;}
 public function membership(string $contact):array{return $this->read(fn():array=>$this->readMembership($contact));}
 private function readMembership(string $contact):array{
  $person=$this->person($contact);$row=$this->query("SELECT * FROM memberships WHERE club_id=? AND contact_id=? AND status IN('aktiv','passiv') AND ends_on IS NULL AND starts_on<=? ORDER BY starts_on DESC,id DESC LIMIT 1".$this->currentLock(),[$person['club_id'],$person['id'],date('Y-m-d')])->fetch();
  if(!$row)throw new DomainException('Für diese Bestätigung muss eine aktuelle aktive oder passive Mitgliedschaft hinterlegt sein.');return $row;
 }
 private function query(string $sql,array $args=[]): \PDOStatement{return DocumentCatalog::query($this->db,$sql,$args);}
 public function canChoosePerson():bool{return BoardAccess::allowed($this->db,$this->actor);}
 public function canUse():bool{try{$a=Permissions::actor($this->db,$this->actor);if($a['role']==='editor')return false;if($this->canChoosePerson())return true;$this->person('');return true;}catch(DomainException){return false;}}
 public function people():array{return $this->read(fn():array=>$this->readPeople());}
 private function readPeople():array{$a=BoardAccess::require($this->db,$this->actor);$args=[$a['club_id']];if($this->kind==='general')$args[]=date('Y-m-d');return $this->query("SELECT id,name FROM contacts WHERE club_id=? AND kind='person' AND archived_at IS NULL ".($this->kind==='general'?" AND EXISTS(SELECT 1 FROM memberships m WHERE m.club_id=contacts.club_id AND m.contact_id=contacts.id AND m.status IN('aktiv','passiv') AND m.ends_on IS NULL AND m.starts_on<=?".$this->currentLock().")":"")." ORDER BY name,id".$this->currentLock(),$args)->fetchAll();}
 /** Ordinary users can never select another contact, including through forged requests. */
 public function person(string $requested=''):array{return $this->read(fn():array=>$this->readPerson($requested));}
 private function readPerson(string $requested=''):array{
  $a=Permissions::actor($this->db,$this->actor);if($a['role']==='editor')throw new DomainException('Für diesen Zugang ist keine Ehrenamtsbestätigung verfügbar.');
  if(!$this->canChoosePerson()){if(!$a['contact_id'])throw new DomainException('Diesem Zugang ist noch kein eigenes Mitgliederprofil zugeordnet. Bitte den Vorstand um Zuordnung bitten.');if($requested!==''&&$requested!==$a['contact_id'])throw new DomainException('Du kannst ausschließlich deine eigene Ehrenamtsbestätigung vorbereiten.');$requested=$a['contact_id'];}
  $person=$this->query("SELECT * FROM contacts WHERE club_id=? AND id=? AND kind='person' AND archived_at IS NULL".$this->currentLock(),[$a['club_id'],$requested])->fetch();if(!$person)throw new DomainException('Bitte eine verfügbare Person auswählen.');return $person;
 }
 public function club():array{return $this->read(fn():array=>$this->readClub());}
 private function readClub():array{
  if(!$this->canUse())throw new DomainException('Ehrenamtsbestätigung nicht verfügbar.');$a=Permissions::actor($this->db,$this->actor);$raw=$this->query("SELECT payload FROM club_settings WHERE club_id=? AND section='club'".$this->currentLock(),[$a['club_id']])->fetchColumn();$settings=$raw?json_decode($raw,true,512,JSON_THROW_ON_ERROR):SettingsSchema::defaults('club');
  return array_intersect_key(array_replace(['name'=>'','street'=>'','postcode'=>'','city'=>''],$settings),array_flip(['name','street','postcode','city']));
 }
 public function canSign():bool{return $this->read(function():bool{try{$actor=Permissions::actor($this->db,$this->actor);if($actor['role']==='editor')return false;return (bool)$actor['is_superuser']||(bool)$this->query("SELECT 1 FROM admission_board WHERE club_id=? AND actor_id=? AND enabled=1 AND office='1. Vorstand'".$this->currentLock(),[$actor['club_id'],$actor['id']])->fetchColumn();}catch(DomainException){return false;}});}
 public function hasSignature():bool{return $this->read(fn():bool=>$this->canSign()&&$this->currentSignature()!==null);}
 /** Bind new certificates to the current office holder, never the generating account. */
 private function firstBoard():array{
  $club=Permissions::actor($this->db,$this->actor)['club_id'];$holders=[];
  foreach($this->query("SELECT actor_id,office,version FROM admission_board WHERE club_id=? AND enabled=1 AND office='1. Vorstand'".$this->currentLock(),[$club])->fetchAll() as $office){
   try{$actor=Permissions::actor($this->db,$office['actor_id']);}catch(DomainException){continue;}
   if($actor['club_id']===$club&&$actor['role']!=='editor')$holders[]=[$office,$actor];
  }
  if(count($holders)!==1)throw new DomainException('Bitte unter Rollen zuweisen genau einen aktuellen ersten Vorstand hinterlegen.');
  [$office,$actor]=$holders[0];$name='';$version=0;
  if($actor['contact_id']){
   $contact=$this->query("SELECT name,version FROM contacts WHERE club_id=? AND id=? AND kind='person' AND archived_at IS NULL".$this->currentLock(),[$club,$actor['contact_id']])->fetch();
   if($contact){$name=trim($contact['name']);$version=(int)$contact['version'];}
  }else{
   $account=$this->query('SELECT payload,version FROM club_settings WHERE club_id=? AND section=?'.$this->currentLock(),[$club,'account:'.$actor['id']])->fetch();
   if($account){$settings=json_decode($account['payload'],true);if(is_string($settings['display_name']??null))$name=trim($settings['display_name']);$version=(int)$account['version'];}
  }
  if($name===''||mb_strlen($name)>180||preg_match('/[\x00-\x1f]/',$name))throw new DomainException('Bitte den vollständigen Namen des ersten Vorstands im zugeordneten Personenprofil hinterlegen.');
  return ['actor_id'=>$actor['id'],'office'=>$office['office'],'board_version'=>(int)$office['version'],'contact_id'=>$actor['contact_id'],'profile_version'=>$version,'name'=>$name];
 }
 /** An existing image must have been introduced after the current office assignment. */
 private function signatureProof(array $asset,array $signer):array{
  $club=Permissions::actor($this->db,$this->actor)['club_id'];$origin=null;
  foreach($this->query("SELECT version,payload,actor_id FROM settings_revisions WHERE club_id=? AND section='paper' ORDER BY version".$this->currentLock(),[$club])->fetchAll() as $revision){
   if((json_decode($revision['payload'],true)['signature']??'')===$asset['id']){$origin=$revision;break;}
  }
  $uploads=$origin?$this->query("SELECT sequence FROM audit_events WHERE club_id=? AND actor_id=? AND object_id='paper' AND object_version=? AND action IN('settings.signature.updated','settings.updated')".$this->currentLock(),[$club,$origin['actor_id'],$origin['version']])->fetchAll():[];
  $assigned=(int)$this->query("SELECT sequence FROM audit_events WHERE club_id=? AND object_id=? AND object_version=? AND action IN('admission.board.permission','admission.board.office-confirmed') ORDER BY sequence DESC LIMIT 1".$this->currentLock(),[$club,$signer['actor_id'],$signer['board_version']])->fetchColumn();
  $identity=(int)$this->query("SELECT sequence FROM audit_events WHERE club_id=? AND object_id=? AND action='identity.assigned' ORDER BY sequence DESC LIMIT 1".$this->currentLock(),[$club,$signer['actor_id']])->fetchColumn();
  if(count($uploads)!==1||!$assigned||(int)$uploads[0]['sequence']<=max($assigned,$identity))throw new DomainException('Die Unterschrift ist dem aktuellen ersten Vorstand noch nicht sicher zugeordnet. Bitte das aktuelle Vorstandsamt unter Rollen zuweisen prüfen und dessen Unterschrift unter Mein Account erneut hinterlegen.');
  return ['asset_id'=>$asset['id'],'upload_sequence'=>(int)$uploads[0]['sequence'],'office_sequence'=>$assigned,'identity_sequence'=>$identity];
 }
 /** Split a German postal address without guessing a missing street or locality. */
 public static function postalAddress(string $raw):array{
  $lines=preg_split('/\R/u',trim($raw))?:[];$lines=array_values(array_filter(array_map(static fn($line)=>trim(preg_replace('/\h+/u',' ',$line)??$line),$lines),static fn($line)=>$line!==''));$address=implode("\n",$lines);
  if(preg_match('/^((?:D[- ]?)?\d{5}\s+\p{L}.*)$/us',$address,$match))return ['street'=>'','city'=>str_replace("\n",', ',$match[1])];
  if(preg_match('/^(.+?)[,;\s]+((?:D[- ]?)?\d{5}\s+\p{L}.*)$/us',$address,$match))return ['street'=>trim(str_replace("\n",', ',$match[1])," ,;\t"),'city'=>str_replace("\n",', ',$match[2])];
  return ['street'=>str_replace("\n",', ',$address),'city'=>''];
 }
 public function prefill(string $contact=''):array{return $this->read(fn():array=>$this->readPrefill($contact));}
 private function readPrefill(string $contact=''):array{
  $person=$this->person($contact);$a=Permissions::actor($this->db,$this->actor);$answers=[];$structured=false;$sameName=static fn(string $v)=>mb_strtolower(trim(preg_replace('/\s+/u',' ',$v)??$v),'UTF-8');
  foreach($this->query("SELECT answers FROM membership_applications WHERE club_id=? AND contact_id=? AND status='accepted' ORDER BY reviewed_at DESC,submitted_at DESC".$this->currentLock(),[$a['club_id'],$person['id']])->fetchAll() as $row){$candidate=json_decode($row['answers'],true);if(!is_array($candidate)||!is_string($candidate['first_name']??null)||!is_string($candidate['last_name']??null))continue;if($sameName($candidate['first_name'].' '.$candidate['last_name'])===$sameName($person['name'])){$answers=$candidate;$structured=true;break;}}
  // Historic contacts contain one name field: offer an explicit editable suggestion only.
  $first=$answers['first_name']??'';$last=$answers['last_name']??'';if(!$structured){$parts=preg_split('/\s+/u',trim($person['name']),2)?:[];if(str_contains($person['name'],',')){[$last,$first]=array_pad(array_map('trim',explode(',',$person['name'],2)),2,'');}elseif(count($parts)===2){[$first,$last]=$parts;}}
  $address=self::postalAddress($person['address']);
  $result=['person'=>$person,'values'=>['first_name'=>$first,'last_name'=>$last,'birthday'=>$person['birthday']??'']+$address,'name_suggestion'=>!$structured,'club'=>$this->club()];
  if($this->kind==='general'){$result['membership']=$this->membership($person['id']);$result['values']['activity']=$result['membership']['status']==='aktiv'?'Unterstützt den Verein aktiv bei Veranstaltungen und weiteren Vereinsaktivitäten.':'';}return $result;
 }
 public static function digest(array $data):string{unset($data['signature']);return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));}
 /** Download only this purpose and its authorized person; no general document access. */
 public function file(string $id):array{return $this->read(fn():array=>$this->readFile($id));}
 private function readFile(string $id):array{
  $a=Permissions::actor($this->db,$this->actor);$file=$this->query("SELECT f.* FROM document_files f JOIN documents d ON d.club_id=f.club_id AND d.id=f.document_id WHERE f.club_id=? AND f.document_id=? AND f.revision=1 AND d.state='active' AND d.admission_only=0 AND d.origin='generated' AND d.kind='membership'".$this->currentLock(),[$a['club_id'],$id])->fetch();$snapshot=$file?json_decode($file['snapshot'],true):null;
  if(!$file||!is_string($file['bytes'])||!hash_equals($file['sha256'],hash('sha256',$file['bytes']))||!is_array($snapshot)||!in_array($snapshot['purpose']??'',['volunteer_form','volunteer_general'],true)||!is_string($snapshot['contact_id']??null))throw new DomainException('Ehrenamtsbestätigung nicht verfügbar.');$this->person($snapshot['contact_id']);return $this->canChoosePerson()?$file:(new PortalDocuments($this->db,$this->actor))->file($id);
 }
 public function build(string $contact,array $input,?bool $signed=null):array{return $this->read(fn():array=>$this->readBuild($contact,$input,$signed));}
 private function readBuild(string $contact,array $input,?bool $signed=null):array{
  $signed??=$this->hasSignature();
  $person=$this->person($contact);$values=[];foreach(self::FIELDS as $key){$v=$input[$key]??'';$optional=$this->kind==='general'&&in_array($key,['birthday','street','city'],true);if(!is_string($v)||(!$optional&&trim($v)==='')||preg_match('//u',$v)!==1||mb_strlen(trim($v))>180||preg_match('/[\x00-\x1f]/',$v))throw new DomainException('Bitte persönliche Angaben und vollständige Anschrift prüfen.');$values[]=trim($v);}
  if($values[2]!==''){$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$values[2]);if(!$date||$date->format('Y-m-d')!==$values[2]||$values[2]>date('Y-m-d'))throw new DomainException('Geburtsdatum prüfen.');$values[2]=$date->format('d.m.Y');}
  $club=$this->club();foreach([$club['name'],$club['street'],trim($club['postcode'].' '.$club['city'])] as $v){if(!is_string($v)||trim($v)===''||preg_match('//u',$v)!==1||mb_strlen($v)>180)throw new DomainException('Vereinsname und Anschrift in Einstellungen vervollständigen lassen.');$values[]=$v;}$values[]=date('d.m.Y');$data=['volunteer_form'=>['values'=>$values],'contact_id'=>$person['id'],'profile_version'=>(int)$person['version'],'signature_used'=>false];
  if($this->kind==='general'){
   $membership=$this->membership($person['id']);$passive=$membership['status']==='passiv';
   if($passive){$input['activity']='';$input['effort']='';}
   $activity=$input['activity']??'';$effort=$input['effort']??'';
   if(!is_string($activity)||(!$passive&&trim($activity)==='')||preg_match('//u',$activity)!==1||mb_strlen($activity)>1800||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$activity))throw new DomainException('Bitte die ehrenamtlichen Aufgaben beschreiben (höchstens 1.800 Zeichen).');
   if(!is_string($effort)||preg_match('//u',$effort)!==1||mb_strlen($effort)>180||preg_match('/[\x00-\x1f]/',$effort))throw new DomainException('Zeitlichen Umfang prüfen (höchstens 180 Zeichen).');
   unset($data['volunteer_form']);$data['volunteer_general']=['values'=>$values,'activity'=>trim($activity),'effort'=>trim($effort),'year'=>(int)date('Y'),'place'=>$club['city'],'signer'=>$this->firstBoard(),'membership'=>array_intersect_key($membership,array_flip(['id','version','member_number','status','starts_on','starts_precision']))];
  }
  if($signed){if(!$this->canSign())throw new DomainException('Die hinterlegte Unterschrift darf hier nur der erste Vorstand oder Superuser verwenden.');$asset=$this->currentSignature();if(!$asset)throw new DomainException('Unterschrift zuerst unter Mein Konto hinterlegen.');if($this->kind==='general')$data['signature_owner_proof']=$this->signatureProof($asset,$data['volunteer_general']['signer']);$data['signature']=base64_encode($asset['bytes']);$data['signature_used']=true;$data['signature_sha256']=hash('sha256',$asset['bytes']);}return $data;
 }
 public function save(string $contact,array $input,bool $signed,string $key,string $previewHash=''):string{
  if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new DomainException('Formular neu öffnen.');
  $existing=$this->replayed($contact,$input,$signed,$key,$previewHash);if($existing!==null)return $existing;
  $data=$this->build($contact,$input,$signed);$hash=self::digest($data);if($previewHash!==''&&!hash_equals($previewHash,$hash))throw new DomainException('Angaben oder Unterschrift wurden geändert. Bitte die vollständige Vorschau erneut öffnen.');if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new DomainException('Formular neu öffnen.');
  $pdf=DocumentPreview::pdf($data);Documents::inspectFile('ehrenamtsbestaetigung.pdf',$pdf,true);
  try{return \Kulturhus\Verein\Infrastructure\Database\Transactions::run($this->db,function()use($contact,$input,$signed,$key,$hash,$data,$pdf):string{
   if(!hash_equals($hash,self::digest($this->build($contact,$input,$signed))))throw new DomainException('Angaben wurden inzwischen geändert. Vorschau erneut öffnen.');
   $a=Permissions::actor($this->db,$this->actor);$requestHash=hash('sha256','volunteer-form|'.$a['id'].'|'.$hash);$previous=$this->query('SELECT request_hash,document_id FROM document_requests WHERE club_id=? AND request_key=?',[$a['club_id'],$key])->fetch();if($previous){if(!hash_equals($previous['request_hash'],$requestHash))throw new DomainException('Vorgangskennung mit anderen Eingaben bereits verwendet.');return $previous['document_id'];}
   $id=bin2hex(random_bytes(16));$this->query('INSERT INTO document_requests VALUES(?,?,?,?)',[$a['club_id'],$key,$requestHash,$id]);
   $contact=$data['contact_id'];$source=DocumentCatalog::register($this->db,$a['club_id'],'contact',$contact);if(!$source)throw new DomainException('Person nicht mehr verfügbar.');$now=gmdate('Y-m-d\TH:i:s\Z');$title=($this->kind==='general'?'Mitgliedsbestätigung · '.($data['volunteer_general']['membership']['status']==='passiv'?'passiv':'aktiv mit Mitarbeit'):'Ehrenamtsbestätigung · Hygiene').' · '.trim($input['first_name'].' '.$input['last_name']);$notes=$signed?'Mit hinterlegter Unterschrift durch einen berechtigten Zugang erstellt.':'Persönliche Vorlage zur Unterschrift durch den Vorstand.';$snapshot=$data;unset($snapshot['signature']);$snapshot['volunteer_intent']=$this->intent($contact,$input,$signed,$key,$hash);$snapshot['purpose']=$this->kind==='general'?'volunteer_general':'volunteer_form';$sha=hash('sha256',$pdf);
   $this->query("INSERT INTO documents(club_id,id,folder_id,title,kind,document_date,scopes,origin,notes,created_at,created_by) VALUES(?,?,?,?,'membership',?,'[\"members\"]','generated',?,?,?)",[$a['club_id'],$id,$source['folder_id'],$title,date('Y-m-d'),$notes,$now,$a['id']]);
   $q=$this->db->prepare("INSERT INTO document_files(club_id,document_id,revision,name,mime,bytes,sha256,created_at,created_by,snapshot) VALUES(?,?,1,'ehrenamtsbestaetigung.pdf','application/pdf',?,?,?,?,?)");foreach([$a['club_id'],$id,$pdf,$sha,$now,$a['id'],json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)] as $ix=>$v)$q->bindValue($ix+1,$v,$ix===2?PDO::PARAM_LOB:PDO::PARAM_STR);$q->execute();
   $this->query("INSERT INTO document_links VALUES(?,?,'contact',?)",[$a['club_id'],$id,$contact]);$this->query("INSERT INTO document_history(club_id,document_id,actor_id,action,version,happened_at,details) VALUES(?,?,?,'generated',1,?,?)",[$a['club_id'],$id,$a['id'],$now,json_encode(['purpose'=>$snapshot['purpose'],'name'=>'ehrenamtsbestaetigung.pdf','sha256'=>$sha,'folder'=>$source['folder_id'],'links'=>['contact:'.$contact],'scopes'=>['members']],JSON_THROW_ON_ERROR)]);
   $this->query('INSERT INTO portal_document_shares VALUES(?,?,?,1,?,?)',[$a['club_id'],$id,$contact,$a['id'],$now]);foreach(['document.generated.volunteer-form','portal.document.shared'] as $action)$this->query('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,1,?)',[$a['club_id'],$a['id'],$action,$id,$now]);
   ManagerNotifications::documentAvailable($this->db,$a['club_id'],$id,$contact);
   return $id;
  });}catch(\PDOException $failure){if((int)($failure->errorInfo[1]??0)===1062){$existing=$this->replayed($contact,$input,$signed,$key,$previewHash);if($existing!==null)return $existing;}throw $failure;}
 }
}
