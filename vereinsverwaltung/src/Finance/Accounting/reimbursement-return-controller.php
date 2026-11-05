<?php declare(strict_types=1);
$reimbursementReturnHandled=false;$reimbursementReturnState=null;$reimbursementReturnError='';$reimbursementReturnSavedState=null;
try{
 $refundGet=($_SERVER['REQUEST_METHOD']??'GET')==='GET'&&array_key_exists('reimbursement_return_saved',$_GET);
 $refundPost=($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&\Kulturhus\Verein\Web\ReimbursementReturnForm::requested($_POST,$_GET);
 if(!$refundGet&&!$refundPost)return;
 $reimbursementReturnHandled=$refundPost;
 if(!$workspaceAllowed||$view!=='finance'||input($_GET,'tab')!=='receipts')throw new DomainException('Den Rückzahlungsschritt an der ursprünglichen privaten Ausgabe öffnen.',403);
 if($refundGet){$entry=\Kulturhus\Verein\ReimbursementActionOriginals::id($_GET['entry']??null);$key=\Kulturhus\Verein\ReimbursementActionOriginals::key($_GET['reimbursement_return_saved']);$kind=$_GET['reimbursement_return_saved_kind']??'return';if(!is_string($kind)||!in_array($kind,['return','void'],true))throw new DomainException('Gespeicherten Rückzahlungsvorgang vollständig öffnen.',422);$reimbursementReturnSavedState=['original'=>(new \Kulturhus\Verein\Finance($db,(string)$_SESSION['actor']))->reimbursementReturnSaved($entry,$key,$kind==='void'),'key'=>$key,'operation'=>$kind];return;}
 $reimbursementReturnState=\Kulturhus\Verein\Web\ReimbursementReturnForm::handle($db,(string)$_SESSION['actor'],$_GET,$_POST,(string)$_SESSION['csrf']);
 if(isset($reimbursementReturnState['saved']))redirectTo(['view'=>'finance','tab'=>'receipts','entry'=>$reimbursementReturnState['saved']['request']['reimbursement']['entry'],'reimbursement_return_saved'=>$_GET['reimbursement_return_key'],'reimbursement_return_saved_kind'=>$reimbursementReturnState['operation']]);
}catch(DomainException$failure){http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$reimbursementReturnError=$failure->getMessage();}
catch(Throwable){http_response_code(503);$reimbursementReturnError='Speicherantwort unklar. Zuerst den gespeicherten Ausgang dieser Kennung lesen; keinen zweiten Auftrag auslösen.';}

if($reimbursementReturnError!==''&&($view!=='finance'||input($_GET,'tab')!=='receipts'||!is_string($_GET['entry']??null)||$_GET['entry']==='')){$refundMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():[];$error=\Kulturhus\Native\Language\ReimbursementMessages::text($reimbursementReturnError,$refundMessages);}
