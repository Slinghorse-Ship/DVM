<?php declare(strict_types=1);
require __DIR__.'/fixtures/ebilanz-period-spheres.php';
use Kulturhus\Verein\{BalancePeriodTaxValues,BalancePeriodFilingValues,BalanceClubTaxReconciliation,BalanceSegmentCalculation,BalancePeriodSphereTax};
$n=0;
function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);++$n;}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$expected=['entry'=>-6000,'exempt'=>0,'exit-gain'=>18000,'exit-loss'=>-12000,'book-return'=>0,'value-return'=>14000,'later-return'=>0,'multiple'=>-4000];
foreach($expected as $case=>$difference)foreach([false,true] as $next){
    $v=spherePeriodFixture($case,$next);$s=$v['subsidiary'];$b=$v['whole'];$r=$s['income']['scope']['source'];
    check($b['book']===$b['tax']&&$b['book']===$r['calculation'],'original whole books unchanged '.$case);
    $bridge=$s['sphere_reconciliation'];$i=$bridge['items']['device'];
    check($s['result']['valuation_difference_cents']===$bridge['valuation_difference_cents'],'bridge adds once '.$case);
    check($s['result']['taxable_income_cents']===$s['result']['book_cents']+$bridge['valuation_difference_cents'],'correct income '.$case);
    check($s['valuation']['current_adjustments']===[]&&$s['valuation']['opening_adjustments']===[],'no fake postings or prior gains carried '.$case);
    check($s['valuation']['book']===$s['valuation']['tax'],'separate computation never invents whole tax balance');
    if(!$next)check($bridge['valuation_difference_cents']===$difference,'specific arithmetic '.$case);
    else{
        $old=$s['previous']['sphere_reconciliation']['items']['device'];
        check($i['tax']['opening_cents']===$old['tax']['closing_cents'],'actual tax carry '.$case);
        if($case!=='later-return')check($i['movements']===[]&&$i['tax']['valuation_result_cents']===0,'prior gains and movements not repeated');
    }
    foreach(['1','2','3'] as $sphere)check($s['valuation']['book']['spheres'][$sphere]===$s['valuation']['tax']['spheres'][$sphere],'exempt original result preserved');
    check($v['transmission']==='unavailable'&&!$s['tax_export_ready'],'no transmission permission created');
    denied(fn()=>BalancePeriodTaxValues::build($r,$s['review']), 'no unscoped bypass');
    $bad=$s['review'];$bad['asset_tax_plans']['device']=['months'=>120,'depreciation_account'=>'dep','reason'=>'Synthetic conflicting review','confirmed'=>true];
    denied(fn()=>BalanceClubTaxReconciliation::build($s['income'],$bad,$s['previous']),'competing tax life rejected');
}
$v=spherePeriodFixture('entry');$bad=$v['subsidiary'];$bad['sphere_reconciliation']['valuation_difference_cents']++;unset($bad['sha256']);$bad['sha256']=BalanceSegmentCalculation::hash($bad);
denied(fn()=>BalanceClubTaxReconciliation::verify($bad),'rehashed bridge tamper rejected');
$s=$v['subsidiary'];$review=$s['review'];
foreach($s['off_balance']['sources'] as $key=>$row)if($row['account']==='dep'&&$row['cents']>0){
    $review['off_balance_adjustments'][]=['id'=>'invalid-dep','kind'=>'non_deductible_expense','sources'=>[$key=>$row['cents']],'reason'=>'Synthetic unsupported addback','legal_basis'=>'Synthetic checked source','confirmed'=>true];break;
}
denied(fn()=>BalanceClubTaxReconciliation::build($s['income'],$review),'original depreciation cannot be mistaken for the changed tax expense');
$item=$v['whole']['asset_schedule']['items']['device'];$item['sphere_change']['tax']['closing_cents']++;
$p=$item['sphere_change'];unset($p['sha256']);$item['sphere_change']['sha256']=BalanceSegmentCalculation::hash($p);
denied(fn()=>BalancePeriodSphereTax::item($item),'rehashed invalid tax closing rejected');
function e(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$money=static fn(int $n):string=>number_format($n/100,2,',','.').' €';
$sphereTaxValues=spherePeriodFixture('exit-gain')['subsidiary'];$before=$sphereTaxValues;
ob_start();require __DIR__.'/../views/Finance/Balance/balance-tax-sphere-values.php';$html=ob_get_clean();
check($sphereTaxValues===$before,'read-only display preserves proof');
check(str_contains($html,'In diesem Zeitraum angeschafft')&&str_contains($html,'1.200,00 €'),'display includes original taxable additions');
check(str_contains($html,'Wirtschaftlicher Geschäftsbetrieb → Vermögensverwaltung')&&str_contains($html,'01.01.2026'),'display names both areas and exact date');
check(str_contains($html,'&lt;name&gt;')&&str_contains($html,'&lt;proof&gt;')&&!str_contains($html,'<proof>'),'display escapes original names and evidence');
check(!str_contains($html,'<form')&&!str_contains($html,'<button'),'proof display cannot change accounting values');
echo "$n sphere tax/whole-book/carry checks passed; synthetic data, no network\n";
