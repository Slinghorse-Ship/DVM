<?php declare(strict_types=1);
// Synthetic arithmetic only. No database, runtime credentials or transport.
use Kulturhus\Verein\{AssetTypes,BalanceTaxValues,BalanceTaxMapping,BalanceWholeClubReport,BalanceAssetAllocation};
$mode=$argv[1]??'annual';
if(in_array($mode,['annual','whole'],true))require __DIR__.'/ebilanz-values.php';
elseif($mode==='dated')require __DIR__.'/ebilanz-period-assets.php';
else throw new InvalidArgumentException('Unknown synthetic mode');
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$out=[];
$historic=json_decode(file_get_contents(__DIR__.'/../../resources/accounting/years/2026-r17.json'),true,512,JSON_THROW_ON_ERROR);
foreach(array_intersect(AssetTypes::codes('movable'),array_merge(...array_values($historic['bindings']))) as $code){
    $variants=$mode==='dated'?['linear','linear-successor','degressive','degressive-successor','fully','fully-successor']:
        ['asset-december','asset-prior','asset-fully','asset-cents','asset-degressive','asset-scrap','asset-sale-loss','asset-tax-faster','asset-partial-cents','asset-partial-tax-fast'];
    foreach($variants as $variant){
        if($mode==='dated'){$v=datedMethodFixture($variant,$code);$preview=null;}
        else{
            [$r,$review]=ebilanzFixture($variant,$code);
            if($mode==='whole'){
                foreach($r['entries'] as &$entry)$entry['id']=md5('synthetic-asset-type-'.$entry['id']);unset($entry);
                $r['calculation']=\Kulturhus\Verein\BalanceCalculation::build($r['year'],$r['accounts'],$r['opening'],$r['entries']);
                $r+=['previous_sha256'=>null,'result_account'=>'capital'];$r=BalanceWholeClubReport::fromReport($r);
            }
            $v=BalanceTaxValues::build($r,$review);
            $preview=BalanceTaxMapping::preview($v['year'],$v['tax']['accounts'],$v['asset_tax']['tax_schedule']??$v['asset_schedule']);
        }
        $shared=[];
        if($mode!=='dated')foreach($v['asset_schedule']['items'] as $id=>$item)$shared[$id]=BalanceAssetAllocation::item($item,['first'=>2,'second'=>3],$v['year']);
        $out[]=['code'=>$code,'variant'=>$variant,'values'=>$v,'preview'=>$preview,'shared'=>$shared];
    }
}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
