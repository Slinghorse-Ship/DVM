<?php declare(strict_types=1);
// The existing general preheader state and actor-bound outcome links also handle mail drafts.
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'||($_POST['voucher_mail_native']??null)!=='1')return;
$voucherManagementHandled=true;
require_once __DIR__.'/EventVoucherMailActions.php';
$voucherActionDraft=\Kulturhus\Verein\EventVoucherMailDraft::restore($_POST,true);
try{
    if(!$workspaceAllowed||$view!=='portal'||input($_GET,'tab')!=='vouchers')throw new DomainException('Gutscheinverwaltung im Vereinsportal öffnen.',403);
    $voucherActionDraft=\Kulturhus\Verein\EventVoucherMailDraft::restore($_POST);
    $voucherMailNative=new \Kulturhus\Verein\EventVoucherMail($db,(string)$_SESSION['actor'],$websiteCalendar??null);
    $voucherMailVouchers=new \Kulturhus\Verein\EventVouchers($db,(string)$_SESSION['actor'],$websiteCalendar??null);
    $voucherMailResult=\Kulturhus\Verein\EventVoucherMailActions::handle($voucherMailNative,$voucherMailVouchers,(string)$_SESSION['actor'],$_POST,$_SESSION);
    $voucherActionDraft=$voucherMailResult['draft'];
    if(isset($voucherMailResult['redirect'])){$_SESSION['notice']=$voucherMailResult['notice'];redirectTo($voucherMailResult['redirect']);}
}catch(DomainException $failure){
    $voucherActionFailure=$failure;$voucherActionError=$failure->getMessage();$voucherActionStatus=in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422;http_response_code($voucherActionStatus);
}catch(Throwable){
    $voucherActionError='Speicherausgang konnte nicht bestätigt werden. Bitte diesen Vorgang prüfen.';$voucherActionStatus=503;http_response_code(503);
}
