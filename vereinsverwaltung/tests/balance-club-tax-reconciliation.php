<?php declare(strict_types=1);
namespace Kulturhus\Verein {function date(string $format,?int $time=null):string{return \gmdate($format,$time??\strtotime('2029-01-15 UTC'));}}
namespace {
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/fixtures/balance-club-tax-reconciliation.php';
use Kulturhus\Verein\{BalanceClubIncomeReconciliation as I,BalanceClubTaxReconciliation as T,BalanceCalculation as C,BalanceInternalClearing as W,BalanceSegmentCalculation as S};
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$checks=0;function check(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException($why);$checks++;}
function denied(callable $f,string $why):void{try{$f();}catch(DomainException){check(true,$why);return;}throw new RuntimeException($why);}
$s=taxIncomeFixture();$i=I::fromState($s);$r=taxIncomeReview($i);$before=serialize([$s,$i,$r]);$v=T::build($i,$r);
check($v['result']===['book_cents'=>1006,'valuation_difference_cents'=>0,'tax_book_cents'=>1006,'additions_cents'=>0,'deductions_cents'=>0,'taxable_income_cents'=>1006],'unadjusted taxable result only');
check($before===serialize([$s,$i,$r])&&!$v['tax_export_ready'],'no mutation, production or transmission approval');
T::verify($v);check(T::build($i,$r)===$v,'exact replay');
foreach(['income_sha256'=>str_repeat('a',64),'complete'=>false,'journal_sha256'=>str_repeat('b',64),'year'=>2026] as $key=>$bad){$x=$r;$x[$key]=$bad;denied(fn()=>T::build($i,$x),'review binding '.$key);}
$x=$r;$x['unknown']=true;denied(fn()=>T::build($i,$x),'no unknown review fields');
$adjustment=['id'=>'receivable-value','reason'=>'Synthetic supported valuation','lines'=>[['account'=>'receivables','debit_cents'=>501,'credit_cents'=>0,'sphere'=>''],['account'=>'expense','debit_cents'=>0,'credit_cents'=>501,'sphere'=>'4']]];
$r['current_adjustments']=[$adjustment];$v=T::build($i,$r);
check($v['result']['tax_book_cents']===1507&&$v['accounts']['expense']['tax_cents']===502,'valuations change only taxable profit');
check($v['valuation']['book']['annual_result_cents']===4012&&$v['valuation']['tax']['annual_result_cents']===4513,'whole arithmetic evidence not mistaken for taxable income');
foreach(['1','2','3'] as $sphere){$x=$r;$x['current_adjustments'][0]['lines'][1]['sphere']=$sphere;denied(fn()=>T::build($i,$x),'exempt valuation rejected '.$sphere);}
$x=$r;$x['current_adjustments'][0]['lines'][1]['sphere']='1';$reverse=$x['current_adjustments'][0];$reverse['id']='opposite';foreach($reverse['lines'] as &$line)[$line['debit_cents'],$line['credit_cents']]=[$line['credit_cents'],$line['debit_cents']];unset($line);$x['current_adjustments'][]=$reverse;
denied(fn()=>T::build($i,$x),'cancelling exempt adjustments still rejected');
$x=$r;$x['current_adjustments'][0]['lines'][0]['debit_cents']++;denied(fn()=>T::build($i,$x),'unbalanced valuation');
$n=taxIncomeNext($s);$ni=I::fromState($n,$i);$nr=taxIncomeReview($ni);$carry=T::carryForward($v,$ni);$nr['opening_adjustments']=[$carry['entry']];$nv=T::build($ni,$nr,$v);
check($nv['valuation']['accounts']['receivables']['tax_opening_cents']===501&&$nv['result']['tax_book_cents']===0,'prior value carried, not profit repeated');
check($nv['income']['internal_balances']['closing_received_cents']===669,'unpaid internal advance kept separate');
$n2=taxIncomeNext($n,0);$ni2=I::fromState($n2,$ni);$nr2=taxIncomeReview($ni2);$nr2['opening_adjustments']=[T::carryForward($nv,$ni2)['entry']];$v2=T::build($ni2,$nr2,$nv);
check($v2['valuation']['accounts']['receivables']['tax_opening_cents']===501&&$v2['result']['taxable_income_cents']===0,'three-year exact carry');
denied(fn()=>T::build($ni,$nr),'missing tax prior');denied(fn()=>T::build($i,$r,$v),'unexpected prior');
$x=$nr;$x['opening_adjustments']=[];denied(fn()=>T::build($ni,$x,$v),'cannot drop prior differences');
$x=$nr;$x['opening_adjustments'][0]['lines'][0]['debit_cents']++;denied(fn()=>T::build($ni,$x,$v),'cannot change carry');
$x=$v;$x['result']['taxable_income_cents']++;$payload=$x;unset($payload['sha256']);$x['sha256']=S::hash($payload);denied(fn()=>T::verify($x),'rehashed fabricated totals rejected');
$r=taxIncomeReview($i);$expense='journal:'.str_repeat('a',32).':0';$sale='journal:'.substr(hash('sha256','food'),0,32).':1';
$correction=['id'=>'nondeductible','kind'=>'non_deductible_expense','sources'=>[$expense=>103],'reason'=>'Synthetic source review','legal_basis'=>'Synthetic reviewed rule (test only)','confirmed'=>true];
$r['off_balance_adjustments']=[$correction,['id'=>'exempt','kind'=>'tax_exempt_income','sources'=>[$sale=>204],'reason'=>'Synthetic exempt source','legal_basis'=>'Synthetic reviewed rule (test only)','confirmed'=>true]];
$v=T::build($i,$r);check($v['result']['taxable_income_cents']===905&&$v['result']['tax_book_cents']===1006,'off-balance corrections do not alter book result');
check($v['valuation']['book']===$v['valuation']['tax'],'off-balance correction never creates fake balancing entries');
foreach(['confirmed'=>false,'reason'=>'','legal_basis'=>'','kind'=>'assumed_rule','sources'=>[]] as $key=>$bad){$x=$r;$x['off_balance_adjustments'][0][$key]=$bad;denied(fn()=>T::build($i,$x),'off-balance validation '.$key);}
foreach([1004,-1,1.1,true] as $amount){$x=$r;$x['off_balance_adjustments'][0]['sources'][$expense]=$amount;denied(fn()=>T::build($i,$x),'amount bounds or sign');}
$x=$r;$x['off_balance_adjustments'][0]['sources']=[$sale=>103];denied(fn()=>T::build($i,$x),'wrong account type');
$x=$r;$x['off_balance_adjustments'][0]['sources']=['journal:'.substr(hash('sha256','club'),0,32).':1'=>103];denied(fn()=>T::build($i,$x),'exempt source cannot be corrected again');
$x=$r;$extra=$correction;$extra['id']='duplicate';$extra['sources'][$expense]=1000;$x['off_balance_adjustments'][]=$extra;denied(fn()=>T::build($i,$x),'split corrections cannot exceed source');
$x=$r;$x['off_balance_adjustments'][]=$correction;denied(fn()=>T::build($i,$x),'duplicate correction ID');
$x=$r;$x['off_balance_adjustments'][0]['sources']=['journal:'.str_repeat('b',32).':0'=>1];denied(fn()=>T::build($i,$x),'repayment not deductible expense');
// A credit note reverses a prior correction with its real negative sign.
$report=$s['segments']['journal'];$alloc=$s['segments']['allocation'];$id=str_repeat('f',32);
$report['entries'][]=['id'=>$id,'date'=>'2025-06-01','description'=>'Synthetic expense refund','lines'=>[['account'=>'cash','debit_cents'=>53,'credit_cents'=>0,'sphere'=>''],['account'=>'expense','debit_cents'=>0,'credit_cents'=>53,'sphere'=>'4']]];
foreach([0,1] as $j)$alloc['allocations'][$id.':'.$j]=['parts'=>['food'=>53],'reason'=>'Synthetic refund source'];
$report['calculation']=C::build(2025,$report['accounts'],$report['opening'],$report['entries']);$w=W::build('synthetic',$report,$alloc['units'],$alloc['allocations'],$s['proof']['instructions']);
$ri=I::fromState(incomeState($report,S::build($report,$alloc['units'],$alloc['allocations']),$w));$rr=taxIncomeReview($ri);$correction['sources']=['journal:'.$id.':1'=>-53];$rr['off_balance_adjustments']=[$correction];
$rv=T::build($ri,$rr);check($rv['result']['tax_book_cents']===1059&&$rv['result']['additions_cents']===-53&&$rv['result']['taxable_income_cents']===1006,'refund reverses addback, not a new expense');
// Automatic tax depreciation retains the complete original asset evidence.
$as=taxIncomeFixture(100,true);$ai=I::fromState($as);$ar=taxIncomeReview($ai);$items=$ai['scope']['source']['asset_schedule']['items'];$asset=substr(hash('sha256','asset-food'),0,32);
$ar['asset_tax_plans'][$asset]=['months'=>120,'depreciation_account'=>'depreciation','reason'=>'Synthetic tax useful life','confirmed'=>true];$av=T::build($ai,$ar);
check($av['result']['valuation_difference_cents']===1200&&$av['valuation']['asset_tax']['items'][$asset]['depreciation_difference_cents']===-1200,'tax asset life affects only original taxable asset');
$ar['asset_tax_plans'][substr(hash('sha256','asset-culture'),0,32)]=$ar['asset_tax_plans'][$asset];denied(fn()=>T::build($ai,$ar),'tax review cannot convert exempt asset');
$ar=taxIncomeReview($ai);$ar['asset_tax_plans'][$asset]=['months'=>120,'depreciation_account'=>'depreciation','reason'=>'Synthetic tax useful life','confirmed'=>true];$ar['current_adjustments']=[$adjustment];$ar['current_adjustments'][0]['lines'][1]['account']='depreciation';denied(fn()=>T::build($ai,$ar),'manual and generated depreciation cannot duplicate');
$ar=taxIncomeReview($ai);$ar['current_adjustments']=[$adjustment];$ar['current_adjustments'][0]['lines'][0]['account']='fixed_assets';denied(fn()=>T::build($ai,$ar),'manual asset value cannot bypass item schedule');
$ar=taxIncomeReview($ai);$ar['current_adjustments']=[$adjustment];$ar['current_adjustments'][0]['lines'][1]['account']='depreciation';denied(fn()=>T::build($ai,$ar),'manual depreciation cannot leave asset plan unchanged');
check(str_contains(T::summary($v),'905')===false&&str_contains(T::summary($v),'9,05 EUR')&&str_contains(T::summary($v),$v['sha256']),'readable source-bound result');
foreach([0,1,100,333,334] as $paid){$fi=I::fromState(taxIncomeFixture($paid));$fv=T::build($fi,taxIncomeReview($fi));check($fv['result']['taxable_income_cents']===1006,'settlement amount never tax income');}
echo "$checks club tax reconciliation checks passed\n";
}
