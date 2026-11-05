<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
require_once __DIR__.'/../src/Finance/Balance/BalancePeriodAssets.php';
use Kulturhus\Verein\{BalancePeriod as P,BalanceCalculation as C,BalancePeriodAssets as A,BalanceTaxValues,AssetBooks};
$n=0;
function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
function p(string $start,string $end):array{return ['start'=>$start,'end'=>$end];}
$calendar=P::calendar(2025);$fiscal=p('2025-07-01','2026-06-30');$short=p('2026-07-01','2026-12-31');
check(P::check($fiscal,2026)===$fiscal&&P::fullMonths($fiscal)===12,'explicit cross-calendar financial year');
check(P::fullMonths($short)===6&&P::follows($fiscal,$short),'short transition follows ordinary financial year');
check(P::key($fiscal)!==P::key($short),'two closings in same calendar year have different identities');
check(P::follows(p('2025-01-01','2025-06-30'),$fiscal),'transition does not require next calendar year label');
check(!P::follows($fiscal,p('2026-06-30','2026-12-31'))&&!P::follows($fiscal,p('2026-07-02','2026-12-31')),'overlap and gap cannot become ordinary carry');
check(P::contains($fiscal,'2025-07-01')&&P::contains($fiscal,'2026-06-30')&&!P::contains($fiscal,'2025-06-30')&&!P::contains($fiscal,'2026-07-01'),'inclusive boundary dates');
check(P::fullMonths(p('2024-02-01','2024-02-29'))===1&&P::fullMonths(p('2023-03-01','2024-02-29'))===12,'leap day belongs to correct full month');
check(P::check(p('2025-06-12','2025-06-12'))===p('2025-06-12','2025-06-12'),'arithmetic allows explicit one-day closing without inventing legal approval');
foreach([p('2025-02-29','2025-12-31'),p('2025-01-01','2026-01-01'),p('2025-07-01','2026-07-01'),p('2025-12-31','2025-01-01'),p('2025-1-01','2025-12-31'),p('2025-01-01',"2025-12-31\n"),['start'=>'2025-01-01'],p('2025-01-01','2025-12-31')+['ignored'=>true]] as $i=>$bad)denied(fn()=>P::check($bad),'invalid period '.$i);
denied(fn()=>P::check($fiscal,2025),'closing label cannot be start year');
foreach([p('2025-07-02','2026-06-30'),p('2025-07-01','2026-06-29')] as $i=>$partial)denied(fn()=>P::fullMonths($partial),'no silently doubled partial-month depreciation '.$i);

$chart=['bank'=>['name'=>'Synthetic bank','group'=>'cash','skr_code'=>'18000'],'capital'=>['name'=>'Synthetic capital','group'=>'equity','skr_code'=>'25000'],'income'=>['name'=>'Synthetic sales','group'=>'revenue','skr_code'=>'44000']];
$pair=static fn(int $cents):array=>[['account'=>'bank','debit_cents'=>$cents,'credit_cents'=>0,'sphere'=>''],['account'=>'income','debit_cents'=>0,'credit_cents'=>$cents,'sphere'=>'4']];
$vouchers=[['id'=>'first','date'=>'2025-07-01','lines'=>$pair(1234)],['id'=>'last','date'=>'2026-06-30','lines'=>$pair(5678)]];
$r=C::build(2026,$chart,[],$vouchers,$fiscal);
check($r['annual_result_cents']===6912&&$r['period']===$fiscal&&$r['entry_count']===2,'journal spans actual period and preserves metadata');
denied(fn()=>C::build(2026,$chart,[],$vouchers),'ordinary calendar journal still rejects earlier-year voucher');
foreach(['2025-06-30','2026-07-01'] as $date)denied(fn()=>C::build(2026,$chart,[],[['id'=>'outside','date'=>$date,'lines'=>$pair(1)]],$fiscal),'out-of-period voucher '.$date);
$carry=C::carryForward($r,'capital');$next=C::build(2026,$chart,$carry,[],$short);
check($next['capital_total_cents']===6912&&$next['annual_result_cents']===0&&$next['period']===$short,'short same-calendar-year successor does not repeat income');
$cal=C::build(2025,$chart,[],[]);$explicit=C::build(2025,$chart,[],[],$calendar);unset($explicit['period']);
check($cal===$explicit&&!isset($cal['period']),'legacy calendar result retains byte-equivalent field structure');
denied(fn()=>BalanceTaxValues::build(['year'=>2026,'period'=>$fiscal,'calculation'=>$r],[]),'calendar tax pipeline cannot silently drop actual period');

