<?php declare(strict_types=1);
use Kulturhus\Verein\Finance\Donations\Cover\{CoverDraft, CoverInput, CoverRequests};

$coverHandled=false;$coverPostedDraft=null;$coverRequests=null;$coverStarted=false;
$coverMethod=$_SERVER['REQUEST_METHOD']??'GET';
$coverReceipt=is_string($_GET['cover_receipt']??null)?$_GET['cover_receipt']:'';
$coverDocument=is_string($_GET['doc']??null)?$_GET['doc']:'';
$coverRoute=($nativeManagerEnabled??false)&&isset($nativeAccess)&&$view==='documents'&&$coverReceipt!==''&&$coverDocument!=='';
$coverPost=($nativeManagerEnabled??false)&&$coverMethod==='POST'&&($_POST['action']??null)==='donation-cover-native';
$coverDownload=isset($_GET['cover_original'])||isset($_GET['cover_pdf'])||isset($_GET['cover_preview_pdf']);
if(!$coverRoute&&!$coverPost&&!$coverDownload)return;
if($coverMethod==='POST'&&!$coverPost&&!$coverDownload)return;
$coverHistoryContext=[];
foreach(['cover_history_q','cover_history_page']as$control)if(is_string($_GET[$control]??null))$coverHistoryContext[$control]=$_GET[$control];
$coverUrl=static fn(array $query=[]):array=>['view'=>'documents','doc'=>$coverDocument,'cover_receipt'=>$coverReceipt]+$query+$coverHistoryContext;
try{
    if(!$coverRoute||!$workspaceAllowed)throw new DomainException('Begleitschreiben am eigenen ursprünglichen Finanzdokument öffnen.',403);
    $coverRequests=new CoverRequests($db,(string)$_SESSION['actor']);$coverActor=$coverRequests->assertAccess();
    if($coverRequests->parentDocument($coverReceipt)!==$coverDocument)throw new DomainException('Ursprüngliche Bescheinigung dieses Begleitschreibens nicht verfügbar.',404);
    if($coverDownload){
        if(!in_array($coverMethod,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: private, no-store');exit;}
        if(isset($_GET['cover_preview_pdf'])){
            $draft=CoverDraft::get($_SESSION,$coverActor,$coverReceipt,$_GET['cover_draft']??null);
            if(!is_string($_GET['cover_preview_pdf'])||!hash_equals($draft['key'],$_GET['cover_preview_pdf'])||!is_array($draft['review'])||$draft['pending']||$draft['accepted']!==null)throw new DomainException('Eigene unverbindliche Begleitschreibenvorschau erneut prüfen.',409);
            $bytes=$coverRequests->reviewedPreview($draft['input'],$draft['review']['hash'])['pdf'];$pdfDownload=true;$filename='dvm-begleitschreiben-vorschau.pdf';
        }else{
            $pdfDownload=isset($_GET['cover_pdf']);$key=$pdfDownload?$_GET['cover_pdf']:$_GET['cover_original'];
            if(!is_string($key))throw new DomainException('Eigene ursprüngliche Begleitschreibenbestätigung nicht verfügbar.',404);
            $first=$coverRequests->receipt($key);
            if($first['input']['receipt']!==$coverReceipt||$first['result']['source_document']!==$coverDocument)throw new DomainException('Eigene ursprüngliche Begleitschreibenbestätigung nicht verfügbar.',404);
            $bytes=$pdfDownload?$coverRequests->pdf($key):$first['original_json'];$filename=$pdfDownload?$first['result']['filename']:'dvm-begleitschreiben-original.json';
        }
        header('Content-Type: '.($pdfDownload?'application/pdf':'application/json; charset=utf-8'));
        header('Content-Disposition: '.(isset($_GET['cover_preview_pdf'])?'inline':'attachment').'; filename="'.$filename.'"');
        header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox; default-src 'none'");header('Content-Length: '.strlen($bytes));
        if($coverMethod!=='HEAD')echo $bytes;exit;
    }
    if(!$coverPost)return;$coverHandled=true;
    if(!is_string($_POST['csrf']??null)||!hash_equals((string)$_SESSION['csrf'],$_POST['csrf']))throw new DomainException('Formular abgelaufen. Bitte neu laden.',403);
    $coverPostedDraft=CoverDraft::get($_SESSION,$coverActor,$coverReceipt,$_POST['cover_draft']??null);
    try{
        $first=$coverRequests->receipt($coverPostedDraft['key']);
        if($first['input']['receipt']!==$coverReceipt||$first['result']['source_document']!==$coverDocument)throw new DomainException('Eigene ursprüngliche Begleitschreibenbestätigung nicht verfügbar.',404);
        CoverDraft::store($_SESSION,CoverDraft::complete($coverPostedDraft,$first['key']));redirectTo($coverUrl(['cover_first'=>$first['key']]));
    }catch(DomainException $lookup){if($lookup->getCode()!==404||$coverPostedDraft['accepted']!==null)throw $lookup;}
    $intent=$_POST['cover_intent']??null;
    if(!is_string($intent)||!in_array($intent,['preview','accept','current','discard'],true))throw new DomainException('Genau eine eigene Begleitschreibenaktion auswählen.',422);
    if($coverPostedDraft['pending']&&$intent!=='current')throw new DomainException('Ursprünglichen Begleitschreibenausgang zuerst lesend prüfen.',409);
    if(!is_string($_POST['cover_revision']??null)||!hash_equals(CoverDraft::revision($coverPostedDraft),$_POST['cover_revision']))throw new DomainException('Der Begleitschreibenentwurf wurde in einem anderen Tab geändert. Ganze Angaben erneut öffnen.',409);
    if($intent==='discard'){CoverDraft::discard($_SESSION,$coverPostedDraft);redirectTo($coverUrl(['cover_closed'=>'1']));}
    $coverRequests->assertAccess(true);
    $input=CoverInput::capture(['receipt'=>$coverReceipt,'day'=>$_POST['day']??$coverPostedDraft['input']['day'],
        'text'=>$_POST['text']??$coverPostedDraft['input']['text'],'reason'=>$_POST['reason']??$coverPostedDraft['input']['reason']]);
    $review=$coverPostedDraft['review'];
    if($intent==='current'){
        $newReview=$coverRequests->prepare($input);$pending=$coverPostedDraft['pending'];$oldKey=$coverPostedDraft['key'];
        $coverPostedDraft=CoverDraft::replace($coverPostedDraft,$input,!$pending);if($pending)$coverPostedDraft['key']=$oldKey;
        $coverPostedDraft['pending']=false;$coverPostedDraft['review']=$newReview;CoverDraft::store($_SESSION,$coverPostedDraft);return;
    }
    if($intent==='preview'||$coverPostedDraft['input']!==$input)$coverPostedDraft=CoverDraft::replace($coverPostedDraft,$input);
    CoverDraft::store($_SESSION,$coverPostedDraft);
    if($intent==='preview'){$coverPostedDraft['review']=$coverRequests->prepare($input);CoverDraft::store($_SESSION,$coverPostedDraft);return;}
    if(!is_array($review)||$review['input']!==$input)throw new DomainException('Geänderte ganze Briefangaben zuerst erneut prüfen.',422);
    if(($_POST['cover_approved']??null)!=='1')throw new DomainException('Genau dieses vollständig geprüfte Begleitschreiben ausdrücklich bestätigen.',422);
    if(!is_string($_POST['cover_key']??null)||!hash_equals($coverPostedDraft['key'],$_POST['cover_key']))throw new DomainException('Der Begleitschreibenentwurf wurde in einem anderen Tab geändert. Ganze Angaben erneut öffnen.',409);
    $coverPostedDraft['pending']=true;CoverDraft::store($_SESSION,$coverPostedDraft);$coverStarted=true;
    $first=$coverRequests->accept($input,$coverPostedDraft['key'],$review['hash'],true);
    CoverDraft::store($_SESSION,CoverDraft::complete($coverPostedDraft,$first['key']));redirectTo($coverUrl(['cover_first'=>$first['key']]));
}catch(DomainException $failure){
    if($coverPost)$coverHandled=true;http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=$failure->getMessage();
    if($coverPostedDraft!==null){if($coverStarted){$coverPostedDraft['pending']=false;$coverPostedDraft['review']=null;}if($failure->getCode()===409)$coverPostedDraft['conflicted']=true;CoverDraft::store($_SESSION,$coverPostedDraft);}
}catch(Throwable){
    if($coverPost)$coverHandled=true;http_response_code(503);$error=$coverStarted?'Begleitschreibenausgang nicht bestätigt. Die ursprüngliche persönliche Entscheidung zuerst lesend prüfen.':'Begleitschreiben konnte nicht geprüft werden. Ganze Angaben bleiben ungespeichert.';
}
if($coverDownload){
    try{$messages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\DonationCoverMessages::text($error,$messages);}catch(Throwable){}
    header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');if($coverMethod!=='HEAD')echo $error;exit;
}
