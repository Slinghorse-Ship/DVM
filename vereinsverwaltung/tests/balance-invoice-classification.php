<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
require_once __DIR__.'/../src/Finance/Balance/BalanceInvoiceClassification.php';
use Kulturhus\Verein\{BalanceInvoiceClassification as C,BalanceSources,BalancePeriodSources};
$n=0;function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);$n++;}
function denied(callable $f,string $label):void{try{$f();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$base=['ordinal'=>1,'description'=>'Synthetic purchase','account_code'=>'50000','account_category'=>'expense','catalog_year'=>2026,'tax_rate'=>'19','tax_cents'=>1900,'input_tax'=>'deductible','booking_key'=>'9'];
foreach([2025,2026] as $year)foreach(['material','goods','services'] as $use)foreach(['none','deductible'] as $mode)foreach(['0','7','19'] as $rate){
    $row=array_replace($base,['catalog_year'=>$year,'account_code'=>['material'=>'51000','goods'=>'52000','services'=>'59000'][$use],'tax_rate'=>$rate,'tax_cents'=>(int)$rate*100,'input_tax'=>$mode,'booking_key'=>$mode==='deductible'?(['0'=>'','7'=>'8','19'=>'9'][$rate]):'']);
    $saved=$row;$r=C::resolve($year,[$row],['version'=>1,'uses'=>[]]);
    $expected=$mode==='none'||$rate==='0'?['material'=>'51290','goods'=>'53490','services'=>'59090'][$use]:($rate==='7'?['material'=>'51100','goods'=>'53000','services'=>'59080'][$use]:['material'=>'51300','goods'=>'54000','services'=>'59060'][$use]);
    check($r[1]['account_code']===$expected&&$r[1]['target']!==''&&$saved===$row,'exact annual account without changing source');
    check(C::questions($year,[$row])[1]['choices']===[],'known use needs no second question');
}
foreach([2025,2026] as $year)foreach(['50000'] as $code)foreach(['material'=>'51300','goods'=>'54000'] as $use=>$expected){
    $row=array_replace($base,['catalog_year'=>$year,'account_code'=>$code]);
    check(C::resolve($year,[$row],['version'=>1,'uses'=>[1=>$use]])[1]['account_code']===$expected,'mixed account uses one factual answer');
    denied(fn()=>C::resolve($year,[$row],['version'=>1,'uses'=>[]]),'mixed purchase never guessed');
}
foreach(['50001','50999','51200','06300','68150','51300','53000','51900'] as $code)check(C::questions(2026,[array_replace($base,['account_code'=>$code])])===[],'no unknown/unrelated/reserved/already precise account override');
foreach([2025,2026] as $year)foreach(['401'=>'19','402'=>'7'] as $key=>$rate){
    $row=array_replace($base,['catalog_year'=>$year,'tax_rate'=>$rate,'tax_cents'=>(int)$rate*100,'booking_key'=>(string)$key]);
    check(C::resolve($year,[$row],['version'=>1,'uses'=>[1=>'goods']])[1]['account_code']===($rate==='19'?'54000':'53000'),'equivalent current DATEV key accepted without changing original key');
}
foreach([['booking_key'=>'19'],['booking_key'=>''],['input_tax'=>'unknown'],['input_tax'=>'none'],['tax_rate'=>'5'],['tax_cents'=>-1],['tax_rate'=>'0'],['catalog_year'=>2025],['account_category'=>'revenue']] as $change)denied(fn()=>C::resolve(2026,[array_replace($base,$change)],['version'=>1,'uses'=>[1=>'goods']]),'ambiguous/inconsistent tax source fails closed');
denied(fn()=>C::resolve(2026,[$base],['version'=>1,'uses'=>[2=>'goods']]),'answer for nonexistent position');
denied(fn()=>C::resolve(2026,[$base],['version'=>1,'uses'=>[1=>'services']]),'mixed material/goods cannot become a service');
denied(fn()=>C::resolve(2026,[array_replace($base,['account_code'=>'51000'])],['version'=>1,'uses'=>[1=>'goods']]),'known use cannot be overridden');
foreach([['classify_purchases'=>[]],['classify_purchases'=>'yes'],['purchase_uses'=>'x'],['purchase_uses'=>[0=>'goods']],['purchase_uses'=>[1=>[]]],['purchase_uses'=>[1=>'guess']]] as $bad)denied(fn()=>C::form($bad),'malformed form');
$request=['version'=>1,'uses'=>[1=>'goods']];check(C::form(C::fields($request))===$request,'form roundtrip preserves approval intent');check(C::form(C::fields(null))===null,'unchecked historical option stays absent');
$dates=['recognised_on'=>'2026-01-01','private_paid_on'=>''];
check(BalanceSources::formOptions('invoice',$dates)===$dates&&BalancePeriodSources::formOptions('invoice',$dates)===$dates,'legacy options unchanged');
foreach([BalanceSources::class,BalancePeriodSources::class] as $class)check($class::formOptions('invoice',$dates+C::fields($request))===$dates+['invoice_classification'=>$request],'both form parsers carry new intent');
$snapshot=['entry'=>['kind'=>'expense'],'positions'=>[$base]];
check(C::view(2026,$snapshot,[],null,false)['request']===['version'=>1,'uses'=>[]],'new invoice opts into automatic classification');
check(C::view(2026,$snapshot,[],null,true)['request']===null,'existing invoice remains byte compatible until explicit correction');
check(C::view(2026,$snapshot,C::fields(null),$request,true)['request']===null,'explicit removal reaches correction workflow');
check(C::view(2026,$snapshot,[],$request,true)['request']===$request,'historical answers retained');
check(C::view(2026,$snapshot,['purchase_form'=>[]],null,false)['error']!=='','malformed UI marker blocked');
function e($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}function hidden($name,$value){return '<input type="hidden" name="'.e($name).'" value="'.e($value).'">';}
$purchaseView=C::view(2026,$snapshot,C::fields($request),null,false);$purchaseBound=false;ob_start();require __DIR__.'/../views/Finance/Balance/balance-invoice-classification.php';$html=ob_get_clean();
check(str_contains($html,'Wofür wurde es gekauft?')&&str_contains($html,'value="goods" checked')&&str_contains($html,'Noch unklar')&&!str_contains($html,'Steuerkonto'),'factual choices visible, checked, with uncertainty and no tax dropdown');
$purchaseView=C::view(2026,['entry'=>['kind'=>'expense'],'positions'=>[array_replace($base,['account_code'=>'51000'])]],[],null,false);ob_start();require __DIR__.'/../views/Finance/Balance/balance-invoice-classification.php';$html=ob_get_clean();
check(!str_contains($html,'<select')&&str_contains($html,'name="classify_purchases" value="1"'),'known original facts require no answer');
echo "$n invoice classification and form checks passed\n";
