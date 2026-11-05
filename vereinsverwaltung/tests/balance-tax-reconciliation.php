<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{BalanceCalculation as C,BalanceLedger as L,BalanceTaxReconciliation as T,TaxYearNotes,TaxFormWorkingPaper};
$n=0;function check(bool $v,string $m):void{global $n;if(!$v)throw new RuntimeException($m);$n++;echo "PASS $m\n";}
function denied(callable $f,string $m):void{try{$f();}catch(DomainException){check(true,$m);return;}throw new RuntimeException($m);}
$calculation=C::build(2025,L::defaultChart(),[],[['id'=>'invoice','date'=>'2025-01-01','lines'=>[['account'=>'receivables','debit_cents'=>100000,'credit_cents'=>0,'sphere'=>''],['account'=>'revenue','debit_cents'=>0,'credit_cents'=>100000,'sphere'=>'4']]],['id'=>'cost','date'=>'2025-02-01','lines'=>[['account'=>'expense','debit_cents'=>40000,'credit_cents'=>0,'sphere'=>'4'],['account'=>'liabilities','debit_cents'=>0,'credit_cents'=>40000,'sphere'=>'']]]]);
$report=['year'=>2025,'state'=>'closed','closed_sha256'=>str_repeat('a',64),'calculation'=>$calculation,'issues'=>[]];
$notes=TaxYearNotes::normalize([2025=>['record_completeness'=>'complete','corrections_status'=>'listed','corrections'=>[['sphere'=>'4','direction'=>'add','amount'=>'20','reason'=>'Synthetic non-deductible part'],['sphere'=>'4','direction'=>'subtract','amount'=>'5','reason'=>'Synthetic tax-only adjustment']]]],2025,2025)[2025];
$result=T::build($report,$notes);
check($result['calculations'][4]['reconciled_result_cents']===61500,'accrual result plus reviewed adjustments, not cash surplus');
check($result['calculations'][1]['reconciled_result_cents']===0,'other spheres are not taxed or merged into business result');
check($result['tax_due_cents']===null&&$result['transmission']==='unavailable','working paper does not invent assessed tax or transmission readiness');
check($result['journal_sha256']===$report['closed_sha256'],'calculation bound to immutable journal hash');
$open=$report;$open['state']='open';check(T::build($open,$notes)['calculations'][4]['reconciled_result_cents']===null,'open journal produces no confirmed tax result');
$unknown=$notes;$unknown['corrections_status']='unknown';$unknown['corrections']=[];check(T::build($report,$unknown)['calculations'][4]['reconciled_result_cents']===null,'unknown corrections are not zero corrections');
$drift=$report;$drift['issues']=['Source changed'];check(T::build($drift,$notes)['calculations'][4]['reconciled_result_cents']===null,'source drift blocks confirmed reconciliation');
$bad=$notes;$bad['year']=2026;denied(fn()=>T::build($report,$bad),'cannot reuse other-year notes');
$bad=$notes;$bad['corrections'][0]['amount_cents']=1.5;denied(fn()=>T::build($report,$bad),'fractional cents rejected');
$bad=$notes;$bad['corrections'][0]['reason']='';denied(fn()=>T::build($report,$bad),'undocumented adjustments rejected');
$bad=$notes;$bad['corrections_status']='none';denied(fn()=>T::build($report,$bad),'contradictory no-adjustment declaration rejected');
$snapshot=['accounting_basis'=>'balance','to'=>2025,'reports'=>[2025=>['balance'=>$report,'annual'=>['spheres'=>[['key'=>'4','result_cents'=>999999999]]]]],'year_notes'=>[2025=>$notes]];
check(TaxFormWorkingPaper::build($snapshot)['calculations'][4]['reconciled_result_cents']===61500,'tax preparation takes balance ledger instead of unrelated payment journal');
$snapshot['reports'][2025]['balance_source_issues']=['Missing receipt'];check(TaxFormWorkingPaper::build($snapshot)['calculations'][4]['reconciled_result_cents']===null,'source reconciliation propagated into tax preparation');
echo "$n balance tax reconciliation checks passed\n";
