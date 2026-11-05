<?php declare(strict_types=1);
if($view!=='finance'||$tab!=='export'||!$canFinance||!$workspaceAllowed)return;
$exportQuery=$_GET;
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&in_array(input($_POST,'action'),['finance-export','finance-bundle'],true)&&$error!=='')$exportQuery=array_intersect_key($_POST,['from'=>true,'to'=>true])+['from'=>'','to'=>'','export_kind'=>$_POST['kind']??'','export_status'=>$_POST['status']??''];
try{$financeExportState=\Kulturhus\Verein\Web\FinanceExportForm::inspect($finance,$exportQuery,\Kulturhus\Verein\Finance::today());}
catch(DomainException $failure){$workspaceAllowed=false;$canFinance=false;$error=$failure->getMessage();http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():403);return;}
if(($financeExportState['error']!==''||$financeExportState['preview_error']!=='')&&http_response_code()<400)http_response_code(422);
if(isset($nativeAccess)&&$error!=='')$error=\Kulturhus\Native\Language\FinanceExportMessages::text($error,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());
