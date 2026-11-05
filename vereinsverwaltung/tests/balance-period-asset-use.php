<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/../src/Finance/Balance/BalanceClubTaxReport.php';require_once __DIR__.'/../src/Finance/Balance/BalancePeriodAssetAllocation.php';
require __DIR__.'/fixtures/balance-club-tax.php';require __DIR__.'/fixtures/balance-shared-disposals.php';
use Kulturhus\Verein\{BalancePeriodAssetAllocation as A,BalancePeriodAssetUse as U,BalancePeriodDisposal as D,BalanceAssetAllocation as Split,BalancePeriod as P};
$checks=0;
function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);++$checks;}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$units=['a'=>['name'=>'Group A','sphere'=>'4'],'b'=>['name'=>'Group B','sphere'=>'4'],'c'=>['name'=>'Group C','sphere'=>'4']];
$lastItem=null;
foreach(['linear_monthly','gwg_immediate','degressive','partial','scrap','sale','scrap-degressive','scrap-gwg','software'] as $method){
    $fixture=in_array($method,['linear_monthly','gwg_immediate','degressive'],true)?sharedAssetFixture(false,$method):sharedDisposalFixture($method);
    $flat=array_values(array_filter($fixture['journal']['asset_schedule']['items'],fn($v)=>$v['sphere']==='4'))[0];
    $plan=$flat['partial_plan']??['basis_cents'=>$flat['basis_cents']??$flat['cost_closing_cents'],'residual_cents'=>$flat['residual_cents'],'months'=>$flat['months'],'acquired_on'=>$flat['acquired_on'],'account'=>$flat['skr_code'],'sphere'=>'4','method'=>$flat['method']??'linear_monthly'];
    foreach(['gwg','degressive'] as $key)if(isset($flat[$key]))$plan[$key]=$flat[$key];
    $period=P::calendar(2025);$item=['item_id'=>'item','name'=>'Synthetic whole asset','fixed_account'=>$flat['account'],'plan'=>$plan];
    if(isset($flat['disposal']))$item['disposal']=$flat['disposal'];
    $item['schedule']=D::calculate($plan,$period,null,$item['disposal']??null);
    $weights=['a'=>2,'b'=>1];$input=[['on'=>'2025-08-01','weights'=>['b'=>1,'c'=>3],'reason'=>'Synthetic monthly use log']];
    if(!isset($item['disposal'])||$item['disposal']['kind']==='partial_scrap')$input[]=['on'=>'2025-11-01','weights'=>['a'=>1],'reason'=>'Synthetic sole use'];
    $changes=U::changes($input,$item,$period,$weights,$units);$result=U::split($item,$period,$weights,$changes,null);
    foreach(A::FIELDS as $f)check(array_sum(array_column($result['parts'],$f))===($item['schedule'][$f]??0),'whole amount retained '.$method.' '.$f);
    foreach($result['parts'] as $u=>$v){
        check($v['book_opening_cents']-$v['depreciation_cents']-$v['book_disposals_cents']+$v['additions_cents']+$v['book_transfer_cents']===$v['book_closing_cents'],'book rollforward with explicit transfer '.$method);
        check(min(array_intersect_key($v,array_flip(A::FIELDS)))>=0,'ordinary values nonnegative '.$method);
    }
    foreach($result['movements'] as $m)foreach(U::TRANSFERS as $f)check(array_sum(array_column($m['parts'],$f))===0,'internal transfer eliminated '.$method);
    $p2=P::calendar(2026);$next=$item;$next['schedule']=D::calculate($plan,$p2,$item['schedule'],$item['disposal']??null);
    $final=end($changes)['weights'];$nextParts=Split::item(A::flat($next,$p2),$final,2026,$p2);
    foreach($units as $u=>$_)foreach(['cost','accumulated','book'] as $f)check(($nextParts[$u][$f.'_opening_cents']??0)===$result['parts'][$u][$f.'_closing_cents'],'next-period carry unchanged '.$method);
    if($method==='degressive'){
        $changes2=U::changes([['on'=>'2026-07-01','weights'=>['b'=>1],'reason'=>'Synthetic dated move']],$next,$p2,$final,$units);
        $two=U::split($next,$p2,$final,$changes2,$item['schedule']);
        check($two['parts']['a']['depreciation_cents']===1530&&$two['parts']['b']['depreciation_cents']===1530,'degressive use phases do not restart financial-year depreciation');
    }
    if($method==='linear_monthly')$lastItem=$item;
}
$period=P::calendar(2025);$item=$lastItem;$weights=['a'=>1];
foreach(['2025-08-02','2024-12-01','2026-01-01','2025-02-30','bad'] as $date)denied(fn()=>U::changes([['on'=>$date,'weights'=>['b'=>1],'reason'=>'Synthetic']],$item,$period,$weights,$units),'invalid or nonmonth date');
foreach([[],['a'=>1],['b'=>0],['b'=>1.5],['b'=>'2'],['b'=>1000001],['foreign'=>1]] as $w)denied(fn()=>U::changes([['on'=>'2025-08-01','weights'=>$w,'reason'=>'Synthetic']],$item,$period,$weights,$units),'invalid or unchanged weights');
$foreign=$units;$foreign['b']['sphere']='1';denied(fn()=>U::changes([['on'=>'2025-08-01','weights'=>['b'=>1],'reason'=>'Synthetic']],$item,$period,$weights,$foreign),'cross-sphere is not an analytical-use change');
// Small integer amounts catch rounding, removed/added areas and zero final book values.
for($basis=2;$basis<=90;$basis++){
    $item['plan']['basis_cents']=$basis;$item['plan']['residual_cents']=$basis%2;$item['plan']['months']=12;
    $item['schedule']=D::calculate($item['plan'],$period,null,null);
    $changes=U::changes([['on'=>'2025-04-01','weights'=>['b'=>2,'c'=>1],'reason'=>'Synthetic'],['on'=>'2025-09-01','weights'=>['a'=>1,'c'=>5],'reason'=>'Synthetic']],$item,$period,['a'=>1],$units);
    $v=U::split($item,$period,['a'=>1],$changes,null);
    foreach(A::FIELDS as $f)check(array_sum(array_column($v['parts'],$f))===($item['schedule'][$f]??0),'small cents conserved');
}
echo "$checks dated asset-use arithmetic checks passed; no database or network\n";
