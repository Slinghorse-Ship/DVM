<?php declare(strict_types=1);
use Kulturhus\Verein\Settings\{RoleDraft,RoleInput,RoleRequests};

$rolesHandled=false;$rolePostedDraft=null;$roleNativeIdentity=null;$roleRequests=null;$roleAcceptStarted=false;
$roleNativeRoute=($nativeManagerEnabled??false)&&isset($nativeAccess)&&$view==='settings'&&input($_GET,'tab')==='roles';
$roleLegacyPost=($nativeManagerEnabled??false)&&($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&(in_array($_POST['action']??null,['settings-role','settings-role-assign','identity-assign','identity-browse','identity-superuser'],true)||($roleNativeRoute&&($_POST['action']??null)==='admission-grant'));
$roleNativePost=($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&(($_POST['action']??null)==='role-native'||$roleLegacyPost);
if(!$roleNativeRoute&&!$roleNativePost)return;
try{
    if(!$roleNativeRoute||!$workspaceAllowed)throw new DomainException('Rollenverwaltung im Vereinsmanager öffnen.',403);
    $roleCurrentActor=\Kulturhus\Verein\Permissions::actor($db,(string)$_SESSION['actor']);
    if(!$roleCurrentActor['settings_access']||$roleCurrentActor['role']==='editor')throw new DomainException('Eigene Rollenverwaltung erforderlich.',403);
    $roleNativeIdentity=new \Kulturhus\Native\Integration\AccountIdentity($db,$nativeAccess,$nativeAuth,\Kulturhus\Native\Shared\SecretBox::fromFile((string)getenv('KH_NATIVE_PRIVATE_KEY_FILE')));
    $roleRequests=new RoleRequests($db,(string)$_SESSION['actor'],$nativeAccess,$roleNativeIdentity);
    if(isset($_GET['role_original'])){
        if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
        if(!is_string($_GET['role_original']))throw new DomainException('Ursprüngliche Änderungsbestätigung auswählen.',422);
        $roleOriginal=$roleRequests->receipt($_GET['role_original']);
        header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="dvm-role-original.json"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header('Content-Security-Policy: sandbox; default-src \'none\'');header('Content-Length: '.strlen($roleOriginal['original_json']));
        if($_SERVER['REQUEST_METHOD']!=='HEAD')echo$roleOriginal['original_json'];exit;
    }
    if(!$roleNativePost)return;
    $rolesHandled=true;
    if(!is_string($_POST['csrf']??null)||!hash_equals((string)$_SESSION['csrf'],$_POST['csrf']))throw new DomainException('Formular abgelaufen. Bitte neu laden.',403);
    if($roleLegacyPost){
        $legacy=$_POST['action'];$operation=match($legacy){'settings-role'=>'role-save','settings-role-assign'=>'role-assign','admission-grant'=>'board-grant','identity-superuser'=>'identity-superuser',default=>'identity-assign'};
        $roleRequests->assertAccess($operation);
        $raw=match($operation){
            'role-save'=>['id'=>$_POST['role_id']??'','version'=>$_POST['version']??'','data'=>$_POST['data']??[]],
            'role-assign'=>['user'=>$_POST['user_id']??'','version'=>$_POST['version']??'','role'=>$_POST['role_id']??''],
            'board-grant'=>['user'=>$_POST['user_id']??'','version'=>$_POST['version']??'','office'=>$_POST['office']??'','enabled'=>$_POST['enabled']??'0'],
            'identity-superuser'=>['account'=>$_POST['account_id']??'','version'=>$_POST['version']??'','enabled'=>$_POST['enabled']??'0'],
            default=>['account'=>$_POST['account_id']??'','version'=>$_POST['version']??'','role_version'=>$_POST['role_version']??'','expected_contact'=>$_POST['expected_contact']??'','role'=>($_POST['role_id']??'')===''?'__keep__':$_POST['role_id'],'contact'=>$_POST['contact_id']??'','enabled'=>$_POST['enabled']??'0'],
        };
        $rolePostedDraft=RoleDraft::open($_SESSION,$roleCurrentActor,$operation,$raw,true);
        foreach(['role'=>'role_pick','profile'=>'profile_pick']as$old=>$new)foreach(['q','page']as$field)if(array_key_exists('identity_'.$old.'_'.$field,$_POST))$rolePostedDraft['query'][$new.'_'.$field]=$_POST['identity_'.$old.'_'.$field];
        $_SESSION['role_drafts'][$rolePostedDraft['id']]=$rolePostedDraft;
        throw new DomainException('Dieses frühere Rollenformular wurde nicht gespeichert. Die erhaltenen Angaben zuerst vollständig prüfen und danach gesondert bestätigen.',422);
    }
    $rolePostedDraft=RoleDraft::get($_SESSION,$roleCurrentActor,$_POST['role_draft']??null);
    if($rolePostedDraft['accepted']!==null){$roleFound=$roleRequests->receipt($rolePostedDraft['key']);redirectTo(['view'=>'settings','tab'=>'roles','role_receipt'=>$roleFound['key']]);}
    $roleBeforeDraft=$rolePostedDraft;
    $roleIntent=$_POST['role_intent']??'';
    if(!is_string($roleIntent)||!in_array($roleIntent,['preview','current','accept','browse','discard','defaults'],true))throw new DomainException('Gültigen Rollen- oder Kontenvorgang auswählen.',422);
    if($roleIntent==='discard'){
        if($rolePostedDraft['pending'])throw new DomainException('Zuerst den ursprünglichen Änderungsausgang prüfen.',409);
        RoleDraft::discard($_SESSION,$rolePostedDraft);redirectTo(['view'=>'settings','tab'=>'roles']);
    }
    if($rolePostedDraft['pending']){
        try{$roleFound=$roleRequests->receipt($rolePostedDraft['key']);$rolePostedDraft['accepted']=$roleFound['key'];$_SESSION['role_drafts'][$rolePostedDraft['id']]=$rolePostedDraft;redirectTo(['view'=>'settings','tab'=>'roles','role_receipt'=>$roleFound['key'],'role_draft'=>$rolePostedDraft['id']]);}
        catch(DomainException $failure){if($failure->getCode()!==404)throw$failure;}
        // The explicit current review is the only way to leave an uncertain old proposal.
        if($roleIntent!=='current')throw new DomainException('Zuerst den ursprünglichen Änderungsausgang prüfen.',409);
    }
    if($roleIntent==='accept'){
        if(!is_string($_POST['role_review']??null)||!is_string($_POST['role_key']??null)||!hash_equals($rolePostedDraft['key'],$_POST['role_key'])||!is_array($rolePostedDraft['review'])||!hash_equals($rolePostedDraft['review']['hash'],$_POST['role_review']))throw new DomainException('Der Entwurf wurde in einem anderen Tab geändert. Die ursprünglichen Angaben erneut öffnen.',409);
        if(RoleDraft::issues($rolePostedDraft['review']))throw new DomainException('Vollständige Rollenänderung zuerst prüfen.',422);
        if(($_POST['role_approved']??null)!=='1')throw new DomainException('Die vollständig geprüfte Änderung ausdrücklich bestätigen.',422);
        // Store the exact offered key before the canonical COMMIT; a lost reply is read by this key.
        $rolePostedDraft['pending']=true;$_SESSION['role_drafts'][$rolePostedDraft['id']]=$rolePostedDraft;
        $roleAcceptStarted=true;
        $roleAccepted=$roleRequests->accept($rolePostedDraft['operation'],$rolePostedDraft['input'],$rolePostedDraft['key'],$rolePostedDraft['review']['hash'],true);
        $roleAcceptStarted=false;
        $rolePostedDraft['accepted']=$roleAccepted['key'];$rolePostedDraft['pending']=false;
        $_SESSION['role_drafts'][$rolePostedDraft['id']]=$rolePostedDraft;
        if(($_SESSION['role_draft_slots'][$rolePostedDraft['slot']]??null)===$rolePostedDraft['id'])unset($_SESSION['role_draft_slots'][$rolePostedDraft['slot']]);
        $roleCanRead=false;
        try{$afterRoleActor=\Kulturhus\Verein\Permissions::actor($db,(string)$_SESSION['actor']);$roleCanRead=$afterRoleActor['settings_access']&&$afterRoleActor['role']!=='editor';}catch(Throwable){}
        if(!$roleCanRead){
            $acceptedNotice='Die geprüfte Rechteänderung wurde angenommen.';
            try{$acceptedNotice=\Kulturhus\Native\Language\RoleMessages::text($acceptedNotice,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());}catch(Throwable){}
            $_SESSION['notice']=$acceptedNotice;redirectTo(['view'=>'dashboard']);
        }
        redirectTo(['view'=>'settings','tab'=>'roles','role_receipt'=>$roleAccepted['key'],'role_draft'=>$rolePostedDraft['id']]);
    }
    if(!is_array($_POST['role_input']??null))throw new DomainException('Vollständige ursprüngliche Rollenfelder prüfen.',422);
    if($roleIntent!=='current'&&$rolePostedDraft['review']!==null&&$rolePostedDraft['input']!==$_POST['role_input']){
        $rolePostedDraft['previous'][]=['key'=>$rolePostedDraft['key'],'input'=>$rolePostedDraft['input'],'review'=>$rolePostedDraft['review']];$rolePostedDraft['key']=bin2hex(random_bytes(16));
    }
    $rolePostedDraft['input']=$_POST['role_input'];$rolePostedDraft['review']=null;$rolePostedDraft['conflicted']=false;
    $rolePostedDraft['query']=is_array($_POST['role_choice']??null)?$_POST['role_choice']:[];
    $_SESSION['role_drafts'][$rolePostedDraft['id']]=$rolePostedDraft;
    if($roleIntent==='defaults'){
        if($rolePostedDraft['operation']!=='role-save')throw new DomainException('Gültigen Rollen- oder Kontenvorgang auswählen.',422);
        $roleDefaultProposal=['key'=>$rolePostedDraft['key'],'input'=>$rolePostedDraft['input'],'review'=>$rolePostedDraft['input']===$roleBeforeDraft['input']?$roleBeforeDraft['review']:null];
        $rolePostedDraft['input']=RoleDraft::defaults($rolePostedDraft['input']);
        $rolePostedDraft['previous'][]=$roleDefaultProposal;$rolePostedDraft['key']=bin2hex(random_bytes(16));
    }
    $rolePlan=$roleRequests->prepare($rolePostedDraft['operation'],$rolePostedDraft['input']);
    if($roleIntent==='current'){$rolePostedDraft=RoleDraft::current($roleBeforeDraft,$rolePlan);$rolePlan=$roleRequests->prepare($rolePostedDraft['operation'],$rolePostedDraft['input']);}
    if($roleIntent!=='browse')$rolePostedDraft['review']=$rolePlan;
    $_SESSION['role_drafts'][$rolePostedDraft['id']]=$rolePostedDraft;
    $roleIssues=RoleDraft::issues($rolePlan);
    if($roleIssues){$error=$roleIssues[0];http_response_code(in_array($error,['Die ursprüngliche Fassung ist nicht mehr aktuell.','Die ursprüngliche persönliche Zuordnung ist nicht mehr aktuell.'],true)?409:422);}
}catch(DomainException $failure){
    if($roleNativePost)$rolesHandled=true;
    if($rolePostedDraft!==null&&($roleIntent??null)==='current'&&isset($roleBeforeDraft))$rolePostedDraft=RoleDraft::failedCurrent($rolePostedDraft,$roleBeforeDraft);
    if($roleAcceptStarted&&$rolePostedDraft!==null){$rolePostedDraft['pending']=false;$rolePostedDraft['conflicted']=true;}
    if($rolePostedDraft!==null)$_SESSION['role_drafts'][$rolePostedDraft['id']]=$rolePostedDraft;
    http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=$failure->getMessage();
    if(isset($_GET['role_original'])){try{$error=\Kulturhus\Native\Language\RoleMessages::text($error,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());}catch(Throwable){}header('Cache-Control: private, no-store');echo htmlspecialchars($error,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');exit;}
}catch(Throwable){
    if($roleNativePost)$rolesHandled=true;
    if($rolePostedDraft!==null&&($roleIntent??null)==='current'&&isset($roleBeforeDraft)){$rolePostedDraft=RoleDraft::failedCurrent($rolePostedDraft,$roleBeforeDraft);$_SESSION['role_drafts'][$rolePostedDraft['id']]=$rolePostedDraft;}
    http_response_code(503);$error=$roleAcceptStarted?'Änderungsausgang nicht bestätigt. Die ursprüngliche Bestätigung über ihre Kennung prüfen.':'Rollenbereich konnte nicht geladen werden.';
    if(isset($_GET['role_original'])){try{$error=\Kulturhus\Native\Language\RoleMessages::text($error,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());}catch(Throwable){}header('Cache-Control: private, no-store');echo htmlspecialchars($error,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');exit;}
}
