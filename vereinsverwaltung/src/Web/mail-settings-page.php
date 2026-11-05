<?php declare(strict_types=1);
use Kulturhus\Verein\Settings\{MailInput,MailDraft,MailSettingsRequests};
$mailsettingsState=null;$mailsettingsDraft=null;$mailsettingsDraftIndex=[];$mailsettingsMessages=[];
if(!$mailsettingsNativeRoute)return;
try{$mailsettingsMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\MailSettingsMessages::text($error,$mailsettingsMessages);}catch(Throwable){}
try{
    if(!$workspaceAllowed)throw new DomainException('Nur der Superuser darf Postfächer und Versandzuordnungen ändern.',403);
    if(!is_string($mailsettingsScope))throw new DomainException('Eigenen Postfachbereich auswählen.',422);
    $mailsettingsRequests??=new MailSettingsRequests($db,(string)$_SESSION['actor']);$mailsettingsActor=$mailsettingsRequests->assertAccess($mailsettingsScope);
    $mailsettingsState=['current'=>[],'receipt'=>null,'history'=>null,'errors'=>[],'inactive_basis'=>$_SESSION['mail_settings_inactive_basis'][$mailsettingsActor['club_id'].':'.$mailsettingsActor['id']]??null];
    $mailsettingsProblem=static function(string $piece,DomainException $failure)use(&$mailsettingsState):void{$mailsettingsState['errors'][$piece]=$failure->getMessage();if(http_response_code()<400)http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);};
    $mailsettingsMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\MailSettingsMessages::text($error,$mailsettingsMessages);
    if($mailsettingsPostedDraft!==null)$mailsettingsDraft=MailDraft::get($_SESSION,$mailsettingsActor,$mailsettingsScope,$mailsettingsPostedDraft['id']);
    elseif(isset($_GET['mailsettings_draft']))$mailsettingsDraft=MailDraft::get($_SESSION,$mailsettingsActor,$mailsettingsScope,$_GET['mailsettings_draft']);
    if(isset($_GET['mailsettings_receipt']))try{
        if(!is_string($_GET['mailsettings_receipt']))throw new DomainException('Ursprüngliche Postfachbestätigung auswählen.',422);$mailsettingsState['receipt']=$mailsettingsRequests->receipt($mailsettingsScope,$_GET['mailsettings_receipt']);
        foreach($_SESSION['mail_settings_drafts']??[]as $id=>$stored)if($stored['actor']===$mailsettingsActor['id']&&$stored['club']===$mailsettingsActor['club_id']&&$stored['section']===$mailsettingsScope&&$stored['key']===$mailsettingsState['receipt']['key'])$_SESSION['mail_settings_drafts'][$id]=MailDraft::accepted($stored,$mailsettingsState['receipt']['key']);
        if($mailsettingsDraft&&$mailsettingsDraft['key']===$mailsettingsState['receipt']['key']){$mailsettingsDraft=MailDraft::accepted($mailsettingsDraft,$mailsettingsState['receipt']['key']);$_SESSION['mail_settings_drafts'][$mailsettingsDraft['id']]=$mailsettingsDraft;}
    }catch(DomainException $failure){$mailsettingsProblem('receipt',$failure);}
    if($mailsettingsDraft&&($mailsettingsDraft['pending']||$mailsettingsDraft['accepted']!==null)&&!$mailsettingsState['receipt'])try{$mailsettingsState['receipt']=$mailsettingsRequests->receipt($mailsettingsScope,$mailsettingsDraft['key']);$mailsettingsDraft=MailDraft::accepted($mailsettingsDraft,$mailsettingsState['receipt']['key']);$_SESSION['mail_settings_drafts'][$mailsettingsDraft['id']]=$mailsettingsDraft;}catch(DomainException $failure){if($failure->getCode()!==404)$mailsettingsProblem('receipt',$failure);}
    if($mailsettingsState['receipt']&&$error===\Kulturhus\Native\Language\MailSettingsMessages::text('Postfachausgang nicht bestätigt. Die ursprüngliche Bestätigung über ihre Kennung lesen.',$mailsettingsMessages)){$error='';if(http_response_code()===503)http_response_code(200);}
    try{$mailsettingsState['history']=$mailsettingsRequests->history($mailsettingsScope,['query'=>$_GET['mailsettings_history_q']??'','page'=>$_GET['mailsettings_history_page']??'1']);if($mailsettingsState['history']['error']!=='')throw new DomainException($mailsettingsState['history']['error'],422);}catch(DomainException $failure){$mailsettingsProblem('history',$failure);}
    try{$mailsettingsKey=\Kulturhus\Verein\ContactArchive::serverKey();$mailsettingsDraftService=new MailDraft($mailsettingsKey);$mailsettingsRequests=new MailSettingsRequests($db,(string)$_SESSION['actor'],$mailsettingsKey);}
    catch(DomainException $failure){$mailsettingsProblem('key',new DomainException($failure->getMessage(),503,$failure));}
    if(isset($mailsettingsKey))foreach(MailInput::SCOPES as $area)try{$mailsettingsState['current'][$area]=$mailsettingsRequests->current($area);}catch(DomainException $failure){$mailsettingsProblem($area,$failure);}catch(Throwable){$mailsettingsProblem($area,new DomainException('Dieses Postfach konnte nicht geladen werden.',503));}
    $explicitHistory=isset($_GET['mailsettings_history_q'])||isset($_GET['mailsettings_history_page']);
    if(!$mailsettingsDraft&&!$mailsettingsState['receipt']&&!isset($_GET['mailsettings_receipt'])&&!$explicitHistory&&!isset($_GET['mailsettings_closed'])){
        $slot=hash('sha256',$mailsettingsActor['club_id']."\0".$mailsettingsActor['id']."\0".$mailsettingsScope);$stored=$_SESSION['mail_settings_slots'][$slot]??null;
        if(!isset($_GET['mailsettings_new'])&&is_string($stored)&&isset($_SESSION['mail_settings_drafts'][$stored])){$candidate=MailDraft::get($_SESSION,$mailsettingsActor,$mailsettingsScope,$stored);if($candidate['accepted']===null)$mailsettingsDraft=$candidate;}
        if(!$mailsettingsDraft&&isset($mailsettingsDraftService,$mailsettingsState['current'][$mailsettingsScope]))$mailsettingsDraft=$mailsettingsDraftService->open($_SESSION,$mailsettingsActor,$mailsettingsScope,MailInput::initial($mailsettingsScope,$mailsettingsState['current'][$mailsettingsScope]),isset($_GET['mailsettings_new']));
    }
    foreach($_SESSION['mail_settings_drafts']??[]as $stored)if($stored['actor']===$mailsettingsActor['id']&&$stored['club']===$mailsettingsActor['club_id']&&$stored['accepted']===null)$mailsettingsDraftIndex[]=['id'=>$stored['id'],'section'=>$stored['section'],'key'=>$stored['key'],'pending'=>$stored['pending']];
}catch(DomainException $failure){http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=\Kulturhus\Native\Language\MailSettingsMessages::text($failure->getMessage(),$mailsettingsMessages);$mailsettingsDraft=null;$mailsettingsState=null;}
catch(Throwable){http_response_code(503);$error=\Kulturhus\Native\Language\MailSettingsMessages::text('Postfacheinstellungen konnten nicht geladen werden.',$mailsettingsMessages);$mailsettingsDraft=null;$mailsettingsState=null;}
