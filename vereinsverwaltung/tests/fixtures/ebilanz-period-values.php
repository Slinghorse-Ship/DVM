<?php declare(strict_types=1);
require __DIR__.'/../../src/bootstrap.php';
require_once __DIR__.'/../../src/Finance/Balance/BalancePeriodFilingValues.php';
use Kulturhus\Verein\{BalanceCalculation as C,BalancePeriodTaxValues as T,BalancePeriodFilingValues as F,BalancePeriodAssetAllocation as A};

function datedFixture(string $variant):array {
    $period=['start'=>'2025-07-01','end'=>'2026-06-30'];
    if($variant==='short-first')$period=['start'=>'2025-03-01','end'=>'2025-12-31'];
    if($variant==='calendar')$period=['start'=>'2026-01-01','end'=>'2026-12-31'];
    $year=(int)substr($period['end'],0,4);
    $chart=['bank'=>['name'=>'Synthetisches Konto','group'=>'cash','skr_code'=>'18000'],
        'capital'=>['name'=>'Synthetischer Vortrag','group'=>'equity','skr_code'=>''],
        'receivables'=>['name'=>'Synthetische Forderungen','group'=>'receivables','skr_code'=>'12000'],
        'liabilities'=>['name'=>'Synthetische Verbindlichkeiten','group'=>'liabilities','skr_code'=>'33000'],
        'sales'=>['name'=>'Synthetischer Umsatz','group'=>'revenue','skr_code'=>''],
        'expense'=>['name'=>'Synthetischer Aufwand','group'=>'expense','skr_code'=>'']];
    $line=fn($a,$d,$c,$s='')=>['account'=>$a,'debit_cents'=>$d,'credit_cents'=>$c,'sphere'=>$s];
    $opening=[$line('bank',50000,0),$line('capital',0,50000)];
    $entries=[['id'=>str_repeat('1',32),'date'=>$period['start'],'lines'=>[$line('receivables',100000,0),$line('sales',0,100000,'4')]],
        ['id'=>str_repeat('2',32),'date'=>$period['end'],'lines'=>[$line('expense',20000,0,'4'),$line('liabilities',0,20000)]]];
    $source=['period'=>$period,'year'=>$year,'state'=>'closed','closed_sha256'=>str_repeat('a',64),'previous_sha256'=>null,'result_account'=>'capital',
        'accounts'=>$chart,'opening'=>$opening,'entries'=>$entries,'calculation'=>C::build($year,$chart,$opening,$entries,$period),'issues'=>[],
        'asset_schedule'=>['period'=>$period,'items'=>[],'totals'=>array_fill_keys(A::FIELDS,0),'issues'=>[]]];
    $review=['period'=>$period,'year'=>$year,'journal_sha256'=>$source['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],
        'current_adjustments'=>[['id'=>'synthetic-change','reason'=>'Synthetic valuation only','lines'=>[$line('receivables',5000,0),$line('expense',0,5000,'4')]]],'asset_tax_plans'=>[]];
    $first=F::build($source,$review);
    if($variant!=='successor')return $first;
    $prior=$source;$priorValues=T::build($source,$review);
    $period=['start'=>'2026-07-01','end'=>'2026-12-31'];$source['period']=$period;$source['closed_sha256']=str_repeat('b',64);$source['previous_sha256']=$prior['closed_sha256'];
    $source['opening']=C::carryForward($prior['calculation'],'capital');$source['entries']=[];
    $source['calculation']=C::build(2026,$chart,$source['opening'],[],$period);$source['asset_schedule']['period']=$period;
    $carry=T::carryForward($prior,$priorValues,$source);$review['period']=$period;$review['journal_sha256']=$source['closed_sha256'];$review['current_adjustments']=[];
    $review['opening_adjustments']=$carry['entry']?[$carry['entry']]:[];
    return F::build($source,$review,$first);
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__)echo json_encode(datedFixture($argv[1]??'cross'),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
