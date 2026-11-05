<?php declare(strict_types=1);
require_once __DIR__.'/fixtures/ebilanz-operating-extended.php';
require_once __DIR__.'/../src/Finance/Balance/BalanceTaxFiling.php';
use Kulturhus\Verein\{BalanceTaxMapping as M,BalanceTaxFiling as F,TaxCatalog as K};
$n=0;
function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);++$n;}
function e(mixed $value):string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$money=static fn(int $amount):string=>number_format($amount/100,2,',','.').' €';
$targets=F::targets();
$specs=json_decode(file_get_contents(__DIR__.'/../resources/accounting/reviews/operating-r14.json'),true,64,JSON_THROW_ON_ERROR);
foreach($specs['years'] as $edition){
    $year=$edition['year'];$chart=M::chartChoices($year);$all=[];$expected=[];$conditional=0;$seen=[];
    foreach($edition['groups'] as $spec)foreach($spec['sources'] as $source){
        $code=$source['code'];$key='source-'.$code;
        $account=['name'=>'<img src=x onerror=alert(1)> Kontno mit Schreiibfeehler','group'=>$spec['group'],'skr_code'=>$code,'amount_cents'=>1234];
        $all[$key]=$account;
        check(!isset($seen[$code]),'exactly one reviewed source '.$year.' '.$code);$seen[$code]=true;
        check($chart[$code]['group']===$spec['group']&&$chart[$code]['review_required']===$spec['conditional'],'catalog/editor agree '.$year.' '.$code);
        $choices=M::choices($year,$account,$targets);
        $actual=array_keys($choices);sort($actual);$wanted=$spec['choices'];sort($wanted);
        check($actual===$wanted,'no unrelated choices '.$year.' '.$code);
        foreach($choices as $label)check(is_string($label)&&trim($label)!==''&&!str_starts_with($label,'is.'),'human-readable label '.$code);
        if($spec['conditional'])++$conditional;else $expected[$key]=$spec['target'];
        $wrong=$account;$wrong['group']=$spec['group']==='expense'?'revenue':'expense';
        check(M::choices($year,$wrong,$targets)===[],'wrong account kind rejected '.$year.' '.$code);
    }
    $before=serialize($all);$preview=M::preview($year,$all);
    check($preview['mapping']===$expected&&$preview['review_count']===$conditional,'complete automatic/conditional coverage '.$year);
    check(serialize($all)===$before,'original names and amounts preserved '.$year);
    foreach($all as $key=>$account){
        $choices=M::choices($year,$account,$targets);$default=$preview['rows'][$key];$selected='';$fieldName='mapping['.$key.']';
        ob_start();require __DIR__.'/../views/Finance/Balance/balance-tax-account.php';$html=ob_get_clean();
        check(str_contains($html,'&lt;img')&&!str_contains($html,'<img'),'rendered original caption escaped '.$key);
        if(isset($expected[$key]))check(!str_contains($html,'<select')&&str_contains($html,'Automatisch:'),'no employee tax selection for automatic code '.$year.' '.$key);
        else check(str_contains($html,e($default['message']))&&str_contains($html,'Noch zu klären'),'unclear source is not guessed '.$year.' '.$key);
    }
    foreach([-1234,0] as $amount){
        $changed=$all;foreach($changed as &$a)$a['amount_cents']=$amount;unset($a);
        check(M::preview($year,$changed)['mapping']===$expected,'refund and zero do not change meaning '.$year.' '.$amount);
    }
    $broken=['unknown'=>['name'=>'Wareneinkauf 19 % Vorsteuer','group'=>'expense','skr_code'=>'99999','amount_cents'=>1],
        'source-conflict'=>['name'=>'Erlösschmälerungen 0 %','group'=>'revenue','skr_code'=>'47340','amount_cents'=>1]];
    check(M::preview($year,$broken)['mapping']===[],'plausible text cannot override invalid number/source contradiction '.$year);
    check(count($all)===($year===2025?358:365)&&count($expected)===($year===2025?186:193),'exact added coverage '.$year);
}
check(!F::enabled(),'test never enables production filing');
echo "$n extended operating mapping/UI checks passed; synthetic data only\n";
