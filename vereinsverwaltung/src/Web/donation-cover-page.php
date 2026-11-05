<?php declare(strict_types=1);
use Kulturhus\Verein\Finance\Donations\Cover\{CoverDraft, CoverRequests, CoverSource};

$coverState=null;$coverDraft=null;$coverMessages=[];
if(!$coverRoute)return;
try{
    $coverMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();
    $error=\Kulturhus\Native\Language\DonationCoverMessages::text($error,$coverMessages);
    if(!$workspaceAllowed)throw new DomainException('Begleitschreiben am eigenen ursprünglichen Finanzdokument öffnen.',403);
    $coverRequests??=new CoverRequests($db,(string)$_SESSION['actor']);$coverActor=$coverRequests->assertAccess();
    if($coverRequests->parentDocument($coverReceipt)!==$coverDocument)throw new DomainException('Ursprüngliche Bescheinigung dieses Begleitschreibens nicht verfügbar.',404);
    $coverState=['source'=>null,'receipt'=>null,'history'=>null,'can_write'=>false,'errors'=>[]];
    $coverProblem=static function(string $piece,DomainException $failure)use(&$coverState):void{
        $coverState['errors'][$piece]=$failure->getMessage();
        if(http_response_code()<400)http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);
    };
    try{$coverRequests->assertAccess(true);$coverState['can_write']=true;}catch(DomainException $failure){if($failure->getCode()!==403)throw $failure;}
    $coverExplicitHistory=isset($_GET['cover_history_q'])||isset($_GET['cover_history_page']);
    $coverSlot=hash('sha256',$coverActor['club_id']."\0".$coverActor['id']."\0".CoverDraft::section($coverReceipt));
    if($coverPostedDraft!==null)$coverDraft=CoverDraft::get($_SESSION,$coverActor,$coverReceipt,$coverPostedDraft['id']);
    elseif(isset($_GET['cover_draft']))$coverDraft=CoverDraft::get($_SESSION,$coverActor,$coverReceipt,$_GET['cover_draft']);
    elseif(!$coverExplicitHistory){$stored=$_SESSION['general_settings_slots'][$coverSlot]??null;if(is_string($stored)&&isset($_SESSION['general_settings_drafts'][$stored]))$coverDraft=CoverDraft::get($_SESSION,$coverActor,$coverReceipt,$stored);}
    $coverWasConfirmed=$coverDraft&&$coverDraft['accepted']!==null;
    if(isset($_GET['cover_first']))try{
        if(!is_string($_GET['cover_first']))throw new DomainException('Eigene ursprüngliche Begleitschreibenbestätigung nicht verfügbar.',404);
        $first=$coverRequests->receipt($_GET['cover_first']);
        if($first['input']['receipt']!==$coverReceipt||$first['result']['source_document']!==$coverDocument)throw new DomainException('Eigene ursprüngliche Begleitschreibenbestätigung nicht verfügbar.',404);
        $coverState['receipt']=$first;
    }catch(DomainException $failure){$coverProblem('receipt',$failure);}
    elseif($coverDraft)try{$coverState['receipt']=$coverRequests->receipt($coverDraft['key']);}catch(DomainException $failure){if($failure->getCode()!==404||$coverDraft['accepted']!==null)$coverProblem('receipt',$failure);}
    if($first=$coverState['receipt']){
        foreach($_SESSION['general_settings_drafts']??[]as$stored)if($stored['actor']===$coverActor['id']&&$stored['club']===$coverActor['club_id']&&$stored['section']===CoverDraft::section($coverReceipt)&&$stored['key']===$first['key'])CoverDraft::store($_SESSION,CoverDraft::complete($stored,$first['key']));
        if($coverDraft&&$coverDraft['key']===$first['key'])$coverDraft=CoverDraft::complete($coverDraft,$first['key']);
        if($error===\Kulturhus\Native\Language\DonationCoverMessages::text('Begleitschreibenausgang nicht bestätigt. Die ursprüngliche persönliche Entscheidung zuerst lesend prüfen.',$coverMessages)){$error='';if(http_response_code()===503)http_response_code(200);}
    }
    if(isset($_GET['cover_new'])&&$coverWasConfirmed&&!isset($_GET['cover_first'])&&!isset($_GET['cover_draft'])&&$coverState['errors']===[]){$coverDraft=null;$coverState['receipt']=null;}
    try{
        $coverState['history']=$coverRequests->history($coverReceipt,['query'=>$_GET['cover_history_q']??'','page'=>$_GET['cover_history_page']??'1']);
        if($coverState['history']['error']!=='')throw new DomainException($coverState['history']['error'],422);
    }catch(DomainException $failure){$coverProblem('history',$failure);}
    if($coverState['receipt'])$coverState['source']=$coverState['receipt']['review']['source']['certificate'];
    else try{$coverState['source']=CoverSource::read($db,(string)$_SESSION['actor'],$coverReceipt);}catch(DomainException $failure){$coverProblem('source',$failure);}
    if(!$coverDraft&&!$coverState['receipt']&&!isset($_GET['cover_first'])&&!isset($_GET['cover_closed'])&&!$coverExplicitHistory&&$coverState['errors']===[]&&$coverState['can_write']){
        $today=(new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        $coverDraft=CoverDraft::open($_SESSION,$coverActor,$coverRequests->initial($coverReceipt,$today),true);
    }
}catch(DomainException $failure){http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=\Kulturhus\Native\Language\DonationCoverMessages::text($failure->getMessage(),$coverMessages);}
catch(Throwable){http_response_code(503);$error=\Kulturhus\Native\Language\DonationCoverMessages::text('Begleitschreibenbereich konnte nicht geladen werden.',$coverMessages);}
