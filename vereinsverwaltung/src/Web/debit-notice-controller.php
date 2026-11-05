<?php declare(strict_types=1);
use Kulturhus\Verein\Finance\Banking\Notice\{DebitDraft, DebitInput, DebitRequests};

$debitHandled=false;$debitPostedDraft=null;$debitRequests=null;$debitStarted=false;
$debitMethod=$_SERVER['REQUEST_METHOD']??'GET';
$debitEntry=is_string($_GET['debit_entry']??null)?$_GET['debit_entry']:'';
$debitDocument=is_string($_GET['doc']??null)?$_GET['doc']:'';
$debitRoute=($nativeManagerEnabled??false)&&isset($nativeAccess)&&$view==='documents'&&$debitEntry!==''&&$debitDocument!=='';
$debitPost=($nativeManagerEnabled??false)&&$debitMethod==='POST'&&($_POST['action']??null)==='debit-notice-native';
$debitDownload=isset($_GET['debit_original'])||isset($_GET['debit_pdf'])||isset($_GET['debit_preview_pdf']);
if(!$debitRoute&&!$debitPost&&!$debitDownload)return;
if($debitMethod==='POST'&&!$debitPost&&!$debitDownload)return;
$debitHistoryContext=[];
foreach(['debit_history_q','debit_history_page']as$control)if(is_string($_GET[$control]??null))$debitHistoryContext[$control]=$_GET[$control];
$debitUrl=static fn(array $query=[]):array=>['view'=>'documents','doc'=>$debitDocument,'debit_entry'=>$debitEntry]+$query+$debitHistoryContext;
try{
    if(!$debitRoute||!$workspaceAllowed)throw new DomainException('Lastschriftvorankündigung am eigenen ursprünglichen Finanzdokument öffnen.',403);
    $debitRequests=new DebitRequests($db,(string)$_SESSION['actor']);$debitActor=$debitRequests->assertAccess();
    if($debitRequests->parentDocument($debitEntry)!==$debitDocument)throw new DomainException('Ursprüngliche Rechnung dieser Vorankündigung nicht verfügbar.',404);
    if($debitDownload){
        if(!in_array($debitMethod,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: private, no-store');exit;}
        if(isset($_GET['debit_preview_pdf'])){
            $draft=DebitDraft::get($_SESSION,$debitActor,$debitEntry,$_GET['debit_draft']??null);
            if(!is_string($_GET['debit_preview_pdf'])||!hash_equals($draft['key'],$_GET['debit_preview_pdf'])||!is_array($draft['review'])||$draft['pending']||$draft['accepted']!==null)throw new DomainException('Eigene unverbindliche Vorankündigungsvorschau erneut prüfen.',409);
            $bytes=$debitRequests->reviewedPreview($draft['input'],$draft['review']['hash'])['pdf'];$pdfDownload=true;$filename='dvm-lastschriftvorankuendigung-vorschau.pdf';
        }else{
            $pdfDownload=isset($_GET['debit_pdf']);$key=$pdfDownload?$_GET['debit_pdf']:$_GET['debit_original'];
            if(!is_string($key))throw new DomainException('Eigene ursprüngliche Vorankündigungsbestätigung nicht verfügbar.',404);
            $first=$debitRequests->receipt($key);
            if($first['input']['entry']!==$debitEntry||$first['result']['source_document']!==$debitDocument)throw new DomainException('Eigene ursprüngliche Vorankündigungsbestätigung nicht verfügbar.',404);
            $bytes=$pdfDownload?$debitRequests->pdf($key):$first['original_json'];$filename=$pdfDownload?$first['result']['filename']:'dvm-lastschriftvorankuendigung-original.json';
        }
        header('Content-Type: '.($pdfDownload?'application/pdf':'application/json; charset=utf-8'));
        header('Content-Disposition: '.(isset($_GET['debit_preview_pdf'])?'inline':'attachment').'; filename="'.$filename.'"');
        header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox; default-src 'none'");header('Content-Length: '.strlen($bytes));
        if($debitMethod!=='HEAD')echo $bytes;exit;
    }
    if(!$debitPost)return;$debitHandled=true;
    if(!is_string($_POST['csrf']??null)||!hash_equals((string)$_SESSION['csrf'],$_POST['csrf']))throw new DomainException('Formular abgelaufen. Bitte neu laden.',403);
    $debitPostedDraft=DebitDraft::get($_SESSION,$debitActor,$debitEntry,$_POST['debit_draft']??null);
    try{
        $first=$debitRequests->receipt($debitPostedDraft['key']);
        if($first['input']['entry']!==$debitEntry||$first['result']['source_document']!==$debitDocument)throw new DomainException('Eigene ursprüngliche Vorankündigungsbestätigung nicht verfügbar.',404);
        DebitDraft::store($_SESSION,DebitDraft::complete($debitPostedDraft,$first['key']));redirectTo($debitUrl(['debit_first'=>$first['key']]));
    }catch(DomainException $lookup){if($lookup->getCode()!==404||$debitPostedDraft['accepted']!==null)throw $lookup;}
    $intent=$_POST['debit_intent']??null;
    if(!is_string($intent)||!in_array($intent,['preview','accept','current','discard'],true))throw new DomainException('Genau eine eigene Vorankündigungsaktion auswählen.',422);
    if($debitPostedDraft['pending']&&$intent!=='current')throw new DomainException('Ursprünglichen Vorankündigungsausgang zuerst lesend prüfen.',409);
    if(!is_string($_POST['debit_revision']??null)||!hash_equals(DebitDraft::revision($debitPostedDraft),$_POST['debit_revision']))throw new DomainException('Der Vorankündigungsentwurf wurde in einem anderen Tab geändert. Ganze Angaben erneut öffnen.',409);
    if($intent==='discard'){DebitDraft::discard($_SESSION,$debitPostedDraft);redirectTo($debitUrl(['debit_closed'=>'1']));}
    $debitRequests->assertAccess(true);
    $input=DebitInput::capture(['entry'=>$debitEntry,'day'=>$_POST['day']??$debitPostedDraft['input']['day'],
        'collection'=>$_POST['collection']??$debitPostedDraft['input']['collection'], 'text'=>$_POST['text']??$debitPostedDraft['input']['text'],'reason'=>$_POST['reason']??$debitPostedDraft['input']['reason']]);
    $review=$debitPostedDraft['review'];
    if($intent==='current'){
        $newReview=$debitRequests->prepare($input);$pending=$debitPostedDraft['pending'];$oldKey=$debitPostedDraft['key'];
        $debitPostedDraft=DebitDraft::replace($debitPostedDraft,$input,!$pending);if($pending)$debitPostedDraft['key']=$oldKey;
        $debitPostedDraft['pending']=false;$debitPostedDraft['review']=$newReview;DebitDraft::store($_SESSION,$debitPostedDraft);return;
    }
    if($intent==='preview'||$debitPostedDraft['input']!==$input)$debitPostedDraft=DebitDraft::replace($debitPostedDraft,$input);
    DebitDraft::store($_SESSION,$debitPostedDraft);
    if($intent==='preview'){$debitPostedDraft['review']=$debitRequests->prepare($input);DebitDraft::store($_SESSION,$debitPostedDraft);return;}
    if(!is_array($review)||$review['input']!==$input)throw new DomainException('Geänderte ganze Briefangaben zuerst erneut prüfen.',422);
    if(($_POST['debit_approved']??null)!=='1')throw new DomainException('Genau diese vollständig geprüfte Lastschriftvorankündigung ausdrücklich bestätigen.',422);
    if(!is_string($_POST['debit_key']??null)||!hash_equals($debitPostedDraft['key'],$_POST['debit_key']))throw new DomainException('Der Vorankündigungsentwurf wurde in einem anderen Tab geändert. Ganze Angaben erneut öffnen.',409);
    $debitPostedDraft['pending']=true;DebitDraft::store($_SESSION,$debitPostedDraft);$debitStarted=true;
    $first=$debitRequests->accept($input,$debitPostedDraft['key'],$review['hash'],true);
    DebitDraft::store($_SESSION,DebitDraft::complete($debitPostedDraft,$first['key']));redirectTo($debitUrl(['debit_first'=>$first['key']]));
}catch(DomainException $failure){
    if($debitPost)$debitHandled=true;http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=$failure->getMessage();
    if($debitPostedDraft!==null){if($debitStarted){$debitPostedDraft['pending']=false;$debitPostedDraft['review']=null;}if($failure->getCode()===409)$debitPostedDraft['conflicted']=true;DebitDraft::store($_SESSION,$debitPostedDraft);}
}catch(Throwable){
    if($debitPost)$debitHandled=true;http_response_code(503);$error=$debitStarted?'Vorankündigungsausgang nicht bestätigt. Die ursprüngliche persönliche Entscheidung zuerst lesend prüfen.':'Lastschriftvorankündigung konnte nicht geprüft werden. Ganze Angaben bleiben ungespeichert.';
}
if($debitDownload){
    try{$messages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\DebitNoticeMessages::text($error,$messages);}catch(Throwable){}
    header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');if($debitMethod!=='HEAD')echo $error;exit;
}
