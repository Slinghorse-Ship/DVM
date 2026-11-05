<?php declare(strict_types=1);
if(isset($_SERVER['SCRIPT_FILENAME'])&&realpath($_SERVER['SCRIPT_FILENAME'])===__FILE__)require __DIR__.'/../../src/bootstrap.php';
require_once __DIR__.'/balance-internal-clearing.php';
require_once __DIR__.'/../../src/Finance/Balance/BalanceClubIncomeReconciliation.php';
use Kulturhus\Verein\{BalanceInternalClearing as IncomeW,BalanceSegmentCalculation as IncomeS,BalanceClearingStatement as IncomeC};

function incomeState(array $report,array $allocation,array $proof,int $revision=2):array
{
    $segment=['club_id'=>$proof['club_id'],'year'=>$report['year'],'revision'=>1,'state'=>'draft','allocation'=>$allocation];
    $segment['sha256']=IncomeS::hash($segment);
    $row=['club_id'=>$proof['club_id'],'year'=>$report['year'],'revision'=>$revision,'state'=>'reviewed',
        'segment_source'=>['revision'=>1,'sha256'=>$segment['sha256']],'proof'=>$proof];
    $row['sha256']=IncomeS::hash($row);
    return ['segments'=>['journal'=>$report,'allocation'=>$allocation,'version'=>1,'history'=>[$segment],'carry_current'=>true],
        'version'=>$revision,'history'=>[$row],'state'=>'reviewed','current_approval'=>true,'source_current'=>true,'carry_current'=>true,'proof'=>$proof];
}
function incomeFixture(int $paid=100):array
{
    [$r,$u,$a,$i]=clearingFixture($paid);$s=IncomeS::build($r,$u,$a);$w=IncomeW::build('synthetic',$r,$u,$a,$i);
    return incomeState($r,$s,$w);
}
function incomeNext(array $state,int $paid=234):array
{
    $a=$state['segments']['allocation'];$w=$state['proof'];
    [$r,$u,$rows,$input]=clearingNext($w,$paid);$carry=IncomeC::carry($state['segments']['journal'],$a,$w,$r);
    $s=IncomeS::build($r,$u,$carry['allocations']+$rows,$carry['capital_carry']??[]);
    return incomeState($r,$s,IncomeW::build('synthetic',$r,$u,$s['allocations'],$input,$w));
}
if(isset($_SERVER['SCRIPT_FILENAME'])&&realpath($_SERVER['SCRIPT_FILENAME'])===__FILE__){
    $out=[];
    foreach([0,1,100,333,334] as $paid){
        $s=incomeFixture($paid);$v=\Kulturhus\Verein\BalanceClubIncomeReconciliation::fromState($s);
        $n=\Kulturhus\Verein\BalanceClubIncomeReconciliation::fromState(incomeNext($s,334-$paid),$v);
        $out[]=[$v,$n];
    }
    echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
