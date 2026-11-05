<?php declare(strict_types=1);
use Kulturhus\Verein\Finance\Banking\NativeSepa\{SepaInput,SepaDraft,SepaRequests};

$sepaHandled=false;$sepaPostedDraft=null;$sepaRequests=null;$sepaStarted=false;
$sepaMethod=$_SERVER['REQUEST_METHOD']??'GET';$sepaRoute=($nativeManagerEnabled??false)&&isset($nativeAccess)&&$view==='finance'&&($_GET['tab']??null)==='debit';
$sepaDetail=$sepaRoute&&isset($_GET['sepa_order']);$sepaOrder=is_string($_GET['sepa_order']??null)?$_GET['sepa_order']:'';
$sepaPost=($nativeManagerEnabled??false)&&$sepaMethod==='POST'&&($_POST['action']??null)==='sepa-native';
$sepaLegacyPost=($nativeManagerEnabled??false)&&$sepaMethod==='POST'&&is_string($_POST['action']??null)&&str_starts_with($_POST['action'],'sepa-order-');
if(!$sepaRoute&&!$sepaLegacyPost&&!$sepaPost&&!isset($_GET['sepa_original'])&&!isset($_GET['sepa_xml'])&&!isset($_GET['sepa_order_xml']))return;
if($sepaMethod==='POST'&&!$sepaPost&&!$sepaLegacyPost&&!isset($_GET['sepa_original'])&&!isset($_GET['sepa_xml'])&&!isset($_GET['sepa_order_xml']))return;
$sepaCatalogueContext=[];foreach(['sepa_q','sepa_state','sepa_page','sepa_manual']as$control)if(is_string($_GET[$control]??null))$sepaCatalogueContext[$control]=$_GET[$control];
$sepaUrl=static fn(array $query=[]):array=>['view'=>'finance','tab'=>'debit']+($sepaOrder!==''?['sepa_order'=>$sepaOrder]:[])+$query+$sepaCatalogueContext;
try{
    if(!$sepaRoute||!$workspaceAllowed||(isset($_GET['sepa_order'])&&$sepaOrder===''))throw new DomainException('Lastschriftauftrag im eigenen Finanzbereich öffnen.',403);
    $sepaRequests=new SepaRequests($db,(string)$_SESSION['actor']);$sepaActor=$sepaRequests->assertAccess();
    if(isset($_GET['sepa_original'])||isset($_GET['sepa_xml'])||isset($_GET['sepa_order_xml'])){
        if(!in_array($sepaMethod,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');exit;}
        $archived=isset($_GET['sepa_order_xml']);$xmlDownload=$archived||isset($_GET['sepa_xml']);$download=$archived?$_GET['sepa_order_xml']:($xmlDownload?$_GET['sepa_xml']:$_GET['sepa_original']);if(!is_string($download))throw new DomainException('Eigene ursprüngliche Lastschriftentscheidung nicht verfügbar.',404);
        if($archived){if($sepaOrder===''||$download!==$sepaOrder)throw new DomainException('Ursprüngliche exportierte Bankdatei nicht verfügbar.',404);$bytes=$sepaRequests->orderXml($download);}
        else{$first=$sepaRequests->receipt($download);if($sepaOrder!==''&&$first['result']['order']['id']!==$sepaOrder)throw new DomainException('Eigene ursprüngliche Lastschriftentscheidung nicht verfügbar.',404);$bytes=$xmlDownload?$sepaRequests->xml($download):$first['original_json'];}
        header('Content-Type: '.($xmlDownload?'application/xml':'application/json').'; charset=utf-8');header('Content-Disposition: attachment; filename="'.($xmlDownload?'dvm-sepa-original.xml':'dvm-sepa-order-original.json').'"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox; default-src 'none'");header('Content-Length: '.strlen($bytes));if($sepaMethod!=='HEAD')echo $bytes;exit;
    }
    if($sepaLegacyPost){$sepaHandled=true;throw new DomainException('Frühere Lastschriftformulare erneut im aktuellen Lastschriftbereich öffnen und bewusst prüfen.',409);}
    if(!$sepaPost)return;$sepaHandled=true;
    if(!is_string($_POST['csrf']??null)||!hash_equals((string)$_SESSION['csrf'],$_POST['csrf']))throw new DomainException('Formular abgelaufen. Bitte neu laden.',403);
    $sepaPostedDraft=SepaDraft::get($_SESSION,$sepaActor,$sepaOrder,$_POST['sepa_draft']??null);
    try{$first=$sepaRequests->receipt($sepaPostedDraft['key']);if($sepaOrder!==''&&$first['result']['order']['id']!==$sepaOrder)throw new DomainException('Eigene ursprüngliche Lastschriftentscheidung nicht verfügbar.',404);SepaDraft::store($_SESSION,SepaDraft::complete($sepaPostedDraft,$first['key']));redirectTo($sepaUrl(['sepa_order'=>$first['result']['order']['id'],'sepa_receipt'=>$first['key']]));}
    catch(DomainException $lookup){if($lookup->getCode()!==404||$sepaPostedDraft['accepted']!==null)throw $lookup;}
    $intent=$_POST['sepa_intent']??(($_GET['sepa_selection']??null)==='1'?'selection':null);if(!is_string($intent)||!in_array($intent,['preview','accept','current','discard','selection','clear-funding','clear-financials'],true))throw new DomainException('Genau eine eigene Lastschriftaktion auswählen.',422);
    if($sepaPostedDraft['pending']&&$intent!=='current')throw new DomainException('Ursprünglichen Lastschriftausgang zuerst lesend prüfen.',409);
    if(!is_string($_POST['sepa_revision']??null)||!hash_equals(SepaDraft::revision($sepaPostedDraft),$_POST['sepa_revision']))throw new DomainException('Der Lastschriftentwurf wurde in einem anderen Tab geändert. Ganze Angaben erneut öffnen.',409);
    if($intent==='discard'){SepaDraft::discard($_SESSION,$sepaPostedDraft);redirectTo($sepaUrl(['sepa_closed'=>'1']));}
    $sepaRequests->assertAuthority();$postedData=is_array($_POST['data']??null)?array_replace(['entries'=>[]],$_POST['data']):($_POST['data']??$sepaPostedDraft['input']['data']);$returnChoices=$_POST['sepa_return_allocations']??[];if(is_array($postedData)){if($intent==='clear-funding')unset($postedData['return_allocations']);elseif($returnChoices!==[]||array_key_exists('return_allocations',$sepaPostedDraft['input']['data']))$postedData['return_allocations']=$returnChoices;}if(is_array($postedData)){if($intent==='clear-financials')unset($postedData['return_financials']);elseif(($_POST['sepa_return_financials']??[])!==[]||array_key_exists('return_financials',$sepaPostedDraft['input']['data']))$postedData['return_financials']=$_POST['sepa_return_financials']??[];}
    $input=SepaInput::capture(['order'=>$sepaOrder,'operation'=>$_POST['operation']??$sepaPostedDraft['input']['operation'],'data'=>$postedData]);$review=$sepaPostedDraft['review'];
    if(in_array($intent,['selection','clear-funding','clear-financials'],true)){
        if($sepaOrder!==''){$sepaPostedDraft=SepaDraft::replace($sepaPostedDraft,$input);SepaDraft::store($_SESSION,$sepaPostedDraft);return;}
        if($sepaOrder!==''||$input['operation']!=='prepare')throw new DomainException('Rechnungsauswahl im eigenen neuen Lastschriftentwurf öffnen.',422);
        $sepaPostedDraft=SepaDraft::replace($sepaPostedDraft,$input);SepaDraft::store($_SESSION,$sepaPostedDraft);$move=$_POST['sepa_candidate_move']??'';if(!is_string($move)||!in_array($move,['','clear','all'],true))throw new DomainException('Ganze Rechnungsauswahl prüfen.',422);
        if($move==='clear')$input['data']['entries']=[];
        elseif($move==='all'){$page=$sepaRequests->candidates(['query'=>$_POST['sepa_candidate_q']??'','page'=>$_POST['sepa_candidate_page']??'1']);if($page['error']!=='')throw new DomainException($page['error'],422);foreach($page['rows']as$row)if(in_array($row['payment_state'],['open','partial'],true)&&(int)$row['remaining_cents']>0)$input['data']['entries'][]=$row['id'];$input['data']['entries']=array_values(array_unique($input['data']['entries']));}
        $sepaPostedDraft=SepaDraft::replace($sepaPostedDraft,$input);SepaDraft::store($_SESSION,$sepaPostedDraft);return;
    }
    if($intent==='current'){
        $newReview=$sepaRequests->prepare($input);$pending=$sepaPostedDraft['pending'];$originalKey=$sepaPostedDraft['key'];$sepaPostedDraft=SepaDraft::replace($sepaPostedDraft,$input,!$pending);if($pending)$sepaPostedDraft['key']=$originalKey;$sepaPostedDraft['pending']=false;$sepaPostedDraft['review']=$newReview;SepaDraft::store($_SESSION,$sepaPostedDraft);return;
    }
    if($intent==='preview'||$sepaPostedDraft['input']!==$input)$sepaPostedDraft=SepaDraft::replace($sepaPostedDraft,$input);SepaDraft::store($_SESSION,$sepaPostedDraft);
    if($intent==='preview'){$sepaPostedDraft['review']=$sepaRequests->prepare($input);SepaDraft::store($_SESSION,$sepaPostedDraft);return;}
    if(!is_array($review)||$review['input']!==$input)throw new DomainException('Geänderte ganze Lastschriftangaben zuerst erneut prüfen.',422);
    if(($_POST['sepa_approved']??null)!=='1')throw new DomainException('Genau diese geprüfte Lastschriftentscheidung ausdrücklich bestätigen.',422);
    if(!is_string($_POST['sepa_key']??null)||!hash_equals($sepaPostedDraft['key'],$_POST['sepa_key']))throw new DomainException('Der Lastschriftentwurf wurde in einem anderen Tab geändert. Ganze Angaben erneut öffnen.',409);
    $sepaPostedDraft['pending']=true;SepaDraft::store($_SESSION,$sepaPostedDraft);$sepaStarted=true;$first=$sepaRequests->accept($input,$sepaPostedDraft['key'],$review['hash'],true);SepaDraft::store($_SESSION,SepaDraft::complete($sepaPostedDraft,$first['key']));redirectTo($sepaUrl(['sepa_order'=>$first['result']['order']['id'],'sepa_receipt'=>$first['key']]));
}catch(DomainException $failure){
    if($sepaPost||$sepaLegacyPost)$sepaHandled=true;http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=$failure->getMessage();
    if($sepaPostedDraft!==null){if($sepaStarted){$sepaPostedDraft['pending']=false;$sepaPostedDraft['review']=null;}if($failure->getCode()===409)$sepaPostedDraft['conflicted']=true;SepaDraft::store($_SESSION,$sepaPostedDraft);}
}catch(Throwable){if($sepaPost||$sepaLegacyPost)$sepaHandled=true;http_response_code(503);$error=$sepaStarted?'Lastschriftausgang nicht bestätigt. Die ursprüngliche persönliche Entscheidung zuerst lesend prüfen.':'Lastschrift konnte nicht geprüft werden. Ganze Angaben bleiben ungespeichert.';}
if(isset($_GET['sepa_original'])||isset($_GET['sepa_xml'])||isset($_GET['sepa_order_xml'])){
    try{$messages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\SepaMessages::text($error,$messages);}catch(Throwable){}
    header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');if($sepaMethod!=='HEAD')echo $error;exit;
}