$plan=['basis_cents'=>120001,'residual_cents'=>1000,'months'=>36,'acquired_on'=>'2025-10-15','method'=>'linear_monthly','account'=>'06300'];
$original=$plan;$asset=A::calculate($plan,$fiscal);
check($asset['cost_opening_cents']===0&&$asset['additions_cents']===120001&&$asset['depreciation_cents']===29750&&$asset['book_closing_cents']===90251,'nine months of original linear plan across calendar boundary');
$assetNext=A::calculate($plan,$short);
check($assetNext['book_opening_cents']===$asset['book_closing_cents']&&$assetNext['depreciation_cents']===19834,'six-month short year uses cumulative rounding not restarted life');
check($plan===$original,'period calculation preserves original acquisition and useful life');
$future=A::calculate($plan,p('2025-01-01','2025-06-30'));
check($future['cost_closing_cents']===0&&$future['additions_cents']===0&&$future['book_opening_cents']===0,'future acquisition does not appear as opening asset');
$fully=A::calculate($plan,P::calendar(2030));
check($fully['cost_closing_cents']===120001&&$fully['depreciation_cents']===0&&$fully['book_closing_cents']===1000,'fully depreciated retained asset keeps historic cost and residual');
foreach([2025,2026,2027,2028,2029] as $year){
    $a=A::calculate($plan,P::calendar($year));$old=AssetBooks::schedule($plan,$year);
    check($a['depreciation_cents']===$old['depreciation_cents']&&$a['book_closing_cents']===$old['closing_cents'],'calendar compatibility with original linear engine '.$year);
}
// Every full-month partition recombines to the same exact depreciation, including odd cents.
foreach([1,2,17,120001,999999] as $basis){
    $test=array_replace($plan,['basis_cents'=>$basis,'residual_cents'=>0,'acquired_on'=>'2025-01-19']);
    $full=A::calculate($test,P::calendar(2025));
    for($month=1;$month<12;++$month){
        $end=(new DateTimeImmutable(sprintf('2025-%02d-01',$month)))->format('Y-m-t');
        $left=A::calculate($test,p('2025-01-01',$end));$right=A::calculate($test,p(sprintf('2025-%02d-01',$month+1),'2025-12-31'));
        check($left['depreciation_cents']+$right['depreciation_cents']===$full['depreciation_cents']&&$left['book_closing_cents']===$right['book_opening_cents'],'partition retains cents '.$basis.' '.$month);
    }
}
$gwg=['basis_cents'=>95200,'residual_cents'=>0,'months'=>1,'acquired_on'=>'2025-09-19','method'=>'gwg_immediate','account'=>'06700',
    'gwg'=>['net_cents'=>80000,'vat_cents'=>15200,'deductible_cents'=>0,'independent_movable'=>true,'no_pool'=>true]];
$g=A::calculate($gwg,$fiscal);$gNext=A::calculate($gwg,$short);
check($g['additions_cents']===95200&&$g['depreciation_cents']===95200&&$g['book_closing_cents']===0,'confirmed GWG charged in actual acquisition period');
check($gNext['cost_opening_cents']===95200&&$gNext['accumulated_opening_cents']===95200&&$gNext['depreciation_cents']===0,'retained GWG not written off again');
foreach([['method'=>'degressive'],['disposal'=>[]],['partial_plan'=>[]],['sphere_changes'=>[]],['months'=>13],['basis_cents'=>1.5],['acquired_on'=>'2025-02-30'],['residual_cents'=>120001]] as $i=>$bad)denied(fn()=>A::calculate(array_replace($plan,$bad),$fiscal),'unsupported or damaged original asset plan '.$i);
denied(fn()=>A::calculate($plan,p('2025-07-15','2026-06-30')),'asset split-month needs explicit separate rule');
denied(fn()=>A::calculate(array_replace($gwg,['gwg'=>array_replace($gwg['gwg'],['no_pool'=>false])]),$fiscal),'GWG period keeps original eligibility check');
echo "$n period and asset-period checks passed; no database writes or export\n";
