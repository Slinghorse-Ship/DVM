<?php declare(strict_types=1);
require __DIR__.'/fixtures/ebilanz-values.php';
require_once __DIR__.'/../src/Finance/Balance/BalanceTaxMapping.php';
require_once __DIR__.'/../src/Finance/Balance/BalanceTaxFiling.php';
use Kulturhus\Verein\{BalanceTaxMapping as M, BalanceTaxValues as T, BalanceTaxFiling as F};
$n=0;function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
$sales='is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.';
foreach(['vat-payable','vat-refund','vat-return','vat-2026'] as $case){
    [$report,$review]=ebilanzFixture($case);$before=serialize([$report,$review]);$values=T::build($report,$review);
    $m=M::preview($report['year'],$values['tax']['accounts']);
    check($m['mapping']['sales7']===$sales.'reducedRateVAT'&&$m['mapping']['sales19']===$sales.'generalRateVAT',"$case revenue rate mapping");
    check($m['mapping']['input-vat']==='bs.ass.currAss.receiv.other.vat'&&$m['mapping']['output-vat']==='bs.eqLiab.liab.other.theroffTax.vat',"$case retains cleared tax accounts and detailed movements");
    check($values['tax']['annual_result_cents']===match($case){'vat-refund'=>-20000,'vat-return'=>15000,default=>16000},"$case VAT does not become profit");
    check($before===serialize([$report,$review]),"$case no implicit closing entries or source mutations");
}
[$report,$review]=ebilanzFixture('vat-unsettled');$m=M::preview(2025,$report['calculation']['accounts']);
foreach(['input-vat','output-vat'] as $id)check($m['rows'][$id]['status']==='review'&&str_contains($m['rows'][$id]['message'],'Umsatzsteuerabschluss'),"$id unsettled account stays visible as unresolved");
$account=['name'=>'Synthetic only','group'=>'other_assets','skr_code'=>'14060'];
foreach([null,'0',0.0,false,1,-1] as $value){$a=$account;if($value!==null)$a['amount_cents']=$value;check(!isset(M::preview(2025,['a'=>$a])['mapping']['a']),'only exact zero proves a cleared account');}
check(M::preview(2025,['a'=>$account+['amount_cents'=>0]])['mapping']['a']==='bs.ass.currAss.receiv.other.vat','exact integer zero allows cleared input tax');
foreach([2025,2026] as $year){
    $rates=M::revenueRates($year);check($rates['41840']===0&&$rates['43000']===7&&$rates['44000']===19,"$year exact sales rates available for source validation");
    check(!isset($rates['42000'])&&!isset($rates['40000'])&&!isset($rates['42900']),"$year unknown/free-use/member accounts have no inferred tax rate");
    // Restored from the original 2025 PDF's previously clipped bottom rows.
    check(($rates['43340']??null)===7,"$year verified source includes 43340 at reduced rate");
    $choices=M::chartChoices($year);check($choices['44000']['group']==='revenue'&&!$choices['44000']['review_required'],"$year standard revenues are selectable without guessing");
    check($choices['14060']['review_required'],"$year tax clearing is conditional");
    $a=['name'=>'Synthetic only','group'=>'revenue','skr_code'=>'43340','amount_cents'=>100];
    check(M::preview($year,['a'=>$a])['rows']['a']['status']==='assigned',"$year restored original account is assigned");
    foreach(['40900','42000','42203'] as $code)
        check(M::preview($year,['a'=>['name'=>'Unreviewed','group'=>'revenue','skr_code'=>$code,'amount_cents'=>100]])['rows']['a']['status']==='review',"$year $code is not silently treated as ordinary sales");
    // Membership fees, donations and the exact 0% source account now have
    // reviewed purposes. Their XML category still proves no domestic VAT rate.
    foreach(['40000'=>'statutoryDuties.membershipFees','40450'=>'statutoryDuties.donations.immediatelyRecognised','42900'=>'netSales.grossSales.otherRateVAT'] as $code=>$suffix){
        $a=['name'=>'Reviewed source category','group'=>'revenue','skr_code'=>(string)$code,'amount_cents'=>100];
        check(M::preview($year,['a'=>$a])['mapping']===['a'=>'is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.'.$suffix],"$year $code retains its specific reviewed purpose");
        check(!isset($rates[$code]),"$year $code does not invent a domestic VAT rate");
    }
    foreach(['43200'=>'untaxable','43370'=>'UStG13b'] as $code=>$suffix){
        $a=['name'=>'Specific statutory sales category','group'=>'revenue','skr_code'=>(string)$code,'amount_cents'=>100];
        check(M::preview($year,['a'=>$a])['mapping']===['a'=>$sales.$suffix],"$year $code keeps its reviewed special category");
        check(!isset($rates[$code]),"$year $code does not invent a domestic VAT rate");
    }
}
$targets=F::targets();check(isset($targets['revenue'][$sales.'generalRateVAT'],$targets['revenue'][$sales.'reducedRateVAT']),'filing UI exposes reviewed revenue targets');
echo "$n VAT and revenue mapping checks passed; synthetic journals only\n";
