<?php declare(strict_types=1);
$reimbursementHandled=false;$reimbursementFormState=null;$reimbursementFormError='';$reimbursementSavedState=null;
require __DIR__.'/reimbursement-return-controller.php';if($reimbursementReturnHandled){$reimbursementHandled=true;return;}
if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'&&array_key_exists('reimbursement_saved',$_GET)){
 try{
  if(!$workspaceAllowed||$view!=='finance'||input($_GET,'tab')!=='receipts')throw new DomainException('Ursprüngliche Ausgabe und Erstattungsformular vollständig öffnen.',403);
  $savedEntry=\Kulturhus\Verein\ReimbursementActionOriginals::id($_GET['entry']??null);$savedKey=\Kulturhus\Verein\ReimbursementActionOriginals::key($_GET['reimbursement_saved']);
  $reimbursementSavedState=['original'=>\Kulturhus\Verein\ReimbursementActionOriginals::read($db,(string)$_SESSION['actor'],$savedEntry,$savedKey)];
 }catch(DomainException$failure){http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$reimbursementSavedState=['error'=>$failure];}
 catch(Throwable){http_response_code(503);$reimbursementSavedState=['error'=>new DomainException('Gespeicherter Ausgang ist momentan nicht lesbar. Nicht erneut speichern; später nochmals lesen.',503)];}
}
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'||!\Kulturhus\Verein\Web\ReimbursementForm::requested($_POST,$_GET))return;
$reimbursementHandled=true;
try{
 if(!$workspaceAllowed||$view!=='finance'||input($_GET,'tab')!=='receipts')throw new DomainException('Ursprüngliche Ausgabe und Erstattungsformular vollständig öffnen.',403);
 $reimbursementFormState=\Kulturhus\Verein\Web\ReimbursementForm::handle($db,(string)$_SESSION['actor'],$_GET,$_POST,(string)$_SESSION['csrf']);
 if(isset($reimbursementFormState['saved']))redirectTo(['view'=>'finance','tab'=>'receipts','entry'=>$reimbursementFormState['saved']['entry'],'reimbursement_saved'=>$reimbursementFormState['saved']['request_key']]);
}catch(DomainException$failure){http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$reimbursementFormError=$failure->getMessage();}
catch(Throwable){http_response_code(503);$reimbursementFormError='Speicherantwort unklar. Zuerst den gespeicherten Ausgang dieser Kennung lesen; keinen zweiten Auftrag auslösen.';}
if($reimbursementFormError!==''&&($view!=='finance'||input($_GET,'tab')!=='receipts'||!is_string($_GET['entry']??null)||$_GET['entry']==='')){
 $reimbursementFallbackMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():[];
 $error=\Kulturhus\Native\Language\ReimbursementMessages::text($reimbursementFormError,$reimbursementFallbackMessages);
}
