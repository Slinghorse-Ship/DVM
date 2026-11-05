<?php declare(strict_types=1);
use Kulturhus\Verein\Settings\{GeneralDraft,PaperDraft,PaperInput,PaperRequests};
$papersettingsState=null;$papersettingsDraft=null;$papersettingsDraftIndex=[];$papersettingsMessages=[];
if(!$papersettingsNativeRoute)return;
try{$papersettingsMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\PaperSettingsMessages::text($error,$papersettingsMessages);}catch(Throwable){}
try{
    if(!$workspaceAllowed)throw new DomainException('Aktuelle Vereinseinstellungen für Briefpapier erforderlich.',403);
    $papersettingsRequests??=new PaperRequests($db,(string)$_SESSION['actor']);$papersettingsActor=$papersettingsRequests->assertAccess('paper');
    $papersettingsState=['current'=>null,'receipt'=>null,'history'=>null,'revisions'=>null,'revision'=>null,'logo'=>null,'errors'=>[]];
    $papersettingsProblem=static function(string $piece,DomainException$failure)use(&$papersettingsState):void{$papersettingsState['errors'][$piece]=$failure->getMessage();if(http_response_code()<400)http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);};
    if($papersettingsPostedDraft!==null)$papersettingsDraft=GeneralDraft::get($_SESSION,$papersettingsActor,'paper',$papersettingsPostedDraft['id']);
    elseif(isset($_GET['papersettings_draft']))$papersettingsDraft=GeneralDraft::get($_SESSION,$papersettingsActor,'paper',$_GET['papersettings_draft']);
    if(isset($_GET['papersettings_receipt']))try{
        if(!is_string($_GET['papersettings_receipt']))throw new DomainException('Ursprüngliche Briefpapierbestätigung auswählen.',422);$papersettingsState['receipt']=$papersettingsRequests->receipt('paper',$_GET['papersettings_receipt']);
        foreach($_SESSION['general_settings_drafts']??[]as$id=>$stored)if($stored['actor']===$papersettingsActor['id']&&$stored['club']===$papersettingsActor['club_id']&&$stored['section']==='paper'&&$stored['key']===$papersettingsState['receipt']['key'])$_SESSION['general_settings_drafts'][$id]=PaperDraft::accepted($stored,$stored['key']);
        if($papersettingsDraft&&$papersettingsDraft['key']===$papersettingsState['receipt']['key'])$papersettingsDraft=PaperDraft::accepted($papersettingsDraft,$papersettingsDraft['key']);
    }catch(DomainException$failure){$papersettingsProblem('receipt',$failure);}
    if($papersettingsDraft&&($papersettingsDraft['pending']||$papersettingsDraft['accepted']!==null)&&!$papersettingsState['receipt'])try{
        $papersettingsState['receipt']=$papersettingsRequests->receipt('paper',$papersettingsDraft['key']);$papersettingsDraft=PaperDraft::accepted($papersettingsDraft,$papersettingsDraft['key']);$_SESSION['general_settings_drafts'][$papersettingsDraft['id']]=$papersettingsDraft;
    }catch(DomainException$failure){if($failure->getCode()!==404)$papersettingsProblem('receipt',$failure);}
    if($papersettingsState['receipt']&&$error===\Kulturhus\Native\Language\PaperSettingsMessages::text('Briefpapierausgang nicht bestätigt. Die ursprüngliche Bestätigung über ihre Kennung lesen.',$papersettingsMessages)){$error='';if(http_response_code()===503)http_response_code(200);}
    foreach(['current','history','revisions','revision']as$piece)try{
        if($piece==='current')$papersettingsState[$piece]=$papersettingsRequests->current();
        elseif($piece==='history'){$papersettingsState[$piece]=$papersettingsRequests->history('paper',['query'=>$_GET['papersettings_history_q']??'','page'=>$_GET['papersettings_history_page']??'1']);if($papersettingsState[$piece]['error']!=='')throw new DomainException($papersettingsState[$piece]['error'],422);}
        elseif($piece==='revisions'){$papersettingsState[$piece]=$papersettingsRequests->revisions('paper',$_GET['papersettings_revisions_page']??'1');if($papersettingsState[$piece]['error']!=='')throw new DomainException($papersettingsState[$piece]['error'],422);}
        elseif(isset($_GET['papersettings_revision']))$papersettingsState[$piece]=$papersettingsRequests->revision('paper',$_GET['papersettings_revision']);
    }catch(DomainException$failure){$papersettingsProblem($piece,$failure);}
    $explicitHistory=isset($_GET['papersettings_history_q'])||isset($_GET['papersettings_history_page'])||isset($_GET['papersettings_revisions_page'])||isset($_GET['papersettings_revision']);
    if(!$papersettingsDraft&&!$papersettingsState['receipt']&&!isset($_GET['papersettings_receipt'])&&!$explicitHistory&&!isset($_GET['papersettings_closed'])&&$papersettingsState['current'])$papersettingsDraft=GeneralDraft::open($_SESSION,$papersettingsActor,'paper',PaperInput::initial($papersettingsState['current']),isset($_GET['papersettings_new']));
    if($papersettingsDraft&&$papersettingsDraft['accepted']===null&&$papersettingsDraft['input']['logo']!==null)try{$papersettingsState['logo']=$papersettingsPrivate()->logo($papersettingsDraft);}catch(DomainException$failure){$papersettingsProblem('private_logo',new DomainException($failure->getMessage(),503,$failure));}
    foreach($_SESSION['general_settings_drafts']??[]as$stored)if($stored['actor']===$papersettingsActor['id']&&$stored['club']===$papersettingsActor['club_id']&&$stored['section']==='paper'&&$stored['accepted']===null)$papersettingsDraftIndex[]=['id'=>$stored['id'],'key'=>$stored['key'],'pending'=>$stored['pending']];
}catch(DomainException$failure){http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=\Kulturhus\Native\Language\PaperSettingsMessages::text($failure->getMessage(),$papersettingsMessages);$papersettingsState=null;$papersettingsDraft=null;}
catch(Throwable){http_response_code(503);$error=\Kulturhus\Native\Language\PaperSettingsMessages::text('Briefpapier konnte nicht geladen werden.',$papersettingsMessages);$papersettingsState=null;$papersettingsDraft=null;}
