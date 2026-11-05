<?php declare(strict_types=1);
use Kulturhus\Verein\Settings\{GeneralDraft,GeneralRequests};
$generalState=null;$generalDraft=null;$generalMessages=[];$generalDraftIndex=[];
if(!$generalNativeRoute||!$workspaceAllowed)return;
$generalMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();
$error=\Kulturhus\Native\Language\GeneralSettingsMessages::text($error,$generalMessages);
try{
    $generalRequests??=new GeneralRequests($db,(string)$_SESSION['actor']);$generalActor=$generalRequests->assertAccess($generalSection);$generalCurrent=$settings->section($generalSection);
    if($generalPostedDraft!==null)$generalDraft=GeneralDraft::get($_SESSION,$generalActor,$generalSection,$generalPostedDraft['id']);
    elseif(isset($_GET['settings_draft']))$generalDraft=GeneralDraft::get($_SESSION,$generalActor,$generalSection,$_GET['settings_draft']);
    $generalState=['current'=>$generalCurrent,'receipt'=>null,'history'=>null,'revisions'=>null,'revision'=>null,'errors'=>[]];
    if(isset($_GET['settings_receipt'])){
        if(!is_string($_GET['settings_receipt']))throw new DomainException('Ursprüngliche Einstellungsbestätigung auswählen.',422);
        try{$generalState['receipt']=$generalRequests->receipt($generalSection,$_GET['settings_receipt']);}
        catch(DomainException $failure){if($failure->getCode()!==404)throw$failure;$generalState['errors']['receipt']=$failure->getMessage();http_response_code(404);}
        if($generalDraft&&$generalState['receipt']&&$generalState['receipt']['key']===$generalDraft['key']){
            if($generalState['receipt']['input']!==\Kulturhus\Verein\Settings\GeneralInput::capture($generalSection,$generalDraft['input']))throw new DomainException('Diese Einstellungskennung gehört zu anderen ursprünglichen Angaben.',409);
            $generalDraft['pending']=false;$generalDraft['accepted']=$generalState['receipt']['key'];$_SESSION['general_settings_drafts'][$generalDraft['id']]=$generalDraft;
        }
    }
    if(!$generalDraft&&!$generalState['receipt']&&!isset($_GET['settings_receipt']))$generalDraft=GeneralDraft::open($_SESSION,$generalActor,$generalSection,GeneralDraft::initial($generalSection,$generalCurrent));
    foreach(['history','revisions']as$kind){
        try{$generalState[$kind]=$kind==='history'?$generalRequests->history($generalSection,['query'=>$_GET['settings_history_q']??'','page'=>$_GET['settings_history_page']??'1']):$generalRequests->revisions($generalSection,$_GET['settings_revisions_page']??'1');if($generalState[$kind]['error']!==''){$generalState['errors'][$kind]=$generalState[$kind]['error'];if(http_response_code()<400)http_response_code(422);}}
        catch(DomainException $failure){$generalState['errors'][$kind]=$failure->getMessage();if(http_response_code()<400)http_response_code(in_array($failure->getCode(),[403,503],true)?$failure->getCode():422);}
    }
    if(isset($_GET['settings_revision']))$generalState['revision']=$generalRequests->revision($generalSection,$_GET['settings_revision']);
    foreach($_SESSION['general_settings_drafts']??[]as$stored)if($stored['actor']===$generalActor['id']&&$stored['club']===$generalActor['club_id']&&$stored['section']===$generalSection&&$stored['accepted']===null)$generalDraftIndex[]=['id'=>$stored['id'],'key'=>$stored['key'],'pending'=>$stored['pending']];
}catch(DomainException $failure){http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():403);$error=\Kulturhus\Native\Language\GeneralSettingsMessages::text($failure->getMessage(),$generalMessages);$generalDraft=null;}
catch(Throwable){http_response_code(503);$error=\Kulturhus\Native\Language\GeneralSettingsMessages::text('Einstellungsbereich konnte nicht geladen werden.',$generalMessages);$generalDraft=null;}
