<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/../src/Finance/Balance/BalanceClubTaxReport.php';require_once __DIR__.'/../src/Finance/Balance/BalanceSegmentExport.php';
require __DIR__.'/fixtures/balance-club-tax.php';require __DIR__.'/fixtures/balance-internal-transfer.php';
use Kulturhus\Verein\{BalanceSegmentCalculation as S,BalanceClubTaxReport as R,BalanceSegmentExport as E,BalanceCalculation as C};
$checks=0;function check(bool $v,string $s):void{global $checks;if(!$v)throw new RuntimeException($s);++$checks;}
function denied(callable $f,string $s):void{try{$f();}catch(DomainException){check(true,$s);return;}throw new RuntimeException($s);}
foreach([false,true] as $internal)foreach([false,true] as $reversal){
    $state=internalTransferFixture($internal,$reversal);$r=$state['journal'];$a=$state['allocation'];$original=$r;$projected=R::fromState($state);
    check($a['schema']===3&&$a['complete'],'balanced areas after explicit internal funding');
    check(S::rebuild($r,$a)===$a,'deterministic independent rebuild');
    check($r===$original,'original journal not altered');
    check($projected['calculation']['annual_result_cents']===($reversal?2009:1006),'funding is not revenue');
    check($projected['calculation']['accounts']['equity']['closing_cents']===-40000-($internal||$reversal?0:1003),'only cross-sphere final funding increases taxable capital');
    check($projected['calculation']['accounts']['equity']['debit_cents']===($reversal&&!$internal?1003:0),'intra-taxable funding eliminated without inflated equity turnover');
    check($projected['calculation']['accounts']['equity']['credit_cents']===($internal?0:1003),'cross-sphere positive turnover retained');
    if($reversal&&!$internal)check($projected['calculation']['accounts']['equity']['debit_cents']===1003,'cross-sphere reversal is retained');
    $files=E::files($state+['carry_current'=>true,'carry_forward'=>null]);
    check(isset($files['bereiche/mitteluebertragungen.csv']),'human-readable source proof');
    $next=$r;$next['year']=2026;$next['previous_sha256']=$r['closed_sha256'];$next['closed_sha256']=hash('sha256','synthetic transfer carry');$next['opening']=C::carryForward($r['calculation'],'equity');$next['entries']=[];$next['asset_schedule']['year']=2026;$next['calculation']=C::build(2026,$r['accounts'],$next['opening'],[]);
    $carry=S::carry($r,$a,$next);$ns=clubTaxState($next,$carry['units'],$carry['allocations'],2,$carry['capital_carry']??[]);$nr=R::fromState($ns,$projected);
    check($nr['calculation']['annual_result_cents']===0,'no prior-year funding as new income');
    check($nr['calculation']['asset_total_cents']===$projected['calculation']['asset_total_cents'],'projected assets carried');
    foreach($a['segments'] as $id=>$seg)check($carry['segments'][$id]['calculation']['capital_total_cents']===$seg['calculation']['capital_total_cents']+$seg['calculation']['annual_result_cents'],'each unit keeps its closing capital');
}
$state=internalTransferFixture();$a=$state['allocation'];$r=$state['journal'];$v=str_repeat('a',32);$t=$a['transfers'];
check(!S::build($r,$a['units'],$a['allocations'])['complete'],'no silent balancing');
foreach(['cash','expense','revenue','foreign'] as $bad){$b=$t;$b[$v]['account']=$bad;denied(fn()=>S::build($r,$a['units'],$a['allocations'],[],$b),'non-equity transfer rejected');}
foreach(['',"bad\nline",str_repeat('x',1001),true] as $bad){$b=$t;$b[$v]['reason']=$bad;denied(fn()=>S::build($r,$a['units'],$a['allocations'],[],$b),'missing or malformed evidence rejected');}
foreach([334,'334',true,334.0,0,-335] as $bad){$b=$t;$b[$v]['parts']['food']=$bad;denied(fn()=>S::build($r,$a['units'],$a['allocations'],[],$b),'wrong exact amount or type rejected');}
$b=$t;$b['opening']=$b[$v];unset($b[$v]);denied(fn()=>S::build($r,$a['units'],$a['allocations'],[],$b),'opening cannot be fabricated');
$b=$a['allocations'];unset($b[$v.':1']);denied(fn()=>S::build($r,$a['units'],$b,[],$t),'incomplete source cannot be settled');
$b=$a['allocations'];$b[$v.':1']['parts']=['club'=>1002,'food'=>1];denied(fn()=>S::build($r,$a['units'],$b,[],$t),'changed allocation needs explicit removal and re-review');
$b=$t;$b[$v]['parts']['foreign']=1;denied(fn()=>S::build($r,$a['units'],$a['allocations'],[],$b),'foreign unit rejected');
$b=$t;$b[$v]['loan']=true;denied(fn()=>S::build($r,$a['units'],$a['allocations'],[],$b),'loan not misrepresented as permanent funding');
echo "$checks internal transfer checks passed; synthetic, no database or transport\n";
