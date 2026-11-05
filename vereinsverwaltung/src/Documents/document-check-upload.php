<?php declare(strict_types=1);
// Host CSRF and workspace authorization have already succeeded.
require_once __DIR__.'/DocumentCheckForm.php';
$checkService=new \Kulturhus\Verein\DocumentChecks($db,(string)$_SESSION['actor'],$registry);
$checkDraft=\Kulturhus\Verein\Documents\DocumentCheckForm::read($_POST);$checkActor=$checkService->actor();
if($checkDraft['actor']!==$checkActor['id']||$checkDraft['club']!==$checkActor['club_id'])throw new DomainException('Nachweisformular gehört zu einem anderen Zugang oder Verein.');
if($checkDraft['browse']==='file:clear'){
 foreach($_POST['live_uploads']['check_file']??[]as$checkToken)if(is_string($checkToken)&&isset($_SESSION['live_uploads'][$checkToken])){$checkRecord=$_SESSION['live_uploads'][$checkToken];if(($checkRecord['actor']??null)===$checkActor['id']&&($checkRecord['action']??null)==='document-check-save'&&($checkRecord['field']??null)==='check_file')\Kulturhus\Verein\LiveUploads::remove($checkActor['id'],$checkToken);}
 unset($_POST['live_uploads']['check_file'],$_FILES['check_file']);
}else{
 $checkNative=$_FILES['check_file']??null;
 if(is_array($checkNative)&&($checkNative['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
  \Kulturhus\Verein\LiveUploads::permit($db,$checkActor['id'],'document-check-save',$_POST);
  $checkStaged=\Kulturhus\Verein\LiveUploads::stage($checkActor['id'],'document-check-save','check_file',$checkNative,bin2hex(random_bytes(16)));
  $_POST['live_uploads']['check_file']=[$checkStaged['token']];
 }
}
$checkDraft['post']=$_POST;
