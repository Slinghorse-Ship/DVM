<?php declare(strict_types=1);
require_once __DIR__.'/../../src/bootstrap.php';
require_once __DIR__.'/../../src/Finance/Balance/BalanceTaxFiling.php';
use Kulturhus\Verein\{BalanceWholeClubReport,BalanceTaxValues};
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$v=$input['original'];$report=BalanceWholeClubReport::rebuild($v['whole_club_scope']);
$review=['year'=>$v['year'],'journal_sha256'=>$report['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[]];
if($input['different']){
    $id=array_key_first($report['asset_schedule']['items']);$dep=null;
    foreach($report['calculation']['accounts'] as $key=>$row)if($row['skr_code']==='62500')$dep=$key;
    $review['asset_tax_plans']=[$id=>['months'=>120,'depreciation_account'=>$dep,'reason'=>'Synthetic independently confirmed different tax useful life for regression only.','confirmed'=>true]];
}
try{
    $out=BalanceTaxValues::build($report,$review);
    echo json_encode(['accepted'=>true,'values'=>$out,'original_unchanged'=>$v===$input['original']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(DomainException $e){
    echo json_encode(['accepted'=>false,'error'=>$e->getMessage(),'original_unchanged'=>$v===$input['original']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
