<?php declare(strict_types=1);
require __DIR__.'/fixtures/ebilanz-values.php';
use Kulturhus\Verein\{BalanceTaxValues as T,BalanceCalculation as C};
$n=0;function check(bool $v,string $m):void{global $n;if(!$v)throw new RuntimeException($m);$n++;}
function denied(callable $f,string $m):void{try{$f();}catch(DomainException){check(true,$m);return;}throw new RuntimeException($m);}
[$report,$review]=ebilanzFixture();$before=serialize($report);$result=T::build($report,$review);
check($result['book']['annual_result_cents']===80000,'book result unchanged');
check($result['tax']['annual_result_cents']===85000,'current tax value correction in double-entry result');
check($result['result_difference_cents']===5000,'result difference traceable');
check($result['accounts']['receivables']['difference_cents']===5000,'tax receivable traceable');
check($result['accounts']['expense']['difference_cents']===-5000,'counter posting traceable');
check($result['tax']['asset_total_cents']===$result['tax']['passive_total_cents'],'balanced tax values');
check($before===serialize($report),'commercial original untouched');
check($result===T::build($report,$review),'deterministic output and fingerprint');
check($result['transmission']==='unavailable','no implicit permission to transmit');
check($result['source_spheres']===['4'],'source scope recorded');
foreach(['state'=>'open','issues'=>['source changed'],'closed_sha256'=>'bad'] as $key=>$value){$bad=$report;$bad[$key]=$value;denied(fn()=>T::build($bad,$review),'invalid journal '.$key);}
$bad=$report;$bad['calculation']['annual_result_cents']++;denied(fn()=>T::build($bad,$review),'forged totals rejected');
foreach(['year'=>2026,'complete'=>false,'journal_sha256'=>str_repeat('b',64),'current_adjustments'=>null,'opening_adjustments'=>null] as $key=>$value){$bad=$review;$bad[$key]=$value;denied(fn()=>T::build($report,$bad),'missing/stale review '.$key);}
$bad=$review;$bad['current_adjustments'][0]['lines'][0]['debit_cents']++;denied(fn()=>T::build($report,$bad),'unbalanced tax correction');
$bad=$review;$bad['current_adjustments'][0]['reason']='';denied(fn()=>T::build($report,$bad),'reason required');
$bad=$review;$bad['current_adjustments'][]=$bad['current_adjustments'][0];denied(fn()=>T::build($report,$bad),'duplicate correction id');
$bad=$review;$bad['opening_adjustments']=$bad['current_adjustments'];$bad['current_adjustments']=[];denied(fn()=>T::build($report,$bad),'opening tax correction cannot post current-year profit');
$opening=$review;$opening['current_adjustments']=[];$opening['opening_adjustments']=[['id'=>'prior-tax-difference','reason'=>'Synthetic prior-year tax difference',
    'lines'=>[['account'=>'receivables','debit_cents'=>1000,'credit_cents'=>0,'sphere'=>''],['account'=>'capital','debit_cents'=>0,'credit_cents'=>1000,'sphere'=>'']]]];
$o=T::build($report,$opening);check($o['tax']['annual_result_cents']===80000,'prior difference does not alter current profit');
check($o['accounts']['capital']['tax_opening_cents']===-51000,'prior difference rolls through opening equity');
check($o['accounts']['receivables']['tax_opening_cents']===1000,'prior tax asset opening retained');
foreach(['unchanged'=>80000,'loss'=>-40000,'zero'=>0,'cents'=>84999,'2026'=>85000] as $variant=>$profit){[$r,$v]=ebilanzFixture((string)$variant);check(T::build($r,$v)['tax']['annual_result_cents']===$profit,(string)$variant);}
echo "$n tax value reconciliation checks passed\n";
[$prior,$v]=ebilanzFixture();$prior['result_account']='capital';
$next=$prior;$next['year']=2026;$next['previous_sha256']=$prior['closed_sha256'];$next['closed_sha256']=str_repeat('c',64);
$next['opening']=C::carryForward($prior['calculation'],'capital');$next['entries']=[];$next['calculation']=C::build(2026,$next['accounts'],$next['opening'],[]);
$carry=T::carryForward($prior,$v,$next);
$nextReview=['year'=>2026,'journal_sha256'=>$next['closed_sha256'],'complete'=>true,'opening_adjustments'=>[$carry['entry']],'current_adjustments'=>[]];
$carried=T::build($next,$nextReview);
check($carried['accounts']['receivables']['difference_cents']===5000,'closing tax difference carried to following year');
check($carried['accounts']['capital']['difference_cents']===5000,'prior tax profit carried to equity');
check($carried['result_difference_cents']===0,'carry does not duplicate income');
$none=$v;$none['current_adjustments']=[];check(T::carryForward($prior,$none,$next)['entry']===null,'equal book/tax creates no invented adjustment');
$bad=$next;$bad['previous_sha256']=str_repeat('d',64);denied(fn()=>T::carryForward($prior,$v,$bad),'unrelated prior journal blocked');
$bad=$next;$bad['opening'][0]['debit_cents']++;$bad['opening'][1]['credit_cents']++;$bad['calculation']=C::build(2026,$bad['accounts'],$bad['opening'],[]);denied(fn()=>T::carryForward($prior,$v,$bad),'changed opening cannot be overwritten');
$bad=$next;$bad['accounts']['receivables']['skr_code']='12001';$bad['calculation']=C::build(2026,$bad['accounts'],$bad['opening'],[]);denied(fn()=>T::carryForward($prior,$v,$bad),'renumbered prior account needs separate review');
echo "$n tax value and carry checks passed\n";
