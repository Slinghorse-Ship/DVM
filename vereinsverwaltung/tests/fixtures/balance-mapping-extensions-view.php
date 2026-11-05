<?php declare(strict_types=1);
/** Pure actual PHP views and defaults. Never connects to a database. */
$root=dirname(__DIR__,2);
require $root.'/src/bootstrap.php';require_once $root.'/src/Finance/Balance/BalanceTaxFiling.php';
use Kulturhus\Verein\{BalanceTaxMapping as M,BalanceTaxFiling,TaxCatalog};
$n=0;$errors=[];
function check(bool $value,string $message):void{global $n,$errors;++$n;if(!$value)$errors[]=$message;}
function e(mixed $value):string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$money=static fn(int $value):string=>number_format($value/100,2,',','.').' €';
$targets=BalanceTaxFiling::targets();
foreach(['financial-income-r21','operating-r22','vat-r23','operating-r24','capital-r25','cost-register-r26'] as $review){
 $spec=json_decode(file_get_contents($root.'/resources/accounting/reviews/'.$review.'.json'),true,64,JSON_THROW_ON_ERROR);
 foreach($spec['years'] as $edition){
  $year=$edition['year'];$catalog=TaxCatalog::accounts($year);$chart=M::chartChoices($year);$rates=M::revenueRates($year);
  foreach($edition['groups'] as $group)foreach($group['sources'] as $row){
   $code=$row['code'];check(isset($catalog[$code])&&!$catalog[$code]['blocked'],'source bookable '.$code);check($chart[$code]['xml_rule'],'chart rule '.$code);
   if($group['conditional'])check(!isset($rates[$code]),'conditional VAT rate must not be inferred '.$year.' '.$code);
   foreach([1234,-1234,0] as $amount){
    $account=['name'=>'Test <b>Kontozuordnung</b>','skr_code'=>$code,'group'=>$group['group'],'amount_cents'=>$amount];$before=serialize($account);
    $preview=M::preview($year,['test'=>$account]);$default=$preview['rows']['test'];$choices=M::choices($year,$account,$targets);
    $actual=array_keys($choices);$expected=$group['choices'];sort($actual);sort($expected);check($actual===$expected,'exact alternatives '.$code);
    $zero=($group['condition']??null)==='vat_clearing_zero';$pending=$group['conditional']&&(!$zero||$amount!==0);
    if($pending)check($preview['mapping']===[]&&$preview['review_count']===1,'review required '.$code);
    else check($preview['mapping']===['test'=>$group['target']]&&$preview['review_count']===0,'automatic target '.$code);
    check(serialize($account)===$before,'source unchanged '.$code);$fieldName='mapping[test]';$selected='';
    ob_start();require $root.'/views/Finance/Balance/balance-tax-account.php';$html=ob_get_clean();
    check(!str_contains($html,'<b>Kontozuordnung</b>')&&str_contains($html,'&lt;b&gt;'),'escaped caption '.$code);
    check(str_contains($html,'<select')===$pending,'correct control '.$code);
    if($pending)check(str_contains($html,e($default['message'])),'visible review message '.$code);
    foreach($choices as $label)check(trim($label)!==''&&!str_starts_with($label,'bs.')&&!str_starts_with($label,'is.'),'readable label '.$code);
   }
  }
 }
}
echo json_encode(['passed'=>!$errors,'checks'=>$n,'errors'=>$errors,'source_years'=>[2025,2026],'database_connected'=>false],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n";
exit($errors?1:0);
