<?php declare(strict_types=1);
use Kulturhus\Verein\Finance\Contributions\{ContributionRunInput,ContributionRunDraft,ContributionRunRequests};

$contributionrunHandled=false;$contributionrunPostedDraft=null;$contributionrunRequests=null;$contributionrunStarted=false;
$contributionrunMethod=$_SERVER['REQUEST_METHOD']??'GET';$contributionrunRoute=($nativeManagerEnabled??false)&&isset($nativeAccess)&&$view==='finance'&&($_GET['tab']??null)==='contribution-mails'&&!isset($_GET['contributionmail_job']);
$contributionrunLegacy=($nativeManagerEnabled??false)&&$contributionrunMethod==='POST'&&($_POST['action']??null)==='contribution-run';$contributionrunPost=($nativeManagerEnabled??false)&&$contributionrunMethod==='POST'&&(($_POST['action']??null)==='contribution-run-native'||$contributionrunLegacy);
if(!$contributionrunRoute&&!$contributionrunPost)return;if($contributionrunMethod==='POST'&&!$contributionrunPost)return;
$contributionrunUrl=static fn(array$query=[]):array=>['view'=>'finance','tab'=>'contribution-mails']+$query;
try{
    if(!$contributionrunRoute||!$workspaceAllowed)throw new DomainException('Beitragslauf im eigenen Finanzbereich öffnen.',403);
    $contributionrunRequests=new ContributionRunRequests($db,(string)$_SESSION['actor']);$contributionrunActor=$contributionrunRequests->assertAccess();
    if(isset($_GET['contributionrun_original'])){
        if(!in_array($contributionrunMethod,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
        if(!is_string($_GET['contributionrun_original']))throw new DomainException('Eigene ursprüngliche Beitragsbestätigung nicht verfügbar.',404);$first=$contributionrunRequests->receipt($_GET['contributionrun_original']);$bytes=$first['original_json'];
        header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="dvm-contribution-run-original.json"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox; default-src 'none'");header('Content-Length: '.strlen($bytes));if($contributionrunMethod!=='HEAD')echo$bytes;exit;
    }
    if(!$contributionrunPost)return;$contributionrunHandled=true;
    if(!is_string($_POST['csrf']??null)||!hash_equals((string)$_SESSION['csrf'],$_POST['csrf']))throw new DomainException('Formular abgelaufen. Bitte neu laden.',403);
    if($contributionrunLegacy){$contributionrunPostedDraft=ContributionRunDraft::open($_SESSION,$contributionrunActor,['data'=>['day'=>ContributionRunInput::today()]],true);throw new DomainException('Dieses frühere Beitragsformular wurde nicht ausgeführt. Ganzen Lauf zuerst bewusst prüfen.',422);}
    $contributionrunPostedDraft=ContributionRunDraft::get($_SESSION,$contributionrunActor,$_POST['contributionrun_draft']??null);
    try{$first=$contributionrunRequests->receipt($contributionrunPostedDraft['key']);$contributionrunPostedDraft=ContributionRunDraft::complete($contributionrunPostedDraft,$first['key']);ContributionRunDraft::store($_SESSION,$contributionrunPostedDraft);redirectTo($contributionrunUrl(['contributionrun_receipt'=>$first['key']]));}
    catch(DomainException$lookup){if($lookup->getCode()!==404||$contributionrunPostedDraft['accepted']!==null)throw$lookup;}
    $intent=$_POST['contributionrun_intent']??null;if(!is_string($intent)||!in_array($intent,['preview','accept','current','discard'],true))throw new DomainException('Genau eine eigene Beitragslaufaktion auswählen.',422);
    if($contributionrunPostedDraft['pending']&&$intent!=='current')throw new DomainException('Ursprünglichen Beitragsausgang zuerst lesend prüfen.',409);
    if($intent==='discard'){ContributionRunDraft::discard($_SESSION,$contributionrunPostedDraft);redirectTo($contributionrunUrl(['contributionrun_closed'=>'1']));}
    $contributionrunRequests->assertAccess(true);$input=ContributionRunInput::capture(['data'=>$_POST['data']??$contributionrunPostedDraft['input']['data']]);$review=$contributionrunPostedDraft['review'];
    if($intent==='current'){
        $newReview=$contributionrunRequests->prepare($input);$pending=$contributionrunPostedDraft['pending'];$originalKey=$contributionrunPostedDraft['key'];$contributionrunPostedDraft=ContributionRunDraft::replace($contributionrunPostedDraft,$input,!$pending);if($pending)$contributionrunPostedDraft['key']=$originalKey;$contributionrunPostedDraft['pending']=false;$contributionrunPostedDraft['review']=$newReview;ContributionRunDraft::store($_SESSION,$contributionrunPostedDraft);return;
    }
    if($intent==='preview'||$contributionrunPostedDraft['input']!==$input)$contributionrunPostedDraft=ContributionRunDraft::replace($contributionrunPostedDraft,$input);ContributionRunDraft::store($_SESSION,$contributionrunPostedDraft);
    if($intent==='preview'){$contributionrunPostedDraft['review']=$contributionrunRequests->prepare($input);ContributionRunDraft::store($_SESSION,$contributionrunPostedDraft);return;}
    if(!is_array($review)||$review['input']!==$input)throw new DomainException('Geänderten ganzen Beitragsstichtag zuerst erneut prüfen.',422);
    if(($_POST['contributionrun_approved']??null)!=='1')throw new DomainException('Genau diesen geprüften Beitragslauf ausdrücklich vorbereiten.',422);
    if(!is_string($_POST['contributionrun_key']??null)||!hash_equals($contributionrunPostedDraft['key'],$_POST['contributionrun_key']))throw new DomainException('Der Beitragsentwurf wurde in einem anderen Tab geändert. Ganze Angaben erneut öffnen.',409);
    $contributionrunPostedDraft['pending']=true;ContributionRunDraft::store($_SESSION,$contributionrunPostedDraft);$contributionrunStarted=true;$first=$contributionrunRequests->accept($input,$contributionrunPostedDraft['key'],$review['hash'],true);$contributionrunPostedDraft=ContributionRunDraft::complete($contributionrunPostedDraft,$first['key']);ContributionRunDraft::store($_SESSION,$contributionrunPostedDraft);redirectTo($contributionrunUrl(['contributionrun_receipt'=>$first['key']]));
}catch(DomainException$failure){
    if($contributionrunPost)$contributionrunHandled=true;http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=$failure->getMessage();
    if($contributionrunPostedDraft!==null){if($contributionrunStarted){$contributionrunPostedDraft['pending']=false;$contributionrunPostedDraft['review']=null;}if($failure->getCode()===409)$contributionrunPostedDraft['conflicted']=true;ContributionRunDraft::store($_SESSION,$contributionrunPostedDraft);}
}catch(Throwable){if($contributionrunPost)$contributionrunHandled=true;http_response_code(503);$error=$contributionrunStarted?'Beitragsausgang nicht bestätigt. Die ursprüngliche persönliche Bestätigung zuerst lesend prüfen.':'Beitragslauf konnte nicht geprüft werden. Ganze Angaben bleiben ungespeichert.';}
if(isset($_GET['contributionrun_original'])){
    try{$messages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\ContributionMessages::text($error,$messages);}catch(Throwable){}
    header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');if($contributionrunMethod!=='HEAD')echo$error;exit;
}
