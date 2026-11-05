<?php declare(strict_types=1);
use Kulturhus\Verein\Finance\Invoices\{OutgoingCorrectionDraft,OutgoingCorrectionRequests};

$outgoingcorrectionState=null;$outgoingcorrectionDraft=null;$outgoingcorrectionMessages=[];
if(!$outgoingcorrectionRoute||$outgoingcorrectionId==='')return;
try{
    $outgoingcorrectionMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\OutgoingInvoiceMessages::text($error,$outgoingcorrectionMessages);
    if(!$workspaceAllowed)throw new DomainException('Ausgangsrechnung im eigenen Finanzbereich öffnen.',403);
    if(!is_string($outgoingcorrectionId))throw new DomainException('Eigene ursprüngliche Rechnung auswählen.',422);
    $outgoingcorrectionRequests??=new OutgoingCorrectionRequests($db,(string)$_SESSION['actor']);$outgoingcorrectionActor=$outgoingcorrectionRequests->assertAccess();
    $outgoingcorrectionState=['current'=>null,'receipt'=>null,'history'=>null,'errors'=>[],'can_write'=>false,'parent'=>null];
    $ocProblem=static function(string$piece,DomainException$failure)use(&$outgoingcorrectionState):void{$outgoingcorrectionState['errors'][$piece]=$failure->getMessage();if(http_response_code()<400)http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);};
    try{$outgoingcorrectionRequests->assertAccess(true);$outgoingcorrectionState['can_write']=true;}catch(DomainException$failure){if($failure->getCode()!==403)throw$failure;}
    try{$outgoingcorrectionState['current']=$outgoingcorrectionRequests->current($outgoingcorrectionId);}catch(DomainException$failure){$ocProblem('current',$failure);}
    $ocSection=OutgoingCorrectionDraft::section($outgoingcorrectionId);$ocSlot=hash('sha256',$outgoingcorrectionActor['club_id']."\0".$outgoingcorrectionActor['id']."\0".$ocSection);
    if($outgoingcorrectionPostedDraft!==null)$outgoingcorrectionDraft=OutgoingCorrectionDraft::get($_SESSION,$outgoingcorrectionActor,$outgoingcorrectionId,$outgoingcorrectionPostedDraft['id']);
    elseif(isset($_GET['outgoingcorrection_draft']))$outgoingcorrectionDraft=OutgoingCorrectionDraft::get($_SESSION,$outgoingcorrectionActor,$outgoingcorrectionId,$_GET['outgoingcorrection_draft']);
    elseif(!isset($_GET['outgoingcorrection_history_q'])&&!isset($_GET['outgoingcorrection_history_page'])){$stored=$_SESSION['general_settings_slots'][$ocSlot]??null;if(is_string($stored)&&isset($_SESSION['general_settings_drafts'][$stored]))$outgoingcorrectionDraft=OutgoingCorrectionDraft::get($_SESSION,$outgoingcorrectionActor,$outgoingcorrectionId,$stored);}
    $ocNew=isset($_GET['outgoingcorrection_new']);$ocWasConfirmed=$outgoingcorrectionDraft&&($outgoingcorrectionDraft['accepted']!==null||isset($outgoingcorrectionDraft['legacy_result']));
    if(isset($_GET['outgoingcorrection_receipt']))try{
        if(!is_string($_GET['outgoingcorrection_receipt']))throw new DomainException('Eigene ursprüngliche Korrekturbestätigung nicht verfügbar.',404);
        $first=$outgoingcorrectionRequests->receipt($_GET['outgoingcorrection_receipt']);if($first['input']['invoice']!==$outgoingcorrectionId)throw new DomainException('Eigene ursprüngliche Korrekturbestätigung nicht verfügbar.',404);$outgoingcorrectionState['receipt']=$first;
    }catch(DomainException$failure){$ocProblem('receipt',$failure);}
    elseif($outgoingcorrectionDraft)try{
        $first=$outgoingcorrectionRequests->receipt($outgoingcorrectionDraft['key']);if($first['input']['invoice']!==$outgoingcorrectionId)throw new DomainException('Eigene ursprüngliche Korrekturbestätigung nicht verfügbar.',404);
        $outgoingcorrectionState['receipt']=$first;
    }catch(DomainException$failure){if($failure->getCode()!==404||$outgoingcorrectionDraft['accepted']!==null)$ocProblem('receipt',$failure);}
    if($outgoingcorrectionDraft&&!$outgoingcorrectionState['receipt']&&isset($outgoingcorrectionDraft['legacy_request_key']))try{
        $earlier=$outgoingcorrectionRequests->legacy($outgoingcorrectionDraft['legacy_request_key'],$outgoingcorrectionDraft['input']);
        if($earlier){$outgoingcorrectionDraft['legacy_result']=$earlier;$outgoingcorrectionDraft['pending']=false;OutgoingCorrectionDraft::store($_SESSION,$outgoingcorrectionDraft);}
    }catch(DomainException$failure){$ocProblem('legacy',$failure);}
    if($first=$outgoingcorrectionState['receipt']){
        foreach($_SESSION['general_settings_drafts']??[]as$stored)if($stored['actor']===$outgoingcorrectionActor['id']&&$stored['club']===$outgoingcorrectionActor['club_id']&&$stored['key']===$first['key']&&$stored['section']===$ocSection)OutgoingCorrectionDraft::store($_SESSION,OutgoingCorrectionDraft::complete($stored,$first['key']));
        if($outgoingcorrectionDraft&&$outgoingcorrectionDraft['key']===$first['key'])$outgoingcorrectionDraft=OutgoingCorrectionDraft::complete($outgoingcorrectionDraft,$first['key']);
        if($error===\Kulturhus\Native\Language\OutgoingInvoiceMessages::text('Korrekturausgang nicht bestätigt. Die ursprüngliche persönliche Bestätigung zuerst lesend prüfen.',$outgoingcorrectionMessages)){$error='';if(http_response_code()===503)http_response_code(200);}
    }
    if($ocNew&&$ocWasConfirmed&&!isset($_GET['outgoingcorrection_receipt'])&&!isset($_GET['outgoingcorrection_draft'])&&$outgoingcorrectionState['errors']===[]){$outgoingcorrectionDraft=null;$outgoingcorrectionState['receipt']=null;}
    $current=$outgoingcorrectionState['current'];
    if($current&&$current['kind']!=='invoice'){$outgoingcorrectionState['parent']=$current['parent_id'];return;}
    try{
        $outgoingcorrectionState['history']=$outgoingcorrectionRequests->history($outgoingcorrectionId,['query'=>$_GET['outgoingcorrection_history_q']??'','page'=>$_GET['outgoingcorrection_history_page']??'1']);
        if($outgoingcorrectionState['history']['error']!=='')throw new DomainException($outgoingcorrectionState['history']['error'],422);
    }catch(DomainException$failure){$ocProblem('history',$failure);}
    if($ocNew&&!$outgoingcorrectionDraft&&!$outgoingcorrectionState['receipt']&&!isset($_GET['outgoingcorrection_receipt'])&&!isset($_GET['outgoingcorrection_closed'])&&$outgoingcorrectionState['errors']===[]&&$outgoingcorrectionState['can_write']){
        $outgoingcorrectionDraft=OutgoingCorrectionDraft::open($_SESSION,$outgoingcorrectionActor,$outgoingcorrectionRequests->initial($outgoingcorrectionId),true);
    }
}catch(DomainException$failure){http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=\Kulturhus\Native\Language\OutgoingInvoiceMessages::text($failure->getMessage(),$outgoingcorrectionMessages);}
catch(Throwable){http_response_code(503);$error=\Kulturhus\Native\Language\OutgoingInvoiceMessages::text('Korrekturbereich konnte nicht geladen werden.',$outgoingcorrectionMessages);}
