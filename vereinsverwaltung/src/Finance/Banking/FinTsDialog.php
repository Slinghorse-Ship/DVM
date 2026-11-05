<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
require_once __DIR__.'/FinTsCredentialVault.php';
require_once __DIR__.'/FintsInstitutions.php';
require_once dirname(dirname(__DIR__, 2),2).'/banking/PaymentRules.php';
/** Short-lived encrypted server state; no PIN/TAN, native state or tokens in browser responses. */
final class FinTsDialog
{
 private array $config;private string $key;private FinTsCredentialVault $credentials;private bool $demoMode=false;
 public function __construct(private PDO $db,private string $actor,private string $session,private string $root,private ?object $engine=null){
  $this->access();if($session==='')throw new DomainException('Aktuelle Website-Sitzung erforderlich.');
  // The exercise environment deliberately never loads the FinTS library or
  // opens a network connection. It uses the same encrypted dialog state and
  // screens as production, but supplies deterministic fictional bank data.
  // Only the browser demo uses the deterministic bank. A caller that passes
  // its own private root (as the adapter/integration tests do) must still use
  // the normal encrypted dialog and tenant checks.
  if(class_exists(\Kulturhus\Native\Demo\Runtime::class)&&\Kulturhus\Native\Demo\Runtime::active()){
   if($root!==EnableBanking::root())throw new DomainException('Eigener Demo-Bankordner erforderlich.');
   $this->demoMode=true;$this->root=\Kulturhus\Native\Demo\Runtime::root().'/banking/fints-mock';
   $keyPath=$this->root.'/fints-state.key';\Kulturhus\Native\Demo\Configuration::privateFile($keyPath);$this->key=(string)file_get_contents($keyPath);
   if(strlen($this->key)!==32)throw new DomainException('Ursprünglicher privater Demo-Bankschlüssel fehlt.');
   $this->config=['url'=>'https://demo-fints.invalid/fints','bank_code'=>'00000000','product_id'=>'DEMO-FINTS-DVM-ONLY','product_version'=>'demo','club_id'=>$this->access()['club_id']];
   $this->credentials=new FinTsCredentialVault($this->root,$this->key,$this->access()['club_id']);return;
  }
  if(getenv('VEREIN_MODE')==='synthetic'&&LocalRuntime::demo()&&$root===EnableBanking::root()){
   $this->demoMode=true;$this->root=dirname(dirname(__DIR__, 2),2).'/.local/vereinsverwaltung/demo-fints';
   if(!is_dir($this->root)&&!mkdir($this->root,0700,true)&&!is_dir($this->root))throw new DomainException('Demo-Bankordner konnte nicht angelegt werden.');
   $keyPath=$this->root.'/fints-state.key';$key=is_file($keyPath)?(string)file_get_contents($keyPath):'';
   if(strlen($key)!==32){$key=random_bytes(32);if(file_put_contents($keyPath,$key,LOCK_EX)!==32)throw new DomainException('Demo-Bankschlüssel konnte nicht gespeichert werden.');@chmod($keyPath,0600);}
   $this->key=$key;$this->config=['url'=>'https://demo-fints.invalid/fints','bank_code'=>'00000000','product_id'=>'DEMO-FINTS-KULTURHUS-01','product_version'=>'demo','club_id'=>$this->access()['club_id']];
   $this->credentials=new FinTsCredentialVault($this->root,$this->key,$this->access()['club_id']);return;
  }
  $path=$root.'/fints.json';$this->config=is_file($path)?json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR):[];
  foreach(['url','bank_code','product_id','product_version','club_id'] as $name)if(empty($this->config[$name])||!is_string($this->config[$name]))throw new DomainException('FinTS-Registrierung und Bankendpunkt zuerst privat einrichten.');
  if($this->config['club_id']!==$this->access()['club_id'])throw new DomainException('FinTS-Zugang gehört zu einem anderen Verein.');
  $url=parse_url($this->config['url']);if(($url['scheme']??'')!=='https'||empty($url['host'])||isset($url['user'])||isset($url['pass'])||isset($url['fragment'])||isset($url['query'])||filter_var($url['host'],FILTER_VALIDATE_IP)||!preg_match('/^[0-9]{8}$/D',$this->config['bank_code']))throw new DomainException('Privaten bestätigten FinTS-Bankendpunkt prüfen.');
  $keyPath=$root.'/fints-state.key';$this->key=is_file($keyPath)?(string)file_get_contents($keyPath):'';if(strlen($this->key)!==32)throw new DomainException('Privater FinTS-Sitzungsschlüssel fehlt.');
  $real=realpath($root);$doc=realpath(($_SERVER['DOCUMENT_ROOT']??'')?:dirname(__DIR__,3).'/public');if(!$real||($doc&&strtolower($real)===strtolower($doc))||($doc&&str_starts_with(strtolower(str_replace('\\','/',$real)),rtrim(strtolower(str_replace('\\','/',$doc)),'/').'/')))throw new DomainException('FinTS-Daten dürfen nicht im Webverzeichnis liegen.');
  if(PHP_OS_FAMILY!=='Windows'&&((fileperms($root)&0077)||(fileperms($keyPath)&0077)))throw new DomainException('FinTS-Verzeichnis und Schlüssel müssen privat sein.');
  $this->credentials=new FinTsCredentialVault($root,$this->key,$this->access()['club_id']);
  if(!$this->engine){require_once dirname(dirname(__DIR__, 2),2).'/banking/FinTsReadEngine.php';$this->engine=new \Kulturhus\Banking\FinTsReadEngine($this->config);}
 }
 private function access():array{return Permissions::finance($this->db,$this->actor,true);}
 private function path():string{return $this->root.'/dialog-'.hash('sha256',$this->access()['club_id'].'|'.$this->actor.'|'.$this->session).'.enc';}
 private function aad():string{return 'kh-fints-v1|'.$this->access()['club_id'].'|'.$this->actor.'|'.hash('sha256',$this->session);}
 private function load():array{$path=$this->path();if(!is_file($path))return [];$raw=(string)file_get_contents($path);if(strlen($raw)<29||strlen($raw)>8*1024*1024)throw new DomainException('FinTS-Sitzung ungültig.');$plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),$this->aad());if($plain===false)throw new DomainException('FinTS-Sitzung konnte nicht geprüft werden.');$s=json_decode($plain,true,128,JSON_THROW_ON_ERROR);if(($s['expires']??0)<time()){unlink($path);return [];}return $s;}
 private function save(array $s):void{$plain=json_encode($s,JSON_THROW_ON_ERROR);if(strlen($plain)>7*1024*1024)throw new DomainException('Bankabruf zu groß. Kleineren Zeitraum wählen.');$iv=random_bytes(12);$cipher=openssl_encrypt($plain,'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,$iv,$tag,$this->aad());if($cipher===false)throw new DomainException('FinTS-Sitzung konnte nicht gespeichert werden.');$path=$this->path();$tmp=$path.'.'.bin2hex(random_bytes(6));file_put_contents($tmp,$iv.$tag.$cipher,LOCK_EX);chmod($tmp,0600);rename($tmp,$path);}
 private function publicState(array $s):array{$out=array_intersect_key($s,array_flip(['phase','version','expires','modes','media','selected_mode','accounts','selected','from','to','challenge','medium','image','next_at','row_count','imported','task','payment_order','payment_order_version','payment_attempt','payment_reference','payment_success','vop_info','vop_result','vop_reason','vop_expires']));if(is_array($s['bank']??null))$out['bank']=array_intersect_key($s['bank'],array_flip(['id','bank_code','bic','name','city']));return $out;}
 public function status():array{return $this->publicState($this->load());}
 public function credentialStatus():array{return $this->credentials->status();}
 public function forgetCredential():void{$this->access();$this->credentials->delete();$this->audit('fints.credentials.forgotten');}
 private function audit(string $action):void{$a=$this->access();$this->db->prepare('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)')->execute([$a['club_id'],$a['id'],$action,$a['club_id'],1,gmdate('c')]);}
 private function configuredBank():array
 {
  if($this->demoMode)return ['id'=>'demo-bank','bank_code'=>'00000000','bic'=>'DEMOXXXXXXX','name'=>'Demo-Bank (Simulation)','city'=>'Übungsstadt','url'=>$this->config['url'],'version'=>'3.0'];
  // The full institution directory is kept only with the existing operational
  // installation. A fresh install may omit it; the privately configured,
  // confirmed endpoint remains usable in that case.
  try { foreach(FintsInstitutions::all() as $row)if($row['bank_code']===$this->config['bank_code']&&rtrim($row['url'],'/')===rtrim($this->config['url'],'/'))return $row; } catch(DomainException) { }
  return ['id'=>(string)$this->config['bank_code'],'bank_code'=>(string)$this->config['bank_code'],'bic'=>'','name'=>'Hinterlegte Bank','city'=>'','url'=>(string)$this->config['url'],'version'=>'3.0'];
 }
 private function selectedBank(array $input):array
 {
  if($this->demoMode)return $this->configuredBank();
  $id=trim((string)($input['bank_id']??''));return $id!==''?FintsInstitutions::find($id):$this->configuredBank();
 }
 public function defaultBank():array{return $this->configuredBank();}
 private function demoAccount():array{return ['uid'=>'demo-bank-account-1','iban'=>'DE02120300000000202051','bic'=>'DEMOXXXXXXX','blz'=>'00000000','account_number'=>'202051','sub_account'=>'00','name'=>'Vereinskonto · Demodaten'];}
 private function demoRows(string $from,string $to,mixed $scenario='normal'):array
 {
  if(class_exists(\Kulturhus\Native\Demo\Runtime::class)&&\Kulturhus\Native\Demo\Runtime::active())return \Kulturhus\Native\Demo\FinTsMock::rows($from,$to,$scenario,$this->db,$this->actor);
  $q=$this->db->prepare("SELECT id,booked_on,cents,counterparty,description FROM finance_transactions WHERE club_id=? AND account_id=(SELECT id FROM finance_accounts WHERE club_id=? AND name='Vereinskonto · Demodaten' LIMIT 1) AND booked_on BETWEEN ? AND ? ORDER BY booked_on,id");$q->execute([$this->access()['club_id'],$this->access()['club_id'],$from,$to]);$rows=[];
  foreach($q as $r){$cents=(int)$r['cents'];$reference='DEMO-'.strtoupper(substr((string)$r['id'],0,12));$raw=['status'=>'BOOK','transaction_amount'=>['currency'=>'EUR','amount'=>Finance::decimal(abs($cents))],'credit_debit_indicator'=>$cents>=0?'CRDT':'DBIT','booking_date'=>(string)$r['booked_on'],'entry_reference'=>$reference,'remittance_information'=>[(string)$r['description'],$reference]];$party=(string)$r['counterparty'];$raw[$cents>=0?'debtor':'creditor']=['name'=>$party];$rows[]=$raw;}
  // Always keep one clearly marked training row available, even when the
  // learner chooses a date range before the fixture's first payment.
  if(!$rows&&$from<='2026-08-03'&&$to>='2026-08-03')$rows[]=['status'=>'BOOK','transaction_amount'=>['currency'=>'EUR','amount'=>'125.00'],'credit_debit_indicator'=>'CRDT','booking_date'=>'2026-08-03','entry_reference'=>'DEMO-EINGANG-01','remittance_information'=>['Eingang Eintritt · Zuordnung üben','DEMO-EINGANG-01'],'debtor'=>['name'=>'Musterveranstaltung']];
  return $rows;
 }
 private function demoAdvance(array $s,string $command,array $input):array
 {
  if($command==='discover'){
   // A saved account is already authenticated for the exercise. The real
   // bank still decides whether a login step-up is needed; only the payment
   // confirmation is simulated here so the learner sees one clear approval.
   if(in_array(($s['task']??''),['transfer','direct-debit'],true)){$s['accounts']=[$this->demoAccount()];$s['phase']='accounts';return $s;}
   $s['modes']=[['id'=>'demo-tan','name'=>'TAN (Simulation)','medium'=>false],['id'=>'demo-app','name'=>'Banking-App bestätigen (Simulation)','medium'=>false]];$s['phase']='select';return $s;
  }
  if($command==='select'){$mode=(string)($input['mode']??'');if(!in_array($mode,['demo-tan','demo-app'],true))throw new DomainException('In der Demo ein TAN-Verfahren auswählen.');$s['task']='login';$s['_demo_step']='login';$s['phase']=$mode==='demo-app'?'decoupled':'tan';$s['challenge']=$mode==='demo-app'?'Demo-Banking-App: Bestätigung simulieren':'Demo-TAN zur Übung: 123456';$s['medium']=$mode==='demo-app'?'Demo-Banking-App':'Demo-TAN';return $s;}
  if($command==='continue'){
   if(($s['phase']??'')==='vop'){
    \Kulturhus\Banking\PaymentRules::confirmPayee($s,$input);
    $s['phase']=($s['_demo_auth']??'app')==='tan'?'tan':'decoupled';
    $s['challenge']=$s['phase']==='tan'?'Demo-TAN zur Übung: 123456':'Demo-Banking-App: Bestätigung simulieren';
    $s['medium']=$s['phase']==='tan'?'Demo-TAN':'Demo-Banking-App';
    return $s;
   }
   if(!in_array(($s['phase']??''),['tan','decoupled'],true))throw new DomainException('Keine Demo-Bankfreigabe offen.');if(($s['phase']??'')==='tan'){$tan=(string)($input['tan']??'');if($tan!=='123456')throw new DomainException('Demo-TAN falsch. Für die Übung 123456 eingeben.');}
   if(($s['_demo_step']??'')==='login'){$s['accounts']=[$this->demoAccount()];$s['phase']='accounts';unset($s['_demo_step'],$s['challenge'],$s['medium']);return $s;}
   if(($s['_demo_step']??'')==='payment'){$result=$s['_demo_result']??'success';$s['phase']=match($result){'declined'=>'payment_declined','interrupted'=>'interrupted',default=>'payment_done'};if($result==='success')$s['payment_success']=(class_exists(\Kulturhus\Native\Demo\Runtime::class)&&\Kulturhus\Native\Demo\Runtime::active()&&\Kulturhus\Native\Demo\Runtime::locale()==='en')?'The demo bank accepted the order. No money was moved.':'Demo-Bank hat den Auftrag angenommen. Es wurde kein Geld bewegt.';else unset($s['payment_success']);unset($s['_demo_step'],$s['challenge'],$s['medium']);return $s;}
  }
  if($command==='statement'){$uid=(string)($input['uid']??'');if($uid!==$this->demoAccount()['uid'])throw new DomainException('Das Demo-Bankkonto auswählen.');$s['selected']=$this->demoAccount();$s['from']=(string)$input['from'];$s['to']=(string)$input['to'];$s['task']='statement';$s['rows']=$this->demoRows($s['from'],$s['to'],$input['demo_scenario']??'normal');$s['phase']='ready';return $s;}
  if(in_array($command,['transfer','direct-debit'],true)){$order=(string)($input['order_id']??'');$orderVersion=(int)($input['order_version']??0);if(!preg_match('/^[a-f0-9]{32}$/D',$order)||$orderVersion<1)throw new DomainException('Demo-Zahlungsauftrag prüfen.');$auth=(string)($input['demo_auth']??'app');if(!in_array($auth,['app','tan'],true))throw new DomainException('Demo-Freigabeart prüfen.');$s['_demo_result']=$input['demo_result']??'success';$s['task']=$command;$s['payment_order']=$order;$s['payment_order_version']=$orderVersion;$s['payment_reference']='DEMO-FINTS-'.strtoupper(substr($order,0,12));$s['_demo_step']='payment';$s['phase']=$auth==='tan'?'tan':'decoupled';$s['challenge']=$auth==='tan'?'Demo-TAN zur Übung: 123456':'Demo-Banking-App: Bestätigung simulieren';$s['medium']=$auth==='tan'?'Demo-TAN':'Demo-Banking-App';if($command==='transfer'){$s['_demo_auth']=$auth;$s['phase']='vop';$s['vop_result']='CompletedFullMatch';$s['vop_info']='Simulation: Name und IBAN stimmen überein.';$s['vop_expires']=date(DATE_ATOM,time()+300);unset($s['challenge'],$s['medium']);}return $s;}
  throw new DomainException('Unbekannter Demo-Bankdialogschritt.');
 }
 public function act(string $command,array $input,int $version):array{
  if(!in_array($command,['start','start-saved','select','continue','statement','transfer','direct-debit','cancel'],true))throw new DomainException('Unbekannter Bankdialogschritt.');
  $this->access();$path=$this->path();$lock=fopen($path.'.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new DomainException('Ein Bankabruf läuft bereits. Bitte kurz warten.');chmod($path.'.lock',0600);
  $paymentLease=null;$paymentLeaseService=null;try{$s=$this->load();if($version!==(int)($s['version']??0))throw new DomainException('Bankdialog wurde bereits fortgesetzt. Bitte neu laden.');
   if($command==='cancel'){if(is_file($path))unlink($path);return [];}
   if($command==='start-saved'){$saved=$this->credentials->load();$bank=$this->configuredBank();if(($saved['bank_id']??'')!==''){try{$bank=FintsInstitutions::find((string)$saved['bank_id']);}catch(DomainException){/* A removed directory entry falls back to the private configured bank. */}}$s=['user'=>$saved['user'],'pin'=>$saved['pin'],'bank'=>$bank,'expires'=>time()+900];$paymentKind=(string)($input['payment_kind']??'');if(in_array($paymentKind,['transfer','direct-debit'],true))$s['task']=$paymentKind;$command='discover';}
   elseif($command==='start'){if(class_exists(\Kulturhus\Native\Demo\Runtime::class)&&\Kulturhus\Native\Demo\Runtime::active()&&(($input['user']??'')!=='demo'||($input['pin']??'')!=='demo'))throw new DomainException('Nur fiktive Demo-Zugangsdaten verwenden: demo / demo.');foreach(['user','pin'] as $name)if(!is_string($input[$name]??null)||$input[$name]===''||strlen($input[$name])>200)throw new DomainException('Bankkennung und PIN eingeben.');$bank=$this->selectedBank($input);if(($input['remember']??'')==='1'){$this->credentials->save($input['user'],$input['pin'],$bank['id']);$this->audit('fints.credentials.saved');}$s=['user'=>$input['user'],'pin'=>$input['pin'],'bank'=>$bank,'expires'=>time()+900];$paymentKind=(string)($input['payment_kind']??'');if(in_array($paymentKind,['transfer','direct-debit'],true))$s['task']=$paymentKind;$command='discover';}
   elseif(!$s)throw new DomainException('Bankdialog abgelaufen. Bitte erneut anmelden.');
   // Refuse locally known input errors before consuming a bank step or opening the adapter.
   if($command==='select'){
    if(($s['phase']??'')!=='select')throw new DomainException('Zuerst ein TAN-Verfahren auswählen.');
    $mode=$input['mode']??null;$known=false;foreach($s['modes']??[]as$offered)if(is_string($mode)&&(string)$offered['id']===$mode)$known=true;
    if(!$known)throw new DomainException('Ein von der Bank angebotenes TAN-Verfahren wählen.');
    if(array_key_exists('medium',$input)&&!is_string($input['medium']))throw new DomainException('Ein von der Bank angebotenes Gerät wählen.');
    if(($s['selected_mode']??'')===$mode&&!empty($s['media'])&&!in_array($input['medium']??'', $s['media'],true))throw new DomainException('Ein von der Bank angebotenes Gerät wählen.');
    if(($s['selected_mode']??'')!==$mode)unset($s['media']);
    $s['selected_mode']=$mode;
   }
   if($command==='continue'&&($s['phase']??'')==='tan'&&(!is_string($input['tan']??null)||$input['tan']===''||strlen($input['tan'])>100))throw new DomainException('TAN eingeben.');
   if($command==='continue'&&!in_array($s['phase']??'',['tan','decoupled','poll','vop'],true))throw new DomainException('Kein fortzusetzender Bankdialog.');
   if($command==='continue'&&($s['phase']??'')==='vop')\Kulturhus\Banking\PaymentRules::confirmPayee($s,$input);
   if($command==='continue'&&($s['next_at']??0)>time())throw new DomainException('Die Bank verarbeitet noch. Bitte einige Sekunden warten.');
   if($command==='statement'){
    if(($s['phase']??'')!=='accounts')throw new DomainException('Zuerst Bankkonten abrufen.');
    $uid=$input['uid']??null;$known=false;foreach($s['accounts']??[]as$account)if(is_string($uid)&&($account['uid']??null)===$uid)$known=true;if(!$known)throw new DomainException('Freigegebenes Bankkonto auswählen.');
    foreach(['from','to']as$field)if(!is_string($input[$field]??null)||!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D',$input[$field]))throw new DomainException('Gültiges Bankdatum eingeben.');
    BankImport::date($input['from']);BankImport::date($input['to']);if($input['from']>$input['to']||$input['to']>Finance::today())throw new DomainException('Bankabrufzeitraum prüfen.');
   }
   if(in_array($command,['transfer','direct-debit'],true)){
    $publicMock=class_exists(\Kulturhus\Native\Demo\Runtime::class)&&\Kulturhus\Native\Demo\Runtime::active();
    if($publicMock&&(!is_string($input['demo_result']??'success')||!in_array($input['demo_result']??'success',['success','declined','interrupted'],true)))throw new DomainException('Demo-Szenario auswählen.');
    if($this->demoMode&&(!is_string($input['demo_auth']??'app')||!in_array($input['demo_auth']??'app',['app','tan'],true)))throw new DomainException('Demo-Freigabeart prüfen.');
    if(($s['phase']??'')!=='accounts')throw new DomainException('Zuerst die Bankkonten abrufen.');
    $paymentLeaseService=$this->paymentService($command);$paymentLease=$paymentLeaseService->acquireFintsLease((string)($input['order_id']??''));
    $payload=$this->paymentPayload($command,(string)($input['order_id']??''),(int)($input['order_version']??0));
    $uid=(string)($input['uid']??'');$selected=false;foreach($s['accounts']??[]as$account)if(($account['uid']??'')===$uid&&($account['iban']??'')===$payload['source_iban'])$selected=true;if(!$selected)throw new DomainException('Das für den Auftrag freigegebene Bankkonto auswählen.');
    if($this->db->inTransaction())throw new DomainException('FinTS erst nach Abschluss des offenen Speichervorgangs starten.',409);
    $payload=$paymentLeaseService->claimFints($payload['order_id'],$payload['order_version']);
    $input=array_merge(['uid'=>$uid,'demo_auth'=>$input['demo_auth']??'app','demo_result'=>$publicMock?($input['demo_result']??'success'):'success'],$payload);
    $s['task']=$command;$s['payment_order']=$payload['order_id'];$s['payment_order_version']=$payload['order_version'];if(isset($payload['attempt_id']))$s['payment_attempt']=$payload['attempt_id'];else unset($s['payment_attempt']);
    $s['payment_payload_hash']=$this->paymentHash($command,$payload);
   }elseif($command==='continue'&&!empty($s['payment_order'])){
    $paymentLeaseService=$this->paymentService((string)($s['task']??''));$paymentLease=$paymentLeaseService->acquireFintsLease((string)$s['payment_order']);
    if($this->db->inTransaction())throw new DomainException('FinTS erst nach Abschluss des offenen Speichervorgangs starten.',409);
    $kind=(string)($s['task']??'');if(empty($s['payment_attempt'])){$payload=$paymentLeaseService->claimFints((string)$s['payment_order'],(int)($s['payment_order_version']??0),(string)($s['payment_payload_hash']??''));$s['payment_attempt']=$payload['attempt_id'];}else $payload=$paymentLeaseService->resumeFints((string)$s['payment_order'],(int)($s['payment_order_version']??0),(int)($s['payment_attempt']??0));
    if(!hash_equals((string)($s['payment_payload_hash']??''),$this->paymentHash($kind,$payload)))throw new DomainException('Zahlungsauftrag verändert oder aus einer älteren Sitzung. Bitte neu prüfen.');
   }
   if($this->demoMode){
    $next=$this->demoAdvance($s,$command,$input);$next['version']=$version+1;$next['expires']=$s['expires'];
    if(($next['phase']??'')==='ready')$next['row_count']=count($next['rows']);
    if(in_array($next['phase']??'',['payment_done','payment_declined'],true)){
     unset($next['challenge'],$next['medium'],$next['next_at']);
     // Never expose a confirmed outcome before its ordinary audit transaction
     // commits. A failed confirmation remains uncertain and cannot be replayed.
     $uncertain=$next;$uncertain['phase']='interrupted';unset($uncertain['payment_success']);$this->save($uncertain);
     if($next['phase']==='payment_done')$paymentLeaseService->markFintsSubmitted((string)$next['payment_order'],(int)$next['payment_order_version'],(string)$next['payment_reference'],(int)$next['payment_attempt']);
     else $paymentLeaseService->resolveFints((string)$next['payment_order'],(int)$next['payment_order_version'],(int)$next['payment_attempt'],false,'DEMO: Auftrag ausdrücklich abgelehnt / explicitly declined');
    }
    $this->save($next);return $this->publicState($next);
   }
   // Consume the version before network I/O. An uncertain TAN submission is never replayed.
   $busy=$s;$busy['phase']='interrupted';$busy['version']=$version+1;unset($busy['pin'],$busy['native'],$busy['action']);$this->save($busy);
   try{$next=$this->engine->advance($s,$command,$input);}catch(\Throwable $e){throw $e instanceof DomainException?$e:new DomainException('FinTS-Abruf unterbrochen. Bitte neu anmelden; eine TAN wird nicht erneut gesendet.');}
   $next['version']=$version+1;$next['expires']=$s['expires'];if(($next['phase']??'')==='ready'){$next['row_count']=count($next['rows']);unset($next['pin'],$next['native'],$next['action']);}elseif(($next['phase']??'')==='payment_done'){unset($next['pin'],$next['native'],$next['action'],$next['challenge'],$next['image'],$next['next_at'],$next['checks']);}
   $this->save($next);if(($next['phase']??'')==='payment_done'&&in_array($next['task']??'',['transfer','direct-debit'],true))$paymentLeaseService->markFintsSubmitted((string)$next['payment_order'],(int)$next['payment_order_version'],(string)($next['payment_reference']??('FinTS '.$next['payment_order'])),(int)($next['payment_attempt']??0));return $this->publicState($next);
  }finally{try{if($paymentLease!==null)$paymentLeaseService->releaseFintsLease($paymentLease);}finally{flock($lock,LOCK_UN);fclose($lock);}}
 }
 public function import(string $account,int $version,string $request):array{
  $service=new BankImport($this->db,$this->actor);$known=$service->savedRun($request,'fints',$account);if($known)return $known;
  $s=$this->load();if(($s['phase']??'')!=='ready'||(int)$s['version']!==$version)throw new DomainException('Zuerst den Bankabruf abschließen.');$settings=(new Finance($this->db,$this->actor))->accountSettings($account);if(!(int)($settings['fints_read']??1))throw new DomainException('FinTS-Lesen ist für dieses Vereinskonto gesperrt. Bitte die Kontoeinstellungen prüfen.');$map=$service->map('fints','PRODUCTION',$s['selected'],$account);
  return $service->fetch($map,'PRODUCTION',$s['selected'],$s['from'],$s['to'],$request,fn()=>['transactions'=>$s['rows']]);
 }
 private function paymentService(string $kind):TransferOrders|SepaOrders{if(!in_array($kind,['transfer','direct-debit'],true))throw new DomainException('Freigegebenen Zahlungsauftrag auswählen.');$vault=MemberBankVault::fromEnvironment($this->db,$this->actor,ProductionRuntime::enabled());return $kind==='transfer'?new TransferOrders($this->db,$this->actor,$vault):new SepaOrders($this->db,$this->actor,$vault);}
 private function paymentPayload(string $kind,string $id,int $version):array
 {
  if(!in_array($kind,['transfer','direct-debit'],true)||!preg_match('/^[a-f0-9]{32}$/D',$id)||$version<1)throw new DomainException('Freigegebenen Zahlungsauftrag auswählen.');
  return $this->paymentService($kind)->fintsPayload($id,$version);
 }
 private function paymentHash(string $kind,array $payload):string
 {
  $parts=[$kind,$payload['order_id'],$payload['order_version'],$payload['source_iban'],$payload['xml']];
  // Keep existing normal-payment dialogs valid; bind new instant mode explicitly.
  if(($payload['transfer_mode']??'standard')!=='standard')$parts[]=$payload['transfer_mode'];
  return hash('sha256',json_encode($parts,JSON_THROW_ON_ERROR));
 }
}
