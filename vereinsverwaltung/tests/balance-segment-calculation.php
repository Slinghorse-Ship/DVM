<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/../src/Finance/Balance/BalanceSegmentCalculation.php';require __DIR__.'/fixtures/balance-segments.php';
use Kulturhus\Verein\{BalanceSegmentCalculation as S,BalanceCalculation};
$checks=0;function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);++$checks;}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
[$report,$units,$alloc]=segmentFixture();$r=S::build($report,$units,$alloc);
check($r['complete']&&!$r['tax_export_ready'],'Complete subdivision does not claim tax filing support');
check(count($r['segments'])===5,'Five units including two businesses');
check($r['segments']['food']['calculation']['expense_cents']===334&&$r['segments']['shop']['calculation']['expense_cents']===669,'Exact shared expense amounts, no whole-percent rounding');
foreach($report['calculation']['accounts'] as $key=>$account)foreach(['opening_cents','debit_cents','credit_cents','closing_cents','amount_cents'] as $field)check(array_sum(array_map(fn($u)=>$u['calculation']['accounts'][$key][$field],$r['segments']))===$account[$field],'Account and side reconciliation');
foreach(['asset_total_cents','passive_total_cents','annual_result_cents','income_cents','expense_cents'] as $field)check(array_sum(array_map(fn($u)=>$u['calculation'][$field],$r['segments']))===$report['calculation'][$field],'Aggregate reconciliation');
check($report===segmentFixture()[0],'Source report unchanged');
$reverse=$alloc;foreach($reverse as &$row)$row['parts']=array_reverse($row['parts'],true);unset($row);$reverse=array_reverse($reverse,true);
check(S::build($report,array_reverse($units,true),$reverse)===$r,'Input order cannot change retained digest');
$missing=$alloc;unset($missing['opening:0']);$draft=S::build($report,$units,$missing);check(!$draft['complete']&&count($draft['missing'])===1,'Missing beginning balance cannot become zero');
$wrong=$alloc;$wrong['opening:0']['parts']['food']++;denied(fn()=>S::build($report,$units,$wrong),'Excess cent blocked');
$wrong=$alloc;$wrong['opening:0']['parts']['food']--;denied(fn()=>S::build($report,$units,$wrong),'Missing cent blocked');
foreach([0,-1,1.0,'20000',true] as $bad){$wrong=$alloc;$wrong['opening:0']['parts']['food']=$bad;denied(fn()=>S::build($report,$units,$wrong),'Malformed cents blocked');}
$wrong=$alloc;$wrong['opening:0']['parts']['unknown']=1;denied(fn()=>S::build($report,$units,$wrong),'Unknown business blocked');
$wrong=$alloc;$wrong['other:0']=$wrong['opening:0'];denied(fn()=>S::build($report,$units,$wrong),'Foreign source blocked');
$wrong=$alloc;$wrong[str_repeat('a',32).':0']['parts']=['club'=>1003];denied(fn()=>S::build($report,$units,$wrong),'Sphere change not hidden in allocation');
$wrong=$alloc;$wrong['opening:0']['reason']='';denied(fn()=>S::build($report,$units,$wrong),'Evidence required');
$wrong=$alloc;$wrong['opening:0']['invented']=true;denied(fn()=>S::build($report,$units,$wrong),'Unknown field blocked');
$wrong=$alloc;$wrong['opening:0']['parts']['food']+=1;$wrong['opening:0']['parts']['shop']-=1;$draft=S::build($report,$units,$wrong);
check(!$draft['complete']&&count($draft['segments']['food']['unbalanced'])===1,'No balancing plug for unequal opening');
$wrong=$alloc;$wrong[str_repeat('a',32).':0']['parts']=['food'=>335,'shop'=>668];check(!S::build($report,$units,$wrong)['complete'],'Unbalanced individual voucher blocked even when club balances');
$wrong=$units;$wrong['shop']['name']=$wrong['food']['name'];denied(fn()=>S::build($report,$wrong,$alloc),'Duplicate label blocked');
$wrong=$units;$wrong['shop']['sphere']='9';denied(fn()=>S::build($report,$wrong,$alloc),'Unknown sphere blocked');
$wrong=$report;$wrong['state']='open';denied(fn()=>S::build($wrong,$units,$alloc),'Unclosed ledger blocked');
$wrong=$report;$wrong['calculation']['annual_result_cents']++;denied(fn()=>S::build($wrong,$units,$alloc),'Recomputed source must match');
$suggest=S::suggest($report,$units);check(count($suggest)===6,'Only unique one-sphere vouchers suggested');
check(!isset($suggest['opening:0'])&&!isset($suggest[str_repeat('a',32).':0']),'No assumptions about opening or multiple businesses');
// Reversal split is retained on its actual side, never discarded or silently netted.
$reverseEntry=$report['entries'][5];$reverseEntry['id']=str_repeat('b',32);$reverseEntry['date']='2025-03-02';
foreach($reverseEntry['lines'] as &$line)[$line['debit_cents'],$line['credit_cents']]=[$line['credit_cents'],$line['debit_cents']];unset($line);
$report['entries'][]=$reverseEntry;for($i=0;$i<2;$i++)$alloc[str_repeat('b',32).':'.$i]=$alloc[str_repeat('a',32).':'.$i];
$report['calculation']=BalanceCalculation::build(2025,$report['accounts'],$report['opening'],$report['entries']);$r=S::build($report,$units,$alloc);
check($r['complete']&&$r['segments']['food']['calculation']['expense_cents']===0&&count($r['segments']['food']['entries'])===3,'Reversal retains source evidence');
for($a=1;$a<1003;$a+=17){foreach(['a','b'] as $id)for($i=0;$i<2;$i++)$alloc[str_repeat($id,32).':'.$i]['parts']=['food'=>$a,'shop'=>1003-$a];$r=S::build($report,$units,$alloc);check($r['complete']&&array_sum(array_map(fn($u)=>$u['calculation']['annual_result_cents'],$r['segments']))===$report['calculation']['annual_result_cents'],'Cent partition invariant');}
[$report,$units,$alloc]=segmentFixture();$r=S::build($report,$units,$alloc);
$next=$report;$next['year']=2026;$next['previous_sha256']=$report['closed_sha256'];$next['closed_sha256']=hash('sha256','synthetic next year');$next['entries']=[];$next['opening']=BalanceCalculation::carryForward($report['calculation'],'equity');$next['calculation']=BalanceCalculation::build(2026,$next['accounts'],$next['opening'],[]);
$carried=S::carry($report,$r,$next);check($carried['complete'],'complete unit opening carried');
foreach($carried['segments'] as $id=>$segment){check($segment['calculation']['annual_result_cents']===0,'prior income never current income');check($segment['calculation']['asset_total_cents']===$r['segments'][$id]['calculation']['asset_total_cents'],'unit assets preserved');check($segment['calculation']['capital_total_cents']===$r['segments'][$id]['calculation']['capital_total_cents']+$r['segments'][$id]['calculation']['annual_result_cents'],'each unit carries its own profit');}
$wrong=$next;$wrong['previous_sha256']=str_repeat('0',64);denied(fn()=>S::carry($report,$r,$wrong),'foreign prior ledger blocked');
$wrong=$next;$wrong['year']=2025;denied(fn()=>S::carry($report,$r,$wrong),'nonconsecutive carry blocked');
$wrong=$next;$wrong['accounts']['cash']['skr_code']='18001';denied(fn()=>S::carry($report,$r,$wrong),'changed reference account cannot silently inherit carry');
$wrong=$r;$wrong['sha256']=str_repeat('0',64);denied(fn()=>S::carry($report,$wrong,$next),'tampered prior allocation cannot carry');
// A loss in one business must not be concealed by the other businesses' capital.
$lossId=str_repeat('c',32);$report['entries'][]=['id'=>$lossId,'date'=>'2025-12-01','description'=>'Synthetic unpaid expense','lines'=>[['account'=>'expense','debit_cents'=>30000,'credit_cents'=>0,'sphere'=>'4'],['account'=>'liabilities','debit_cents'=>0,'credit_cents'=>30000,'sphere'=>'']]];
for($i=0;$i<2;$i++)$alloc[$lossId.':'.$i]=['parts'=>['food'=>30000],'reason'=>'Synthetic unpaid invoice.'];
$report['calculation']=BalanceCalculation::build(2025,$report['accounts'],$report['opening'],$report['entries']);$r=S::build($report,$units,$alloc);
check($r['complete']&&$r['segments']['food']['calculation']['annual_result_cents']<0,'business loss retained independently');
$next['opening']=BalanceCalculation::carryForward($report['calculation'],'equity');$next['calculation']=BalanceCalculation::build(2026,$next['accounts'],$next['opening'],[]);
$lossCarry=S::carry($report,$r,$next);
check($lossCarry['complete']&&$lossCarry['schema']===2,'opposing unit capital remains separate in verified carry');
check($lossCarry['segments']['food']['calculation']['capital_total_cents']===$r['segments']['food']['calculation']['capital_total_cents']+$r['segments']['food']['calculation']['annual_result_cents'],'business deficit carried without concealment');
echo "$checks segment calculation checks passed; synthetic data, no database or transport\n";
