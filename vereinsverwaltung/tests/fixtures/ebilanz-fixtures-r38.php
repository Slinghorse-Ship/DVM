<?php declare(strict_types=1);
// Original PHP journal and retained plans, entirely synthetic and without a database.
$mode=$argv[1]??'annual';$whole=($argv[2]??'single')==='whole';
if($mode==='annual')require __DIR__.'/ebilanz-values.php';
elseif($mode==='dated')require __DIR__.'/ebilanz-period-assets.php';
else throw new InvalidArgumentException('Unknown synthetic mode');
use Kulturhus\Verein\{BalanceCalculation,BalanceWholeClubReport,BalanceTaxValues,BalanceTaxMapping,BalanceAssetAllocation};
require_once __DIR__.'/../../src/Finance/Balance/BalancePeriodWholeFiling.php';
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$out=[];
foreach(['02800','02850','02900','03100','03150','03200','03900','03950','03980','06800'] as $code){
    $variants=$mode==='annual'?['asset-december','asset-prior','asset-cents','asset-residual','asset-tax-faster','asset-tax-carry']:['linear','linear-successor'];
    foreach($variants as $variant){
        if($mode==='dated')$v=datedMethodFixture($variant,$code,$whole,60);
        else{
            [$r,$review]=ebilanzFixture($variant,$code,str_starts_with($variant,'asset-tax-')?120:60,60);
            if($whole){
                foreach($r['entries'] as &$entry)$entry['id']=md5('synthetic-linear-r27-'.$entry['id']);unset($entry);
                $r['calculation']=BalanceCalculation::build($r['year'],$r['accounts'],$r['opening'],$r['entries']);
                $r+=['previous_sha256'=>null,'result_account'=>'capital'];$r=BalanceWholeClubReport::fromReport($r);
            }
            $v=BalanceTaxValues::build($r,$review);
        }
        $schedule=$mode==='dated'?\Kulturhus\Verein\BalancePeriodWholeFiling::mappingSchedule($v):($v['asset_tax']['tax_schedule']??$v['asset_schedule']);
        $preview=BalanceTaxMapping::preview($v['year'],$v['tax']['accounts'],$schedule);$shared=[];
        if($mode==='annual')foreach($v['asset_schedule']['items'] as $id=>$item)$shared[$id]=BalanceAssetAllocation::item($item,['first'=>2,'second'=>3],$v['year']);
        $out[]=['code'=>$code,'mode'=>$mode,'whole'=>$whole,'variant'=>$variant,'values'=>$v,'preview'=>$preview,'shared'=>$shared];
    }
}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
