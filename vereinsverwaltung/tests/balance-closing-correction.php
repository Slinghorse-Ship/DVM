<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/../src/Finance/Balance/BalanceClosingCorrection.php';
use Kulturhus\Verein\{BalanceClosingCorrection as C,BalanceCalculation as B,BalanceLedger};
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$checks=0;function check(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException($why);$checks++;echo "PASS $why\n";}
function denied(callable $f,string $why):void{try{$f();}catch(DomainException){check(true,$why);return;}throw new RuntimeException($why);}
function pair(string $d,string $c,int $n,string $sphere='4'):array{return [['account'=>$d,'debit_cents'=>$n,'credit_cents'=>0,'sphere'=>in_array($d,['expense','revenue'],true)?$sphere:''],['account'=>$c,'debit_cents'=>0,'credit_cents'=>$n,'sphere'=>in_array($c,['expense','revenue'],true)?$sphere:'']];}
$chart=BalanceLedger::defaultChart();$opening=pair('cash','equity',100000);
$r=['year'=>2025,'state'=>'closed','closed_sha256'=>hash('sha256','synthetic source'),'previous_sha256'=>null,'accounts'=>$chart,'opening'=>$opening,'result_account'=>'equity','entries'=>[],'issues'=>[]];
$r['calculation']=B::build(2025,$chart,$opening,[]);
$input=['kind'=>'error','reason'=>'Missing synthetic invoice','legal_basis'=>'Synthetic reviewed correction only','confirmed'=>true,
    'entries'=>[['id'=>str_repeat('a',32),'date'=>'2025-12-31','description'=>'Accrued invoice','evidence'=>'Synthetic proof','lines'=>pair('expense','liabilities',401)]]];
$p=C::build($r,$input);$corrected=C::report($r,$p);
check($r['entries']===[]&&$r['calculation']['annual_result_cents']===0,'original untouched');
check($p['calculation']['annual_result_cents']===-401&&$p['calculation']['liability_total_cents']===401,'missing accrued cost calculated once');
check($corrected['closed_sha256']!==$r['closed_sha256']&&$corrected['correction']['base_sha256']===$r['closed_sha256'],'separate immutable closing identity');
check(count($p['deltas'])===2,'only changed account balances listed');
denied(fn()=>C::build($corrected,$input),'same correction cannot be added twice');
foreach(['kind'=>'unknown','reason'=>'','legal_basis'=>'','confirmed'=>false,'entries'=>[]] as $key=>$value){$bad=$input;$bad[$key]=$value;denied(fn()=>C::build($r,$bad),'reject invalid '.$key);}
$bad=$input;$bad['surprise']=true;denied(fn()=>C::build($r,$bad),'unknown instructions rejected');
foreach(['date'=>'2026-01-01','id'=>'123','description'=>'','evidence'=>''] as $key=>$value){$bad=$input;$bad['entries'][0][$key]=$value;denied(fn()=>C::build($r,$bad),'invalid entry '.$key);}
$bad=$input;$bad['entries'][0]['lines'][1]['credit_cents']=400;denied(fn()=>C::build($r,$bad),'unbalanced amendment refused');
$bad=$input;$bad['entries'][0]['lines']=pair('fixed_assets','liabilities',1);denied(fn()=>C::build($r,$bad),'journal-only asset amendment refused');
$bad=$r;$bad['accounts']['expense']['skr_code']='62200';$bad['calculation']=B::build(2025,$bad['accounts'],$opening,[]);denied(fn()=>C::build($bad,$input),'depreciation needs corrected original schedule');
$next=$r;$next['year']=2026;$next['previous_sha256']=$r['closed_sha256'];$next['closed_sha256']=hash('sha256','synthetic next');$next['calculation']=B::build(2026,$chart,$opening,[]);
$carry=['kind'=>'carry','reason'=>'Prior correction','legal_basis'=>'Synthetic reviewed carry','confirmed'=>true,'entries'=>[]];
$n=C::build($next,$carry,$corrected);check($n['calculation']['annual_result_cents']===0&&$n['calculation']['liability_total_cents']===401,'carry changes balance without repeating old expense');
check($n['prior_sha256']===$corrected['closed_sha256']&&$n['carry_changed'],'exact revised prior bound');
denied(fn()=>C::build($next,$carry),'missing predecessor refused');
denied(fn()=>C::build($next,$carry,$r),'no-op carry refused');
$bad=$input;$bad['kind']='carry';denied(fn()=>C::build($r,$bad),'carry is never an arbitrary posting');
$n2=C::report($next,$n);$after=$input;$after['entries'][0]['date']='2026-12-31';$after['entries'][0]['id']=str_repeat('b',32);
$q=C::build($n2,$after,$corrected);check($q['calculation']['annual_result_cents']===-401&&$q['calculation']['liability_total_cents']===802,'later correction retains carried liability');
$sphere=$input;$sphere['entries'][0]['lines']=[['account'=>'expense','debit_cents'=>50,'credit_cents'=>0,'sphere'=>'1'],['account'=>'expense','debit_cents'=>0,'credit_cents'=>50,'sphere'=>'4']];
$p=C::build($r,$sphere);check($p['calculation']['annual_result_cents']===0&&$p['calculation']['spheres']['1']['result_cents']===-50&&$p['calculation']['spheres']['4']['result_cents']===50,'source-proven sphere reclassification preserves whole result');
check($p['deltas']===[]&&count($p['entries'][0]['lines'])===2,'zero-net reclassification keeps both source lines');
echo "$checks closing-correction arithmetic checks passed\n";
