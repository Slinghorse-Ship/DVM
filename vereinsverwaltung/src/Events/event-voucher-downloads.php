<?php declare(strict_types=1);
require __DIR__.'/event-voucher-mail-downloads.php';
if(isset($_GET['voucher_original'])){
 header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
 $voucherOriginalCatalog=isset($nativeAccess)?new \Kulturhus\Native\Language\Catalog($db,$nativeAccess):null;
 $voucherOriginalMessages=$voucherOriginalCatalog?->messages()??[];
 try{
  $voucherOriginalService=new \Kulturhus\Verein\EventVouchers($db,(string)$_SESSION['actor'],$websiteCalendar??null);
  $voucherOriginalReceipt=$voucherOriginalService->confirmation(input($_GET,'voucher_original'));
  if(!$voucherOriginalReceipt['input_available'])throw new DomainException('Ursprüngliche Gutscheinbestätigung nicht verfügbar.');
  header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="dvm-gutschein-original.json"');echo$voucherOriginalReceipt['original_json'];
 }catch(DomainException $failure){http_response_code($failure->getCode()===503?503:403);echo e(\Kulturhus\Native\Language\EventVoucherMessages::text($failure->getCode()===503?$failure->getMessage():'Ursprüngliche Gutscheinbestätigung nicht verfügbar.',$voucherOriginalMessages));}
 catch(Throwable){http_response_code(500);echo e(\Kulturhus\Native\Language\EventVoucherMessages::text('Ursprüngliche Gutscheinbestätigung konnte nicht geladen werden.',$voucherOriginalMessages));}
 exit;
}
if(isset($_GET['voucher_pdf'])||isset($_GET['own_voucher_pdf'])||isset($_GET['voucher_manager_pdf'])){
 $voucherDownloadMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():[];
 $voucherDownloadText=static fn(string $source):string=>\Kulturhus\Native\Language\EventVoucherMessages::text($source,$voucherDownloadMessages);
 header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
 try{
  if(isset($_GET['voucher_manager_pdf'])){
   $voucherDownloadService=new \Kulturhus\Verein\EventVouchers($db,(string)$_SESSION['actor'],$websiteCalendar??null);
   $voucherDownloadBytes=\Kulturhus\Verein\EventVoucherPdf::fromSnapshot($voucherDownloadService->managementPrintSnapshot(input($_GET,'voucher_manager_pdf'),input($_GET,'voucher_person')));
  }elseif(isset($_GET['own_voucher_pdf'])){
   $voucherDownloadService=new \Kulturhus\Verein\EventVouchers($db,(string)$_SESSION['actor'],$websiteCalendar??null);
   $voucherDownloadAccess=$voucherDownloadService->workspaceAccess();$voucherDownloadPerson=$voucherDownloadAccess['actor']['contact_id']??'';
   $voucherDownloadSnapshot=$voucherDownloadService->printSnapshot(input($_GET,'own_voucher_pdf'),$voucherDownloadPerson,true);
   $voucherDownloadBytes=\Kulturhus\Verein\EventVoucherPdf::fromSnapshot($voucherDownloadSnapshot);
  }else $voucherDownloadBytes=\Kulturhus\Verein\EventVoucherPdf::file($db,(string)$_SESSION['actor'],input($_GET,'voucher_event'),input($_GET,'voucher_pdf'),$websiteCalendar??null);
  header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="gutscheine-85x55mm.pdf"');echo $voucherDownloadBytes;
 }catch(DomainException $failure){$voucherDownloadStatus=$failure->getCode()===503?503:((isset($_GET['own_voucher_pdf'])||isset($_GET['voucher_manager_pdf']))&&in_array($failure->getCode(),[409,422],true)?$failure->getCode():403);http_response_code($voucherDownloadStatus);echo e($voucherDownloadText($voucherDownloadStatus!==403?$failure->getMessage():'Gutscheindatei nicht verfügbar.'));}
 catch(Throwable){http_response_code(500);echo e($voucherDownloadText('Persönliche Gutscheine konnten nicht geladen werden.'));}
 exit;
}
