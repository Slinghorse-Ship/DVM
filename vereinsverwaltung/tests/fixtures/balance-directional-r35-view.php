<?php declare(strict_types=1);
$root=$argv[1]??(__DIR__.'/../..');
require $root.'/tests/fixtures/ebilanz-directional-r35.php';require_once $root.'/src/Finance/Balance/BalanceTaxFiling.php';
use Kulturhus\Verein\{BalanceTaxMapping as M,BalanceTaxFiling,BalanceDirectionalClosing as D};
$checks=0;$errors=[];
function check(bool $ok,string $what):void{global $checks,$errors;++$checks;if(!$ok)$errors[]=$what;}
function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$money=static fn(int $v):string=>number_format($v/100,2,',','.').' €';$targets=BalanceTaxFiling::targets();
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$spec=json_decode(file_get_contents($root.'/resources/accounting/reviews/directional-r35.json'),true,64,JSON_THROW_ON_ERROR);
foreach($spec['years'] as $ed){$year=$ed['year'];
 foreach($ed['groups'] as $index=>$g)foreach($g['sources'] as $source){$code=$source['code'];
  foreach([1234,-1234,0] as $closing){$account=['name'=>'Original <b>Prüfung</b>','skr_code'=>$code,'group'=>$g['group'],'closing_cents'=>$closing,'amount_cents'=>$g['group']==='liabilities'?-$closing:$closing];$before=serialize($account);
   $choices=M::choices($year,$account,$targets);$expected=$g['directional_balance']?($closing>0?$g['directional_balance']['debit_targets']:($closing<0?$g['directional_balance']['credit_targets']:$g['choices'])):$g['choices'];
   $actual=array_keys($choices);sort($actual);sort($expected);check($actual===$expected,'exact signed choices '.$year.' '.$code.' '.$closing);
   $without=$account;unset($without['closing_cents']);check(M::choices($year,$without,$targets)===$choices,'original amount fallback '.$code.' '.$closing);
   $preview=M::preview($year,['test'=>$account]);$default=$preview['rows']['test'];check($preview['mapping']===[]&&$preview['review_count']===1,'review required '.$code);
   $fieldName='mapping[test]';$selected='';ob_start();require $root.'/views/Finance/Balance/balance-tax-account.php';$html=ob_get_clean();
   check(str_contains($html,'<select')&&str_contains($html,e($default['message'])),'actual review control '.$code);check(!str_contains($html,'<b>Prüfung</b>')&&str_contains($html,'&lt;b&gt;'),'escaped review caption '.$code);
   check(serialize($account)===$before,'original unchanged '.$code);
   foreach($choices as $label)check(trim($label)!==''&&!str_starts_with($label,'bs.'),'readable label '.$code);
  }
  foreach($g['directional_balance']?['debit','credit','tax-flip-debit','tax-flip-credit']:['debit'] as $mode){$values=directionalR35($year,$index,$mode,true);$key='code-'.$code;$values['accounts'][$key]['name']='Original <script>Kontoname</script>';
   $filing=['state'=>'validated'];$approved=false;ob_start();require $root.'/views/Finance/Balance/balance-tax-figures.php';$html=ob_get_clean();$key='code-'.$code;
   check(!str_contains($html,'<script>Kontoname</script>')&&str_contains($html,'&lt;script&gt;'),'escaped figures '.$code);
   if($g['directional_balance']){check(str_contains($html,'Änderung des Originalsaldos'),'signed delta explained '.$code);foreach(['book','tax'] as $section){$raw=$values[$section]['accounts'][$key];check(str_contains($html,($section==='book'?'Buchwert':'Steuerwert').' · '.($raw['closing_cents']>0?'Forderung':'Verbindlichkeit')),'correct visible side '.$code.' '.$section);check(str_contains($html,$money(abs($raw['closing_cents']))),'positive figures '.$code);check(str_contains($html,$money($values['tax']['accounts'][$key]['closing_cents']-$values['book']['accounts'][$key]['closing_cents'])),'original signed delta '.$code);}}
  }
 }
 foreach(['other_assets','liabilities'] as $group){$choices=M::choices($year,['name'=>'Free use','skr_code'=>'','group'=>$group,'amount_cents'=>100],$targets);foreach(array_keys($choices) as $target)check(str_starts_with($target,$group==='other_assets'?'bs.ass.':'bs.eqLiab.'),'free-use retains ordinary side');}
}
echo json_encode(['passed'=>!$errors,'checks'=>$checks,'errors'=>$errors,'database_connected'=>false,'network_used'=>false],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n";exit($errors?1:0);
