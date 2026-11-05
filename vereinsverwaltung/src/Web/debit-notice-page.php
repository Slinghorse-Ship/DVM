<?php declare(strict_types=1);
use Kulturhus\Verein\Finance\Banking\Notice\{DebitDraft, DebitRequests, DebitSource};

$debitState=null;$debitDraft=null;$debitMessages=[];
if(!$debitRoute)return;
try{
    $debitMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();
    $error=\Kulturhus\Native\Language\DebitNoticeMessages::text($error,$debitMessages);
    if(!$workspaceAllowed)throw new DomainException('Lastschriftvorankündigung am eigenen ursprünglichen Finanzdokument öffnen.',403);
    $debitRequests??=new DebitRequests($db,(string)$_SESSION['actor']);$debitActor=$debitRequests->assertAccess();
    if($debitRequests->parentDocument($debitEntry)!==$debitDocument)throw new DomainException('Ursprüngliche Rechnung dieser Vorankündigung nicht verfügbar.',404);
    $debitState=['source'=>null,'receipt'=>null,'history'=>null,'can_write'=>false,'errors'=>[]];
    $debitProblem=static function(string $piece,DomainException $failure)use(&$debitState):void{
        $debitState['errors'][$piece]=$failure->getMessage();
        if(http_response_code()<400)http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);
    };
    try{$debitRequests->assertAccess(true);$debitState['can_write']=true;}catch(DomainException $failure){if($failure->getCode()!==403)throw $failure;}
    $debitExplicitHistory=isset($_GET['debit_history_q'])||isset($_GET['debit_history_page']);
    $debitSlot=hash('sha256',$debitActor['club_id']."\0".$debitActor['id']."\0".DebitDraft::section($debitEntry));
    if($debitPostedDraft!==null)$debitDraft=DebitDraft::get($_SESSION,$debitActor,$debitEntry,$debitPostedDraft['id']);
    elseif(isset($_GET['debit_draft']))$debitDraft=DebitDraft::get($_SESSION,$debitActor,$debitEntry,$_GET['debit_draft']);
    elseif(!$debitExplicitHistory){$stored=$_SESSION['general_settings_slots'][$debitSlot]??null;if(is_string($stored)&&isset($_SESSION['general_settings_drafts'][$stored]))$debitDraft=DebitDraft::get($_SESSION,$debitActor,$debitEntry,$stored);}
    $debitWasConfirmed=$debitDraft&&$debitDraft['accepted']!==null;
    if(isset($_GET['debit_first']))try{
        if(!is_string($_GET['debit_first']))throw new DomainException('Eigene ursprüngliche Vorankündigungsbestätigung nicht verfügbar.',404);
        $first=$debitRequests->receipt($_GET['debit_first']);
        if($first['input']['entry']!==$debitEntry||$first['result']['source_document']!==$debitDocument)throw new DomainException('Eigene ursprüngliche Vorankündigungsbestätigung nicht verfügbar.',404);
        $debitState['receipt']=$first;
    }catch(DomainException $failure){$debitProblem('receipt',$failure);}
    elseif($debitDraft)try{$debitState['receipt']=$debitRequests->receipt($debitDraft['key']);}catch(DomainException $failure){if($failure->getCode()!==404||$debitDraft['accepted']!==null)$debitProblem('receipt',$failure);}
    if($first=$debitState['receipt']){
        foreach($_SESSION['general_settings_drafts']??[]as$stored)if($stored['actor']===$debitActor['id']&&$stored['club']===$debitActor['club_id']&&$stored['section']===DebitDraft::section($debitEntry)&&$stored['key']===$first['key'])DebitDraft::store($_SESSION,DebitDraft::complete($stored,$first['key']));
        if($debitDraft&&$debitDraft['key']===$first['key'])$debitDraft=DebitDraft::complete($debitDraft,$first['key']);
        if($error===\Kulturhus\Native\Language\DebitNoticeMessages::text('Vorankündigungsausgang nicht bestätigt. Die ursprüngliche persönliche Entscheidung zuerst lesend prüfen.',$debitMessages)){$error='';if(http_response_code()===503)http_response_code(200);}
    }
    if(isset($_GET['debit_new'])&&$debitWasConfirmed&&!isset($_GET['debit_first'])&&!isset($_GET['debit_draft'])&&$debitState['errors']===[]){$debitDraft=null;$debitState['receipt']=null;}
    try{
        $debitState['history']=$debitRequests->history($debitEntry,['query'=>$_GET['debit_history_q']??'','page'=>$_GET['debit_history_page']??'1']);
        if($debitState['history']['error']!=='')throw new DomainException($debitState['history']['error'],422);
    }catch(DomainException $failure){$debitProblem('history',$failure);}
    if($debitState['receipt'])$debitState['source']=$debitState['receipt']['review']['source']['claim'];
    else try{$debitState['source']=$debitRequests->source($debitEntry);}catch(DomainException $failure){$debitProblem('source',$failure);}
    if(!$debitDraft&&!$debitState['receipt']&&!isset($_GET['debit_first'])&&!isset($_GET['debit_closed'])&&!$debitExplicitHistory&&$debitState['errors']===[]&&$debitState['can_write']){
        $today=(new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        $debitDraft=DebitDraft::open($_SESSION,$debitActor,$debitRequests->initial($debitEntry,$today,max($debitState['source']['entry']['due_date'],(new DateTimeImmutable('today',new DateTimeZone('Europe/Berlin')))->modify('+14 days')->format('Y-m-d'))),true);
    }
}catch(DomainException $failure){http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=\Kulturhus\Native\Language\DebitNoticeMessages::text($failure->getMessage(),$debitMessages);}
catch(Throwable){http_response_code(503);$error=\Kulturhus\Native\Language\DebitNoticeMessages::text('Vorankündigungsbereich konnte nicht geladen werden.',$debitMessages);}
