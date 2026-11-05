<?php declare(strict_types=1);
require __DIR__.'/balance-period.php';
use Kulturhus\Verein\{BalancePeriod, BalancePeriodAssets, DegressiveDepreciation};
$base=$n;$n=0;
$plan=['basis_cents'=>120000,'residual_cents'=>0,'months'=>60,'acquired_on'=>'2025-09-18','method'=>'degressive','account'=>'06300',
    'degressive'=>['rate_basis_points'=>3000,'movable'=>true,'same_book_tax'=>true,'switch_to_linear'=>true]];
$first=['start'=>'2025-07-01','end'=>'2026-06-30'];$second=['start'=>'2026-07-01','end'=>'2026-12-31'];
$a=BalancePeriodAssets::calculate($plan,$first);
check($a['depreciation_cents']===30000&&$a['book_closing_cents']===90000&&$a['book_opening_cents']===0,'ten acquisition months within actual fiscal year, not two calendar restarts');
$b=BalancePeriodAssets::calculate($plan,$second,$a);
check($b['depreciation_cents']===13500&&$b['book_opening_cents']===90000&&$b['book_closing_cents']===76500,'six-month transition uses preceding real closing and half annual rate');
check($b['degressive_state']['elapsed_months']===16&&$b['degressive_state']['linear']===null,'actual consumed life preserved across short year');
denied(fn()=>BalancePeriodAssets::calculate($plan,$second),'cannot infer calendar prior for fiscal degression');
denied(fn()=>BalancePeriodAssets::calculate($plan,$first,$a),'first acquisition cannot consume a fabricated previous balance');
$gap=['start'=>'2027-02-01','end'=>'2027-12-31'];denied(fn()=>BalancePeriodAssets::calculate($plan,$gap,$b),'gap in asset chain rejected');
foreach(['book_closing_cents','cost_closing_cents','accumulated_closing_cents'] as $key){$bad=$a;$bad[$key]++;denied(fn()=>BalancePeriodAssets::calculate($plan,$second,$bad),'changed previous '.$key.' rejected');}
$bad=$a;$bad['degressive_state']['elapsed_months']++;denied(fn()=>BalancePeriodAssets::calculate($plan,$second,$bad),'changed consumed life rejected');
$future=BalancePeriodAssets::calculate($plan,['start'=>'2025-01-01','end'=>'2025-06-30']);
check($future['cost_closing_cents']===0&&$future['depreciation_cents']===0&&$future['degressive_state']['elapsed_months']===0,'future acquisition creates no cost or depreciation');
// Calendar periods still match every yearly decision of the original engine.
foreach([120000,120001,33333] as $basis){
    $p=array_replace($plan,['basis_cents'=>$basis]);$prior=null;
    for($year=2025;$year<=2033;++$year){$out=BalancePeriodAssets::calculate($p,BalancePeriod::calendar($year),$prior);$old=DegressiveDepreciation::schedule($p,$year);check($out['depreciation_cents']===$old['depreciation_cents']&&$out['book_closing_cents']===$old['closing_cents'],'calendar decision compatibility '.$basis.' '.$year);$prior=$out;}
}
$prior=$b;$total=$a['depreciation_cents']+$b['depreciation_cents'];$switch=null;
for($year=2027;$year<=2033;++$year){$out=BalancePeriodAssets::calculate($plan,BalancePeriod::calendar($year),$prior);$total+=$out['depreciation_cents'];check($out['book_opening_cents']===$prior['book_closing_cents']&&$out['accumulated_closing_cents']+$out['book_closing_cents']===120000,'fiscal chain preserves cost and carry '.$year);if($switch!==null)check($out['degressive_state']['switched_period']===$switch,'linear transition is irreversible '.$year);$switch=$out['degressive_state']['switched_period'];$prior=$out;}
check($total===120000&&$prior['book_closing_cents']===0&&$switch!==null,'irregular-year chain writes off exact basis without remainder or extra expense');
$bad=$prior;$bad['degressive_state']['linear']['used']--;denied(fn()=>BalancePeriodAssets::calculate($plan,BalancePeriod::calendar(2034),$bad),'changed linear carry rejected');
echo "$n degressive period checks passed plus $base period checks; arithmetic only\n";
