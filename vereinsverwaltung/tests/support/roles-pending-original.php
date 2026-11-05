<?php declare(strict_types=1);
// Reuse the real earlier PDO COMMIT fault, then execute the actual read-only page entry.
$nrOldSession=$_SESSION??[];$nrOldGet=$_GET;$nrPendingSession=[];$nrPendingDraft=\Kulturhus\Verein\Settings\RoleDraft::open($nrPendingSession,\Kulturhus\Verein\Permissions::actor($db,'own-list-admin'),'role-save',$nrLostInput);$nrPendingDraft['review']=$nrLostPlan;$nrPendingDraft['key']=$nrLostKey;$nrPendingDraft['pending']=true;$nrPendingSession['role_drafts'][$nrPendingDraft['id']]=$nrPendingDraft;$nrPendingSession['actor']='own-list-admin';$nrBefore=ownOriginalDatabaseHash($db,$tables);
try{
 foreach([true,false]as$nrPendingFlag){
    $nrPendingSession['role_drafts'][$nrPendingDraft['id']]['pending']=$nrPendingFlag;
    $_SESSION=$nrPendingSession;$_GET=['role_draft'=>$nrPendingDraft['id'],'role_receipt'=>$nrLostKey];
    $nrReadResolution=(static function()use($db,$nrNativeAccess,$nrIdentity,$nrNativeRequests):array{
        $roleNativeRoute=true;$workspaceAllowed=true;$nativeAccess=$nrNativeAccess;$roleNativeIdentity=$nrIdentity;$roleRequests=$nrNativeRequests;$rolePostedDraft=null;$error='';require dirname(__DIR__,2).'/src/Web/role-page.php';
        return['state'=>$roleState,'drafts'=>$roleDrafts,'error'=>$error];
    })();
    $check($nrLost&&$nrReadResolution['error']===''&&$nrReadResolution['state']['receipt']===$nrLostReceipt&&$nrReadResolution['drafts']['role-save']['accepted']===$nrLostKey&&!$nrReadResolution['drafts']['role-save']['pending']&&$_SESSION['role_drafts'][$nrPendingDraft['id']]['accepted']===$nrLostKey,'actual native page resolves its earlier genuinely lost accepted COMMIT from the exact offered original and replaces uncertainty only after a deliberate read');
    $check(ownOriginalDatabaseHash($db,$tables)===$nrBefore,'actual lost-COMMIT page resolution changes only its private session and creates no second canonical role revision audit or accepted original');
 }
}finally{$_SESSION=$nrOldSession;$_GET=$nrOldGet;http_response_code(200);}
