<?php declare(strict_types=1);
use Kulturhus\Verein\Finance\Contributions\{ContributionRunInput,ContributionRunDraft,ContributionRunRequests};

$contributionrunState=null;$contributionrunDraft=null;$contributionrunMessages=[];if(!$contributionrunRoute)return;
try{
    $contributionrunMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\ContributionMessages::text($error,$contributionrunMessages);
    if(!$workspaceAllowed)throw new DomainException('Beitragslauf im eigenen Finanzbereich öffnen.',403);$contributionrunRequests??=new ContributionRunRequests($db,(string)$_SESSION['actor']);$contributionrunActor=$contributionrunRequests->assertAccess();
    $contributionrunState=['current'=>null,'receipt'=>null,'history'=>null,'errors'=>[],'can_write'=>false];
    $crProblem=static function(string$piece,DomainException$failure)use(&$contributionrunState):void{$contributionrunState['errors'][$piece]=$failure->getMessage();if(http_response_code()<400)http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);};
    try{$contributionrunRequests->assertAccess(true);$contributionrunState['can_write']=true;}catch(DomainException$failure){if($failure->getCode()!==403)throw$failure;}
    $crExplicitHistory=isset($_GET['contributionrun_history_q'])||isset($_GET['contributionrun_history_page']);$crSlot=hash('sha256',$contributionrunActor['club_id']."\0".$contributionrunActor['id']."\0".ContributionRunRequests::SECTION);
    if($contributionrunPostedDraft!==null)$contributionrunDraft=ContributionRunDraft::get($_SESSION,$contributionrunActor,$contributionrunPostedDraft['id']);
    elseif(isset($_GET['contributionrun_draft']))$contributionrunDraft=ContributionRunDraft::get($_SESSION,$contributionrunActor,$_GET['contributionrun_draft']);
    elseif(!$crExplicitHistory){$stored=$_SESSION['general_settings_slots'][$crSlot]??null;if(is_string($stored)&&isset($_SESSION['general_settings_drafts'][$stored]))$contributionrunDraft=ContributionRunDraft::get($_SESSION,$contributionrunActor,$stored);}
    $crWasConfirmed=$contributionrunDraft&&$contributionrunDraft['accepted']!==null;
    if(isset($_GET['contributionrun_receipt']))try{if(!is_string($_GET['contributionrun_receipt']))throw new DomainException('Eigene ursprüngliche Beitragsbestätigung nicht verfügbar.',404);$contributionrunState['receipt']=$contributionrunRequests->receipt($_GET['contributionrun_receipt']);}catch(DomainException$failure){$crProblem('receipt',$failure);}
    elseif($contributionrunDraft)try{$contributionrunState['receipt']=$contributionrunRequests->receipt($contributionrunDraft['key']);}catch(DomainException$failure){if($failure->getCode()!==404||$contributionrunDraft['accepted']!==null)$crProblem('receipt',$failure);}
    if($first=$contributionrunState['receipt']){
        foreach($_SESSION['general_settings_drafts']??[]as$stored)if($stored['actor']===$contributionrunActor['id']&&$stored['club']===$contributionrunActor['club_id']&&$stored['key']===$first['key']&&$stored['section']===ContributionRunRequests::SECTION)ContributionRunDraft::store($_SESSION,ContributionRunDraft::complete($stored,$first['key']));
        if($contributionrunDraft&&$contributionrunDraft['key']===$first['key'])$contributionrunDraft=ContributionRunDraft::complete($contributionrunDraft,$first['key']);
        if($error===\Kulturhus\Native\Language\ContributionMessages::text('Beitragsausgang nicht bestätigt. Die ursprüngliche persönliche Bestätigung zuerst lesend prüfen.',$contributionrunMessages)){$error='';if(http_response_code()===503)http_response_code(200);}
    }
    if(isset($_GET['contributionrun_new'])&&$crWasConfirmed&&!isset($_GET['contributionrun_receipt'])&&!isset($_GET['contributionrun_draft'])&&$contributionrunState['errors']===[]){$contributionrunDraft=null;$contributionrunState['receipt']=null;}
    try{$contributionrunState['history']=$contributionrunRequests->history(['query'=>$_GET['contributionrun_history_q']??'','page'=>$_GET['contributionrun_history_page']??'1']);if($contributionrunState['history']['error']!=='')throw new DomainException($contributionrunState['history']['error'],422);}catch(DomainException$failure){$crProblem('history',$failure);}
    if(!$contributionrunDraft&&!$contributionrunState['receipt']&&!isset($_GET['contributionrun_receipt'])&&!isset($_GET['contributionrun_closed'])&&!$crExplicitHistory&&$contributionrunState['errors']===[]&&$contributionrunState['can_write'])$contributionrunDraft=ContributionRunDraft::open($_SESSION,$contributionrunActor,['data'=>['day'=>ContributionRunInput::today()]],true);
    $day=ContributionRunInput::today();if($contributionrunDraft&&$contributionrunDraft['accepted']===null){try{$day=ContributionRunInput::normalize($contributionrunDraft['input'])['day'];}catch(DomainException$failure){$day=null;$crProblem('day',$failure);}}
    if($day!==null)try{$contributionrunState['current']=$contributionrunRequests->state($day);}catch(DomainException$failure){$crProblem('current',$failure);}
}catch(DomainException$failure){http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=\Kulturhus\Native\Language\ContributionMessages::text($failure->getMessage(),$contributionrunMessages);}
catch(Throwable){http_response_code(503);$error=\Kulturhus\Native\Language\ContributionMessages::text('Beitragsbereich konnte nicht geladen werden.',$contributionrunMessages);}
