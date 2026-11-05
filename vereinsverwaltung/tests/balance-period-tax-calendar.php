<?php declare(strict_types=1);
namespace Kulturhus\Verein {function date(string $format,?int $time=null):string{return \gmdate($format,$time??\strtotime('2029-01-15 UTC'));}}
namespace {
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/fixtures/balance-club-tax-reconciliation.php';
use Kulturhus\Verein\{BalanceClubTaxReconciliation as T,BalanceClubIncomeReconciliation as I,BalanceCalculation as C,BalanceSegmentCalculation as S,BalanceClearingStatement as Statement,BalanceInternalClearing as W,BalancePeriodAssetAllocation as Usage,BalancePeriodDisposal as D,BalancePeriodTaxAssetValues as TaxAssets};
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$n=0;function check(bool $ok,string $why):void{global $n;if(!$ok)throw new RuntimeException($why);$n++;}
function denied(callable $fn,string $why):void{try{$fn();}catch(DomainException){check(true,$why);return;}throw new RuntimeException($why);}
$state=taxIncomeFixture(100,true);$r=$state['segments']['journal'];$a=$state['segments']['allocation'];$shares=[];
foreach($r['asset_schedule']['items'] as $id=>$item)$shares[$id]=['item_sha256'=>S::hash($item),'weights'=>[$item['sphere']==='4'?'food':'culture'=>1],'measure'=>'uses','reason'=>'Synthetic original use'];
$a=S::build($r,$a['units'],$a['allocations'],[],[],$shares);$state=incomeState($r,$a,$state['proof']);
$income=I::fromState($state);$review=taxIncomeReview($income);$asset=substr(hash('sha256','asset-food'),0,32);
$review['asset_tax_plans'][$asset]=['months'=>120,'depreciation_account'=>'depreciation','reason'=>'Synthetic retained tax life','confirmed'=>true];$tax=T::build($income,$review);
$bridge=TaxAssets::calendar($income,$tax['valuation'],$review['asset_tax_plans']);
check($bridge['tax_schedule']['items'][$asset]['schedule']['book_closing_cents']===10800,'calendar tax closing reconstructed exactly');
$source=$income['scope']['source'];
$prior=Usage::calendar($source,$income['scope']['allocation']);$period=['start'=>'2026-01-01','end'=>'2026-06-30'];$items=[];$entries=[];$lineAlloc=[];
foreach($prior['items'] as $id=>$item){
    $schedule=D::calculate($item['plan'],$period,$item['schedule'],$item['disposal']??null);
    $items[$id]=array_replace($item,['schedule'=>$schedule,'posted'=>true]);$amount=$schedule['depreciation_cents'];
    $entry=hash('md5','Synthetic next '.$id);$entries[]=['id'=>$entry,'date'=>$period['end'],'description'=>'Synthetic next depreciation','lines'=>[['account'=>'depreciation','debit_cents'=>$amount,'credit_cents'=>0,'sphere'=>$item['plan']['sphere']],['account'=>$item['fixed_account'],'debit_cents'=>0,'credit_cents'=>$amount,'sphere'=>'']]];
    foreach([0,1] as $i)$lineAlloc[$entry.':'.$i]=['parts'=>[$item['plan']['sphere']==='4'?'food':'culture'=>$amount],'reason'=>'Synthetic original use'];
}
$r=$income['scope']['source'];$r['year']=2026;$r['previous_sha256']=$r['closed_sha256'];$r['closed_sha256']=hash('sha256','Synthetic short successor');$r['opening']=C::carryForward($r['calculation'],'equity');$r['entries']=$entries;
$r['calculation']=C::build(2026,$r['accounts'],$r['opening'],$entries,$period);$r['asset_schedule']=['period'=>$period,'items'=>$items,'issues'=>[]];$r['period']=$period;
$carry=Statement::carry($income['scope']['source'],$income['scope']['allocation'],$income['scope']['clearing'],$r);$alloc=$carry['allocations']+$lineAlloc;
$a=S::build($r,$carry['units'],$alloc,$carry['capital_carry']??[]);$w=W::build('synthetic',$r,$carry['units'],$alloc,['advances'=>[],'repayments'=>[]],$income['scope']['clearing']);$statement=Statement::build($r,$a,$w);
$shares=$prior['shares'];foreach($shares as $id=>&$s)$s['item_sha256']=S::hash($items[$id]);unset($s);$r['assets']=Usage::build($r,$a['units'],$statement['units'],$shares,$prior);
$next=I::rebuild(['schema'=>3,'kind'=>'club_income_reconciliation','club_id'=>'synthetic','source'=>$r,'allocation'=>$a,'clearing'=>$w,'review'=>['allocation'=>['revision'=>2,'sha256'=>hash('sha256','Synthetic period review')],'clearing'=>['revision'=>2,'sha256'=>hash('sha256','Synthetic period review')]],'previous'=>$income['scope']]);
$review2=taxIncomeReview($next);$review2['opening_adjustments']=[T::carryForward($tax,$next)['entry']];$review2['asset_tax_plans']=$review['asset_tax_plans'];$review2['period']=$period;$out=T::build($next,$review2,$tax);T::verify($out);
check($out['valuation']['asset_tax']['tax_schedule']['items'][$asset]['schedule']['book_opening_cents']===10800,'original calendar tax value becomes exact dated opening');
check($out['result']['valuation_difference_cents']===600,'prior 1200 difference not repeated as current result');
check($out['valuation']['tax']['spheres']['3']===$out['valuation']['book']['spheres']['3'],'exempt calendar asset remains untouched');
$bad=$tax['valuation'];$bad['asset_tax']['tax_schedule']['items'][$asset]['book_closing_cents']++;denied(fn()=>TaxAssets::calendar($income,$bad,$review['asset_tax_plans']),'false calendar tax values rejected');
echo "$n calendar-to-period tax checks passed; synthetic arithmetic only\n";
}
