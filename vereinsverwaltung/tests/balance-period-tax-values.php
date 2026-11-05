<?php declare(strict_types=1);
namespace Kulturhus\Verein {function date(string $format,?int $time=null):string{return \gmdate($format,$time??\strtotime('2029-01-15 UTC'));}}
namespace {
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/fixtures/balance-period-tax.php';
use Kulturhus\Verein\{BalanceClubTaxReconciliation as T,BalanceSegmentCalculation as S,BalanceTaxValues as CalendarValues};
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$n=0;function check(bool $ok,string $why):void{global $n;if(!$ok)throw new RuntimeException($why);$n++;}
function denied(callable $fn,string $why):void{try{$fn();}catch(DomainException){check(true,$why);return;}throw new RuntimeException($why);}
$income=periodTaxIncomeFixture();$review=periodTaxReview($income);$before=serialize([$income,$review]);$book=T::build($income,$review);
check($book['schema']===2&&$book['period']===$income['period']&&!$book['tax_export_ready'],'exact fiscal period remains a workpaper');
check($book['valuation']['book']===$book['valuation']['tax']&&$book['result']['valuation_difference_cents']===0,'identical values do not create adjustments');
check($before===serialize([$income,$review]),'original books not mutated');
foreach($income['scope']['source']['asset_schedule']['items'] as $id=>$item)$review['asset_tax_plans'][$id]=['months'=>120,'depreciation_account'=>$id==='software'?'softwaredep':'dep','reason'=>'Synthetic reviewed tax life','confirmed'=>true];
$value=T::build($income,$review);T::verify($value);
check($value['valuation']['tax']['period']===$income['period'],'tax calculation uses fiscal dates, not ending calendar year');
check($value['valuation']['asset_tax']['items']['switch']['sale']['book']['gain']&&!$value['valuation']['asset_tax']['items']['switch']['sale']['tax']['gain'],'tax life can turn original book gain into tax loss');
check($value['valuation']['asset_tax']['items']['partial']['difference']['disposal_cents']>0,'tax component residual reconciled separately');
check($value['result']['valuation_difference_cents']===17500,'only remaining linear and partial asset differences affect total result');
check($value['valuation']['tax']['accounts']['fixed']['amount_cents']===$value['valuation']['asset_tax']['tax_schedule']['items']['linear']['schedule']['book_closing_cents']+$value['valuation']['asset_tax']['tax_schedule']['items']['partial']['schedule']['book_closing_cents'],'all retained tax asset values reconcile');
$next=periodTaxIncomeFixture($income);$nextReview=periodTaxReview($next);$carry=T::carryForward($value,$next);$nextReview['opening_adjustments']=[$carry['entry']];$nextReview['asset_tax_plans']=$review['asset_tax_plans'];$nextValue=T::build($next,$nextReview,$value);
check($nextValue['year']===$value['year']&&$nextValue['period']!==$value['period'],'two tax closings ending same year remain separate');
foreach($nextValue['valuation']['asset_tax']['tax_schedule']['items'] as $id=>$item)foreach(['cost','accumulated','book'] as $f)check($item['schedule'][$f.'_opening_cents']===$value['valuation']['asset_tax']['tax_schedule']['items'][$id]['schedule'][$f.'_closing_cents'],'unmodified tax asset opening '.$id.' '.$f);
check($nextValue['valuation']['asset_tax']['tax_schedule']['totals']['book_disposals_cents']===0,'no disposal repeated in short period');
check($nextValue['result']['book_cents']===-21000&&$nextValue['result']['tax_book_cents']===-10500&&$nextValue['result']['valuation_difference_cents']===10500,'prior 17500 difference is carried, only current 10500 changes profit');
$bad=$review;$bad['period']=['start'=>'2026-01-01','end'=>'2026-12-31'];denied(fn()=>T::build($income,$bad),'ending year alone is not a tax identity');
$bad=$review;unset($bad['period']);denied(fn()=>T::build($income,$bad),'calendar review cannot authorize period');
$bad=$nextReview;$bad['opening_adjustments']=[];denied(fn()=>T::build($next,$bad,$value),'tax difference cannot disappear');
$bad=$nextReview;unset($bad['asset_tax_plans']['linear']);denied(fn()=>T::build($next,$bad,$value),'tax plan cannot disappear');
$bad=$nextReview;$bad['asset_tax_plans']['linear']['months']=144;denied(fn()=>T::build($next,$bad,$value),'old tax life cannot silently change');
$bad=$review;$bad['current_adjustments']=[['id'=>'duplicate','reason'=>'Synthetic duplicate','lines'=>[['account'=>'fixed','debit_cents'=>1,'credit_cents'=>0,'sphere'=>''],['account'=>'dep','debit_cents'=>0,'credit_cents'=>1,'sphere'=>'4']]]];denied(fn()=>T::build($income,$bad),'manual asset adjustment cannot double generated value');
$bad=$value;$bad['valuation']['tax']['annual_result_cents']++;$p=$bad;unset($p['sha256']);$bad['sha256']=S::hash($p);denied(fn()=>T::verify($bad),'rehashed false tax total rejected');
denied(fn()=>CalendarValues::build($income['scope']['source'],$review),'new period proof cannot unlock old calendar export');
check(str_contains(T::summary($value),'2025-07-01 bis 2026-06-30'),'summary labels exact period');
echo "$n dated tax valuation checks passed; synthetic arithmetic, no database or transport\n";
}
