<?php declare(strict_types=1);
use Kulturhus\Verein\Finance\Invoices\{OutgoingMailDraft,OutgoingMailInput,OutgoingMailRequests};
$outgoingmailHandled=false;$outgoingmailPostedDraft=null;$outgoingmailRequests=null;$outgoingmailAcceptStarted=false;
$outgoingmailMethod=$_SERVER['REQUEST_METHOD']??'GET';
$outgoingmailRoute=($nativeManagerEnabled??false)&&isset($nativeAccess)&&$view==='finance'&&($_GET['tab']??null)==='outgoing';
$outgoingmailInvoice=$_GET['invoice']??$_POST['invoice']??'';
$outgoingmailPost=($nativeManagerEnabled??false)&&$outgoingmailMethod==='POST'&&in_array($_POST['action']??null,['outgoing-mail','outgoing-mail-native'],true);
$outgoingmailUrl=static fn(string $invoice,array $query=[]):array=>['view'=>'finance','tab'=>'outgoing','invoice'=>$invoice]+$query;
if(!$outgoingmailRoute&&!$outgoingmailPost)return;
if(!$outgoingmailPost&&$outgoingmailInvoice==='')return;
try{
    if(!$outgoingmailRoute||!$workspaceAllowed)throw new DomainException('Ausgangsrechnung im eigenen Finanzbereich öffnen.',403);
    if(!is_string($outgoingmailInvoice))throw new DomainException('Ausgestellte eigene Rechnung auswählen.',422);
    OutgoingMailDraft::section($outgoingmailInvoice);$outgoingmailRequests=new OutgoingMailRequests($db,(string)$_SESSION['actor']);$outgoingmailActor=$outgoingmailRequests->assertAccess();
    if(isset($_GET['outgoingmail_original'])){
        if(!in_array($outgoingmailMethod,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
        if(!is_string($_GET['outgoingmail_original']))throw new DomainException('Eigene ursprüngliche Rechnungs-Mailbestätigung auswählen.',422);
        $first=$outgoingmailRequests->receipt($_GET['outgoingmail_original']);if($first['input']['invoice']!==$outgoingmailInvoice)throw new DomainException('Eigene ursprüngliche Rechnungs-Mailbestätigung nicht verfügbar.',404);
        $bytes=$first['original_json'];header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="dvm-outgoing-mail-original.json"');
        header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox; default-src 'none'");header('Content-Length: '.strlen($bytes));if($outgoingmailMethod!=='HEAD')echo $bytes;exit;
    }
    if(isset($_GET['outgoingmail_pdf'])){
        if(!in_array($outgoingmailMethod,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
        if($_GET['outgoingmail_pdf']!=='original'){
            $draft=OutgoingMailDraft::get($_SESSION,$outgoingmailActor,$outgoingmailInvoice,$_GET['outgoingmail_pdf']);
            if($draft['pending']||$draft['accepted']!==null||!is_array($draft['review'])||!is_string($_GET['outgoingmail_review']??null)||!hash_equals($draft['review']['hash'],$_GET['outgoingmail_review']))throw new DomainException('Eigenen vollständig geprüften Rechnungs-Mailentwurf öffnen.',409);
            $review=$outgoingmailRequests->prepare($draft['input']);if(!hash_equals($draft['review']['hash'],$review['hash']))throw new DomainException('Nachricht, Rechnung, Zahlungsstand oder Quellen inzwischen geändert. Aktuellen Stand bewusst erneut prüfen.',409);
        }
        $context=$outgoingmailRequests->context($outgoingmailInvoice);$bytes=$context['file']['bytes'];
        header('Content-Type: application/pdf');header('Content-Disposition: '.(($_GET['outgoingmail_pdf_download']??null)==='1'?'attachment':'inline').'; filename="dvm-original-invoice.pdf"');
        header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox; default-src 'none'");header('Content-Length: '.strlen($bytes));if($outgoingmailMethod!=='HEAD')echo $bytes;exit;
    }
    if(!$outgoingmailPost)return;$outgoingmailHandled=true;
    if(!is_string($_POST['csrf']??null)||!hash_equals((string)$_SESSION['csrf'],$_POST['csrf']))throw new DomainException('Formular abgelaufen. Bitte neu laden.',403);
    if(($_POST['invoice']??null)!==$outgoingmailInvoice)throw new DomainException('Ausgestellte eigene Rechnung auswählen.',422);
    if($_POST['action']==='outgoing-mail'){
        $outgoingmailRequests->assertAccess(true);$context=$outgoingmailRequests->context($outgoingmailInvoice);
        $input=OutgoingMailInput::initial($context,($context['delivery']['state']??null)==='blocked'?'replace':'queue');$input['version']=$_POST['version']??'';
        if(array_key_exists('data',$_POST))$input['data']=$_POST['data'];
        $outgoingmailPostedDraft=OutgoingMailDraft::open($_SESSION,$outgoingmailActor,$input,true);OutgoingMailDraft::store($_SESSION,$outgoingmailPostedDraft);
        throw new DomainException('Dieses frühere Versandformular wurde nicht beauftragt. Ganze Nachricht und Original-PDF zuerst prüfen und gesondert bestätigen.',422);
    }
    $outgoingmailPostedDraft=OutgoingMailDraft::get($_SESSION,$outgoingmailActor,$outgoingmailInvoice,$_POST['outgoingmail_draft']??null);
    if($outgoingmailPostedDraft['accepted']!==null){$first=$outgoingmailRequests->receipt($outgoingmailPostedDraft['key']);redirectTo($outgoingmailUrl($outgoingmailInvoice,['outgoingmail_receipt'=>$first['key']]));}
    $intent=$_POST['outgoingmail_intent']??null;
    if(!is_string($intent)||!in_array($intent,['preview','current','accept','discard'],true))throw new DomainException('Gültige Rechnungs-Mailaktion auswählen.',422);
    if($outgoingmailPostedDraft['pending']){
        try{$first=$outgoingmailRequests->receipt($outgoingmailPostedDraft['key']);$outgoingmailPostedDraft=OutgoingMailDraft::complete($outgoingmailPostedDraft,$first['key']);OutgoingMailDraft::store($_SESSION,$outgoingmailPostedDraft);redirectTo($outgoingmailUrl($outgoingmailInvoice,['outgoingmail_receipt'=>$first['key']]));}
        catch(DomainException $failure){if($failure->getCode()!==404)throw $failure;}
        if($intent!=='current')throw new DomainException('Zuerst den ursprünglichen Rechnungs-Mailausgang lesen.',409);$outgoingmailPostedDraft['pending']=false;
    }
    if($intent==='discard'){OutgoingMailDraft::discard($_SESSION,$outgoingmailPostedDraft);redirectTo($outgoingmailUrl($outgoingmailInvoice,['outgoingmail_closed'=>'1']));}
    if($intent==='accept'){
        if(!is_string($_POST['outgoingmail_key']??null)||!is_string($_POST['outgoingmail_review']??null)||!hash_equals($outgoingmailPostedDraft['key'],$_POST['outgoingmail_key'])||!is_array($outgoingmailPostedDraft['review'])||!hash_equals($outgoingmailPostedDraft['review']['hash'],$_POST['outgoingmail_review']))throw new DomainException('Der Entwurf wurde in einem anderen Tab geändert. Die ursprünglichen Angaben erneut öffnen.',409);
        if(($_POST['outgoingmail_approved']??null)!=='1')throw new DomainException('Nachricht, Empfänger und genau dieses Original-PDF ausdrücklich bestätigen.',422);
        $outgoingmailPostedDraft['pending']=true;OutgoingMailDraft::store($_SESSION,$outgoingmailPostedDraft);$outgoingmailAcceptStarted=true;
        $first=$outgoingmailRequests->accept($outgoingmailPostedDraft['input'],$outgoingmailPostedDraft['key'],$outgoingmailPostedDraft['review']['hash'],true);
        $outgoingmailPostedDraft=OutgoingMailDraft::complete($outgoingmailPostedDraft,$first['key']);OutgoingMailDraft::store($_SESSION,$outgoingmailPostedDraft);redirectTo($outgoingmailUrl($outgoingmailInvoice,['outgoingmail_receipt'=>$first['key']]));
    }
    $outgoingmailRequests->assertAccess(true);$input=$outgoingmailPostedDraft['input'];
    if(array_key_exists('data',$_POST))$input['data']=$_POST['data'];
    elseif($intent!=='current')throw new DomainException('Ganzen Betreff und Nachrichtentext prüfen.',422);
    if($intent==='current'){$currentContext=$outgoingmailRequests->context($outgoingmailInvoice);$input['version']=(string)$currentContext['invoice']['version'];if($input['kind']==='queue'&&($currentContext['delivery']['state']??null)==='blocked')$input['kind']='replace';}
    $outgoingmailPostedDraft=OutgoingMailDraft::replace($outgoingmailPostedDraft,$input,$intent==='current');OutgoingMailDraft::store($_SESSION,$outgoingmailPostedDraft);
    $outgoingmailPostedDraft['review']=$outgoingmailRequests->prepare($outgoingmailPostedDraft['input']);OutgoingMailDraft::store($_SESSION,$outgoingmailPostedDraft);
}catch(DomainException $failure){
    if($outgoingmailPost)$outgoingmailHandled=true;
    http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=$failure->getMessage();
    if($outgoingmailPostedDraft!==null){if($failure->getCode()===409)$outgoingmailPostedDraft['conflicted']=true;OutgoingMailDraft::store($_SESSION,$outgoingmailPostedDraft);}
}catch(Throwable){
    if($outgoingmailPost)$outgoingmailHandled=true;http_response_code(503);
    $error=$outgoingmailAcceptStarted?'Rechnungs-Mailausgang nicht bestätigt. Die ursprüngliche Bestätigung über ihre Kennung lesen.':'Rechnungs-Mailentwurf konnte nicht geprüft werden. Ganze Angaben bleiben ungespeichert.';
}

// Download failures terminate here; an inaccessible invoice must not reach its ordinary detail view.
if(isset($_GET['outgoingmail_original'])||isset($_GET['outgoingmail_pdf'])){
    try{$messages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\OutgoingMailMessages::text($error,$messages);}catch(Throwable){}
    header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
    if($outgoingmailMethod!=='HEAD')echo $error;exit;
}
