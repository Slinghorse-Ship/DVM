<?php declare(strict_types=1);
use Kulturhus\Verein\Settings\{GeneralDraft,TextInput,TextContext,TextRequests};
$textsettingsState=null;$textsettingsDraft=null;$textsettingsDraftIndex=[];$textsettingsMessages=[];
if(!$textsettingsNativeRoute)return;
try{$textsettingsMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\TextSettingsMessages::text($error,$textsettingsMessages);}catch(Throwable){}
try{
    if(!$workspaceAllowed)throw new DomainException('Aktuelle Vereinseinstellungen für Textbausteine erforderlich.',403);TextInput::section($textsettingsScope);
    $textsettingsRequests??=new TextRequests($db,(string)$_SESSION['actor']);$textsettingsActor=$textsettingsRequests->assertAccess($textsettingsScope);
    $textsettingsState=['current'=>null,'receipt'=>null,'history'=>null,'revisions'=>null,'revision'=>null,'errors'=>[]];
    $textsettingsProblem=static function(string $piece,DomainException$failure)use(&$textsettingsState):void{$textsettingsState['errors'][$piece]=$failure->getMessage();if(http_response_code()<400)http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);};
    if($textsettingsPostedDraft!==null)$textsettingsDraft=GeneralDraft::get($_SESSION,$textsettingsActor,$textsettingsScope,$textsettingsPostedDraft['id']);
    elseif(isset($_GET['textsettings_draft']))$textsettingsDraft=GeneralDraft::get($_SESSION,$textsettingsActor,$textsettingsScope,$_GET['textsettings_draft']);
    if(isset($_GET['textsettings_receipt']))try{
        if(!is_string($_GET['textsettings_receipt']))throw new DomainException('Ursprüngliche Textbausteinbestätigung auswählen.',422);$textsettingsState['receipt']=$textsettingsRequests->receipt($textsettingsScope,$_GET['textsettings_receipt']);
        foreach($_SESSION['general_settings_drafts']??[]as$id=>$stored)if($stored['actor']===$textsettingsActor['id']&&$stored['club']===$textsettingsActor['club_id']&&$stored['section']===$textsettingsScope&&$stored['key']===$textsettingsState['receipt']['key'])$_SESSION['general_settings_drafts'][$id]=$textsettingsComplete($stored,$stored['key']);
        if($textsettingsDraft&&$textsettingsDraft['key']===$textsettingsState['receipt']['key'])$textsettingsDraft=$textsettingsComplete($textsettingsDraft,$textsettingsDraft['key']);
    }catch(DomainException$failure){$textsettingsProblem('receipt',$failure);}
    if($textsettingsDraft&&($textsettingsDraft['pending']||$textsettingsDraft['accepted']!==null)&&!$textsettingsState['receipt'])try{
        $textsettingsState['receipt']=$textsettingsRequests->receipt($textsettingsScope,$textsettingsDraft['key']);$textsettingsDraft=$textsettingsComplete($textsettingsDraft,$textsettingsDraft['key']);$_SESSION['general_settings_drafts'][$textsettingsDraft['id']]=$textsettingsDraft;
    }catch(DomainException$failure){if($failure->getCode()!==404)$textsettingsProblem('receipt',$failure);}
    if($textsettingsState['receipt']&&$error===\Kulturhus\Native\Language\TextSettingsMessages::text('Textbausteinausgang nicht bestätigt. Die ursprüngliche Bestätigung über ihre Kennung lesen.',$textsettingsMessages)){$error='';if(http_response_code()===503)http_response_code(200);}
    foreach(['current','history','revisions','revision']as$piece)try{
        if($piece==='current')$textsettingsState[$piece]=$textsettingsRequests->current($textsettingsScope);
        elseif($piece==='history'){$textsettingsState[$piece]=$textsettingsRequests->history($textsettingsScope,['query'=>$_GET['textsettings_history_q']??'','page'=>$_GET['textsettings_history_page']??'1']);if($textsettingsState[$piece]['error']!=='')throw new DomainException($textsettingsState[$piece]['error'],422);}
        elseif($piece==='revisions'){$textsettingsState[$piece]=$textsettingsRequests->revisions($textsettingsScope,$_GET['textsettings_revisions_page']??'1');if($textsettingsState[$piece]['error']!=='')throw new DomainException($textsettingsState[$piece]['error'],422);}
        elseif(isset($_GET['textsettings_revision']))$textsettingsState[$piece]=$textsettingsRequests->revision($textsettingsScope,$_GET['textsettings_revision']);
    }catch(DomainException$failure){$textsettingsProblem($piece,$failure);}
    $explicitHistory=isset($_GET['textsettings_history_q'])||isset($_GET['textsettings_history_page'])||isset($_GET['textsettings_revisions_page'])||isset($_GET['textsettings_revision']);
    if(!$textsettingsDraft&&!$textsettingsState['receipt']&&!isset($_GET['textsettings_receipt'])&&!$explicitHistory&&!isset($_GET['textsettings_closed'])&&$textsettingsState['current']){
        $slot=hash('sha256',$textsettingsActor['club_id']."\0".$textsettingsActor['id']."\0".$textsettingsScope);$stored=$_SESSION['general_settings_slots'][$slot]??null;
        if(!isset($_GET['textsettings_new'])&&is_string($stored)&&isset($_SESSION['general_settings_drafts'][$stored])){$candidate=GeneralDraft::get($_SESSION,$textsettingsActor,$textsettingsScope,$stored);if($candidate['accepted']===null)$textsettingsDraft=$candidate;}
        if(!$textsettingsDraft)$textsettingsDraft=GeneralDraft::open($_SESSION,$textsettingsActor,$textsettingsScope,TextInput::initial($textsettingsScope,$textsettingsState['current']),isset($_GET['textsettings_new']));
    }
    $textsettingsState['preview']=null;
    if($textsettingsDraft&&$textsettingsDraft['accepted']===null&&!$textsettingsDraft['pending']&&is_array($textsettingsDraft['review']))try{
        $prepared=$textsettingsRequests->reviewedPreview($textsettingsScope,$textsettingsDraft['input'],$textsettingsDraft['review']);
        if(!hash_equals($textsettingsDraft['review']['hash'],$prepared['review']['hash'])||$textsettingsDraft['input']['version']!==(string)$prepared['review']['current']['version'])throw new DomainException('Die Textbausteine oder ihre Quellen wurden inzwischen geändert. Aktuellen Stand bewusst erneut prüfen.',409);
        $textsettingsState['preview']=$prepared['document'];
    }catch(DomainException$failure){$textsettingsProblem('preview',$failure);}
    foreach($_SESSION['general_settings_drafts']??[]as$stored)if($stored['actor']===$textsettingsActor['id']&&$stored['club']===$textsettingsActor['club_id']&&TextInput::supports($stored['section'])&&$stored['accepted']===null)$textsettingsDraftIndex[]=['id'=>$stored['id'],'key'=>$stored['key'],'section'=>$stored['section'],'pending'=>$stored['pending']];
}catch(DomainException$failure){http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=\Kulturhus\Native\Language\TextSettingsMessages::text($failure->getMessage(),$textsettingsMessages);$textsettingsState=null;$textsettingsDraft=null;}
catch(Throwable){http_response_code(503);$error=\Kulturhus\Native\Language\TextSettingsMessages::text('Textbausteine konnten nicht geladen werden.',$textsettingsMessages);$textsettingsState=null;$textsettingsDraft=null;}
