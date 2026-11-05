<?php declare(strict_types=1);
use Kulturhus\Verein\Finance\Banking\NativeTransfer\{TransferInput,TransferDraft,TransferRequests};

$transferHandled=false;$transferPostedDraft=null;$transferRequests=null;$transferStarted=false;
$transferMethod=$_SERVER['REQUEST_METHOD']??'GET';$transferRoute=($nativeManagerEnabled??false)&&isset($nativeAccess)&&$view==='finance'&&($_GET['tab']??null)==='receipts'&&isset($_GET['entry']);
$transferEntry=is_string($_GET['entry']??null)?$_GET['entry']:'';
$transferDetail=$transferRoute&&isset($_GET['transfer_order']);$transferOrder=is_string($_GET['transfer_order']??null)?$_GET['transfer_order']:'';
$transferPost=($nativeManagerEnabled??false)&&$transferMethod==='POST'&&($_POST['action']??null)==='transfer-native';
$transferPaymentPost=($nativeManagerEnabled??false)&&$transferMethod==='POST'&&($_POST['action']??null)==='transfer-payment';
$transferLegacyPost=($nativeManagerEnabled??false)&&$transferMethod==='POST'&&is_string($_POST['action']??null)&&str_starts_with($_POST['action'],'transfer-order-');
if(!$transferRoute&&!$transferLegacyPost&&!$transferPost&&!$transferPaymentPost&&!isset($_GET['transfer_original'])&&!isset($_GET['transfer_xml'])&&!isset($_GET['transfer_order_xml']))return;
if($transferMethod==='POST'&&!$transferPost&&!$transferLegacyPost&&!$transferPaymentPost&&!isset($_GET['transfer_original'])&&!isset($_GET['transfer_xml'])&&!isset($_GET['transfer_order_xml']))return;
$transferCatalogueContext=array_diff_key(receiptPaymentContext($transferEntry,$_GET,false),array_fill_keys(['view','tab','entry'],true));foreach(['transfer_q','transfer_state','transfer_page','transfer_manual']as$control)if(is_string($_GET[$control]??null))$transferCatalogueContext[$control]=$_GET[$control];
$transferUrl=static fn(array $query=[]):array=>['view'=>'finance','tab'=>'receipts','entry'=>$transferEntry]+($transferOrder!==''?['transfer_order'=>$transferOrder]:[])+$query+$transferCatalogueContext;
try{
    if(!$transferRoute||!$workspaceAllowed||!preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/D',$transferEntry)||(isset($_GET['transfer_order'])&&$transferOrder===''))throw new DomainException('Überweisungsauftrag im eigenen Finanzbereich öffnen.',403);
    $transferRequests=new TransferRequests($db,(string)$_SESSION['actor']);$transferActor=$transferRequests->assertAccess();
    if(isset($_GET['transfer_original'])||isset($_GET['transfer_xml'])||isset($_GET['transfer_order_xml'])){
        if(!in_array($transferMethod,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');exit;}
        $archived=isset($_GET['transfer_order_xml']);$xmlDownload=$archived||isset($_GET['transfer_xml']);$download=$archived?$_GET['transfer_order_xml']:($xmlDownload?$_GET['transfer_xml']:$_GET['transfer_original']);if(!is_string($download))throw new DomainException('Eigene ursprüngliche Überweisungsentscheidung nicht verfügbar.',404);
        if($archived){if($transferOrder===''||$download!==$transferOrder)throw new DomainException('Ursprüngliche exportierte Bankdatei nicht verfügbar.',404);$transferRequests->context($transferEntry,$download);$bytes=$transferRequests->orderXml($download);}
        else{$first=$transferRequests->receipt($download);if($first['result']['order']['entry_id']!==$transferEntry||($transferOrder!==''&&$first['result']['order']['id']!==$transferOrder))throw new DomainException('Eigene ursprüngliche Überweisungsentscheidung nicht verfügbar.',404);$bytes=$xmlDownload?$transferRequests->xml($download):$first['original_json'];}
        header('Content-Type: '.($xmlDownload?'application/xml':'application/json').'; charset=utf-8');header('Content-Disposition: attachment; filename="'.($xmlDownload?'dvm-transfer-original.xml':'dvm-transfer-order-original.json').'"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox; default-src 'none'");header('Content-Length: '.strlen($bytes));if($transferMethod!=='HEAD')echo $bytes;exit;
    }
    if($transferPaymentPost){
        $transferHandled=true;
        if(!is_string($_POST['csrf']??null)||!hash_equals((string)$_SESSION['csrf'],$_POST['csrf']))throw new DomainException('Formular abgelaufen. Bitte neu laden.',403);
        $selection=\Kulturhus\Verein\Finance\Banking\NativeTransfer\PaymentForm::get($_SESSION,$transferActor,$transferEntry,$_POST['payment_form']??null,$_POST['payment_mode']??null);
        $transferStarted=true;$first=$transferRequests->accept($selection['choice']['input'],$selection['draft']['key'],$selection['choice']['hash'],true);
        \Kulturhus\Verein\Finance\Banking\NativeTransfer\PaymentForm::complete($_SESSION,$selection['draft'],$first['key']);
        $current=$transferRequests->context($transferEntry,$first['result']['order']['id']);$order=$current['order'];
        if($order['state']==='approved')redirectTo(['view'=>'finance','tab'=>'bank','account'=>$order['account_id'],'fints'=>1,'payment'=>'transfer','payment_order'=>$order['id']]);
        redirectTo($transferUrl(['transfer_order'=>$order['id']]));
    }
    if($transferLegacyPost){$transferHandled=true;throw new DomainException('Frühere Überweisungsformulare erneut im aktuellen Überweisungsbereich öffnen und bewusst prüfen.',409);}
    if(!$transferPost)return;$transferHandled=true;
    if(!is_string($_POST['csrf']??null)||!hash_equals((string)$_SESSION['csrf'],$_POST['csrf']))throw new DomainException('Formular abgelaufen. Bitte neu laden.',403);
    $transferPostedDraft=TransferDraft::get($_SESSION,$transferActor,$transferEntry,$transferOrder,$_POST['transfer_draft']??null);
    try{$first=$transferRequests->receipt($transferPostedDraft['key']);if($first['result']['order']['entry_id']!==$transferEntry||($transferOrder!==''&&$first['result']['order']['id']!==$transferOrder))throw new DomainException('Eigene ursprüngliche Überweisungsentscheidung nicht verfügbar.',404);TransferDraft::store($_SESSION,TransferDraft::complete($transferPostedDraft,$first['key']));redirectTo($transferUrl(['transfer_order'=>$first['result']['order']['id'],'transfer_receipt'=>$first['key']]));}
    catch(DomainException $lookup){if($lookup->getCode()!==404||$transferPostedDraft['accepted']!==null)throw $lookup;}
    $intent=$_POST['transfer_intent']??null;if(!is_string($intent)||!in_array($intent,['preview','accept','current','discard'],true))throw new DomainException('Genau eine eigene Überweisungsaktion auswählen.',422);
    if($transferPostedDraft['pending']&&$intent!=='current')throw new DomainException('Ursprünglichen Überweisungsausgang zuerst lesend prüfen.',409);
    if(!is_string($_POST['transfer_revision']??null)||!hash_equals(TransferDraft::revision($transferPostedDraft),$_POST['transfer_revision']))throw new DomainException('Der Überweisungsentwurf wurde in einem anderen Tab geändert. Ganze Angaben erneut öffnen.',409);
    if($intent==='discard'){TransferDraft::discard($_SESSION,$transferPostedDraft);redirectTo($transferUrl(['transfer_closed'=>'1']));}
    $transferRequests->assertAccess(true);$input=TransferInput::capture(['entry'=>$transferEntry,'order'=>$transferOrder,'operation'=>$_POST['operation']??$transferPostedDraft['input']['operation'],'data'=>$_POST['data']??$transferPostedDraft['input']['data']]);$review=$transferPostedDraft['review'];
    if($intent==='current'){
        $newReview=$transferRequests->prepare($input);$pending=$transferPostedDraft['pending'];$originalKey=$transferPostedDraft['key'];$transferPostedDraft=TransferDraft::replace($transferPostedDraft,$input,!$pending);if($pending)$transferPostedDraft['key']=$originalKey;$transferPostedDraft['pending']=false;$transferPostedDraft['review']=$newReview;TransferDraft::store($_SESSION,$transferPostedDraft);return;
    }
    if($intent==='preview'||$transferPostedDraft['input']!==$input)$transferPostedDraft=TransferDraft::replace($transferPostedDraft,$input);TransferDraft::store($_SESSION,$transferPostedDraft);
    if($intent==='preview'){$transferPostedDraft['review']=$transferRequests->prepare($input);TransferDraft::store($_SESSION,$transferPostedDraft);return;}
    if(!is_array($review)||$review['input']!==$input)throw new DomainException('Geänderte ganze Überweisungsangaben zuerst erneut prüfen.',422);
    if(($_POST['transfer_approved']??null)!=='1')throw new DomainException('Genau diese geprüfte Überweisungsentscheidung ausdrücklich bestätigen.',422);
    if(!is_string($_POST['transfer_key']??null)||!hash_equals($transferPostedDraft['key'],$_POST['transfer_key']))throw new DomainException('Der Überweisungsentwurf wurde in einem anderen Tab geändert. Ganze Angaben erneut öffnen.',409);
    $transferPostedDraft['pending']=true;TransferDraft::store($_SESSION,$transferPostedDraft);$transferStarted=true;$first=$transferRequests->accept($input,$transferPostedDraft['key'],$review['hash'],true);TransferDraft::store($_SESSION,TransferDraft::complete($transferPostedDraft,$first['key']));redirectTo($transferUrl(['transfer_order'=>$first['result']['order']['id'],'transfer_receipt'=>$first['key']]));
}catch(DomainException $failure){
    if($transferPost||$transferLegacyPost||$transferPaymentPost)$transferHandled=true;http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=$failure->getMessage();
    if($transferPaymentPost)$error=match($failure->getCode()){
        404=>'Zahlung nicht mehr verfügbar. Bitte die Rechnung erneut öffnen.',
        409=>'Die Zahlung wurde inzwischen geändert. Bitte den aktuellen Zahlungsstand prüfen.',
        default=>$error,
    };
    if($transferPostedDraft!==null){if($transferStarted){$transferPostedDraft['pending']=false;$transferPostedDraft['review']=null;}if($failure->getCode()===409)$transferPostedDraft['conflicted']=true;TransferDraft::store($_SESSION,$transferPostedDraft);}
}catch(Throwable){if($transferPost||$transferLegacyPost||$transferPaymentPost)$transferHandled=true;http_response_code(503);$error=$transferPaymentPost?'Zahlung konnte nicht geöffnet werden. Bitte den aktuellen Zahlungsstand laden.':($transferStarted?'Überweisungsausgang nicht bestätigt. Die ursprüngliche persönliche Entscheidung zuerst lesend prüfen.':'Überweisung konnte nicht geprüft werden. Ganze Angaben bleiben ungespeichert.');}
if(isset($_GET['transfer_original'])||isset($_GET['transfer_xml'])||isset($_GET['transfer_order_xml'])){
    try{$messages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\TransferMessages::text($error,$messages);}catch(Throwable){}
    header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');if($transferMethod!=='HEAD')echo $error;exit;
}
