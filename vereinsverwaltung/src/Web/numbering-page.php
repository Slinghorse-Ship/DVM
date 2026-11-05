<?php declare(strict_types=1);
use Kulturhus\Verein\Settings\{GeneralDraft,GeneralInput,GeneralRequests};
$numberingState=null;$numberingDraft=null;$numberingDraftIndex=[];$numberingMessages=[];
if(!$numberingNativeRoute||!$workspaceAllowed)return;
$numberingMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\NumberingMessages::text($error,$numberingMessages);
try{
    $numberingRequests??=new GeneralRequests($db,(string)$_SESSION['actor']);$numberingActor=$numberingRequests->assertAccess('numbers');
    if($numberingPostedDraft!==null)$numberingDraft=GeneralDraft::get($_SESSION,$numberingActor,'numbers',$numberingPostedDraft['id']);elseif(isset($_GET['number_draft']))$numberingDraft=GeneralDraft::get($_SESSION,$numberingActor,'numbers',$_GET['number_draft']);
    $date=$numberingDraft['input']['date']??gmdate('Y-m-d');$numberingState=\Kulturhus\Verein\Web\NumberingWorkspace::current($db,(string)$_SESSION['actor'],$date)+['receipt'=>null,'history'=>null,'revisions'=>null,'revision'=>null,'errors'=>[]];
    if($numberingState['error']!==''&&http_response_code()<400)http_response_code(422);
    if(isset($_GET['number_receipt'])){
        if(!is_string($_GET['number_receipt']))throw new DomainException('Ursprüngliche Nummernbestätigung auswählen.',422);
        try{$numberingState['receipt']=$numberingRequests->receipt('numbers',$_GET['number_receipt']);}catch(DomainException $failure){if($failure->getCode()!==404)throw$failure;$numberingState['errors']['receipt']=$failure->getMessage();http_response_code(404);}
        if($numberingDraft&&$numberingState['receipt']&&$numberingDraft['key']===$numberingState['receipt']['key']){if($numberingState['receipt']['input']!==GeneralInput::capture('numbers',$numberingDraft['input']))throw new DomainException('Diese Einstellungskennung gehört zu anderen ursprünglichen Angaben.',409);$numberingDraft['accepted']=$numberingState['receipt']['key'];$numberingDraft['pending']=false;$_SESSION['general_settings_drafts'][$numberingDraft['id']]=$numberingDraft;}
    }
    if(!$numberingDraft&&!$numberingState['receipt']&&!isset($_GET['number_receipt'])&&$numberingState['can_write'])$numberingDraft=GeneralDraft::open($_SESSION,$numberingActor,'numbers',['version'=>(string)$numberingState['current']['version'],'data'=>$numberingState['current']['data'],'date'=>$date]);
    foreach(['history','revisions']as$kind){try{$numberingState[$kind]=$kind==='history'?$numberingRequests->history('numbers',['query'=>$_GET['number_history_q']??'','page'=>$_GET['number_history_page']??'1']):$numberingRequests->revisions('numbers',$_GET['number_revisions_page']??'1');if($numberingState[$kind]['error']!==''){$numberingState['errors'][$kind]=$numberingState[$kind]['error'];if(http_response_code()<400)http_response_code(422);}}catch(DomainException $failure){$numberingState['errors'][$kind]=$failure->getMessage();if(http_response_code()<400)http_response_code(in_array($failure->getCode(),[403,503],true)?$failure->getCode():422);}}
    if(isset($_GET['number_revision']))$numberingState['revision']=$numberingRequests->revision('numbers',$_GET['number_revision']);
    foreach($_SESSION['general_settings_drafts']??[]as$stored)if($stored['actor']===$numberingActor['id']&&$stored['club']===$numberingActor['club_id']&&$stored['section']==='numbers'&&$stored['accepted']===null)$numberingDraftIndex[]=['id'=>$stored['id'],'key'=>$stored['key'],'pending'=>$stored['pending']];
}catch(DomainException $failure){http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():403);$error=\Kulturhus\Native\Language\NumberingMessages::text($failure->getMessage(),$numberingMessages);$numberingDraft=null;}
catch(Throwable){http_response_code(503);$error=\Kulturhus\Native\Language\NumberingMessages::text('Nummernkreise konnten nicht geladen werden.',$numberingMessages);$numberingDraft=null;}
