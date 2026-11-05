<?php declare(strict_types=1);
use Kulturhus\Verein\Finance\Invoices\Reminder\{ReminderDecisionInput,ReminderDecisionDraft,ReminderDecisions};

$reminderdecisionHandled=false;$reminderdecisionPostedDraft=null;$reminderdecisionRequests=null;$reminderdecisionStarted=false;
$reminderdecisionMethod=$_SERVER['REQUEST_METHOD']??'GET';$reminderdecisionRoute=$reminderRoute&&isset($_GET['reminder_job']);
$reminderdecisionDetail=$reminderdecisionRoute&&isset($_GET['reminder_job']);$reminderdecisionJob=is_string($_GET['reminder_job']??null)?$_GET['reminder_job']:'';
$reminderdecisionPost=($nativeManagerEnabled??false)&&$reminderdecisionMethod==='POST'&&($_POST['action']??null)==='invoice-reminder-decision-native';
if(!$reminderdecisionDetail&&!$reminderdecisionPost&&!isset($_GET['reminder_decision_original']))return;
if($reminderdecisionMethod==='POST'&&!$reminderdecisionPost&&!isset($_GET['reminder_decision_original']))return;
$reminderdecisionUrl=static fn(array $query=[]):array=>$reminderUrl(['reminder_job'=>$reminderdecisionJob]+$query);
try{
    if(!$reminderdecisionDetail||!$workspaceAllowed||$reminderdecisionJob==='')throw new DomainException('Erinnerungsmailauftrag im eigenen Finanzbereich öffnen.',403);
    $reminderdecisionRequests=new ReminderDecisions($db,(string)$_SESSION['actor'],\Kulturhus\Verein\ContactArchive::serverKey());$reminderdecisionActor=$reminderdecisionRequests->assertAccess();if((new \Kulturhus\Verein\Finance\Invoices\Reminder\ReminderRequests($db,(string)$_SESSION['actor']))->parentDocument($reminderInvoice)!==$reminderDocument)throw new DomainException('Ursprüngliche Rechnung dieser Erinnerung nicht verfügbar.',404);if($reminderdecisionRequests->context($reminderdecisionJob)['invoice_id']!==$reminderInvoice)throw new DomainException('Eigene ursprüngliche Erinnerung auswählen.',404);
    if(isset($_GET['reminder_decision_original'])){
        if(!in_array($reminderdecisionMethod,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');exit;}
        if(!is_string($_GET['reminder_decision_original']))throw new DomainException('Eigene ursprüngliche Erinnerungsmailentscheidung nicht verfügbar.',404);$first=$reminderdecisionRequests->receipt($_GET['reminder_decision_original']);if($first['input']['job']!==$reminderdecisionJob)throw new DomainException('Eigene ursprüngliche Erinnerungsmailentscheidung nicht verfügbar.',404);$bytes=$first['original_json'];
        header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="dvm-invoice-reminder-delivery-original.json"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox; default-src 'none'");header('Content-Length: '.strlen($bytes));if($reminderdecisionMethod!=='HEAD')echo $bytes;exit;
    }
    if(!$reminderdecisionPost)return;$reminderdecisionHandled=true;
    if(!is_string($_POST['csrf']??null)||!hash_equals((string)$_SESSION['csrf'],$_POST['csrf']))throw new DomainException('Formular abgelaufen. Bitte neu laden.',403);
    $reminderdecisionPostedDraft=ReminderDecisionDraft::get($_SESSION,$reminderdecisionActor,$reminderdecisionJob,$_POST['reminder_decision_draft']??null);
    try{$first=$reminderdecisionRequests->receipt($reminderdecisionPostedDraft['key']);if($first['input']['job']!==$reminderdecisionJob)throw new DomainException('Eigene ursprüngliche Erinnerungsmailentscheidung nicht verfügbar.',404);ReminderDecisionDraft::store($_SESSION,ReminderDecisionDraft::complete($reminderdecisionPostedDraft,$first['key']));redirectTo($reminderdecisionUrl(['reminder_decision_receipt'=>$first['key']]));}
    catch(DomainException $lookup){if($lookup->getCode()!==404||$reminderdecisionPostedDraft['accepted']!==null)throw $lookup;}
    $intent=$_POST['reminder_decision_intent']??null;if(!is_string($intent)||!in_array($intent,['preview','accept','current','discard'],true))throw new DomainException('Genau eine eigene Erinnerungsmailaktion auswählen.',422);
    if($reminderdecisionPostedDraft['pending']&&$intent!=='current')throw new DomainException('Ursprünglichen Erinnerungsmailausgang zuerst lesend prüfen.',409);
    if(!is_string($_POST['reminder_decision_revision']??null)||!hash_equals(ReminderDecisionDraft::revision($reminderdecisionPostedDraft),$_POST['reminder_decision_revision']))throw new DomainException('Der Erinnerungsentwurf wurde in einem anderen Tab geändert. Ganze Angaben erneut öffnen.',409);
    if($intent==='discard'){ReminderDecisionDraft::discard($_SESSION,$reminderdecisionPostedDraft);redirectTo($reminderdecisionUrl(['reminder_decision_closed'=>'1']));}
    $reminderdecisionRequests->assertAccess(true);$input=ReminderDecisionInput::capture(['job'=>$reminderdecisionJob,'operation'=>$_POST['operation']??$reminderdecisionPostedDraft['input']['operation'],'data'=>$_POST['data']??$reminderdecisionPostedDraft['input']['data']]);$review=$reminderdecisionPostedDraft['review'];
    if($intent==='current'){
        $newReview=$reminderdecisionRequests->prepare($input);$pending=$reminderdecisionPostedDraft['pending'];$originalKey=$reminderdecisionPostedDraft['key'];$reminderdecisionPostedDraft=ReminderDecisionDraft::replace($reminderdecisionPostedDraft,$input,!$pending);if($pending)$reminderdecisionPostedDraft['key']=$originalKey;$reminderdecisionPostedDraft['pending']=false;$reminderdecisionPostedDraft['review']=$newReview;ReminderDecisionDraft::store($_SESSION,$reminderdecisionPostedDraft);return;
    }
    if($intent==='preview'||$reminderdecisionPostedDraft['input']!==$input)$reminderdecisionPostedDraft=ReminderDecisionDraft::replace($reminderdecisionPostedDraft,$input);ReminderDecisionDraft::store($_SESSION,$reminderdecisionPostedDraft);
    if($intent==='preview'){$reminderdecisionPostedDraft['review']=$reminderdecisionRequests->prepare($input);ReminderDecisionDraft::store($_SESSION,$reminderdecisionPostedDraft);return;}
    if(!is_array($review)||$review['input']!==$input)throw new DomainException('Geänderte ganze Erinnerungsmailangaben zuerst erneut prüfen.',422);
    if(($_POST['reminder_decision_approved']??null)!=='1')throw new DomainException('Genau diese geprüfte Erinnerungsmailentscheidung ausdrücklich bestätigen.',422);
    if(!is_string($_POST['reminder_decision_key']??null)||!hash_equals($reminderdecisionPostedDraft['key'],$_POST['reminder_decision_key']))throw new DomainException('Der Erinnerungsmailentwurf wurde in einem anderen Tab geändert. Ganze Angaben erneut öffnen.',409);
    $reminderdecisionPostedDraft['pending']=true;ReminderDecisionDraft::store($_SESSION,$reminderdecisionPostedDraft);$reminderdecisionStarted=true;$first=$reminderdecisionRequests->accept($input,$reminderdecisionPostedDraft['key'],$review['hash'],true);ReminderDecisionDraft::store($_SESSION,ReminderDecisionDraft::complete($reminderdecisionPostedDraft,$first['key']));redirectTo($reminderdecisionUrl(['reminder_decision_receipt'=>$first['key']]));
}catch(DomainException $failure){
    if($reminderdecisionPost)$reminderdecisionHandled=true;http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=$failure->getMessage();
    if($reminderdecisionPostedDraft!==null){if($reminderdecisionStarted){$reminderdecisionPostedDraft['pending']=false;$reminderdecisionPostedDraft['review']=null;}if($failure->getCode()===409)$reminderdecisionPostedDraft['conflicted']=true;ReminderDecisionDraft::store($_SESSION,$reminderdecisionPostedDraft);}
}catch(Throwable){if($reminderdecisionPost)$reminderdecisionHandled=true;http_response_code(503);$error=$reminderdecisionStarted?'Erinnerungsmailausgang nicht bestätigt. Die ursprüngliche persönliche Entscheidung zuerst lesend prüfen.':'Erinnerungsmail konnte nicht geprüft werden. Ganze Angaben bleiben ungespeichert.';}
if(isset($_GET['reminder_decision_original'])){
    try{$messages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\InvoiceReminderMessages::text($error,$messages);}catch(Throwable){}
    header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');if($reminderdecisionMethod!=='HEAD')echo $error;exit;
}
