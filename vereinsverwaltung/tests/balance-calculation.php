<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{BalanceCalculation as Calc,BalanceLedger,TaxFormWorkingPaper,TaxFilingScope};
$checks=0;
function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";$checks++;}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
function pair(string $debit,string $credit,int $cents,string $sphere='1'):array{
    return [['account'=>$debit,'debit_cents'=>$cents,'credit_cents'=>0,'sphere'=>in_array($debit,['expense','revenue'],true)?$sphere:''],['account'=>$credit,'debit_cents'=>0,'credit_cents'=>$cents,'sphere'=>in_array($credit,['expense','revenue'],true)?$sphere:'']];
}
$chart=BalanceLedger::defaultChart();$opening=pair('cash','equity',100000);$vouchers=[];
$add=static function(string $id,array $lines,string $date='2025-03-01')use(&$vouchers){$vouchers[]=['id'=>$id,'date'=>$date,'lines'=>$lines];};
$report=Calc::build(2025,$chart,$opening,[]);check($report['asset_total_cents']===100000&&$report['annual_result_cents']===0,'opening capital is not income');
$add('supplier-invoice',pair('expense','liabilities',10000));$report=Calc::build(2025,$chart,$opening,$vouchers);
check($report['annual_result_cents']===-10000&&$report['liability_total_cents']===10000,'unpaid invoice creates expense and liability, no cash movement');
$add('payment',pair('liabilities','cash',10000));$report=Calc::build(2025,$chart,$opening,$vouchers);
check($report['annual_result_cents']===-10000&&$report['liability_total_cents']===0&&$report['asset_total_cents']===90000,'supplier payment never counts expense twice');
$add('member-advance',pair('expense','member_payables',20000));$add('member-part-reimbursement',pair('member_payables','cash',5000));$report=Calc::build(2025,$chart,$opening,$vouchers);
check($report['annual_result_cents']===-30000&&$report['liabilities'][array_search('member_payables',array_column($report['liabilities'],'id'))]['amount_cents']===15000,'private advance remains member payable; partial reimbursement is not second expense');
$add('asset-invoice',pair('fixed_assets','liabilities',30000));$add('asset-payment',pair('liabilities','cash',30000));$add('depreciation',pair('expense','fixed_assets',5000));$report=Calc::build(2025,$chart,$opening,$vouchers);
check($report['annual_result_cents']===-35000&&$report['accounts']['fixed_assets']['amount_cents']===25000,'asset capitalization and later depreciation, no duplicate acquisition expense');
$add('outgoing-invoice',pair('receivables','revenue',50000,'3'));$add('customer-part-payment',pair('cash','receivables',20000));$report=Calc::build(2025,$chart,$opening,$vouchers);
check($report['income_cents']===50000&&$report['accounts']['receivables']['amount_cents']===30000&&$report['spheres'][3]['result_cents']===50000,'income recognized once, partial payment reduces receivable');
$add('prepaid-next-year',pair('prepaid','cash',10000));$add('unpaid-provision',pair('expense','provisions',7000));$add('advance-from-customer',pair('cash','deferred',15000));$report=Calc::build(2025,$chart,$opening,$vouchers);
check($report['annual_result_cents']===8000&&$report['accounts']['prepaid']['amount_cents']===10000&&$report['accounts']['deferred']['amount_cents']===15000,'period allocation and provisions affect correct balance sides');
check($report['asset_total_cents']===145000&&$report['passive_total_cents']===145000&&$report['liability_total_cents']===37000,'independently reconciled assets, capital, liabilities, profit');
$carry=Calc::carryForward($report,'equity');$next=Calc::build(2026,$chart,$carry,[]);
check($next['annual_result_cents']===0&&$next['capital_total_cents']===108000&&$next['asset_total_cents']===145000,'next year carries only balance accounts and separately calculated profit');
$next=Calc::build(2026,$chart,$carry,[['id'=>'prior-customer-payment','date'=>'2026-01-01','lines'=>pair('cash','receivables',30000)]]);
check($next['annual_result_cents']===0&&$next['accounts']['receivables']['amount_cents']===0,'payment of prior-year income does not become new-year revenue');
denied(fn()=>Calc::build(2025,$chart,[['account'=>'cash','debit_cents'=>100,'credit_cents'=>0]],[]),'unbalanced opening blocked, no automatic capital plug');
denied(fn()=>Calc::build(2025,$chart,pair('cash','revenue',100),[]),'opening profit account blocked');
denied(fn()=>Calc::build(2025,$chart,[...$opening,...$opening],[]),'duplicate opening account cannot conceal netting');
denied(fn()=>Calc::build(2025,$chart,$opening,[['id'=>'same','date'=>'2025-01-01','lines'=>pair('cash','revenue',100)],['id'=>'same','date'=>'2025-01-01','lines'=>pair('cash','revenue',100)]]),'duplicate voucher ID blocked');
denied(fn()=>Calc::build(2025,$chart,$opening,[['id'=>'wrong-year','date'=>'2026-01-01','lines'=>pair('cash','revenue',100)]]),'other-year posting blocked');
denied(fn()=>Calc::build(2025,$chart,$opening,[['id'=>'bad-date','date'=>'2025-02-30','lines'=>pair('cash','revenue',100)]]),'invalid date blocked');
$lines=pair('expense','cash',10);$lines[0]['debit_cents']='10';denied(fn()=>Calc::lines($lines,$chart),'string instead of integer cents rejected');
$lines=pair('expense','cash',10);$lines[0]['debit_cents']=10.5;denied(fn()=>Calc::lines($lines,$chart),'fractional cents rejected');
$lines=pair('expense','cash',10);$lines[0]['sphere']='9';denied(fn()=>Calc::lines($lines,$chart),'unclear sphere cannot become final P&L');
$lines=pair('expense','cash',10);$lines[0]['credit_cents']=1;denied(fn()=>Calc::lines($lines,$chart),'both sides on one line rejected');
$lines=pair('expense','cash',10);$lines[0]['account']='other-club';denied(fn()=>Calc::lines($lines,$chart),'unknown account rejected');
$lines=pair('expense','cash',10);$lines[0]['debit_cents']=-10;denied(fn()=>Calc::lines($lines,$chart),'negative posting rejected');
denied(fn()=>Calc::carryForward($report,'cash'),'carry-forward requires explicit equity account');
denied(fn()=>Calc::build(2025,$chart,pair('cash','equity',Calc::LIMIT),[['id'=>'overflow','date'=>'2025-01-01','lines'=>pair('cash','revenue',1)]]),'arithmetic cap enforced');
$overdraft=Calc::build(2025,$chart,[],[['id'=>'overdraft','date'=>'2025-01-01','lines'=>pair('expense','cash',1)]]);
check(count($overdraft['issues'])===1&&$overdraft['asset_total_cents']===-1,'credit balance on asset remains visible, never silently netted or changed');
$zero=Calc::build(2025,$chart,[],[]);check($zero['annual_result_cents']===0&&$zero['difference_cents']===0,'explicit new-club zero opening supported by calculator');
$taxChart=$chart+['input_tax'=>['name'=>'Explicit input tax claim','group'=>'other_assets','skr_code'=>'']];
$taxLines=[...pair('expense','liabilities',10000),...pair('input_tax','liabilities',1900)];
$deductible=Calc::build(2025,$taxChart,$opening,[['id'=>'vat-invoice','date'=>'2025-01-01','lines'=>$taxLines]]);
check($deductible['expense_cents']===10000&&$deductible['liability_total_cents']===11900&&$deductible['accounts']['input_tax']['amount_cents']===1900,'explicit deductible input tax is asset, not double expense');
$gross=Calc::build(2025,$chart,$opening,[['id'=>'no-vat-deduction','date'=>'2025-01-01','lines'=>pair('expense','liabilities',11900)]]);
check($gross['expense_cents']===11900&&$gross['liability_total_cents']===11900,'without input tax deduction the full invoice remains expense');
// Deterministic cent-precision sequences and their exact reversals.
for($n=1;$n<=60;$n++){
    $amount=$n*137;$lines=pair('expense','cash',$amount,(string)(1+$n%4));$undo=$lines;
    foreach($undo as &$l)[$l['debit_cents'],$l['credit_cents']]=[$l['credit_cents'],$l['debit_cents']];unset($l);
    $r=Calc::build(2025,$chart,$opening,[['id'=>'a','date'=>'2025-01-01','lines'=>$lines],['id'=>'b','date'=>'2025-12-31','lines'=>$undo]]);
    check($r['annual_result_cents']===0&&$r['asset_total_cents']===100000&&$r['difference_cents']===0,'cent-exact reversal '.$n);
}
foreach([2025,2026] as $year){$mapping=TaxFormWorkingPaper::build(['to'=>$year,'accounting_basis'=>'balance']);check($mapping['calculations']===[]&&$mapping['transmission']==='unavailable','balance never uses cash result as taxable profit '.$year);}
$answers=array_fill_keys(array_keys(TaxFilingScope::questions()),'no');$answers['accounting']='euer';$answers['vat']='small';$answers['notices_checked']='yes';$answers['profit_return']='yes';$scope=TaxFilingScope::build(2025,$answers);
check($scope['families']['euer']['state']==='needed'&&$scope['families']['ebilanz']['state']==='not_needed'&&$scope['families']['lsta']['state']==='not_needed','own club stays EÜR with no payroll');
echo "$checks balance calculation checks passed\n";
