<?php declare(strict_types=1);
/** Include after authenticated actor/db setup and before output/global POST dispatcher. */
$memberBankHandled=false;
if(input($_GET,'view')!=='portal'||input($_GET,'tab')!=='bank')return;
require_once __DIR__.'/MemberBankVault.php';require_once __DIR__.'/MemberBankStepUp.php';
$bankNativeAuth=\Kulturhus\Verein\ProductionRuntime::nativeManager();
$bankFactorMethod='';
header('Cache-Control: no-store, private, max-age=0');header('Pragma: no-cache');header('Referrer-Policy: no-referrer');header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
$bankMayManage=false;$bankEmpty=false;$bankChoices=null;$bankShowOperation='save';$bankPostedVersion=null;$bankStale=false;$bankRawFields=[];$bankReceipt=null;$bankUnavailable=false;$bankHumanDraft=[];
$bankContact='';$bankContactLabel='';$bankOwn=false;$bankSummary=[];$bankTerms=['enabled'=>false,'payload'=>[]];$bankDraft=[];$bankPending=[];$bankError='';$bankNotice='';$bankFieldErrors=[];$bankMembers=[];$bankRevealed=false;$bankFormVersion=null;
$bankSessionHash=hash('sha256',session_id());$bankPendingKey='member_bank_pending_v1';$bankAction=input($_POST,'action');$bankPost=($_SERVER['REQUEST_METHOD']??'GET')==='POST';
$bankMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():\Kulturhus\Native\Language\MemberBankMessages::messages('de');$bankText=static fn(string $source,array $values=[]):string=>\Kulturhus\Native\Language\MemberBankMessages::text($source,$bankMessages,$values);
$bankOperations=['member-bank-begin','member-bank-confirm','member-bank-cancel'];if($bankPost)$memberBankHandled=true;
try{
 if(isset($workspaceAllowed)&&!$workspaceAllowed)throw new DomainException('Kein Zugriff auf Mitgliedsbankdaten.',403);
 \Kulturhus\Verein\Web\MemberBankForm::frame($_GET,$bankPost?$_POST:[]);
 if($bankPost&&(!is_string($_POST['csrf']??null)||empty($_SESSION['csrf'])||!hash_equals((string)$_SESSION['csrf'],$_POST['csrf'])))throw new DomainException('Formular abgelaufen. Bitte neu öffnen.',403);
 if(session_status()!==PHP_SESSION_ACTIVE||session_id()==='')throw new DomainException('Aktive Vereinsmanager-Sitzung erforderlich.');
 $bankActor=\Kulturhus\Verein\Permissions::actor($db,(string)$_SESSION['actor']);$bankContact=$bankPost?\Kulturhus\Verein\Web\MemberBankForm::target($_GET,$_POST,(string)($bankActor['contact_id']??'')):(array_key_exists('person',$_GET)?\Kulturhus\Verein\Web\MemberBankForm::text($_GET['person'],128):(string)($bankActor['contact_id']??''));
 if($bankPost&&$bankAction==='member-bank-begin'){$bankShowOperation=is_string($_POST['operation']??null)?$_POST['operation']:'';$bankPostedVersion=$_POST['version']??null;if($bankShowOperation==='save'&&is_array($_POST['data']??null))foreach(['holder','iban','bic']as$field){$value=$_POST['data'][$field]??'';if(is_string($value)&&strlen($value)<=10000)$bankDraft[$field]=$value;}elseif(in_array($bankShowOperation,['revoke','delete'],true)&&is_string($_POST['data']['reason']??null))$bankDraft=['reason'=>$_POST['data']['reason']];}
 $bankHumanDraft=$bankDraft;
 $bankIdentityGuard=null;
 if($bankNativeAuth){$bankAuth=\Kulturhus\Native\Auth\Runtime::service($db);$bankAuthToken=is_string($_SESSION['auth_token']??null)?$_SESSION['auth_token']:'';$bankFactorMethod=$bankAuth->reauthenticationState($bankAuthToken)['factor'];$bankIdentityGuard=static fn()=>\Kulturhus\Native\Integration\MemberBankAuthentication::identity($db,(string)$_SESSION['actor'],$bankAuth,$bankAuthToken);}
 $bankVault=\Kulturhus\Verein\MemberBankVault::fromEnvironment($db,(string)$_SESSION['actor'],!empty($production),$bankIdentityGuard);
 $bankMayManage=$bankVault->canManageMembers();
 if(!$bankPost&&$bankMayManage){$bankChoices=$bankVault->memberChoices(input($_GET,'member_q'),\Kulturhus\Verein\Web\MemberBankForm::version($_GET['member_page']??'1'));$bankMembers=$bankChoices['rows'];}
 // An administrator without an own current membership gets a deliberate chooser.
 // A POST never substitutes a different person, even after a list change.
 if(!$bankPost&&$bankMayManage&&!array_key_exists('person',$_GET)&&!$bankVault->canAccess($bankContact)){$bankContact='';$bankEmpty=true;return;}
 // Both summary and mandateTerms enforce the Vault's current membership/owner/treasurer ACL.
 $bankSummary=$bankVault->summary($bankContact);$bankOwn=($bankActor['contact_id']??'')===$bankContact;$nameQuery=$db->prepare('SELECT name FROM contacts WHERE club_id=? AND id=?');$nameQuery->execute([$bankActor['club_id'],$bankContact]);$bankContactLabel=(string)$nameQuery->fetchColumn();$bankTerms=$bankVault->mandateTerms($bankContact);
 $bankReceipt=$_SESSION['member_bank_last_attempt_v1']??null;
 if(is_array($bankReceipt)&&($bankReceipt['actor']??null)===(string)$_SESSION['actor']&&($bankReceipt['contact']??null)===$bankContact){
  $stored=$bankVault->operationResult($bankContact,$bankReceipt['action'],$bankReceipt['version'],$bankReceipt['payload_hash'],$bankReceipt['grant'],$bankSessionHash);
  $samePost=$bankPost&&($bankAction==='member-bank-confirm'&&is_string($_POST['challenge']??null)&&hash_equals($bankReceipt['challenge'],$_POST['challenge'])||$bankAction==='member-bank-begin'&&($_POST['operation']??null)===$bankReceipt['action']&&is_string($_POST['version']??null)&&$_POST['version']===(string)$bankReceipt['version']);
  if($samePost&&$bankAction==='member-bank-begin')try{$samePost=hash_equals($bankReceipt['payload_hash'],\Kulturhus\Verein\MemberBankVault::payloadHash($bankReceipt['action'],is_array($_POST['data']??null)?$_POST['data']:[]));}catch(DomainException){$samePost=false;}
  if($stored&&$samePost){$_SESSION['notice']=$bankText('Der ursprüngliche Bankvorgang ist bereits gespeichert. Prüfe den aktuellen Stand.');redirectTo(['view'=>'portal','tab'=>'bank','person'=>$bankContact]);}
  if($stored&&!$bankPost)$bankNotice='Der ursprüngliche Bankvorgang ist bereits gespeichert. Prüfe den aktuellen Stand.';
 }
 if($bankPost){
  if(!in_array($bankAction,$bankOperations,true))throw new DomainException('Bankformular-Aktion prüfen.');
  if(!hash_equals((string)($_SESSION['csrf']??''),input($_POST,'csrf'))||empty($_SESSION['csrf'])){http_response_code(403);throw new DomainException('Formular abgelaufen. Bitte neu öffnen.');}
  if($bankAction==='member-bank-cancel'){unset($_SESSION[$bankPendingKey],$_SESSION['member_bank_stepup_v1']);redirectTo(['view'=>'portal','tab'=>'bank','person'=>$bankContact]);}
  if($bankAction==='member-bank-begin'){
   unset($_SESSION[$bankPendingKey],$_SESSION['member_bank_stepup_v1']);$operation=input($_POST,'operation');$version=$_POST['version']??null;$bankPostedVersion=$version;$bankShowOperation=$operation;if(!in_array($operation,['save','mandate','revoke','delete','reveal'],true))throw new DomainException('Bankformular prüfen.');
   $version=(string)\Kulturhus\Verein\Web\MemberBankForm::version($version);$raw=is_array($_POST['data']??null)?$_POST['data']:[];if(strlen(json_encode($raw,JSON_THROW_ON_ERROR))>10000)throw new DomainException('Bankformular ist zu groß.');
   if($operation==='save'){
    $bankFormVersion=(int)$version;
    foreach(['holder','iban','bic'] as $field)$bankDraft[$field]=is_string($raw[$field]??null)?$raw[$field]:'';
    foreach(['holder','iban','bic']as$field)if(isset($raw[$field])&&!is_string($raw[$field])){$bankRawFields[$field]=$raw[$field];$bankFieldErrors[$field]='Einzelne Bankangabe eingeben.';}
    if(trim($bankDraft['holder'])==='')$bankFieldErrors['holder']='Kontoinhaber eingeben.';
    if(trim($bankDraft['iban'])==='')$bankFieldErrors['iban']='IBAN eingeben.';
    try{\Kulturhus\Verein\InvoiceSupplierContacts::bankFields(['iban'=>$bankDraft['iban'],'bic'=>'']);}catch(DomainException $e){$bankFieldErrors['iban']=$e->getMessage();}
    try{\Kulturhus\Verein\InvoiceSupplierContacts::bankFields(['iban'=>'','bic'=>$bankDraft['bic']]);}catch(DomainException $e){$bankFieldErrors['bic']=$e->getMessage();}
    if($bankFieldErrors)throw new DomainException('Bitte die markierten Bankangaben prüfen.');
   }
   if(in_array($operation,['revoke','delete'],true))$bankDraft=['reason'=>is_string($raw['reason']??null)?$raw['reason']:''];
   $bankDraft=\Kulturhus\Verein\MemberBankVault::canonicalPayload($operation,$raw);
   if((int)$version!==(int)$bankSummary['version']){$bankStale=true;throw new DomainException('Bankdaten wurden inzwischen geändert. Bitte neu öffnen.',409);}
   if($operation==='mandate'&&(!$bankOwn||!$bankTerms['enabled']||$bankDraft!==$bankTerms['payload']))throw new DomainException('Mandatstext oder Freigabe geändert. Bitte neu öffnen.');
   $binding=['contact'=>$bankContact,'action'=>$operation,'version'=>(int)$version,'draft_hash'=>\Kulturhus\Verein\MemberBankVault::payloadHash($operation,$bankDraft)];
   unset($_SESSION['member_bank_last_attempt_v1']);$bankReceipt=null;
   $step=($production||$bankNativeAuth)?\Kulturhus\Verein\MemberBankStepUp::production($db,(string)$_SESSION['actor']):\Kulturhus\Verein\MemberBankStepUp::localDemo($db,(string)$_SESSION['actor']);$result=$step->begin($binding,input($_POST,'password'));unset($_POST['password'],$_POST['code']);
   if($result['requires_code']){
    $envelope=$bankVault->sealPending($bankContact,$operation,(int)$version,$bankDraft,$bankSessionHash,$result['challenge'],(int)$result['expires']);
    $_SESSION[$bankPendingKey]=['contact'=>$bankContact,'action'=>$operation,'version'=>(int)$version,'challenge'=>$result['challenge'],'expires'=>(int)$result['expires'],'envelope'=>$envelope];
    redirectTo(['view'=>'portal','tab'=>'bank','person'=>$bankContact]);
   }
   if($operation!=='reveal'){$bankReceipt=['actor'=>(string)$_SESSION['actor'],'contact'=>$bankContact,'action'=>$operation,'version'=>(int)$version,'payload_hash'=>\Kulturhus\Verein\MemberBankVault::payloadHash($operation,$bankDraft),'grant'=>$result['token'],'challenge'=>''];$_SESSION['member_bank_last_attempt_v1']=$bankReceipt;session_write_close();session_start();}
   $bankResult=$bankVault->$operation($bankContact,(int)$version,$bankDraft,$result['token'],$bankSessionHash);if($operation==='reveal'){$bankDraft=$bankResult;$bankRevealed=true;}
  }else{
   $pending=$_SESSION[$bankPendingKey]??null;if(!is_array($pending)||$pending['contact']!==$bankContact||$pending['expires']<=time()||!hash_equals($pending['challenge'],input($_POST,'challenge')))throw new DomainException('Bestätigung abgelaufen. Bitte erneut beginnen.');
   $operation=$pending['action'];$bankShowOperation=$operation;$bankPostedVersion=(string)$pending['version'];if($operation==='save')$bankFormVersion=(int)$pending['version'];$bankDraft=$bankVault->openPending($bankContact,$operation,(int)$pending['version'],$pending['envelope'],$bankSessionHash,$pending['challenge']);
   $binding=['contact'=>$bankContact,'action'=>$operation,'version'=>(int)$pending['version'],'draft_hash'=>\Kulturhus\Verein\MemberBankVault::payloadHash($operation,$bankDraft)];
   $step=($production||$bankNativeAuth)?\Kulturhus\Verein\MemberBankStepUp::production($db,(string)$_SESSION['actor']):\Kulturhus\Verein\MemberBankStepUp::localDemo($db,(string)$_SESSION['actor']);$token=$step->confirm($pending['challenge'],$binding,input($_POST,'code'));unset($_POST['password'],$_POST['code']);
   if($operation!=='reveal'){$bankReceipt=['actor'=>(string)$_SESSION['actor'],'contact'=>$bankContact,'action'=>$operation,'version'=>(int)$pending['version'],'payload_hash'=>\Kulturhus\Verein\MemberBankVault::payloadHash($operation,$bankDraft),'grant'=>$token,'challenge'=>(string)$pending['challenge']];$_SESSION['member_bank_last_attempt_v1']=$bankReceipt;session_write_close();session_start();}
   unset($_SESSION[$bankPendingKey]);$bankResult=$bankVault->$operation($bankContact,(int)$pending['version'],$bankDraft,$token,$bankSessionHash);if($operation==='reveal'){$bankDraft=$bankResult;$bankRevealed=true;}
  }
  unset($_SESSION[$bankPendingKey],$_SESSION['member_bank_stepup_v1']);
  if($bankRevealed)$bankNotice='Bankdaten für diese Ansicht freigegeben. Sie können die Angaben unten prüfen und ändern. Erst das gesonderte Speichern ändert die Bankverbindung.';
  else{$_SESSION['notice']=$bankText('Bankvorgang bestätigt und gespeichert. Es wurde keine Zahlung ausgelöst.');redirectTo(['view'=>'portal','tab'=>'bank','person'=>$bankContact]);}
 }
}catch(\Throwable $failure){
 if($bankHumanDraft)$bankDraft=$bankHumanDraft;
 $bankError=$failure instanceof DomainException?$failure->getMessage():'Bankvorgang konnte nicht sicher abgeschlossen werden. Bitte erneut versuchen.';
 $server=$failure instanceof PDOException||str_contains($bankError,'schlüssel')||str_contains($bankError,'tresor')||str_contains($bankError,'sicher prüfbar');
 $status=$failure instanceof DomainException&&in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():($server?503:($bankSummary?422:403));
 if($failure instanceof PDOException&&is_array($bankReceipt)&&isset($bankVault))try{if($bankVault->operationResult($bankContact,$bankReceipt['action'],$bankReceipt['version'],$bankReceipt['payload_hash'],$bankReceipt['grant'],$bankSessionHash)){$_SESSION['notice']=$bankText('Der ursprüngliche Bankvorgang ist bereits gespeichert. Prüfe den aktuellen Stand.');redirectTo(['view'=>'portal','tab'=>'bank','person'=>$bankContact]);}}catch(\Throwable){/* No unconfirmed substitute result. */}
 http_response_code($status);if($status===503){$bankUnavailable=true;$bankError='Bankdaten derzeit nicht sicher verfügbar. Bitte erneut prüfen.';}
 if($bankPostedVersion!==null&&$bankSummary)try{$bankStale=$bankStale||\Kulturhus\Verein\Web\MemberBankForm::version($bankPostedVersion)!==(int)$bankSummary['version'];}catch(DomainException){$bankStale=true;}
}
finally{unset($_POST['password'],$_POST['code']);}
// Pending content stays encrypted in the manager session, never in hidden fields/storage.
if(isset($bankVault)&&$bankSummary){$pending=$_SESSION[$bankPendingKey]??null;if(is_array($pending))try{
 if($pending['contact']!==$bankContact||$pending['expires']<=time())throw new DomainException('Bestätigung abgelaufen.');
 $pendingDraft=$bankVault->openPending($bankContact,$pending['action'],(int)$pending['version'],$pending['envelope'],$bankSessionHash,$pending['challenge']);$bankPending=array_intersect_key($pending,array_flip(['challenge','action','version','expires']));
 }catch(\Throwable){unset($_SESSION[$bankPendingKey],$_SESSION['member_bank_stepup_v1']);if($bankError==='')$bankError='Bestätigung abgelaufen oder nicht mehr zugänglich. Bitte erneut beginnen.';}}

if(!$bankPost)$bankDraft=[];
