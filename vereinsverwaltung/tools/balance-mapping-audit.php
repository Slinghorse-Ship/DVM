<?php declare(strict_types=1);
/** Read-only release evidence. Exit 2 means incomplete annual E-Bilanz mapping. */
if(PHP_SAPI!=='cli') {http_response_code(404);exit;}
require __DIR__.'/../src/bootstrap.php';
require_once __DIR__.'/../src/Finance/Balance/BalanceTaxMapping.php';
use Kulturhus\Verein\BalanceTaxMapping;
$registry=json_decode(file_get_contents(__DIR__.'/../resources/ebilanz/skr42-6.9.json'),true,64,JSON_THROW_ON_ERROR);
$report=['revision'=>$registry['revision'],'mapping_complete'=>true,'production_authorized'=>false,'years'=>[]];
foreach([2025,2026] as $year){
    $report['years'][(string)$year]=BalanceTaxMapping::coverage($year);
    if($report['years'][(string)$year]['missing'])$report['mapping_complete']=false;
}
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
exit($report['mapping_complete']?0:2);
