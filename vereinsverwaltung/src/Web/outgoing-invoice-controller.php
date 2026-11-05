<?php declare(strict_types=1);
use Kulturhus\Verein\Finance\Invoices\{OutgoingInvoiceDraft,OutgoingInvoiceInput,OutgoingInvoiceRequests};
use Kulturhus\Verein\Settings\GeneralInput;

$outgoinginvoiceHandled=false;$outgoinginvoicePostedDraft=null;$outgoinginvoiceRequests=null;$outgoinginvoiceStarted=false;
$outgoinginvoiceMethod=$_SERVER['REQUEST_METHOD']??'GET';
$outgoinginvoiceRoute=($nativeManagerEnabled??false)&&isset($nativeAccess)&&$view==='finance'&&($_GET['tab']??null)==='outgoing';
$outgoinginvoiceId=$_GET['invoice']??$_POST['invoice']??'';
$outgoinginvoiceLegacy=($nativeManagerEnabled??false)&&$outgoinginvoiceMethod==='POST'&&in_array($_POST['action']??null,['outgoing-save','outgoing-review','outgoing-issue'],true);
$outgoinginvoicePost=($nativeManagerEnabled??false)&&$outgoinginvoiceMethod==='POST'&&(($_POST['action']??null)==='outgoing-invoice-native'||$outgoinginvoiceLegacy);
$outgoinginvoiceContext=\Kulturhus\Verein\OutgoingInvoiceCatalogue::nativeContext($_GET);
$outgoinginvoiceUrl=static fn(string $id,array $query=[]):array=>['view'=>'finance','tab'=>'outgoing']+($id!==''?['invoice'=>$id]:['new_invoice'=>'1'])+$query+$outgoinginvoiceContext;
if(!$outgoinginvoiceRoute&&!$outgoinginvoicePost)return;
if(!$outgoinginvoicePost&&$outgoinginvoiceId===''&&!isset($_GET['new_invoice'])&&!isset($_GET['outgoinginvoice_original'])&&!isset($_GET['outgoinginvoice_pdf']))return;
try{
    if(!$outgoinginvoiceRoute||!$workspaceAllowed)throw new DomainException('Ausgangsrechnung im eigenen Finanzbereich öffnen.',403);
    if(!is_string($outgoinginvoiceId))throw new DomainException('Eigene ganze Rechnung auswählen.',422);
    OutgoingInvoiceDraft::section($outgoinginvoiceId);$outgoinginvoiceRequests=new OutgoingInvoiceRequests($db,(string)$_SESSION['actor']);$outgoinginvoiceActor=$outgoinginvoiceRequests->assertAccess();
    if(isset($_GET['outgoinginvoice_original'])){
        if(!in_array($outgoinginvoiceMethod,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
        if(!is_string($_GET['outgoinginvoice_original']))throw new DomainException('Eigene ursprüngliche Rechnungsbestätigung nicht verfügbar.',404);
        $first=$outgoinginvoiceRequests->receipt($_GET['outgoinginvoice_original']);
        if($first['result']['id']!==$outgoinginvoiceId)throw new DomainException('Eigene ursprüngliche Rechnungsbestätigung nicht verfügbar.',404);
        $bytes=$first['original_json'];header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="dvm-outgoing-invoice-original.json"');
        header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox; default-src 'none'");header('Content-Length: '.strlen($bytes));if($outgoinginvoiceMethod!=='HEAD')echo $bytes;exit;
    }
    if(isset($_GET['outgoinginvoice_pdf'])){
        if(!in_array($outgoinginvoiceMethod,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
        if(!in_array($_GET['outgoinginvoice_pdf'],['current','original','first'],true))throw new DomainException('Genau den gespeicherten Rechnungsstand erneut öffnen.',422);
        $firstPdf=null;
        if($_GET['outgoinginvoice_pdf']==='first'){
            if(!is_string($_GET['outgoinginvoice_receipt']??null))throw new DomainException('Eigene ursprüngliche Rechnungsbestätigung nicht verfügbar.',404);
            $firstPdf=$outgoinginvoiceRequests->receipt($_GET['outgoinginvoice_receipt']);
            if($firstPdf['result']['id']!==$outgoinginvoiceId||!is_array($firstPdf['file']))throw new DomainException('Eigene ursprüngliche Rechnungsbestätigung nicht verfügbar.',404);
        }
        if(in_array($_GET['outgoinginvoice_pdf'],['original','first'],true)){
            $state=$outgoinginvoiceRequests->state($outgoinginvoiceId);
            if(!$state['invoice']||$state['invoice']['document_id']===null)throw new DomainException('Das ursprüngliche ausgegebene Rechnungs-PDF fehlt.',404);
            $version=(int)$state['invoice']['version'];
        }else $version=GeneralInput::version($_GET['outgoinginvoice_version']??null);
        $pdf=$outgoinginvoiceRequests->preview($outgoinginvoiceId,$version);
        if($_GET['outgoinginvoice_pdf']==='first'){
            if($firstPdf['file']['document_id']!==$pdf['invoice']['document_id']||!hash_equals($firstPdf['file']['sha256'],$pdf['sha256']))throw new DomainException('PDF der ursprünglichen Rechnungsbestätigung stimmt nicht mit dem zugänglichen Original überein.',409);
        }
        if($_GET['outgoinginvoice_pdf']==='current'&&(!is_string($_GET['outgoinginvoice_source']??null)||!hash_equals($pdf['source_sha256'],$_GET['outgoinginvoice_source'])))throw new DomainException('Genau den gespeicherten Rechnungsstand erneut öffnen.',409);
        $bytes=$pdf['bytes'];header('Content-Type: application/pdf');header('Content-Disposition: '.(($_GET['outgoinginvoice_download']??null)==='1'?'attachment':'inline').'; filename="dvm-invoice.pdf"');
        header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox; default-src 'none'");header('Content-Length: '.strlen($bytes));if($outgoinginvoiceMethod!=='HEAD')echo $bytes;exit;
    }
    if(!$outgoinginvoicePost)return;$outgoinginvoiceHandled=true;
    if(!is_string($_POST['csrf']??null)||!hash_equals((string)$_SESSION['csrf'],$_POST['csrf']))throw new DomainException('Formular abgelaufen. Bitte neu laden.',403);
    if(($_POST['invoice']??null)!==$outgoinginvoiceId)throw new DomainException('Eigene ganze Rechnung auswählen.',422);
    if($outgoinginvoiceLegacy){
        $state=$outgoinginvoiceRequests->state($outgoinginvoiceId);
        $input=OutgoingInvoiceInput::initial($state['invoice']);$input['version']=(string)GeneralInput::version($_POST['version']??null);
        if($_POST['action']==='outgoing-save'){
            foreach(OutgoingInvoiceInput::FIELDS as$field){
                if($field==='domestic_confirmed')$input['data'][$field]=($_POST[$field]??null)==='1'?'1':'0';
                elseif($field==='invoice_format')$input['data'][$field]=$_POST[$field]??'hybrid-xrechnung';
                elseif(array_key_exists($field,$_POST))$input['data'][$field]=$_POST[$field];
                else throw new DomainException('Alle ursprünglichen Rechnungsfelder vollständig übermitteln.',422);
            }
            $input['data']['positions']=$_POST['positions']??null;
        }elseif($_POST['action']==='outgoing-review'){
            if(!$state['invoice']||(string)$state['invoice']['version']!==$input['version'])throw new DomainException('Früheres gespeichertes Rechnungsformular gezielt mit seinem damaligen Stand erneut öffnen.',409);
            $input['operation']='review';
        }else{
            $input=['operation'=>'issue','invoice'=>$outgoinginvoiceId,'version'=>$input['version'],'data'=>['reviewed_hash'=>'']];
        }
        $outgoinginvoicePostedDraft=OutgoingInvoiceDraft::open($_SESSION,$outgoinginvoiceActor,$input,true);
        if($_POST['action']==='outgoing-save'&&$outgoinginvoiceId===''){
            $legacyKey=$_POST['request_key']??null;
            if(!is_string($legacyKey)||!preg_match('/\A[a-f0-9]{32}\z/D',$legacyKey))throw new DomainException('Ursprüngliche Kennung des früheren Anlageformulars prüfen.',422);
            $outgoinginvoicePostedDraft['legacy_request_key']=$legacyKey;$outgoinginvoicePostedDraft['key']=$legacyKey;$outgoinginvoicePostedDraft['pending']=true;
            OutgoingInvoiceDraft::store($_SESSION,$outgoinginvoicePostedDraft);
            try{
                $first=$outgoinginvoiceRequests->receipt($legacyKey);$outgoinginvoicePostedDraft=OutgoingInvoiceDraft::complete($outgoinginvoicePostedDraft,$first['key']);OutgoingInvoiceDraft::store($_SESSION,$outgoinginvoicePostedDraft);
                redirectTo($outgoinginvoiceUrl($first['result']['id'],['outgoinginvoice_receipt'=>$first['key']]));
            }catch(DomainException$lookupFailure){if($lookupFailure->getCode()!==404)throw $lookupFailure;}
            $earlier=$outgoinginvoiceRequests->legacyCreation($legacyKey,$outgoinginvoicePostedDraft['input']);
            if($earlier){
                $outgoinginvoicePostedDraft=OutgoingInvoiceDraft::recoverCreation($_SESSION,$outgoinginvoiceActor,$outgoinginvoicePostedDraft,$earlier);
                redirectTo($outgoinginvoiceUrl($earlier['invoice']['id'],['outgoinginvoice_draft'=>$outgoinginvoicePostedDraft['id'],'outgoinginvoice_legacy'=>'1']));
            }
            $outgoinginvoicePostedDraft['pending']=false;OutgoingInvoiceDraft::store($_SESSION,$outgoinginvoicePostedDraft);
        }
        $outgoinginvoiceRequests->assertAccess(true);
        throw new DomainException('Dieses frühere Rechnungsformular wurde nicht ausgeführt. Ganze Angaben und gespeicherten Stand bewusst erneut prüfen.',422);
    }
    $outgoinginvoicePostedDraft=OutgoingInvoiceDraft::get($_SESSION,$outgoinginvoiceActor,$outgoinginvoiceId,$_POST['outgoinginvoice_draft']??null);
    // Session flags may be older than the durable transaction. Resolve its original key before any new operation.
    try{
        $first=$outgoinginvoiceRequests->receipt($outgoinginvoicePostedDraft['key']);
        if($outgoinginvoiceId!==''&&$first['result']['id']!==$outgoinginvoiceId)throw new DomainException('Eigene ursprüngliche Rechnungsbestätigung nicht verfügbar.',404);
        $outgoinginvoicePostedDraft=OutgoingInvoiceDraft::complete($outgoinginvoicePostedDraft,$first['key']);OutgoingInvoiceDraft::store($_SESSION,$outgoinginvoicePostedDraft);
        redirectTo($outgoinginvoiceUrl($first['result']['id'],['outgoinginvoice_receipt'=>$first['key']]));
    }catch(DomainException$lookupFailure){if($lookupFailure->getCode()!==404||$outgoinginvoicePostedDraft['accepted']!==null)throw $lookupFailure;}
    if(isset($outgoinginvoicePostedDraft['legacy_request_key'])){
        $earlier=$outgoinginvoiceRequests->legacyCreation($outgoinginvoicePostedDraft['legacy_request_key'],$outgoinginvoicePostedDraft['input']);
        if($earlier){
            $outgoinginvoicePostedDraft=OutgoingInvoiceDraft::recoverCreation($_SESSION,$outgoinginvoiceActor,$outgoinginvoicePostedDraft,$earlier);
            redirectTo($outgoinginvoiceUrl($earlier['invoice']['id'],['outgoinginvoice_draft'=>$outgoinginvoicePostedDraft['id'],'outgoinginvoice_legacy'=>'1']));
        }
    }
    $intent=$_POST['outgoinginvoice_intent']??null;$row=$_POST['outgoinginvoice_row']??null;
    if($row!==null&&$intent!==null)throw new DomainException('Genau eine eigene Rechnungsaktion auswählen.',422);
    if(($row!==null&&!is_string($row))||($row===null&&(!is_string($intent)||!in_array($intent,['save','review','issue','current','discard','clear-contact'],true))))throw new DomainException('Eigene ganze Rechnungsaktion auswählen.',422);
    if($outgoinginvoicePostedDraft['pending']){
        if($intent!=='current')throw new DomainException('Ursprünglichen Rechnungsausgang zuerst lesend prüfen.',409);
        $outgoinginvoicePostedDraft['pending']=false;
    }
    if($intent==='discard'){OutgoingInvoiceDraft::discard($_SESSION,$outgoinginvoicePostedDraft);redirectTo($outgoinginvoiceUrl($outgoinginvoiceId,['outgoinginvoice_closed'=>'1']));}
    $outgoinginvoiceRequests->assertAccess(true);$input=$outgoinginvoicePostedDraft['input'];
    if($intent==='current'){
        if(array_key_exists('data',$_POST)&&$input['operation']!=='issue')$input['data']=$_POST['data'];
        $state=$outgoinginvoiceRequests->state($outgoinginvoiceId);$input['version']=(string)($state['invoice']['version']??0);
        if($input['operation']==='issue'){
            if(($state['invoice']['state']??null)!=='reviewed')throw new DomainException('Genau den zuvor geprüften Rechnungsstand erneut öffnen.',409);
            $input['data']['reviewed_hash']=$state['invoice']['reviewed_hash'];
        }
        $outgoinginvoicePostedDraft=OutgoingInvoiceDraft::replace($outgoinginvoicePostedDraft,$input,!isset($outgoinginvoicePostedDraft['legacy_request_key']));OutgoingInvoiceDraft::store($_SESSION,$outgoinginvoicePostedDraft);return;
    }
    if($input['operation']==='issue'){
        if(array_key_exists('data',$_POST))throw new DomainException('Ausgabe getrennt für genau den gespeicherten geprüften Stand bestätigen.',422);
        if($intent!=='issue'||($_POST['outgoinginvoice_approved']??null)!=='1')throw new DomainException('Genau diesen geprüften Rechnungsstand ausdrücklich ausgeben.',422);
    }else{
        if(!array_key_exists('data',$_POST)||!is_array($_POST['data']))throw new DomainException('Alle ursprünglichen Rechnungsfelder vollständig übermitteln.',422);
        $input=['operation'=>'save','invoice'=>$outgoinginvoiceId,'version'=>$_POST['outgoinginvoice_version']??'','data'=>$_POST['data']];
        $input=OutgoingInvoiceInput::capture($input);
        if($row!==null)$input=OutgoingInvoiceInput::row($input,$row);
        elseif($intent==='clear-contact')$input['data']['contact_id']='';
        elseif($intent==='review')$input['operation']='review';
        $outgoinginvoicePostedDraft=OutgoingInvoiceDraft::replace($outgoinginvoicePostedDraft,$input);OutgoingInvoiceDraft::store($_SESSION,$outgoinginvoicePostedDraft);
        if($row!==null||$intent==='clear-contact')return;
        try{\Kulturhus\Verein\OutgoingInvoices::normalize($input['data']);}catch(DomainException$failure){throw new DomainException($failure->getMessage(),422,$failure);}
    }
    if(!is_string($_POST['outgoinginvoice_key']??null)||!hash_equals($outgoinginvoicePostedDraft['key'],$_POST['outgoinginvoice_key']))throw new DomainException('Der Rechnungsentwurf wurde in einem anderen Tab geändert. Ganze ursprüngliche Angaben erneut öffnen.',409);
    $outgoinginvoicePostedDraft['pending']=true;OutgoingInvoiceDraft::store($_SESSION,$outgoinginvoicePostedDraft);$outgoinginvoiceStarted=true;
    $first=$outgoinginvoiceRequests->execute($outgoinginvoicePostedDraft['input'],$outgoinginvoicePostedDraft['key'],$intent==='issue');
    $outgoinginvoicePostedDraft=OutgoingInvoiceDraft::complete($outgoinginvoicePostedDraft,$first['key']);OutgoingInvoiceDraft::store($_SESSION,$outgoinginvoicePostedDraft);
    redirectTo($outgoinginvoiceUrl($first['result']['id'],['outgoinginvoice_receipt'=>$first['key']]));
}catch(DomainException$failure){
    if($outgoinginvoicePost)$outgoinginvoiceHandled=true;
    http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=$failure->getMessage();
    if($outgoinginvoiceStarted&&http_response_code()===422&&$outgoinginvoicePostedDraft!==null){
        // A known validation rejection rolls back the operation; resolve any durable first outcome before reopening it.
        try{
            $first=$outgoinginvoiceRequests->receipt($outgoinginvoicePostedDraft['key']);
            $outgoinginvoicePostedDraft=OutgoingInvoiceDraft::complete($outgoinginvoicePostedDraft,$first['key']);OutgoingInvoiceDraft::store($_SESSION,$outgoinginvoicePostedDraft);
            redirectTo($outgoinginvoiceUrl($first['result']['id'],['outgoinginvoice_receipt'=>$first['key']]));
        }catch(DomainException$lookupFailure){if($lookupFailure->getCode()===404)$outgoinginvoicePostedDraft['pending']=false;}
    }
    if($outgoinginvoicePostedDraft!==null){if($failure->getCode()===409)$outgoinginvoicePostedDraft['conflicted']=true;OutgoingInvoiceDraft::store($_SESSION,$outgoinginvoicePostedDraft);}
}catch(Throwable){
    if($outgoinginvoicePost)$outgoinginvoiceHandled=true;http_response_code(503);
    $error=$outgoinginvoiceStarted?'Rechnungsausgang nicht bestätigt. Die ursprüngliche persönliche Bestätigung zuerst lesend prüfen.':'Rechnungsentwurf konnte nicht geprüft werden. Ganze Angaben bleiben ungespeichert.';
}
if(isset($_GET['outgoinginvoice_original'])||isset($_GET['outgoinginvoice_pdf'])){
    try{$messages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\OutgoingInvoiceMessages::text($error,$messages);}catch(Throwable){}
    header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');if($outgoinginvoiceMethod!=='HEAD')echo $error;exit;
}
