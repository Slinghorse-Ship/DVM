<?php declare(strict_types=1);
if(!isset($_GET['voucher_mail_original'])&&!isset($_GET['voucher_mail_preview_pdf']))return;
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
$voucherMailDownloadMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():[];
$voucherMailDownloadOpaque=isset($_GET['voucher_mail_original'])?'Ursprüngliche Versandvorschau nicht verfügbar.':'Persönliche Versandvorschau nicht verfügbar.';
$voucherMailDownloadText=static fn(string $source):string=>\Kulturhus\Native\Language\EventVoucherMessages::text($source,$voucherMailDownloadMessages);
try{
    $voucherMailDownloadService=new \Kulturhus\Verein\EventVoucherMail($db,(string)$_SESSION['actor'],$websiteCalendar??null);
    if(isset($_GET['voucher_mail_original'])){
        $voucherMailDownloadOriginal=$voucherMailDownloadService->original(input($_GET,'voucher_event'),input($_GET,'voucher_mail_original'));
        header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="dvm-gutschein-versand-original.json"');echo$voucherMailDownloadOriginal['original_json'];
    }else{
        $voucherMailDownloadKey=input($_GET,'voucher_mail_preview_pdf');
        $voucherMailDownloadReview=$_SESSION['voucher_mail_reviews'][$voucherMailDownloadKey]??null;
        if(!preg_match('/^[a-f0-9]{32}$/D',$voucherMailDownloadKey)||!is_array($voucherMailDownloadReview)||($voucherMailDownloadReview['actor']??null)!==(string)$_SESSION['actor']||($voucherMailDownloadReview['action']??null)!=='event-voucher-mail'||!is_array($voucherMailDownloadReview['data']['snapshot']??null))throw new DomainException('Persönliche Versandvorschau nicht verfügbar.',403);
        $voucherMailDownloadCurrent=$voucherMailDownloadService->preview($voucherMailDownloadReview['event'],$voucherMailDownloadReview['data']['snapshot']['person']['id']);
        if(!hash_equals($voucherMailDownloadCurrent['hash'],$voucherMailDownloadReview['data']['hash']))throw new DomainException('Gutscheine oder Empfängerdaten geändert. Bitte Vorschau erneut prüfen.',409);
        $voucherMailDownloadPdf=\Kulturhus\Verein\EventVoucherPdf::fromSnapshot($voucherMailDownloadReview['data']['snapshot']);
        header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="gutschein-mail-vorschau.pdf"');echo$voucherMailDownloadPdf;
    }
}catch(DomainException $failure){
    $voucherMailDownloadStatus=in_array($failure->getCode(),[409,422,503],true)?$failure->getCode():403;http_response_code($voucherMailDownloadStatus);echo e($voucherMailDownloadText($voucherMailDownloadStatus===403?$voucherMailDownloadOpaque:$failure->getMessage()));
}catch(Throwable){http_response_code(500);echo e($voucherMailDownloadText('Gutscheinversanddatei konnte nicht geladen werden.'));}
exit;
