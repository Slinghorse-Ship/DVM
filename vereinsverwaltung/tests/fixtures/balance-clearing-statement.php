<?php declare(strict_types=1);
namespace Kulturhus\Verein {function date(string $format,?int $time=null):string{return \gmdate($format,$time??\strtotime('2029-01-15 UTC'));}}
namespace {
require __DIR__.'/../../src/bootstrap.php';require_once __DIR__.'/../../src/Finance/Balance/BalanceClearingStatement.php';require __DIR__.'/balance-internal-clearing.php';
use Kulturhus\Verein\{BalanceClearingStatement as R,BalanceSegmentCalculation as S,BalanceInternalClearing as W,BalanceCalculation as C};
[$r,$units,$rows,$input]=clearingFixture();$r['year']=2024;
foreach($r['entries'] as &$entry)$entry['date']='2024'.substr($entry['date'],4);unset($entry);
$r['calculation']=C::build(2024,$r['accounts'],$r['opening'],$r['entries']);
$a=S::build($r,$units,$rows);$w=W::build('synthetic',$r,$units,$rows,$input);$data=[];
for($year=2024;$year<=2026;$year++){
    $data[]=['source'=>$r,'allocation'=>$a,'proof'=>$w,'statement'=>R::build($r,$a,$w)];
    if($year===2026)break;
    [$next,$units,$running,$input]=clearingNext($w,$year===2024?234:0);
    $opening=R::carry($r,$a,$w,$next);$a=S::build($next,$units,$opening['allocations']+$running,$opening['capital_carry']??[]);
    $w=W::build('synthetic',$next,$units,$a['allocations'],$input,$w);$r=$next;
}
echo json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
