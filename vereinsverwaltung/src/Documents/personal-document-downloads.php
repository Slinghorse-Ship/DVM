<?php declare(strict_types=1);
if(isset($_GET['portal_document'])||isset($_GET['personal_voucher_file'])){
 $personalDownloadMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():[];
 $personalDownloadText=static fn(string $source):string=>\Kulturhus\Verein\Documents\HseInterfaceText::text($source,$personalDownloadMessages);
 header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
 try{
  $personalDownloadService=new \Kulturhus\Verein\PortalDocuments($db,(string)$_SESSION['actor']);
  $personalDownloadAsset=isset($_GET['personal_voucher_file'])?['name'=>'gutscheine.pdf','mime'=>'application/pdf','bytes'=>$personalDownloadService->voucherFile(input($_GET,'personal_voucher_file'),$websiteCalendar??null)]:$personalDownloadService->file(input($_GET,'portal_document'));
  header('Content-Type: '.$personalDownloadAsset['mime']);header('Content-Disposition: attachment; filename="beleg"; filename*=UTF-8\'\''.rawurlencode($personalDownloadAsset['name']));echo $personalDownloadAsset['bytes'];
 }catch(DomainException $failure){http_response_code($failure->getCode()===503?503:403);echo e($personalDownloadText($failure->getCode()===503?$failure->getMessage():'Beleg nicht verfügbar.'));}
 catch(Throwable){http_response_code(500);echo e($personalDownloadText('Persönliche Belege konnten nicht geladen werden.'));}
 exit;
}
