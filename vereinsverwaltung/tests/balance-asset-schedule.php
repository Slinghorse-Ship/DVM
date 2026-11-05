<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{BalanceAssetSchedule,BalanceCalculation,AssetBooks};
$n=0;function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$chart=['plant'=>['name'=>'Synthetic plant','group'=>'fixed_assets','skr_code'=>'06300'],'pay'=>['name'=>'Payable','group'=>'liabilities','skr_code'=>''],'expense'=>['name'=>'Depreciation','group'=>'expense','skr_code'=>'']];
$pair=static fn($a,$b,$v)=>[['account'=>$a,'debit_cents'=>$v,'credit_cents'=>0,'sphere'=>$a==='expense'?'1':''],['account'=>$b,'debit_cents'=>0,'credit_cents'=>$v,'sphere'=>'']];
$calc=BalanceCalculation::build(2025,$chart,[],[['id'=>'purchase','date'=>'2025-12-02','lines'=>$pair('plant','pay',12000)],['id'=>'afa','date'=>'2025-12-31','lines'=>$pair('expense','plant',1000)]]);
$plan=['basis_cents'=>12000,'residual_cents'=>0,'acquired_on'=>'2025-12-02','months'=>12,'sphere'=>'1','account'=>'06300','method'=>'linear_monthly','reason'=>'Synthetic documented life'];
$acquisition=['source_id'=>'invoice','source_hash'=>str_repeat('a',64),'snapshot'=>['entry'=>['display_number'=>'SYN-1'],'asset_accounts'=>['item'=>'plant'],'assets'=>[['item_id'=>'item','payload'=>json_encode($plan)]]]];
$annual=['item'=>['source_hash'=>str_repeat('b',64),'snapshot'=>['annual'=>['item_id'=>'item','year'=>2025,'opening_cents'=>12000,'depreciation_cents'=>1000,'closing_cents'=>11000,'snapshot'=>json_encode($plan)]]]];
$out=BalanceAssetSchedule::calculate(2025,$calc,[$acquisition],$annual);$item=$out['items']['item'];
check(!$out['issues'],'December asset reconciles with actual depreciation journal');
check($item['cost_opening_cents']===0&&$item['additions_cents']===12000&&$item['cost_closing_cents']===12000,'gross asset rollforward handles acquisition during year');
check($item['accumulated_opening_cents']===0&&$item['depreciation_cents']===1000&&$item['accumulated_closing_cents']===1000,'accumulated depreciation starts at zero in acquisition year');
check($item['book_opening_cents']===0&&$item['book_closing_cents']===11000,'net book rollforward agrees with ledger');
check(!$out['ready_for_xbrl'],'internal asset schedule never claims a complete official XBRL asset report');
check($out['totals']['cost_closing_cents']-$out['totals']['accumulated_closing_cents']===$out['totals']['book_closing_cents'],'cost less accumulated depreciation equals net assets');
denied(fn()=>BalanceAssetSchedule::calculate(2025,$calc,[$acquisition,$acquisition],$annual),'duplicate acquisition cannot double asset values');
$bad=$annual;$bad['item']['snapshot']['annual']['depreciation_cents']=999;
check((bool)BalanceAssetSchedule::calculate(2025,$calc,[$acquisition],$bad)['issues'],'one cent depreciation discrepancy detected');
$bad=$annual;$bad['item']['snapshot']['annual']['year']=2024;
check((bool)BalanceAssetSchedule::calculate(2025,$calc,[$acquisition],$bad)['issues'],'depreciation from wrong year rejected');
$badCalc=$calc;$badCalc['accounts']['plant']['closing_cents']++;
check((bool)BalanceAssetSchedule::calculate(2025,$badCalc,[$acquisition],$annual)['issues'],'net asset balance discrepancy detected');
$badCalc=$calc;$badCalc['accounts']['plant']['opening_cents']=1;
check((bool)BalanceAssetSchedule::calculate(2025,$badCalc,[$acquisition],$annual)['issues'],'missing opening asset proof detected');
check((bool)BalanceAssetSchedule::calculate(2025,$calc,[],[])['issues'],'nonzero asset ledger cannot be called complete with no individual assets');
check((bool)BalanceAssetSchedule::calculate(2025,null,[],[])['issues'],'missing ledger is not a confirmed zero asset balance');
check((bool)BalanceAssetSchedule::calculate(2025,$calc,[], $annual)['issues'],'orphan annual depreciation detected');
foreach([['residual_cents'=>-1],['months'=>0],['months'=>13],['method'=>'unknown'],['acquired_on'=>'2026-01-01'],['basis_cents'=>0]] as $change){
    $bad=$acquisition;$bad['snapshot']['assets'][0]['payload']=json_encode(array_replace($plan,$change));
    denied(fn()=>BalanceAssetSchedule::calculate(2025,$calc,[$bad],$annual),'invalid retained asset plan rejected: '.array_key_first($change));
}
$negative=['basis'=>'120','residual'=>'-1','acquired_on'=>'2025-12-02','months'=>'12','sphere'=>'1','account'=>'06300','reason'=>'Synthetic'];
denied(fn()=>AssetBooks::plan($negative),'negative residual cannot enter a new asset plan');
// After the first year's month, eleven months remain. No new acquisition is recorded next year.
$nextChart=$chart+['equity'=>['name'=>'Result carried','group'=>'equity','skr_code'=>'']];
$opening=[['account'=>'plant','debit_cents'=>11000,'credit_cents'=>0,'sphere'=>''],['account'=>'pay','debit_cents'=>0,'credit_cents'=>12000,'sphere'=>''],['account'=>'equity','debit_cents'=>1000,'credit_cents'=>0,'sphere'=>'']];
$nextCalc=BalanceCalculation::build(2026,$nextChart,$opening,[['id'=>'next-afa','date'=>'2026-12-31','lines'=>$pair('expense','plant',11000)]]);
$nextAnnual=$annual;$nextAnnual['item']['snapshot']['annual']=array_replace($nextAnnual['item']['snapshot']['annual'],['year'=>2026,'opening_cents'=>11000,'depreciation_cents'=>11000,'closing_cents'=>0]);
$next=BalanceAssetSchedule::calculate(2026,$nextCalc,[$acquisition],$nextAnnual);$item=$next['items']['item'];
check(!$next['issues']&&$item['cost_opening_cents']===12000&&$item['additions_cents']===0,'prior year gross cost carries without new acquisition');
check($item['accumulated_opening_cents']===1000&&$item['depreciation_cents']===11000&&$item['book_closing_cents']===0,'remaining life depreciated without rounding drift');
echo "$n asset schedule checks passed; synthetic arithmetic only\n";
