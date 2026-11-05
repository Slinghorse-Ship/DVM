<?php declare(strict_types=1);
use Kulturhus\Verein\Finance\Banking\NativeTransfer\{TransferInput,TransferDraft,TransferRequests};

$transferState=null;$transferDraft=null;$transferMessages=[];$transferPaymentState=null;
$transferSimple=($_GET['transfer_manual']??null)!=='1'&&!array_intersect(['transfer_receipt','transfer_draft','transfer_history_q','transfer_history_page'],array_keys($_GET))&&!($transferPost??false);
if(!$transferRoute)return;
// Ordinary incoming receipts have no outgoing transfer workspace. Explicit
// transfer requests still run their normal source and permission checks.
if($financeEntry&&$financeEntry['kind']!=='expense'&&!$transferPost&&!$transferLegacyPost&&!array_filter(array_keys($_GET),static fn($key):bool=>is_string($key)&&str_starts_with($key,'transfer_')))return;
try{
    $transferMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\TransferMessages::text($error,$transferMessages);
    if(!$workspaceAllowed)throw new DomainException('Überweisungsauftrag im eigenen Finanzbereich öffnen.',403);$transferRequests??=new TransferRequests($db,(string)$_SESSION['actor']);$transferActor=$transferRequests->assertAccess();
    $transferState=['invoice'=>null,'listing'=>null,'accounts'=>[],'context'=>null,'receipt'=>null,'history'=>null,'errors'=>[],'can_write'=>false,'can_approve'=>false];
    $transferProblem=static function(string $piece,DomainException $failure)use(&$transferState):void{$transferState['errors'][$piece]=$failure->getMessage();if(http_response_code()<400)http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);};
    try{$transferRequests->assertAccess(true);$transferState['can_write']=true;}catch(DomainException $failure){if($failure->getCode()!==403)throw $failure;}
    try{$transferRequests->assertAuthority();$transferState['can_approve']=true;}catch(DomainException $failure){if($failure->getCode()!==403)throw $failure;}
    if($transferSimple){
        $transferState['invoice']=$transferRequests->entry($transferEntry);
        $transferPaymentState=['current'=>null,'form'=>null,'reviews'=>[],'reason'=>'','can_pay'=>$transferState['can_approve']];
        if($transferState['can_write'])$transferPaymentState['current']=$transferRequests->paymentOrder($transferEntry,$transferOrder);
        $paymentEntry=$transferState['invoice']['entry'];$paymentCurrent=$transferPaymentState['current'];
        if($transferState['can_approve']&&in_array($paymentEntry['payment_state'],['open','partial'],true)&&$paymentEntry['remaining_cents']>0&&($paymentCurrent===null||$paymentCurrent['order']['state']==='prepared')){
            try{
                $transferPaymentState['reviews']=$transferRequests->paymentReviews($transferEntry,$paymentCurrent);
                if(!($transferPaymentPost??false))$transferPaymentState['form']=\Kulturhus\Verein\Finance\Banking\NativeTransfer\PaymentForm::open($_SESSION,$transferActor,$transferEntry,$transferPaymentState['reviews']);
            }catch(DomainException $failure){$transferPaymentState['reason']=$failure->getMessage();}
        }
        return;
    }
    $explicitHistory=isset($_GET['transfer_history_q'])||isset($_GET['transfer_history_page']);$explicitCatalogue=isset($_GET['transfer_q'])||isset($_GET['transfer_state'])||isset($_GET['transfer_page']);$slot=hash('sha256',$transferActor['club_id']."\0".$transferActor['id']."\0".TransferDraft::section($transferEntry,$transferOrder));
    if($transferPostedDraft!==null)$transferDraft=TransferDraft::get($_SESSION,$transferActor,$transferEntry,$transferOrder,$transferPostedDraft['id']);
    elseif(isset($_GET['transfer_draft']))$transferDraft=TransferDraft::get($_SESSION,$transferActor,$transferEntry,$transferOrder,$_GET['transfer_draft']);
    elseif(!$explicitHistory&&!$explicitCatalogue){$stored=$_SESSION['general_settings_slots'][$slot]??null;if(is_string($stored)&&isset($_SESSION['general_settings_drafts'][$stored]))$transferDraft=TransferDraft::get($_SESSION,$transferActor,$transferEntry,$transferOrder,$stored);}
    $wasConfirmed=$transferDraft&&$transferDraft['accepted']!==null;
    if(isset($_GET['transfer_receipt']))try{
        if(!is_string($_GET['transfer_receipt']))throw new DomainException('Eigene ursprüngliche Überweisungsentscheidung nicht verfügbar.',404);$first=$transferRequests->receipt($_GET['transfer_receipt']);if($first['result']['order']['entry_id']!==$transferEntry||($transferOrder!==''&&$first['result']['order']['id']!==$transferOrder))throw new DomainException('Eigene ursprüngliche Überweisungsentscheidung nicht verfügbar.',404);$transferState['receipt']=$first;
    }catch(DomainException $failure){$transferProblem('receipt',$failure);}
    elseif($transferDraft)try{$transferState['receipt']=$transferRequests->receipt($transferDraft['key']);}catch(DomainException $failure){if($failure->getCode()!==404||$transferDraft['accepted']!==null)$transferProblem('receipt',$failure);}
    if($first=$transferState['receipt']){
        foreach($_SESSION['general_settings_drafts']??[]as$stored)if($stored['actor']===$transferActor['id']&&$stored['club']===$transferActor['club_id']&&$stored['section']===TransferDraft::section($transferEntry,$transferOrder)&&$stored['key']===$first['key'])TransferDraft::store($_SESSION,TransferDraft::complete($stored,$first['key']));
        if($transferDraft&&$transferDraft['key']===$first['key'])$transferDraft=TransferDraft::complete($transferDraft,$first['key']);
        if($error===\Kulturhus\Native\Language\TransferMessages::text('Überweisungsausgang nicht bestätigt. Die ursprüngliche persönliche Entscheidung zuerst lesend prüfen.',$transferMessages)){$error='';if(http_response_code()===503)http_response_code(200);}
    }
    if(isset($_GET['transfer_new'])&&$wasConfirmed&&!isset($_GET['transfer_receipt'])&&!isset($_GET['transfer_draft'])&&$transferState['errors']===[]){$transferDraft=null;$transferState['receipt']=null;}
    try{$transferState['invoice']=$transferRequests->entry($transferEntry);}catch(DomainException $failure){$transferProblem('invoice',$failure);}
    try{$transferState['history']=$transferRequests->history($transferEntry,$transferOrder!==''?$transferOrder:null,['query'=>$_GET['transfer_history_q']??'','page'=>$_GET['transfer_history_page']??'1']);if($transferState['history']['error']!=='')throw new DomainException($transferState['history']['error'],422);}catch(DomainException $failure){$transferProblem('history',$failure);}
    if($transferState['can_write']){
        if($transferDetail)try{$transferState['context']=$transferRequests->context($transferEntry,$transferOrder);}catch(DomainException $failure){$transferProblem('context',$failure);}
        else{
            try{$transferState['accounts']=$transferRequests->accounts();}catch(DomainException $failure){$transferProblem('accounts',$failure);}
            try{$transferState['listing']=$transferRequests->listing($transferEntry,['query'=>$_GET['transfer_q']??'','state'=>$_GET['transfer_state']??'','page'=>$_GET['transfer_page']??'1']);if($transferState['listing']['error']!=='')throw new DomainException($transferState['listing']['error'],422);}catch(DomainException $failure){$transferProblem('listing',$failure);}
        }
    }
    if(!$transferDraft&&!$transferState['receipt']&&!isset($_GET['transfer_receipt'])&&!isset($_GET['transfer_closed'])&&!$explicitHistory&&!$explicitCatalogue&&$transferState['errors']===[]&&$transferState['can_write']&&($transferState['context']['order']['state']??'')!=='cancelled')$transferDraft=TransferDraft::open($_SESSION,$transferActor,TransferInput::initial($transferEntry,$transferOrder),true);
}catch(DomainException $failure){http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=\Kulturhus\Native\Language\TransferMessages::text($failure->getMessage(),$transferMessages);}
catch(Throwable){http_response_code(503);$error=\Kulturhus\Native\Language\TransferMessages::text('Überweisungsbereich konnte nicht geladen werden.',$transferMessages);}
