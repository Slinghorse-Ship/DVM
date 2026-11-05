<?php declare(strict_types=1);
if(isset($_GET['balance_period_download'])){
    try{
        require_once __DIR__.'/BalancePeriodExport.php';
        $periodDownloadId=input($_GET,'balance_period_download');
        $bytes=(new \Kulturhus\Verein\BalancePeriodExport($db,(string)$_SESSION['actor']))->zip($periodDownloadId);
        header('Cache-Control: private, no-store');header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="buchungsunterlagen-'.$periodDownloadId.'.zip"');echo $bytes;
    }catch(DomainException $ex){http_response_code(403);echo e($ex->getMessage());}
    exit;
}
