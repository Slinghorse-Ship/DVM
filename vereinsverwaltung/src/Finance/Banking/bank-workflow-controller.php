<?php declare(strict_types=1);
// Include after native identity, CSRF setup and Finance construction, before output.
$bankWorkflowError='';$fintsStatus=[];$fintsCredentialStatus=['saved'=>false,'expires'=>0,'bank_id'=>''];$fintsDefaultBank=[];$fintsAvailable=false;$sepaPreview=null;$sepaService=null;$fintsDialog=null;
$paypalAccount=null;
if(input($_GET,'bank_import')==='1'||input($_POST,'action')==='bank-import-review'||input($_GET,'import_original')!==''||input($_GET,'import_correction')!==''){$isBankWorkflow=true;require __DIR__.'/bank-import-review-controller.php';return;}
if($canFinance&&$finance->canWrite()&&(str_starts_with(input($_POST,'action'),'paypal-')||(input($_GET,'view')==='finance'&&input($_GET,'tab')==='bank'))){
 try {
  require_once dirname(dirname(__DIR__, 2)).'/native/bootstrap.php';require_once __DIR__.'/PayPalAccount.php';
  $paypalKey=getenv('KH_NATIVE_PRIVATE_KEY_FILE')?:dirname(dirname(__DIR__, 2),2).'/.local/native-cms-integration/private.key';
  $paypalBox=\Kulturhus\Native\Shared\SecretBox::fromFile($paypalKey);
  $paypalActor=\Kulturhus\Verein\Permissions::actor($db,(string)$_SESSION['actor']);
  $paypalConfig=(new \Kulturhus\Native\Integration\Accounts($db,$paypalBox))->get(\Kulturhus\Native\Integration\ManagerBridge::clubKey($paypalActor['club_id']),'paypal');
  $paypalAccount=new \Kulturhus\Verein\PayPalAccount($db,(string)$_SESSION['actor'],new \Kulturhus\Native\Payments\PayPal($paypalConfig),$paypalConfig);
  if(str_starts_with(input($_POST,'action'),'paypal-')){
   if($_SERVER['REQUEST_METHOD']!=='POST'||!hash_equals($_SESSION['csrf'],input($_POST,'csrf')))throw new DomainException('PayPal-Formular abgelaufen.');
   if(input($_POST,'action')==='paypal-map'){$paypalAccount->map(input($_POST,'account_id'));$_SESSION['notice']='PayPal-Finanzkonto zugeordnet.';}
   elseif(input($_POST,'action')==='paypal-sync'){$run=$paypalAccount->sync(input($_POST,'mapping'),input($_POST,'date_from'),input($_POST,'date_to'),input($_POST,'request_key'));$_SESSION['notice']='PayPal-Abruf: '.$run['state'].' · '.$run['rows_count'].' Umsatz- und Gebührenzeilen.';}
   else throw new DomainException('Unbekannte PayPal-Kontoaktion.');
   redirectTo(['view'=>'finance','tab'=>'bank']);
  }
 }catch(DomainException|RuntimeException $ex){if(str_starts_with(input($_POST,'action'),'paypal-'))$bankWorkflowError='PayPal-Kontoabruf nicht abgeschlossen. Verbindung, Berechtigung und Zeitraum prüfen.';}
}
$bankWorkflowAction=input($_POST,'action');$isBankWorkflow=str_starts_with($bankWorkflowAction,'fints-')||str_starts_with($bankWorkflowAction,'sepa-order-')||$bankWorkflowAction==='bank-import-review';
if(($canFinance&&$finance->canWrite())&&($isBankWorkflow||(input($_GET,'view')==='finance'&&in_array(input($_GET,'tab'),['bank','debit'],true)))){
 try{
  if($isBankWorkflow&&($_SERVER['REQUEST_METHOD']!=='POST'||!hash_equals($_SESSION['csrf'],input($_POST,'csrf'))))throw new DomainException('Bankformular abgelaufen. Bitte neu öffnen.');
  $showSavedBankImport=static function(array $run,string $account):never{
   $_SESSION['notice']='FinTS-Abruf gespeichert: '.$run['rows_count'].' Bankzeilen, '.$run['review_count'].' zur Prüfung.';unset($_SESSION['fints_target_account']);
   redirectTo(['view'=>'finance','tab'=>'bank','bank_import'=>1,'account'=>$account,'import_state'=>'','import_mapping'=>$run['mapping_id']]);
  };
  if($bankWorkflowAction==='fints-import'){$known=(new \Kulturhus\Verein\BankImport($db,(string)$_SESSION['actor']))->savedRun(input($_POST,'request_key'),'fints',input($_POST,'account_id'));if($known)$showSavedBankImport($known,input($_POST,'account_id'));}
  if($bankWorkflowAction==='bank-import-review'){$service=new \Kulturhus\Verein\BankImport($db,(string)$_SESSION['actor']);$service->decide(input($_POST,'record'),(int)input($_POST,'record_version'),input($_POST,'decision'),input($_POST,'transaction'),input($_POST,'reason'));$_SESSION['notice']='Entscheidung zum Bankumsatz gespeichert.';redirectTo(['view'=>'finance','tab'=>'bank']);}
  if($bankWorkflowAction==='fints-schedule-save'){
   $scheduler=new \Kulturhus\Verein\FintsScheduledSync($db,(string)$_SESSION['actor'],\Kulturhus\Verein\EnableBanking::root());
   $schedule=\Kulturhus\Verein\Web\BankScheduleForm::parse($_POST,input($_GET,'account'));
   $scheduler->saveSchedule($schedule['mapping'],$schedule['enabled'],'08:00','18:00',$schedule['version'],$schedule['account']);
   $_SESSION['notice']='FinTS-Abrufplan gespeichert.';
   header('Location: '.url(['view'=>'finance','tab'=>'bank','account'=>$schedule['account']]).'#fints-schedule',true,303);exit;
  }
  if(!str_starts_with($bankWorkflowAction,'bank-directory-')&&input($_GET,'directory')!=='1'&&(str_starts_with($bankWorkflowAction,'fints-')||(input($_GET,'tab')==='bank'&&input($_GET,'bank_environment','PRODUCTION')==='PRODUCTION'))){
   try{$fintsDialog=new \Kulturhus\Verein\FinTsDialog($db,(string)$_SESSION['actor'],hash('sha256',session_id()),\Kulturhus\Verein\EnableBanking::root());$fintsAvailable=true;$fintsStatus=$fintsDialog->status();$fintsCredentialStatus=$fintsDialog->credentialStatus();$fintsDefaultBank=$fintsDialog->defaultBank();}catch(DomainException $ex){$bankWorkflowError=$ex->getMessage();if(str_starts_with($bankWorkflowAction,'fints-'))throw $ex;}
   if(str_starts_with($bankWorkflowAction,'fints-')){if(!$fintsDialog)throw new DomainException('FinTS zuerst privat einrichten.');$version=\Kulturhus\Verein\Web\BankDialogFields::version($_POST);$fintsResult=[];
    if($bankWorkflowAction==='fints-import'){$run=$fintsDialog->import(input($_POST,'account_id'),$version,input($_POST,'request_key'));$showSavedBankImport($run,input($_POST,'account_id'));}
    elseif($bankWorkflowAction==='fints-forget'){if($version!==(int)($fintsStatus['version']??0))throw new DomainException('Bankdialog wurde bereits fortgesetzt. Bitte neu laden.',409);$fintsDialog->forgetCredential();$_SESSION['notice']='Gespeicherter FinTS-Zugang wurde gelöscht.';}
    elseif(in_array($bankWorkflowAction,['fints-transfer-start','fints-direct-debit-start'],true)){
     $orderId=input($_POST,'payment_order');$orderVersion=(int)input($_POST,'payment_order_version');$kind=$bankWorkflowAction==='fints-transfer-start'?'transfer':'direct-debit';
     if($fintsStatus['phase']!=='accounts')throw new DomainException('Zuerst die Bankkonten abrufen.');
     if($kind==='transfer'){$paymentService=new \Kulturhus\Verein\TransferOrders($db,(string)$_SESSION['actor'],\Kulturhus\Verein\MemberBankVault::fromEnvironment($db,(string)$_SESSION['actor'],\Kulturhus\Verein\ProductionRuntime::enabled()));$payload=$paymentService->fintsPayload($orderId,$orderVersion);}
     else{$paymentService=new \Kulturhus\Verein\SepaOrders($db,(string)$_SESSION['actor'],\Kulturhus\Verein\MemberBankVault::fromEnvironment($db,(string)$_SESSION['actor'],\Kulturhus\Verein\ProductionRuntime::enabled()));$payload=$paymentService->fintsPayload($orderId,$orderVersion);}
     $uid='';foreach($fintsStatus['accounts']??[] as $bankAccount)if(($bankAccount['iban']??'')===$payload['source_iban']){$uid=(string)$bankAccount['uid'];break;}if($uid==='')throw new DomainException('Das freigegebene Vereinskonto wurde im FinTS-Dialog nicht gefunden. Bankkonten erneut abrufen.');
     $fintsResult=$fintsDialog->act($kind,['uid'=>$uid,'order_id'=>$payload['order_id'],'order_version'=>$payload['order_version'],'source_iban'=>$payload['source_iban'],'xml'=>$payload['xml'],'demo_auth'=>$_POST['demo_auth']??'app','demo_result'=>$_POST['demo_result']??'success'],$version);$fintsStatus=$fintsResult;
    }
    else{if(!in_array($bankWorkflowAction,['fints-start','fints-start-saved','fints-select','fints-continue','fints-statement','fints-cancel'],true))throw new DomainException('Unbekannter Bankdialogschritt.');$fintsResult=$fintsDialog->act(substr($bankWorkflowAction,6),$_POST,$version);$fintsStatus=$fintsResult;$_SESSION['notice']='Bankdialog aktualisiert.';}
    if(($fintsStatus['phase']??'')==='payment_done'&&in_array(($fintsStatus['task']??''),['transfer','direct-debit'],true)&&($fintsStatus['payment_order']??'')!==''){
     $_SESSION['notice']='Zahlungsauftrag wurde von der Bank angenommen und dokumentiert. Der Beleg bleibt bis zum Umsatzimport offen.';
    }
    $targetAccount=(string)($_SESSION['fints_target_account']??'');
    if(in_array($bankWorkflowAction,['fints-import','fints-cancel','fints-forget'],true))unset($_SESSION['fints_target_account']);
    $paymentOrder=(string)input($_POST,'payment_order',(string)($fintsStatus['payment_order']??''));$paymentKind=(string)input($_POST,'payment_kind',input($_GET,'payment'));$redirect=['view'=>'finance','tab'=>'bank','fints'=>1];
    // Keep the selected account while the multi-step dialog redirects between
    // requests. Without it, the next page would show all accounts and hide the
    // active TAN/statement step after the first POST.
    if($targetAccount!=='')$redirect['account']=$targetAccount;
    if(!\Kulturhus\Native\Demo\Runtime::active()&&(input($_GET,'edit_fints')==='1'||$bankWorkflowAction==='fints-cancel'))$redirect['edit_fints']=1;
    if($paymentOrder!=='')$redirect['payment_order']=$paymentOrder;if(in_array($paymentKind,['transfer','direct-debit'],true))$redirect['payment']=$paymentKind;redirectTo($redirect);
   }
  }
  if(!($nativeManagerEnabled??false)&&(str_starts_with($bankWorkflowAction,'sepa-order-')||input($_GET,'tab')==='debit')){
   $sepaService=new \Kulturhus\Verein\SepaOrders($db,(string)$_SESSION['actor'],\Kulturhus\Verein\MemberBankVault::fromEnvironment($db,(string)$_SESSION['actor'],\Kulturhus\Verein\ProductionRuntime::enabled()));
   if(str_starts_with($bankWorkflowAction,'sepa-order-')){
    $entries=$sepaService->selection($_POST['entries']??[]);$account=input($_POST,'account_id');$collection=input($_POST,'collection_date');$notice=input($_POST,'notice_date');$id=input($_POST,'order');$version=(int)input($_POST,'order_version');
    if($bankWorkflowAction==='sepa-order-selection'){
     if(input($_POST,'candidate_move')==='clear')$entries=[];
     elseif(input($_POST,'candidate_move')==='all'){$page=$sepaService->candidatePage($_POST['candidate_page']??1,$_POST['candidate_search']??'');$entries=$sepaService->selection(array_values(array_unique([...$entries,...array_column($page['rows'],'id')])));}
     $_POST['entries']=$entries;
    }
    elseif($bankWorkflowAction==='sepa-order-preview'){$sepaPreview=$sepaService->preview($entries,$account,$collection,$notice);}
    elseif($bankWorkflowAction==='sepa-order-prepare'){$id=$sepaService->prepare($entries,$account,$collection,$notice,input($_POST,'preview_hash'),input($_POST,'request_key'));$_SESSION['notice']='Auftrag vorbereitet. Jetzt als Kassenwart freigeben.';}
    elseif($bankWorkflowAction==='sepa-order-approve'){$sepaService->approve($id,$version);$_SESSION['notice']='Auftrag freigegeben. Die Bankdatei kann heruntergeladen werden.';}
    elseif($bankWorkflowAction==='sepa-order-export'){$xml=$sepaService->export($id,$version);header('Content-Type: application/xml; charset=UTF-8');header('Content-Disposition: attachment; filename="sepa-core-'.$id.'.xml"');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');echo $xml;exit;}
    elseif($bankWorkflowAction==='sepa-order-submitted'){$sepaService->submitted($id,$version,input($_POST,'bank_reference'));$_SESSION['notice']='Externe Einreichung dokumentiert. Der Auftrag bestätigt noch keine Zahlung.';}
    elseif($bankWorkflowAction==='sepa-order-cancel'){$sepaService->cancel($id,$version);$_SESSION['notice']='Vorbereiteter Auftrag verworfen.';}
    elseif($bankWorkflowAction==='sepa-order-return'){$sepaService->recordReturn(input($_POST,'item'),input($_POST,'transaction'),input($_POST,'reason'),input($_POST,'document')?:null);$_SESSION['notice']='Rücklastschrift dokumentiert. Finanzzuordnung prüfen.';}
    else throw new DomainException('Unbekannte SEPA-Aktion.');
    if(!in_array($bankWorkflowAction,['sepa-order-preview','sepa-order-selection'],true))redirectTo(['view'=>'finance','tab'=>'debit','sepa_order'=>$id]);
   }
  }
 }catch(Throwable $ex){if(str_starts_with($bankWorkflowAction,'fints-')&&$ex instanceof DomainException&&in_array($ex->getCode(),[409,422],true))http_response_code($ex->getCode());$bankWorkflowError=$ex instanceof DomainException?$ex->getMessage():'Bankvorgang konnte nicht abgeschlossen werden. Bitte den gespeicherten Stand prüfen.';if($fintsDialog){try{$fintsStatus=$fintsDialog->status();}catch(Throwable){$fintsStatus=[];}}}
}elseif($isBankWorkflow){http_response_code(403);exit('Finanz-Schreibrecht erforderlich.');}
