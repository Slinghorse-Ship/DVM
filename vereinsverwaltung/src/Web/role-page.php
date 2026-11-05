<?php declare(strict_types=1);
use Kulturhus\Verein\Settings\RoleDraft;

$roleState=null;$roleDrafts=[];$roleMessages=[];$roleDraftIndex=[];
if(!$roleNativeRoute||!$workspaceAllowed)return;
$roleMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();
$error=\Kulturhus\Native\Language\RoleMessages::text($error,$roleMessages);
$roleQuery=$_GET;$roleOpenedDraft=null;
try{
    $rolePageActor=\Kulturhus\Verein\Permissions::actor($db,(string)$_SESSION['actor']);
    if(!$rolePageActor['settings_access']||$rolePageActor['role']==='editor')throw new DomainException('Eigene Rollenverwaltung erforderlich.',403);
    if($rolePostedDraft!==null)$roleOpenedDraft=RoleDraft::get($_SESSION,$rolePageActor,$rolePostedDraft['id']);
    elseif(isset($_GET['role_draft']))$roleOpenedDraft=RoleDraft::get($_SESSION,$rolePageActor,$_GET['role_draft']);
    if($roleOpenedDraft){
        $roleRequests->assertAccess($roleOpenedDraft['operation']);
        $ri=$roleOpenedDraft['input'];$op=$roleOpenedDraft['operation'];
        if($op==='role-save'&&!isset($roleQuery['edit'])&&!isset($roleQuery['new_role'])){
            if(is_string($ri['id']??null)&&$ri['id']!=='')$roleQuery['edit']=$ri['id'];else $roleQuery['new_role']='1';
        }elseif(in_array($op,['role-assign','board-grant'],true)&&!isset($roleQuery['role_user'])&&is_string($ri['user']??null))$roleQuery['role_user']=$ri['user'];
        elseif(str_starts_with($op,'identity-')&&!isset($roleQuery['role_user'])&&is_string($ri['account']??null))$roleQuery['role_user']=(new \Kulturhus\Verein\RoleCatalogue($db,(string)$_SESSION['actor']))->accountForNativeAccount($ri['account'])['id'];
    }
    $roleState=(new \Kulturhus\Verein\Web\RoleState($db,(string)$_SESSION['actor'],$nativeAccess,$roleNativeIdentity))->prepare($roleQuery);
    if(!$roleState['actor'])throw new DomainException('Eigene Rollenverwaltung erforderlich.',403);
    if($roleOpenedDraft&&($roleState['receipt']['key']??null)===$roleOpenedDraft['key']){
        $expected=\Kulturhus\Verein\Settings\RoleInput::capture($roleOpenedDraft['operation'],$roleOpenedDraft['input']);
        if($roleState['receipt']['operation']!==$roleOpenedDraft['operation']||$roleState['receipt']['input']!==$expected)throw new DomainException('Diese Änderungskennung gehört zu anderen ursprünglichen Angaben.',409);
        $roleOpenedDraft['pending']=false;$roleOpenedDraft['accepted']=$roleState['receipt']['key'];
        $_SESSION['role_drafts'][$roleOpenedDraft['id']]=$roleOpenedDraft;
        if(($_SESSION['role_draft_slots'][$roleOpenedDraft['slot']]??null)===$roleOpenedDraft['id'])unset($_SESSION['role_draft_slots'][$roleOpenedDraft['slot']]);
    }
    $roleOpen=static function(string $op,array $input)use(&$roleDrafts,$roleOpenedDraft,$rolePageActor):void{
        $draft=$roleOpenedDraft&&$roleOpenedDraft['operation']===$op?$roleOpenedDraft:RoleDraft::open($_SESSION,$rolePageActor,$op,$input);
        $roleDrafts[$op]=$draft;
    };
    if($roleState['selected_role']||$roleState['new_role'])$roleOpen('role-save',RoleDraft::role($roleState['selected_role']));
    if($roleState['selected_user']){
        $u=$roleState['selected_user'];$roleOpen('role-assign',['user'=>$u['id'],'version'=>(string)$u['version'],'role'=>$u['role_id']??'__base__']);
        if($roleState['board']!==null)$roleOpen('board-grant',['user'=>$u['id'],'version'=>(string)($roleState['board']['row']['version']??0),'office'=>$roleState['board']['row']['office']??'','enabled'=>!empty($roleState['board']['row']['enabled'])?'1':'0']);
        if($roleState['identity']&&$u['id']!==$rolePageActor['id']){
            $i=$roleState['identity'];$roleOpen('identity-assign',['account'=>$i['id'],'version'=>(string)$i['version'],'role_version'=>(string)$i['role_version'],'expected_contact'=>$i['contact_id']??'','role'=>'__keep__','contact'=>$i['contact_id']??'','enabled'=>!$i['suspended']&&$i['active']&&(!$i['verified']||$i['account_active'])?'1':'0']);
            if($i['verified']&&$i['active']&&$i['account_active']&&$i['role']!=='editor')$roleOpen('identity-superuser',['account'=>$i['id'],'version'=>(string)$i['version'],'enabled'=>!empty($i['is_superuser'])?'1':'0']);
        }
    }
    // Explicitly selected drafts remain available even if their selected metadata disappeared.
    if($roleOpenedDraft)$roleDrafts[$roleOpenedDraft['operation']]=$roleOpenedDraft;
    $roleChoiceService=new \Kulturhus\Verein\Web\RoleChoices($db,(string)$_SESSION['actor'],$nativeAccess);
    foreach($roleDrafts as$op=>&$draft){
        if($op==='role-assign'||$op==='identity-assign'){
            $selection=$draft['input']['role']??'';
            $draft['role_choices']=$roleChoiceService->listing('role',$draft['query'],is_string($selection)?$selection:'');
            if($op==='identity-assign'){$selection=$draft['input']['contact']??'';$draft['profile_choices']=$roleChoiceService->listing('profile',$draft['query'],is_string($selection)?$selection:'');}
            foreach(['role_choices','profile_choices']as$part)if(($draft[$part]['error']??'')!=='')$roleState['http_status']=max($roleState['http_status'],422);
        }
    }unset($draft);
    foreach($_SESSION['role_drafts']??[]as$stored){
        if($stored['actor']!==$rolePageActor['id']||$stored['club']!==$rolePageActor['club_id']||$stored['accepted']!==null)continue;
        if($stored['operation']==='board-grant'&&!$roleState['can_board'])continue;
        if(str_starts_with($stored['operation'],'identity-')&&!$roleState['can_native'])continue;
        $roleDraftIndex[]=['id'=>$stored['id'],'key'=>$stored['key'],'operation'=>$stored['operation'],'pending'=>$stored['pending']];
    }
    if($roleState['http_status']>0&&http_response_code()<400)http_response_code($roleState['http_status']);
}catch(DomainException $failure){http_response_code(in_array($failure->getCode(),[403,404,422,503],true)?$failure->getCode():403);$error=\Kulturhus\Native\Language\RoleMessages::text($failure->getMessage(),$roleMessages);$roleDrafts=[];}
catch(Throwable){http_response_code(503);$error=\Kulturhus\Native\Language\RoleMessages::text('Rollenverwaltung konnte nicht geladen werden.',$roleMessages);$roleDrafts=[];}
