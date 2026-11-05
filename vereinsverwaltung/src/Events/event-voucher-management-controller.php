<?php declare(strict_types=1);
$voucherManagementHandled=false;$voucherActionFailure=null;$voucherActionDraft=null;$voucherActionError='';$voucherActionStatus=0;
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'||($_POST['voucher_native']??null)!=='1')return;
$voucherManagementHandled=true;
require_once __DIR__.'/EventVoucherManagementActions.php';
$voucherActionDraft=\Kulturhus\Verein\EventVoucherDraft::retain($_POST);
try{
    if(!$workspaceAllowed||$view!=='portal'||input($_GET,'tab')!=='vouchers')throw new DomainException('Gutscheinverwaltung im Vereinsportal öffnen.',403);
    $voucherActionDraft=\Kulturhus\Verein\EventVoucherDraft::restore($_POST);
    $voucherNativeService=new \Kulturhus\Verein\EventVouchers($db,(string)$_SESSION['actor'],$websiteCalendar??null);
    $voucherNativeResult=\Kulturhus\Verein\EventVoucherManagementActions::handle($voucherNativeService,(string)$_SESSION['actor'],$_POST,$_SESSION);
    $voucherActionDraft=$voucherNativeResult['draft'];
    if(isset($voucherNativeResult['redirect'])){$_SESSION['notice']=$voucherNativeResult['notice'];redirectTo($voucherNativeResult['redirect']);}
}catch(DomainException $failure){
    $voucherActionFailure=$failure;$voucherActionError=$failure->getMessage();$voucherActionStatus=in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422;http_response_code($voucherActionStatus);
}catch(Throwable){
    $voucherActionError='Speicherausgang konnte nicht bestätigt werden. Bitte diesen Vorgang prüfen.';$voucherActionStatus=503;http_response_code(503);
}
