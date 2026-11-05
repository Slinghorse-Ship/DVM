<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/../src/Finance/Balance/BalanceClubTaxReport.php';require_once __DIR__.'/../src/Finance/Balance/BalanceCombinedClubValues.php';require_once __DIR__.'/../src/Finance/Balance/BalanceSegmentExport.php';
require __DIR__.'/fixtures/balance-club-tax.php';require __DIR__.'/fixtures/balance-capital-carry.php';
use Kulturhus\Verein\{BalanceSegmentCalculation as S,BalanceClubTaxReport as R,BalanceSegmentExport as E};
$checks=0;function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);++$checks;}
function denied(callable $f,string $label):void{try{$f();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
foreach([[10000,10000],[20000,10000],[10000,20000],[1,2],[2,1],[1,1],[10000,50000]] as [$income,$loss])foreach([false,true] as $cross){
    $prior=capitalCarryFixture($income,$loss,$cross,2024);$copy=$prior;$next=capitalCarryNext($prior);$again=capitalCarryNext($next);
    $a=$next['allocation'];$r=R::fromState($prior);$nr=R::fromState($next,$r);$ar=R::fromState($again,$nr);
    check($a['complete']&&$a['schema']===2,'mixed signs represented without a balancing posting');
    check($a['capital_carry']['result']===($cross?['club'=>-$income,'shop'=>$loss]:['food'=>-$income,'shop'=>$loss]),'original opposing capital kept exactly');
    check($again['allocation']['capital_carry']===$a['capital_carry'],'repeated carry stable');
    check($nr['calculation']['accounts']['result']['opening_cents']===($cross?$loss:$loss-$income),'taxable aggregate excludes non-taxable gain');
    check($ar['calculation']['capital_total_cents']===$nr['calculation']['capital_total_cents'],'second carry keeps amount');
    check($next['journal']['entries']===[]&&$prior===$copy,'no invented journal entry or source mutation');
    foreach($a['segments'] as $id=>$segment){
        check($segment['calculation']['annual_result_cents']===0,'carry is not revenue or expense');
        check($segment['calculation']['capital_total_cents']===$prior['allocation']['segments'][$id]['calculation']['capital_total_cents']+$prior['allocation']['segments'][$id]['calculation']['annual_result_cents'],'each area retains its own result');
    }
    $cap=array_reverse($a['capital_carry'],true);foreach($cap as &$parts)$parts=array_reverse($parts,true);unset($parts);
    check(S::build($next['journal'],$a['units'],array_reverse($a['allocations'],true),$cap)===$a,'canonical sort stable');
    $state=$next+['carry_current'=>true,'carry_forward'=>['year'=>2024,'revision'=>2,'sha256'=>$prior['history'][0]['sha256']]];
    $files=E::files($state);check(isset($files['bereiche/kapitalvortrag.csv'])&&str_contains($files['bereiche/kapitalvortrag.csv'],'result;29700;shop;Verkauf;'.$loss.';'.(-$loss)),'separate signed capital proof emitted');
    if($income===$loss){check(!in_array('result',array_column($next['journal']['opening'],'account'),true),'net-zero capital is absent only from consolidated opening');check(isset($a['segments']['shop']['calculation']['accounts']['result']),'unit proof keeps zero-consolidated account');}
}
$prior=capitalCarryFixture();$next=capitalCarryNext($prior);$a=$next['allocation'];$report=$next['journal'];
foreach([true,1.0,'10000',0,S::class] as $bad){$c=$a['capital_carry'];$c['result']['shop']=$bad;denied(fn()=>S::build($report,$a['units'],$a['allocations'],$c),'non-integer or zero capital refused');}
$c=$a['capital_carry'];$c['result']['shop']++;denied(fn()=>S::build($report,$a['units'],$a['allocations'],$c),'net capital drift blocked');
$c=$a['capital_carry'];$c['result']['foreign']=1;denied(fn()=>S::build($report,$a['units'],$a['allocations'],$c),'unknown unit refused');
foreach(['cash','liabilities','expense','unknown'] as $key)denied(fn()=>S::build($report,$a['units'],$a['allocations'],[$key=>$a['capital_carry']['result']]),'capital carry cannot invent assets or liabilities');
$c=['result'=>['food'=>-1,'shop'=>1]];$forged=clubTaxState($report,$a['units'],$a['allocations'],2,$c);
denied(fn()=>R::fromState($forged,R::fromState($prior)),'rehashed wrong per-unit capital rejected against actual prior');
$r=$report;$r['previous_sha256']=null;denied(fn()=>S::build($r,$a['units'],$a['allocations'],$a['capital_carry']),'no first-year fictitious netting');
denied(fn()=>R::fromState($next),'missing prior proof blocked');
$r=$report;$r['accounts']['result']['group']='reserves';$r['calculation']=\Kulturhus\Verein\BalanceCalculation::build($r['year'],$r['accounts'],$r['opening'],$r['entries']);denied(fn()=>S::build($r,$a['units'],$a['allocations'],$a['capital_carry']),'no negative reserve workaround');
echo "$checks capital carry checks passed; synthetic data, no database or transport\n";
