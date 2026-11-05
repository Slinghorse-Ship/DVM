<?php declare(strict_types=1);
require __DIR__.'/ebilanz-period-calendar.php';
use Kulturhus\Verein\{BalanceWholeClubReport as Whole,BalanceCalendarFilingSource as Calendar,BalanceTaxValues as Values,BalancePeriodTaxValues as PV,BalancePeriodFilingValues as PF};
function wholeCalendarFixture(string $variant):array
{
    if(!in_array($variant,['book','tax','all-tax'],true))throw new InvalidArgumentException('Unknown whole calendar case');
    $base=calendarPeriodFixture($variant==='all-tax'?'tax':$variant);$source=Whole::fromReport($base['subsidiary']['previous']['income']['scope']['source']);
    $review=['year'=>$source['year'],'journal_sha256'=>$source['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[],
        'asset_tax_plans'=>$variant!=='book'?$base['subsidiary']['previous']['review']['asset_tax_plans']:[],'capital_movements'=>[]];
    if($variant==='all-tax')foreach($source['asset_schedule']['items'] as $id=>$item)$review['asset_tax_plans'][$id]=['months'=>120,'depreciation_account'=>'depreciation','reason'=>'Synthetic all-areas taxable corporation','confirmed'=>true];
    if($variant!=='book'){
        $review['current_adjustments'][]=['id'=>'capital-proof','reason'=>'Synthetic retained contribution','lines'=>[['account'=>'cash','debit_cents'=>100,'credit_cents'=>0,'sphere'=>''],['account'=>'equity','debit_cents'=>0,'credit_cents'=>100,'sphere'=>'']]];
        $review['capital_movements']=['tax:capital-proof:1'=>['kind'=>'contribution','reason'=>'Synthetic original capital evidence']];
    }
    $old=Values::build($source,$review);$packet=Calendar::wholePacket($old,$review);
    $r=Whole::fromReport($base['whole']['period_source']['source']);
    // Persisted plans retain notes which old flat calendar schedules did not store.
    foreach($r['asset_schedule']['items'] as &$item)$item['plan']['reason']='Synthetic retained original plan note';unset($item);
    $r=Whole::fromReport($r);$carry=PV::carryForward($source,$old,$r);
    $next=['period'=>$r['period'],'year'=>$r['year'],'journal_sha256'=>$r['closed_sha256'],'complete'=>true,'opening_adjustments'=>$carry['entry']?[$carry['entry']]:[],
        'current_adjustments'=>[],'asset_tax_plans'=>$review['asset_tax_plans'],'capital_movements'=>[]];
    return PF::whole($r,$next,$packet);
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__)echo json_encode(wholeCalendarFixture($argv[1]??'tax'),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
