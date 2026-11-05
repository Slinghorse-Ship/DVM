<?php declare(strict_types=1);
if(!array_key_exists('activity_report_download',$_GET))return;
header('Cache-Control: private, no-store');
if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
try{$reportDownload=\Kulturhus\Verein\Web\ActivityReportForm::query($_GET);$file=(new \Kulturhus\Verein\ActivityReportReview($db,(string)$_SESSION['actor'],null,$reportDownload['report_kind']))->file($reportDownload['report_id']);header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.$file['name'].'"');header('Content-Length: '.strlen($file['bytes']));header("Content-Security-Policy: sandbox; default-src 'none'");if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo$file['bytes'];}
catch(DomainException $reportDownloadFailure){http_response_code(\Kulturhus\Verein\Web\ActivityReportPageState::status($reportDownloadFailure));header('Content-Type: text/plain; charset=UTF-8');if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD'){$reportDownloadMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():[];echo \Kulturhus\Native\Language\ActivityReportMessages::text($reportDownloadFailure->getMessage(),$reportDownloadMessages);}}
exit;
