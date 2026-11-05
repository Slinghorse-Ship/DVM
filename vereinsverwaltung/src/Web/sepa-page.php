<?php declare(strict_types=1);
use Kulturhus\Verein\Finance\Banking\NativeSepa\{SepaInput,SepaDraft,SepaRequests};

$sepaState=null;$sepaDraft=null;$sepaMessages=[];if(!$sepaRoute)return;
try{
    $sepaMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\SepaMessages::text($error,$sepaMessages);
    if(!$workspaceAllowed)throw new DomainException('Lastschriftauftrag im eigenen Finanzbereich öffnen.',403);$sepaRequests??=new SepaRequests($db,(string)$_SESSION['actor']);$sepaActor=$sepaRequests->assertAccess();
    $sepaState=['listing'=>null,'candidates'=>null,'selected'=>[],'accounts'=>[],'returns'=>null,'documents'=>null,'selected_document'=>null,'return_financials'=>[],'return_funding'=>null,'return_funding_selected'=>[],'context'=>null,'receipt'=>null,'history'=>null,'errors'=>[],'can_write'=>false];
    $cdProblem=static function(string $piece,DomainException $failure)use(&$sepaState):void{$sepaState['errors'][$piece]=$failure->getMessage();if(http_response_code()<400)http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);};
    try{$sepaRequests->assertAuthority();$sepaState['can_write']=true;}catch(DomainException $failure){if($failure->getCode()!==403)throw $failure;}
    $explicitHistory=isset($_GET['sepa_history_q'])||isset($_GET['sepa_history_page']);$explicitCatalogue=isset($_GET['sepa_q'])||isset($_GET['sepa_state'])||isset($_GET['sepa_page']);$slot=hash('sha256',$sepaActor['club_id']."\0".$sepaActor['id']."\0".SepaDraft::section($sepaOrder));
    if($sepaPostedDraft!==null)$sepaDraft=SepaDraft::get($_SESSION,$sepaActor,$sepaOrder,$sepaPostedDraft['id']);
    elseif(isset($_GET['sepa_draft']))$sepaDraft=SepaDraft::get($_SESSION,$sepaActor,$sepaOrder,$_GET['sepa_draft']);
    elseif(!$explicitHistory&&(!$explicitCatalogue||$sepaDetail)){$stored=$_SESSION['general_settings_slots'][$slot]??null;if(is_string($stored)&&isset($_SESSION['general_settings_drafts'][$stored]))$sepaDraft=SepaDraft::get($_SESSION,$sepaActor,$sepaOrder,$stored);}
    $wasConfirmed=$sepaDraft&&$sepaDraft['accepted']!==null;
    if(isset($_GET['sepa_receipt']))try{
        if(!is_string($_GET['sepa_receipt']))throw new DomainException('Eigene ursprüngliche Lastschriftentscheidung nicht verfügbar.',404);$first=$sepaRequests->receipt($_GET['sepa_receipt']);if($sepaOrder!==''&&$first['result']['order']['id']!==$sepaOrder)throw new DomainException('Eigene ursprüngliche Lastschriftentscheidung nicht verfügbar.',404);$sepaState['receipt']=$first;
    }catch(DomainException $failure){$cdProblem('receipt',$failure);}
    elseif($sepaDraft)try{$sepaState['receipt']=$sepaRequests->receipt($sepaDraft['key']);}catch(DomainException $failure){if($failure->getCode()!==404||$sepaDraft['accepted']!==null)$cdProblem('receipt',$failure);}
    if($first=$sepaState['receipt']){
        foreach($_SESSION['general_settings_drafts']??[]as$stored)if($stored['actor']===$sepaActor['id']&&$stored['club']===$sepaActor['club_id']&&$stored['section']===SepaDraft::section($sepaOrder)&&$stored['key']===$first['key'])SepaDraft::store($_SESSION,SepaDraft::complete($stored,$first['key']));
        if($sepaDraft&&$sepaDraft['key']===$first['key'])$sepaDraft=SepaDraft::complete($sepaDraft,$first['key']);
        if($error===\Kulturhus\Native\Language\SepaMessages::text('Lastschriftausgang nicht bestätigt. Die ursprüngliche persönliche Entscheidung zuerst lesend prüfen.',$sepaMessages)){$error='';if(http_response_code()===503)http_response_code(200);}
    }
    if(isset($_GET['sepa_new'])&&$wasConfirmed&&!isset($_GET['sepa_receipt'])&&!isset($_GET['sepa_draft'])&&$sepaState['errors']===[]){$sepaDraft=null;$sepaState['receipt']=null;}
    try{$sepaState['history']=$sepaRequests->history($sepaOrder!==''?$sepaOrder:null,['query'=>$_GET['sepa_history_q']??'','page'=>$_GET['sepa_history_page']??'1']);if($sepaState['history']['error']!=='')throw new DomainException($sepaState['history']['error'],422);}catch(DomainException $failure){$cdProblem('history',$failure);}
    if($sepaState['can_write']){
        if($sepaDetail)try{$sepaState['context']=$sepaRequests->context($sepaOrder);}catch(DomainException $failure){$cdProblem('context',$failure);}
        else{
            try{$sepaState['accounts']=$sepaRequests->accounts();}catch(DomainException $failure){$cdProblem('accounts',$failure);}
            try{$sepaState['listing']=$sepaRequests->listing(['query'=>$_GET['sepa_q']??'','state'=>$_GET['sepa_state']??'','page'=>$_GET['sepa_page']??'1']);if($sepaState['listing']['error']!=='')throw new DomainException($sepaState['listing']['error'],422);}catch(DomainException $failure){$cdProblem('listing',$failure);}
            try{$sepaState['candidates']=$sepaRequests->candidates(['query'=>$_POST['sepa_candidate_q']??$_GET['sepa_candidate_q']??'','page'=>$_POST['sepa_candidate_page']??$_GET['sepa_candidate_page']??'1']);if($sepaState['candidates']['error']!=='')throw new DomainException($sepaState['candidates']['error'],422);}catch(DomainException $failure){$cdProblem('candidates',$failure);}
        }
        if(in_array($sepaState['context']['order']['state']??'',['exported','submitted'],true))try{$sepaState['returns']=$sepaRequests->returns($sepaOrder,['query'=>$_POST['sepa_return_q']??$_GET['sepa_return_q']??'','page'=>$_POST['sepa_return_page']??$_GET['sepa_return_page']??'1']);if($sepaState['returns']['error']!=='')throw new DomainException($sepaState['returns']['error'],422);}catch(DomainException $failure){$cdProblem('returns',$failure);}
        if(in_array($sepaState['context']['order']['state']??'',['exported','submitted'],true))try{$sepaState['documents']=$sepaRequests->documents(['query'=>$_POST['sepa_document_q']??$_GET['sepa_document_q']??'','page'=>$_POST['sepa_document_page']??$_GET['sepa_document_page']??'1']);if($sepaState['documents']['error']!=='')throw new DomainException($sepaState['documents']['error'],422);}catch(DomainException $failure){$cdProblem('documents',$failure);}
    }
    if(!$sepaDraft&&!$sepaState['receipt']&&!isset($_GET['sepa_receipt'])&&!isset($_GET['sepa_closed'])&&!$explicitHistory&&(!$explicitCatalogue||$sepaDetail)&&$sepaState['errors']===[]&&$sepaState['can_write']&&($sepaState['context']['order']['state']??'')!=='cancelled')$sepaDraft=SepaDraft::open($_SESSION,$sepaActor,SepaInput::initial($sepaOrder),true);
    if($sepaOrder===''&&$sepaDraft&&$sepaState['can_write'])try{$sepaState['selected']=$sepaRequests->selected($sepaDraft['input']['data']['entries']);}catch(DomainException $failure){$cdProblem('selected',$failure);}
    if($sepaDraft&&$sepaState['can_write']&&$sepaDraft['accepted']===null&&$sepaDraft['input']['data']['item']!==''&&(in_array($sepaDraft['input']['operation'],['return','return_attach'],true)||isset($sepaDraft['input']['data']['return_allocations'])))try{$item=$sepaDraft['input']['data']['item'];$sepaState['return_funding']=$sepaRequests->returnFundingSources($item,$_POST['sepa_return_allocation_page']??'1',$_POST['sepa_return_allocation_q']??'');foreach($sepaDraft['input']['data']['return_allocations']??[]as$id)$sepaState['return_funding_selected'][$id]=$sepaRequests->returnFundingChoice($item,$id);}catch(DomainException $failure){$cdProblem('return_funding',$failure);}
    if($sepaDraft&&$sepaState['can_write']&&$sepaDraft['input']['operation']==='return_existing'&&$sepaDraft['input']['data']['item']!=='')try{$sepaState['return_financials']=$sepaRequests->returnFinancials($sepaDraft['input']['data']['item']);}catch(DomainException $failure){$cdProblem('return_financials',$failure);}
    if($sepaDraft&&$sepaState['can_write']&&$sepaDraft['input']['data']['document']!=='')try{$sepaState['selected_document']=$sepaRequests->document($sepaDraft['input']['data']['document']);}catch(DomainException $failure){$cdProblem('selected_document',$failure);}
}catch(DomainException $failure){http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=\Kulturhus\Native\Language\SepaMessages::text($failure->getMessage(),$sepaMessages);}
catch(Throwable){http_response_code(503);$error=\Kulturhus\Native\Language\SepaMessages::text('Lastschriftbereich konnte nicht geladen werden.',$sepaMessages);}
