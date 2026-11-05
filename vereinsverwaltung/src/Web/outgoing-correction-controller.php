<?php declare(strict_types=1);
use Kulturhus\Verein\Finance\Invoices\{OutgoingCorrectionInput,OutgoingCorrectionDraft,OutgoingCorrectionRequests};

$outgoingcorrectionHandled=false;$outgoingcorrectionPostedDraft=null;$outgoingcorrectionRequests=null;$outgoingcorrectionStarted=false;
$outgoingcorrectionMethod=$_SERVER['REQUEST_METHOD']??'GET';$outgoingcorrectionRoute=($nativeManagerEnabled??false)&&isset($nativeAccess)&&$view==='finance'&&($_GET['tab']??null)==='outgoing';
$outgoingcorrectionId=$_GET['invoice']??$_POST['invoice']??'';$outgoingcorrectionLegacy=($nativeManagerEnabled??false)&&$outgoingcorrectionMethod==='POST'&&($_POST['action']??null)==='outgoing-correct';
$outgoingcorrectionPost=($nativeManagerEnabled??false)&&$outgoingcorrectionMethod==='POST'&&(($_POST['action']??null)==='outgoing-correction-native'||$outgoingcorrectionLegacy);
$outgoingcorrectionContext=\Kulturhus\Verein\OutgoingInvoiceCatalogue::nativeContext($_GET);
$outgoingcorrectionUrl=static fn(string$id,array$query=[]):array=>['view'=>'finance','tab'=>'outgoing','invoice'=>$id]+$query+$outgoingcorrectionContext;
if(!$outgoingcorrectionRoute&&!$outgoingcorrectionPost)return;
if($outgoingcorrectionMethod==='POST'&&!$outgoingcorrectionPost)return;
if($outgoingcorrectionId===''&&!$outgoingcorrectionPost&&!isset($_GET['outgoingcorrection_original'])&&!isset($_GET['outgoingcorrection_pdf']))return;
try{
    if(!$outgoingcorrectionRoute||!$workspaceAllowed)throw new DomainException('Ausgangsrechnung im eigenen Finanzbereich öffnen.',403);
    if(!is_string($outgoingcorrectionId))throw new DomainException('Eigene ursprüngliche Rechnung auswählen.',422);OutgoingCorrectionDraft::section($outgoingcorrectionId);
    $outgoingcorrectionRequests=new OutgoingCorrectionRequests($db,(string)$_SESSION['actor']);$outgoingcorrectionActor=$outgoingcorrectionRequests->assertAccess();
    if(isset($_GET['outgoingcorrection_original'])||isset($_GET['outgoingcorrection_pdf'])){
        if(!in_array($outgoingcorrectionMethod,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
        if(isset($_GET['outgoingcorrection_original'])){
            if(!is_string($_GET['outgoingcorrection_original']))throw new DomainException('Eigene ursprüngliche Korrekturbestätigung nicht verfügbar.',404);
            $first=$outgoingcorrectionRequests->receipt($_GET['outgoingcorrection_original']);if($first['input']['invoice']!==$outgoingcorrectionId)throw new DomainException('Eigene ursprüngliche Korrekturbestätigung nicht verfügbar.',404);
            $bytes=$first['original_json'];header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="dvm-outgoing-correction-original.json"');
        }else{
            if($_GET['outgoingcorrection_pdf']==='draft'){
                $draft=OutgoingCorrectionDraft::get($_SESSION,$outgoingcorrectionActor,$outgoingcorrectionId,$_GET['outgoingcorrection_draft']??null);$hash=$_GET['outgoingcorrection_review']??null;
                if(!is_string($hash)||!is_array($draft['review'])||!hash_equals($draft['review']['hash'],$hash))throw new DomainException('Genau den ganzen geprüften Korrekturentwurf erneut öffnen.',409);
                $pdf=$outgoingcorrectionRequests->preview($draft['input'],$hash);
            }elseif($_GET['outgoingcorrection_pdf']==='first'){
                if(!is_string($_GET['outgoingcorrection_receipt']??null))throw new DomainException('Eigene ursprüngliche Korrekturbestätigung nicht verfügbar.',404);
                $first=$outgoingcorrectionRequests->receipt($_GET['outgoingcorrection_receipt']);if($first['input']['invoice']!==$outgoingcorrectionId)throw new DomainException('Eigene ursprüngliche Korrekturbestätigung nicht verfügbar.',404);
                $pdf=(new \Kulturhus\Verein\OutgoingInvoices($db,(string)$_SESSION['actor']))->currentPreview($first['result']['id'],true);
                if($pdf['invoice']['document_id']!==$first['file']['document_id']||!hash_equals($first['file']['sha256'],$pdf['sha256']))throw new DomainException('PDF der ursprünglichen Korrekturbestätigung stimmt nicht mit dem zugänglichen Original überein.',409);
            }else throw new DomainException('Eigene ganze Korrekturvorschau auswählen.',422);
            $bytes=$pdf['bytes'];header('Content-Type: application/pdf');header('Content-Disposition: '.(($_GET['outgoingcorrection_download']??null)==='1'?'attachment':'inline').'; filename="dvm-correction.pdf"');
        }
        header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox; default-src 'none'");header('Content-Length: '.strlen($bytes));if($outgoingcorrectionMethod!=='HEAD')echo$bytes;exit;
    }
    if(!$outgoingcorrectionPost)return;$outgoingcorrectionHandled=true;
    if(!is_string($_POST['csrf']??null)||!hash_equals((string)$_SESSION['csrf'],$_POST['csrf']))throw new DomainException('Formular abgelaufen. Bitte neu laden.',403);
    if(($_POST['invoice']??null)!==$outgoingcorrectionId)throw new DomainException('Eigene ursprüngliche Rechnung auswählen.',422);
    if($outgoingcorrectionLegacy){
        $input=OutgoingCorrectionInput::capture(['invoice'=>$outgoingcorrectionId,'version'=>$_POST['version']??null,'data'=>['kind'=>$_POST['correction_kind']??null,'amount'=>$_POST['amount']??null,'reason'=>$_POST['reason']??null,'date'=>$_POST['correction_date']??null]]);
        $outgoingcorrectionPostedDraft=OutgoingCorrectionDraft::open($_SESSION,$outgoingcorrectionActor,$input,true);$legacyKey=$_POST['request_key']??null;
        if(!is_string($legacyKey)||!preg_match('/\A[a-f0-9]{32}\z/D',$legacyKey))throw new DomainException('Ursprüngliche Kennung des früheren Korrekturformulars prüfen.',422);
        $outgoingcorrectionPostedDraft['legacy_request_key']=$legacyKey;$outgoingcorrectionPostedDraft['key']=$legacyKey;OutgoingCorrectionDraft::store($_SESSION,$outgoingcorrectionPostedDraft);
    }else $outgoingcorrectionPostedDraft=OutgoingCorrectionDraft::get($_SESSION,$outgoingcorrectionActor,$outgoingcorrectionId,$_POST['outgoingcorrection_draft']??null);
    // Resolve the durable key before trusting session flags or accepting another operation.
    try{
        $first=$outgoingcorrectionRequests->receipt($outgoingcorrectionPostedDraft['key']);if($first['input']['invoice']!==$outgoingcorrectionId)throw new DomainException('Eigene ursprüngliche Korrekturbestätigung nicht verfügbar.',404);
        $outgoingcorrectionPostedDraft=OutgoingCorrectionDraft::complete($outgoingcorrectionPostedDraft,$first['key']);OutgoingCorrectionDraft::store($_SESSION,$outgoingcorrectionPostedDraft);redirectTo($outgoingcorrectionUrl($outgoingcorrectionId,['outgoingcorrection_receipt'=>$first['key']]));
    }catch(DomainException$lookupFailure){if($lookupFailure->getCode()!==404||$outgoingcorrectionPostedDraft['accepted']!==null)throw$lookupFailure;}
    if(!$outgoingcorrectionLegacy&&($_POST['outgoingcorrection_intent']??null)==='discard'&&!$outgoingcorrectionPostedDraft['pending']){OutgoingCorrectionDraft::discard($_SESSION,$outgoingcorrectionPostedDraft);redirectTo($outgoingcorrectionUrl($outgoingcorrectionId,['outgoingcorrection_closed'=>'1']));}
    if(isset($outgoingcorrectionPostedDraft['legacy_request_key'])){
        $earlier=$outgoingcorrectionRequests->legacy($outgoingcorrectionPostedDraft['legacy_request_key'],$outgoingcorrectionPostedDraft['input']);
        if($earlier){
            if(!$outgoingcorrectionLegacy&&($_POST['outgoingcorrection_intent']??null)==='discard'){OutgoingCorrectionDraft::discard($_SESSION,$outgoingcorrectionPostedDraft);redirectTo($outgoingcorrectionUrl($outgoingcorrectionId,['outgoingcorrection_closed'=>'1']));}
            $outgoingcorrectionPostedDraft['legacy_result']=$earlier;$outgoingcorrectionPostedDraft['pending']=false;OutgoingCorrectionDraft::store($_SESSION,$outgoingcorrectionPostedDraft);redirectTo($outgoingcorrectionUrl($outgoingcorrectionId,['outgoingcorrection_draft'=>$outgoingcorrectionPostedDraft['id'],'outgoingcorrection_legacy'=>'1']));
        }
    }
    if($outgoingcorrectionLegacy)throw new DomainException('Dieses frühere Korrekturformular wurde nicht ausgeführt. Ganze Angaben und tatsächlichen Stand bewusst erneut prüfen.',422);
    $intent=$_POST['outgoingcorrection_intent']??null;if(!is_string($intent)||!in_array($intent,['preview','accept','current','discard'],true))throw new DomainException('Genau eine eigene Korrekturaktion auswählen.',422);
    if($outgoingcorrectionPostedDraft['pending']&&$intent!=='current')throw new DomainException('Ursprünglichen Korrekturausgang zuerst lesend prüfen.',409);
    if($intent==='discard'){OutgoingCorrectionDraft::discard($_SESSION,$outgoingcorrectionPostedDraft);redirectTo($outgoingcorrectionUrl($outgoingcorrectionId,['outgoingcorrection_closed'=>'1']));}
    $outgoingcorrectionRequests->assertAccess(true);$input=$outgoingcorrectionPostedDraft['input'];
    if($intent==='current'){
        if(array_key_exists('data',$_POST))$input['data']=$_POST['data'];$current=$outgoingcorrectionRequests->current($outgoingcorrectionId);$input['version']=(string)$current['version'];
        $outgoingcorrectionPostedDraft=OutgoingCorrectionDraft::replace($outgoingcorrectionPostedDraft,$input,!isset($outgoingcorrectionPostedDraft['legacy_request_key']));$outgoingcorrectionPostedDraft['pending']=false;$outgoingcorrectionPostedDraft['conflicted']=false;OutgoingCorrectionDraft::store($_SESSION,$outgoingcorrectionPostedDraft);return;
    }
    if(isset($outgoingcorrectionPostedDraft['legacy_result']))throw new DomainException('Dieses frühere Korrekturdokument ist bereits ausgegeben. Tatsächlichen Ausgang öffnen.',409);
    $input=OutgoingCorrectionInput::capture(['invoice'=>$outgoingcorrectionId,'version'=>$_POST['outgoingcorrection_version']??null,'data'=>$_POST['data']??null]);$review=$outgoingcorrectionPostedDraft['review'];
    if($intent==='preview'||$outgoingcorrectionPostedDraft['input']!==$input)$outgoingcorrectionPostedDraft=OutgoingCorrectionDraft::replace($outgoingcorrectionPostedDraft,$input);
    OutgoingCorrectionDraft::store($_SESSION,$outgoingcorrectionPostedDraft);
    if($intent==='preview'){$outgoingcorrectionPostedDraft['review']=$outgoingcorrectionRequests->prepare($input);$outgoingcorrectionPostedDraft['conflicted']=false;OutgoingCorrectionDraft::store($_SESSION,$outgoingcorrectionPostedDraft);return;}
    if(!is_array($review)||$review['input']!==$input)throw new DomainException('Geänderte ganze Korrekturangaben zuerst erneut prüfen.',422);
    if(($_POST['outgoingcorrection_approved']??null)!=='1')throw new DomainException('Genau diesen ganzen geprüften Korrekturentwurf ausdrücklich ausgeben.',422);
    if(!is_string($_POST['outgoingcorrection_key']??null)||!hash_equals($outgoingcorrectionPostedDraft['key'],$_POST['outgoingcorrection_key']))throw new DomainException('Der Korrekturentwurf wurde in einem anderen Tab geändert. Ganze Angaben erneut öffnen.',409);
    $outgoingcorrectionPostedDraft['pending']=true;OutgoingCorrectionDraft::store($_SESSION,$outgoingcorrectionPostedDraft);$outgoingcorrectionStarted=true;
    $first=$outgoingcorrectionRequests->accept($input,$outgoingcorrectionPostedDraft['key'],$review['hash'],true);$outgoingcorrectionPostedDraft=OutgoingCorrectionDraft::complete($outgoingcorrectionPostedDraft,$first['key']);OutgoingCorrectionDraft::store($_SESSION,$outgoingcorrectionPostedDraft);redirectTo($outgoingcorrectionUrl($outgoingcorrectionId,['outgoingcorrection_receipt'=>$first['key']]));
}catch(DomainException$failure){
    // This attempt rolled back on a domain rejection; an earlier unknown attempt keeps its pending flag.
    if($outgoingcorrectionPost)$outgoingcorrectionHandled=true;http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=$failure->getMessage();
    if($outgoingcorrectionPostedDraft!==null){if($outgoingcorrectionStarted){$outgoingcorrectionPostedDraft['pending']=false;$outgoingcorrectionPostedDraft['review']=null;}if($failure->getCode()===409)$outgoingcorrectionPostedDraft['conflicted']=true;OutgoingCorrectionDraft::store($_SESSION,$outgoingcorrectionPostedDraft);}
}catch(Throwable){
    if($outgoingcorrectionPost)$outgoingcorrectionHandled=true;http_response_code(503);$error=$outgoingcorrectionStarted?'Korrekturausgang nicht bestätigt. Die ursprüngliche persönliche Bestätigung zuerst lesend prüfen.':'Korrekturentwurf konnte nicht geprüft werden. Ganze Angaben bleiben ungespeichert.';
}
if(isset($_GET['outgoingcorrection_original'])||isset($_GET['outgoingcorrection_pdf'])){
    try{$messages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\OutgoingInvoiceMessages::text($error,$messages);}catch(Throwable){}
    header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');if($outgoingcorrectionMethod!=='HEAD')echo$error;exit;
}
