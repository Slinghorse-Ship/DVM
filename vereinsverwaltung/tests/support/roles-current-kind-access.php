<?php declare(strict_types=1);
// Retaining general role management must not retain withdrawn personal/technical authority.
$nrOldSession=$_SESSION??[];$nrOldGet=$_GET;$nrCurrentKindSession=[];$nrCurrentKindDraft=\Kulturhus\Verein\Settings\RoleDraft::open($nrCurrentKindSession,$nrDraftActor,'identity-superuser',$nrTechInput);$nrCurrentKindDraft['review']=$nrTechPlan;$nrCurrentKindSession['role_drafts'][$nrCurrentKindDraft['id']]=$nrCurrentKindDraft;
$nrCurrentKindSession['actor']='own-list-admin';$nrBefore=ownOriginalDatabaseHash($db,$tables);$db->beginTransaction();$db->exec("UPDATE actors SET is_superuser=0 WHERE id='own-list-admin'");$nrReducedBefore=ownOriginalDatabaseHash($db,$tables);
try{
    $nrNativeRequests->assertAccess('role-save');$nrCurrentKindState=(new \Kulturhus\Verein\Web\RoleState($db,'own-list-admin',$nrNativeAccess,$nrIdentity))->prepare(['role_user'=>'own-roles-target','role_receipt'=>$nrBoardReceipt['key']]);
    $check($nrCurrentKindState['actor']!==null&&$nrCurrentKindState['roles']!==null&&!$nrCurrentKindState['can_board']&&!$nrCurrentKindState['can_native']&&$nrCurrentKindState['receipt']===null&&$nrCurrentKindState['http_status']===403&&count(array_filter($nrCurrentKindState['history']['rows'],static fn(array $row):bool=>!str_starts_with($row['operation'],'role-')))===0&&ownOriginalDatabaseHash($db,$tables)===$nrReducedBefore,'withdrawing technical authority immediately hides private board and native originals and history while retaining independently permitted general roles');
    $_SESSION=$nrCurrentKindSession;$_GET=['role_draft'=>$nrCurrentKindDraft['id']];
    $nrPageResult=(static function()use($db,$nrNativeAccess,$nrIdentity,$nrNativeRequests):array{
        $roleNativeRoute=true;$workspaceAllowed=true;$nativeAccess=$nrNativeAccess;$roleNativeIdentity=$nrIdentity;$roleRequests=$nrNativeRequests;$rolePostedDraft=null;$error='';
        require dirname(__DIR__,2).'/src/Web/role-page.php';
        return['status'=>http_response_code(),'drafts'=>$roleDrafts,'state'=>$roleState];
    })();
    $check($nrPageResult['status']===403&&$nrPageResult['drafts']===[]&&$nrPageResult['state']===null&&$db->inTransaction()&&ownOriginalDatabaseHash($db,$tables)===$nrReducedBefore,'actual native role page refuses an earlier complete technical session proposal after current authority withdrawal without closing its caller transaction or changing any original');
}finally{if($db->inTransaction())$db->rollBack();$_SESSION=$nrOldSession;$_GET=$nrOldGet;http_response_code(200);}
$check(ownOriginalDatabaseHash($db,$tables)===$nrBefore,'current personal-kind rejection preserves all whole original rows after the owning caller rollback');
