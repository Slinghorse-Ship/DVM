<?php declare(strict_types=1);
use Kulturhus\Verein\Finance\Invoices\Reminder\{ReminderDraft,ReminderInput,ReminderRequests};

$reminderHandled=false;$reminderPostedDraft=null;$reminderRequests=null;$reminderStarted=false;
$reminderMethod=$_SERVER['REQUEST_METHOD']??'GET';
$reminderInvoice=is_string($_GET['reminder_invoice']??null)?$_GET['reminder_invoice']:'';
$reminderDocument=is_string($_GET['doc']??null)?$_GET['doc']:'';
$reminderRoute=($nativeManagerEnabled??false)&&isset($nativeAccess)&&$view==='documents'&&$reminderInvoice!==''&&$reminderDocument!=='';
$reminderPost=($nativeManagerEnabled??false)&&$reminderMethod==='POST'&&($_POST['action']??null)==='invoice-reminder-native';
$reminderDownload=isset($_GET['reminder_original'])||isset($_GET['reminder_pdf'])||isset($_GET['reminder_eml'])||isset($_GET['reminder_preview_pdf'])||isset($_GET['reminder_preview_eml']);
$reminderHistoryContext=[];
foreach(['reminder_history_q','reminder_history_page','reminder_delivery_page']as$control)if(is_string($_GET[$control]??null))$reminderHistoryContext[$control]=$_GET[$control];
$reminderUrl=static fn(array $query=[]):array=>['view'=>'documents','doc'=>$reminderDocument,'reminder_invoice'=>$reminderInvoice]+$query+$reminderHistoryContext;
if(!$reminderRoute&&!$reminderPost&&!$reminderDownload)return;
if($reminderMethod==='POST'&&!$reminderPost&&!$reminderDownload)return;
try{
    if(!$reminderRoute||!$workspaceAllowed)throw new DomainException('Zahlungserinnerung am eigenen ursprünglichen Rechnungsdokument öffnen.',403);
    $reminderRequests=new ReminderRequests($db,(string)$_SESSION['actor']);$reminderActor=$reminderRequests->assertAccess();
    if($reminderRequests->parentDocument($reminderInvoice)!==$reminderDocument)throw new DomainException('Ursprüngliche Rechnung dieser Erinnerung nicht verfügbar.',404);
    if($reminderDownload){
        if(!in_array($reminderMethod,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: private, no-store');exit;}
        $preview=isset($_GET['reminder_preview_pdf'])||isset($_GET['reminder_preview_eml']);$eml=isset($_GET['reminder_eml'])||isset($_GET['reminder_preview_eml']);$pdf=isset($_GET['reminder_pdf'])||isset($_GET['reminder_preview_pdf']);
        if($preview){
            $draft=ReminderDraft::get($_SESSION,$reminderActor,$reminderInvoice,$_GET['reminder_draft']??null);$id=$_GET[$eml?'reminder_preview_eml':'reminder_preview_pdf'];
            if(!is_string($id)||!hash_equals($draft['key'],$id)||!is_array($draft['review'])||$draft['pending']||$draft['accepted']!==null)throw new DomainException('Eigene unverbindliche Erinnerungsvorschau erneut prüfen.',409);
            $plan=$reminderRequests->reviewedPreview($draft['input'],$draft['review']['hash']);$bytes=$plan[$eml?'mime':'pdf'];if(!is_string($bytes))throw new DomainException('Für den Briefweg gibt es kein E-Mail-Original.',404);$filename='dvm-zahlungserinnerung-vorschau.'.($eml?'eml':'pdf');
        }else{
            $key=$_GET[$eml?'reminder_eml':($pdf?'reminder_pdf':'reminder_original')];if(!is_string($key))throw new DomainException('Eigene ursprüngliche Erinnerungsbestätigung nicht verfügbar.',404);
            $first=$reminderRequests->receipt($key);if($first['input']['invoice']!==$reminderInvoice||$first['result']['source_document']!==$reminderDocument)throw new DomainException('Eigene ursprüngliche Erinnerungsbestätigung nicht verfügbar.',404);
            $bytes=$eml?$reminderRequests->eml($key):($pdf?$reminderRequests->pdf($key):$first['original_json']);$filename=$eml?'dvm-zahlungserinnerung-original.eml':($pdf?$first['result']['filename']:'dvm-zahlungserinnerung-original.json');
        }
        header('Content-Type: '.($eml?'message/rfc822':($pdf?'application/pdf':'application/json; charset=utf-8')));header('Content-Disposition: '.($preview&&$pdf?'inline':'attachment').'; filename="'.$filename.'"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox; default-src 'none'");header('Content-Length: '.strlen($bytes));if($reminderMethod!=='HEAD')echo $bytes;exit;
    }
    if(!$reminderPost)return;$reminderHandled=true;
    if(!is_string($_POST['csrf']??null)||!hash_equals((string)$_SESSION['csrf'],$_POST['csrf']))throw new DomainException('Formular abgelaufen. Bitte neu laden.',403);
    $reminderPostedDraft=ReminderDraft::get($_SESSION,$reminderActor,$reminderInvoice,$_POST['reminder_draft']??null);
    try{
        $first=$reminderRequests->receipt($reminderPostedDraft['key']);if($first['input']['invoice']!==$reminderInvoice||$first['result']['source_document']!==$reminderDocument)throw new DomainException('Eigene ursprüngliche Erinnerungsbestätigung nicht verfügbar.',404);
        ReminderDraft::store($_SESSION,ReminderDraft::complete($reminderPostedDraft,$first['key']));redirectTo($reminderUrl(['reminder_first'=>$first['key']]));
    }catch(DomainException $lookup){if($lookup->getCode()!==404||$reminderPostedDraft['accepted']!==null)throw $lookup;}
    $intent=$_POST['reminder_intent']??null;if(!is_string($intent)||!in_array($intent,['preview','accept','current','discard'],true))throw new DomainException('Genau eine eigene Erinnerungsaktion auswählen.',422);
    if($reminderPostedDraft['pending']&&$intent!=='current')throw new DomainException('Ursprünglichen Erinnerungsausgang zuerst lesend prüfen.',409);
    if(!is_string($_POST['reminder_revision']??null)||!hash_equals(ReminderDraft::revision($reminderPostedDraft),$_POST['reminder_revision']))throw new DomainException('Der Erinnerungsentwurf wurde in einem anderen Tab geändert. Ganze Angaben erneut öffnen.',409);
    if($intent==='discard'){ReminderDraft::discard($_SESSION,$reminderPostedDraft);redirectTo($reminderUrl(['reminder_closed'=>'1']));}
    $reminderRequests->assertAccess(true);
    $input=ReminderInput::capture(['invoice'=>$reminderInvoice,'day'=>$_POST['day']??$reminderPostedDraft['input']['day'],'text'=>$_POST['text']??$reminderPostedDraft['input']['text'],'reason'=>$_POST['reason']??$reminderPostedDraft['input']['reason'],'channel'=>$_POST['channel']??$reminderPostedDraft['input']['channel'],'attach_original'=>$_POST['attach_original']??$reminderPostedDraft['input']['attach_original']]);$review=$reminderPostedDraft['review'];
    if($intent==='current'){
        $newReview=$reminderRequests->prepare($input);$pending=$reminderPostedDraft['pending'];$oldKey=$reminderPostedDraft['key'];$reminderPostedDraft=ReminderDraft::replace($reminderPostedDraft,$input,!$pending);if($pending)$reminderPostedDraft['key']=$oldKey;
        $reminderPostedDraft['pending']=false;$reminderPostedDraft['review']=$newReview;ReminderDraft::store($_SESSION,$reminderPostedDraft);return;
    }
    if($intent==='preview'||$reminderPostedDraft['input']!==$input)$reminderPostedDraft=ReminderDraft::replace($reminderPostedDraft,$input);ReminderDraft::store($_SESSION,$reminderPostedDraft);
    if($intent==='preview'){$reminderPostedDraft['review']=$reminderRequests->prepare($input);ReminderDraft::store($_SESSION,$reminderPostedDraft);return;}
    if(!is_array($review)||$review['input']!==$input)throw new DomainException('Geänderte ganze Erinnerungsangaben zuerst erneut prüfen.',422);
    if(($_POST['reminder_approved']??null)!=='1')throw new DomainException('Genau diese vollständig geprüfte Erinnerung ausdrücklich bestätigen.',422);
    if(!is_string($_POST['reminder_key']??null)||!hash_equals($reminderPostedDraft['key'],$_POST['reminder_key']))throw new DomainException('Der Erinnerungsentwurf wurde in einem anderen Tab geändert. Ganze Angaben erneut öffnen.',409);
    $reminderPostedDraft['pending']=true;ReminderDraft::store($_SESSION,$reminderPostedDraft);$reminderStarted=true;$first=$reminderRequests->accept($input,$reminderPostedDraft['key'],$review['hash'],true);ReminderDraft::store($_SESSION,ReminderDraft::complete($reminderPostedDraft,$first['key']));redirectTo($reminderUrl(['reminder_first'=>$first['key']]));
}catch(DomainException $failure){
    if($reminderPost)$reminderHandled=true;http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=$failure->getMessage();
    if($reminderPostedDraft!==null){if($reminderStarted){$reminderPostedDraft['pending']=false;$reminderPostedDraft['review']=null;}if($failure->getCode()===409)$reminderPostedDraft['conflicted']=true;ReminderDraft::store($_SESSION,$reminderPostedDraft);}
}catch(Throwable){if($reminderPost)$reminderHandled=true;http_response_code(503);$error=$reminderStarted?'Erinnerungsausgang nicht bestätigt. Die ursprüngliche persönliche Entscheidung zuerst lesend prüfen.':'Erinnerung konnte nicht geprüft werden. Ganze Angaben bleiben ungespeichert.';}
if($reminderDownload){
    try{$messages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\InvoiceReminderMessages::text($error,$messages);}catch(Throwable){}
    header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');if($reminderMethod!=='HEAD')echo $error;exit;
}
