<?php declare(strict_types=1);
if(isset($_SERVER['SCRIPT_FILENAME'])&&realpath($_SERVER['SCRIPT_FILENAME'])===__FILE__)require __DIR__.'/../../src/bootstrap.php';
require_once __DIR__.'/balance-club-income.php';
require_once __DIR__.'/balance-club-tax.php';
require_once __DIR__.'/../../src/Finance/Balance/BalanceClubTaxReconciliation.php';
use Kulturhus\Verein\{BalanceClubIncomeReconciliation as TaxIncome,BalanceClubTaxReconciliation as TaxRecon,BalanceInternalClearing as TaxW,BalanceSegmentCalculation as TaxS};

function taxIncomeFixture(int $paid=100,bool $assets=false):array
{
    [$r,$u,$a,$instructions]=clearingFixture($paid);
    $extra=clubTaxFixture($assets);$original=count($r['entries']);
    $r['accounts']=$extra['journal']['accounts'];$r['asset_schedule']=$extra['journal']['asset_schedule'];
    foreach($extra['journal']['entries'] as $entry){
        if(in_array($entry['id'],array_column($r['entries'],'id'),true))continue;
        $r['entries'][]=$entry;
        foreach($entry['lines'] as $i=>$_)$a[$entry['id'].':'.$i]=$extra['allocation']['allocations'][$entry['id'].':'.$i];
    }
    $r['calculation']=\Kulturhus\Verein\BalanceCalculation::build(2025,$r['accounts'],$r['opening'],$r['entries']);
    return incomeState($r,TaxS::build($r,$u,$a),TaxW::build('synthetic',$r,$u,$a,$instructions));
}
function taxIncomeReview(array $income):array
{
    return ['year'=>$income['year'],'journal_sha256'=>$income['scope']['source']['closed_sha256'],'income_sha256'=>$income['sha256'],'complete'=>true,
        'opening_adjustments'=>[],'current_adjustments'=>[],'asset_tax_plans'=>[],'off_balance_adjustments'=>[]];
}
function taxIncomeNext(array $state,int $paid=234):array
{
    $n=incomeNext($state,$paid);$r=$n['segments']['journal'];$r['asset_schedule']=$state['segments']['journal']['asset_schedule'];
    $r['asset_schedule']['year']=$r['year'];
    return incomeState($r,$n['segments']['allocation'],$n['proof']);
}
if(isset($_SERVER['SCRIPT_FILENAME'])&&realpath($_SERVER['SCRIPT_FILENAME'])===__FILE__){
    $out=[];
    foreach([0,1,100,333,334] as $paid){
        $s=taxIncomeFixture($paid);$i=TaxIncome::fromState($s);$review=taxIncomeReview($i);
        $review['current_adjustments'][]=['id'=>'receivable-value','reason'=>'Synthetic evidenced valuation','lines'=>[
            ['account'=>'receivables','debit_cents'=>501,'credit_cents'=>0,'sphere'=>''],['account'=>'expense','debit_cents'=>0,'credit_cents'=>501,'sphere'=>'4']]];
        $v=TaxRecon::build($i,$review);
        $ni=TaxIncome::fromState(taxIncomeNext($s,334-$paid),$i);$nr=taxIncomeReview($ni);$nr['opening_adjustments']=[TaxRecon::carryForward($v,$ni)['entry']];
        $out[]=[$v,TaxRecon::build($ni,$nr,$v)];
    }
    echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
