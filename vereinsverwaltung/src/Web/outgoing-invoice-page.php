<?php declare(strict_types=1);
use Kulturhus\Verein\Finance\Invoices\{OutgoingInvoiceDraft,OutgoingInvoiceInput,OutgoingInvoiceRequests};

$outgoinginvoiceState=null;$outgoinginvoiceDraft=null;$outgoinginvoiceMessages=[];
$outgoingInvoiceSource=\Kulturhus\Verein\Web\OutgoingInvoiceSource::request($outgoinginvoiceRoute,$_GET,$_SERVER['REQUEST_METHOD']??'GET');
if(!$outgoinginvoiceRoute)return;
try{
    $outgoinginvoiceMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();
    if($outgoingInvoiceSource['state']==='invalid')return;
    $error=\Kulturhus\Native\Language\OutgoingInvoiceMessages::text($error,$outgoinginvoiceMessages);
    if($outgoinginvoiceId===''&&!isset($_GET['new_invoice'])&&!isset($_GET['outgoinginvoice_draft'])&&!isset($_GET['outgoinginvoice_receipt'])&&$outgoinginvoicePostedDraft===null&&!$outgoinginvoicePost)return;
    if(!$workspaceAllowed)throw new DomainException('Ausgangsrechnung im eigenen Finanzbereich öffnen.',403);
    $outgoinginvoiceRequests??=new OutgoingInvoiceRequests($db,(string)$_SESSION['actor']);$outgoinginvoiceActor=$outgoinginvoiceRequests->assertAccess();
    $outgoinginvoiceState=['current'=>null,'source'=>null,'receipt'=>null,'history'=>null,'errors'=>[],'can_write'=>false];
    $oiProblem=static function(string $piece,DomainException $failure)use(&$outgoinginvoiceState):void{
        $outgoinginvoiceState['errors'][$piece]=$failure->getMessage();if(http_response_code()<400)http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);
    };
    try{$outgoinginvoiceRequests->assertAccess(true);$outgoinginvoiceState['can_write']=true;}catch(DomainException$failure){if($failure->getCode()!==403)throw $failure;}
    if(!is_string($outgoinginvoiceId))throw new DomainException('Eigene ganze Rechnung auswählen.',422);
    $oiSection=OutgoingInvoiceDraft::section($outgoinginvoiceId);$oiSlot=hash('sha256',$outgoinginvoiceActor['club_id']."\0".$outgoinginvoiceActor['id']."\0".$oiSection);
    if($outgoinginvoicePostedDraft!==null)$outgoinginvoiceDraft=OutgoingInvoiceDraft::get($_SESSION,$outgoinginvoiceActor,$outgoinginvoiceId,$outgoinginvoicePostedDraft['id']);
    elseif(isset($_GET['outgoinginvoice_draft']))$outgoinginvoiceDraft=OutgoingInvoiceDraft::get($_SESSION,$outgoinginvoiceActor,$outgoinginvoiceId,$_GET['outgoinginvoice_draft']);
    elseif(!isset($_GET['outgoinginvoice_edit'])&&!isset($_GET['outgoinginvoice_issue'])){
        $stored=$_SESSION['general_settings_slots'][$oiSlot]??null;
        if(is_string($stored)&&isset($_SESSION['general_settings_drafts'][$stored])){
            $candidate=OutgoingInvoiceDraft::get($_SESSION,$outgoinginvoiceActor,$outgoinginvoiceId,$stored);
            if($candidate['accepted']===null)$outgoinginvoiceDraft=$candidate;
        }
    }
    if(isset($_GET['outgoinginvoice_receipt']))try{
        if(!is_string($_GET['outgoinginvoice_receipt']))throw new DomainException('Eigene ursprüngliche Rechnungsbestätigung nicht verfügbar.',404);
        $first=$outgoinginvoiceRequests->receipt($_GET['outgoinginvoice_receipt']);
        if($outgoinginvoiceId!==''&&$first['result']['id']!==$outgoinginvoiceId)throw new DomainException('Eigene ursprüngliche Rechnungsbestätigung nicht verfügbar.',404);
        $outgoinginvoiceState['receipt']=$first;
    }catch(DomainException$failure){$oiProblem('receipt',$failure);}
    if($outgoinginvoiceDraft&&!$outgoinginvoiceState['receipt'])try{
        $resolved=$outgoinginvoiceRequests->receipt($outgoinginvoiceDraft['key']);
        if($outgoinginvoiceId!==''&&$resolved['result']['id']!==$outgoinginvoiceId)throw new DomainException('Eigene ursprüngliche Rechnungsbestätigung nicht verfügbar.',404);
        $outgoinginvoiceState['receipt']=$resolved;
    }catch(DomainException$failure){if($failure->getCode()!==404)$oiProblem('receipt',$failure);}
    if($outgoinginvoiceDraft&&!$outgoinginvoiceState['receipt']&&isset($outgoinginvoiceDraft['legacy_request_key']))try{
        $earlier=$outgoinginvoiceRequests->legacyCreation($outgoinginvoiceDraft['legacy_request_key'],$outgoinginvoiceDraft['input']);
        if($earlier){
            $outgoinginvoiceDraft=OutgoingInvoiceDraft::recoverCreation($_SESSION,$outgoinginvoiceActor,$outgoinginvoiceDraft,$earlier);
            redirectTo($outgoinginvoiceUrl($earlier['invoice']['id'],['outgoinginvoice_draft'=>$outgoinginvoiceDraft['id'],'outgoinginvoice_legacy'=>'1']));
        }
    }catch(DomainException$failure){$oiProblem('legacy',$failure);}
    if($first=$outgoinginvoiceState['receipt']){
        foreach($_SESSION['general_settings_drafts']??[]as$stored){
            if($stored['actor']===$outgoinginvoiceActor['id']&&$stored['club']===$outgoinginvoiceActor['club_id']&&$stored['key']===$first['key']&&str_starts_with($stored['section'],'outgoing-invoice.'))OutgoingInvoiceDraft::store($_SESSION,OutgoingInvoiceDraft::complete($stored,$first['key']));
        }
        if($outgoinginvoiceDraft&&$outgoinginvoiceDraft['key']===$first['key'])$outgoinginvoiceDraft=OutgoingInvoiceDraft::complete($outgoinginvoiceDraft,$first['key']);
        if($outgoinginvoiceId==='')redirectTo($outgoinginvoiceUrl($first['result']['id'],['outgoinginvoice_receipt'=>$first['key']]));
        if($error===\Kulturhus\Native\Language\OutgoingInvoiceMessages::text('Rechnungsausgang nicht bestätigt. Die ursprüngliche persönliche Bestätigung zuerst lesend prüfen.',$outgoinginvoiceMessages)){$error='';if(http_response_code()===503)http_response_code(200);}
    }
    if($outgoinginvoiceId!==''){
        try{$state=$outgoinginvoiceRequests->state($outgoinginvoiceId);$outgoinginvoiceState['current']=$state['invoice'];$outgoinginvoiceState['source']=$state['source'];}catch(DomainException$failure){$oiProblem('current',$failure);$outgoingInvoiceSource=\Kulturhus\Verein\Web\OutgoingInvoiceSource::unavailable($outgoingInvoiceSource,$failure->getCode());}
        try{
            $outgoinginvoiceState['history']=$outgoinginvoiceRequests->history($outgoinginvoiceId,['query'=>$_GET['outgoinginvoice_history_q']??'','page'=>$_GET['outgoinginvoice_history_page']??'1']);
            if($outgoinginvoiceState['history']['error']!=='')throw new DomainException($outgoinginvoiceState['history']['error'],422);
        }catch(DomainException$failure){$oiProblem('history',$failure);}
    }
    $current=$outgoinginvoiceState['current'];$explicitHistory=isset($_GET['outgoinginvoice_history_q'])||isset($_GET['outgoinginvoice_history_page']);
    $explicitIssue=isset($_GET['outgoinginvoice_issue']);$explicitEdit=isset($_GET['outgoinginvoice_edit']);
    $mayOpen=!$outgoinginvoiceDraft&&!$outgoinginvoiceState['receipt']&&!isset($_GET['outgoinginvoice_receipt'])&&!$explicitHistory&&!isset($_GET['outgoinginvoice_closed'])&&$outgoinginvoiceState['can_write']&&$outgoinginvoiceState['errors']===[];
    if($mayOpen&&$explicitIssue){
        if(!$current||$current['kind']!=='invoice'||$current['state']!=='reviewed')throw new DomainException('Genau den zuvor geprüften Rechnungsstand erneut öffnen.',409);
        $outgoinginvoiceRequests->preview($outgoinginvoiceId,(int)$current['version']);
        $outgoinginvoiceDraft=OutgoingInvoiceDraft::open($_SESSION,$outgoinginvoiceActor,['operation'=>'issue','invoice'=>$outgoinginvoiceId,'version'=>(string)$current['version'],'data'=>['reviewed_hash'=>$current['reviewed_hash']]],true);
    }elseif($mayOpen&&(($outgoinginvoiceId===''&&isset($_GET['new_invoice']))||($current&&$current['kind']==='invoice'&&($current['state']==='draft'||($current['state']==='reviewed'&&$explicitEdit))))){
        $outgoinginvoiceDraft=OutgoingInvoiceDraft::open($_SESSION,$outgoinginvoiceActor,OutgoingInvoiceInput::initial($current),$explicitEdit);
    }
}catch(DomainException$failure){
    if($outgoinginvoiceState===null)$outgoingInvoiceSource=\Kulturhus\Verein\Web\OutgoingInvoiceSource::unavailable($outgoingInvoiceSource,$failure->getCode());
    http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=\Kulturhus\Native\Language\OutgoingInvoiceMessages::text($failure->getMessage(),$outgoinginvoiceMessages);
}catch(Throwable){$outgoingInvoiceSource=\Kulturhus\Verein\Web\OutgoingInvoiceSource::unavailable($outgoingInvoiceSource,503);http_response_code(503);$error=\Kulturhus\Native\Language\OutgoingInvoiceMessages::text('Rechnungsbereich konnte nicht geladen werden.',$outgoinginvoiceMessages);}
