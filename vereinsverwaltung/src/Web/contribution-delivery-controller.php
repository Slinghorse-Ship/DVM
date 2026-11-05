<?php declare(strict_types=1);
use Kulturhus\Verein\Finance\Contributions\{ContributionDeliveryInput,ContributionDeliveryDraft,ContributionDeliveryRequests};

$contributiondeliveryHandled=false;$contributiondeliveryPostedDraft=null;$contributiondeliveryRequests=null;$contributiondeliveryStarted=false;
$contributiondeliveryMethod=$_SERVER['REQUEST_METHOD']??'GET';$contributiondeliveryRoute=($nativeManagerEnabled??false)&&isset($nativeAccess)&&$view==='finance'&&($_GET['tab']??null)==='contribution-mails';
$contributiondeliveryDetail=$contributiondeliveryRoute&&isset($_GET['contributionmail_job']);$contributiondeliveryJob=is_string($_GET['contributionmail_job']??null)?$_GET['contributionmail_job']:'';
$contributiondeliveryPost=($nativeManagerEnabled??false)&&$contributiondeliveryMethod==='POST'&&($_POST['action']??null)==='contribution-delivery-native';
if(!$contributiondeliveryDetail&&!$contributiondeliveryPost&&!isset($_GET['contributionmail_original']))return;
if($contributiondeliveryMethod==='POST'&&!$contributiondeliveryPost&&!isset($_GET['contributionmail_original']))return;
$contributiondeliveryUrl=static fn(array $query=[]):array=>['view'=>'finance','tab'=>'contribution-mails','contributionmail_job'=>$contributiondeliveryJob]+$query;
try{
    if(!$contributiondeliveryDetail||!$workspaceAllowed||$contributiondeliveryJob==='')throw new DomainException('Beitragsmailauftrag im eigenen Finanzbereich öffnen.',403);
    $contributiondeliveryRequests=new ContributionDeliveryRequests($db,(string)$_SESSION['actor']);$contributiondeliveryActor=$contributiondeliveryRequests->assertAccess();
    if(isset($_GET['contributionmail_original'])){
        if(!in_array($contributiondeliveryMethod,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');exit;}
        if(!is_string($_GET['contributionmail_original']))throw new DomainException('Eigene ursprüngliche Beitragsmailentscheidung nicht verfügbar.',404);$first=$contributiondeliveryRequests->receipt($_GET['contributionmail_original']);if($first['input']['job']!==$contributiondeliveryJob)throw new DomainException('Eigene ursprüngliche Beitragsmailentscheidung nicht verfügbar.',404);$bytes=$first['original_json'];
        header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="dvm-contribution-delivery-original.json"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox; default-src 'none'");header('Content-Length: '.strlen($bytes));if($contributiondeliveryMethod!=='HEAD')echo $bytes;exit;
    }
    if(!$contributiondeliveryPost)return;$contributiondeliveryHandled=true;
    if(!is_string($_POST['csrf']??null)||!hash_equals((string)$_SESSION['csrf'],$_POST['csrf']))throw new DomainException('Formular abgelaufen. Bitte neu laden.',403);
    $contributiondeliveryPostedDraft=ContributionDeliveryDraft::get($_SESSION,$contributiondeliveryActor,$contributiondeliveryJob,$_POST['contributionmail_draft']??null);
    try{$first=$contributiondeliveryRequests->receipt($contributiondeliveryPostedDraft['key']);if($first['input']['job']!==$contributiondeliveryJob)throw new DomainException('Eigene ursprüngliche Beitragsmailentscheidung nicht verfügbar.',404);ContributionDeliveryDraft::store($_SESSION,ContributionDeliveryDraft::complete($contributiondeliveryPostedDraft,$first['key']));redirectTo($contributiondeliveryUrl(['contributionmail_receipt'=>$first['key']]));}
    catch(DomainException $lookup){if($lookup->getCode()!==404||$contributiondeliveryPostedDraft['accepted']!==null)throw $lookup;}
    $intent=$_POST['contributionmail_intent']??null;if(!is_string($intent)||!in_array($intent,['preview','accept','current','discard'],true))throw new DomainException('Genau eine eigene Beitragsmailaktion auswählen.',422);
    if($contributiondeliveryPostedDraft['pending']&&$intent!=='current')throw new DomainException('Ursprünglichen Beitragsmailausgang zuerst lesend prüfen.',409);
    if($intent==='discard'){ContributionDeliveryDraft::discard($_SESSION,$contributiondeliveryPostedDraft);redirectTo($contributiondeliveryUrl(['contributionmail_closed'=>'1']));}
    $contributiondeliveryRequests->assertAccess(true);$input=ContributionDeliveryInput::capture(['job'=>$contributiondeliveryJob,'operation'=>$_POST['operation']??$contributiondeliveryPostedDraft['input']['operation'],'data'=>$_POST['data']??$contributiondeliveryPostedDraft['input']['data']]);$review=$contributiondeliveryPostedDraft['review'];
    if($intent==='current'){
        $newReview=$contributiondeliveryRequests->prepare($input);$pending=$contributiondeliveryPostedDraft['pending'];$originalKey=$contributiondeliveryPostedDraft['key'];$contributiondeliveryPostedDraft=ContributionDeliveryDraft::replace($contributiondeliveryPostedDraft,$input,!$pending);if($pending)$contributiondeliveryPostedDraft['key']=$originalKey;$contributiondeliveryPostedDraft['pending']=false;$contributiondeliveryPostedDraft['review']=$newReview;ContributionDeliveryDraft::store($_SESSION,$contributiondeliveryPostedDraft);return;
    }
    if($intent==='preview'||$contributiondeliveryPostedDraft['input']!==$input)$contributiondeliveryPostedDraft=ContributionDeliveryDraft::replace($contributiondeliveryPostedDraft,$input);ContributionDeliveryDraft::store($_SESSION,$contributiondeliveryPostedDraft);
    if($intent==='preview'){$contributiondeliveryPostedDraft['review']=$contributiondeliveryRequests->prepare($input);ContributionDeliveryDraft::store($_SESSION,$contributiondeliveryPostedDraft);return;}
    if(!is_array($review)||$review['input']!==$input)throw new DomainException('Geänderte ganze Beitragsmailangaben zuerst erneut prüfen.',422);
    if(($_POST['contributionmail_approved']??null)!=='1')throw new DomainException('Genau diese geprüfte Beitragsmailentscheidung ausdrücklich bestätigen.',422);
    if(!is_string($_POST['contributionmail_key']??null)||!hash_equals($contributiondeliveryPostedDraft['key'],$_POST['contributionmail_key']))throw new DomainException('Der Beitragsmailentwurf wurde in einem anderen Tab geändert. Ganze Angaben erneut öffnen.',409);
    $contributiondeliveryPostedDraft['pending']=true;ContributionDeliveryDraft::store($_SESSION,$contributiondeliveryPostedDraft);$contributiondeliveryStarted=true;$first=$contributiondeliveryRequests->accept($input,$contributiondeliveryPostedDraft['key'],$review['hash'],true);ContributionDeliveryDraft::store($_SESSION,ContributionDeliveryDraft::complete($contributiondeliveryPostedDraft,$first['key']));redirectTo($contributiondeliveryUrl(['contributionmail_receipt'=>$first['key']]));
}catch(DomainException $failure){
    if($contributiondeliveryPost)$contributiondeliveryHandled=true;http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=$failure->getMessage();
    if($contributiondeliveryPostedDraft!==null){if($contributiondeliveryStarted){$contributiondeliveryPostedDraft['pending']=false;$contributiondeliveryPostedDraft['review']=null;}if($failure->getCode()===409)$contributiondeliveryPostedDraft['conflicted']=true;ContributionDeliveryDraft::store($_SESSION,$contributiondeliveryPostedDraft);}
}catch(Throwable){if($contributiondeliveryPost)$contributiondeliveryHandled=true;http_response_code(503);$error=$contributiondeliveryStarted?'Beitragsmailausgang nicht bestätigt. Die ursprüngliche persönliche Entscheidung zuerst lesend prüfen.':'Beitragsmail konnte nicht geprüft werden. Ganze Angaben bleiben ungespeichert.';}
if(isset($_GET['contributionmail_original'])){
    try{$messages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\ContributionDeliveryMessages::text($error,$messages);}catch(Throwable){}
    header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');if($contributiondeliveryMethod!=='HEAD')echo $error;exit;
}
