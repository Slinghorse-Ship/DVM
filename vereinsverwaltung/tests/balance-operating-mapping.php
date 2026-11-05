<?php declare(strict_types=1);
require __DIR__.'/fixtures/ebilanz-values.php';
require_once __DIR__.'/../src/Finance/Balance/BalanceTaxFiling.php';
use Kulturhus\Verein\{BalanceTaxMapping as M,BalanceTaxFiling,TaxCatalog};
$n=0;
function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);++$n;}
function target(string $suffix):string{return str_starts_with($suffix,'is.')?$suffix:'is.netIncome.regular.operatingTC.'.$suffix;}
$cases=json_decode(file_get_contents(__DIR__.'/fixtures/ebilanz-operating-accounts.json'),true,64,JSON_THROW_ON_ERROR);
$registry=json_decode(file_get_contents(__DIR__.'/../resources/ebilanz/skr42-6.9.json'),true,64,JSON_THROW_ON_ERROR);
$targets=BalanceTaxFiling::targets();
function e(mixed $value):string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$money=static fn(int $cents):string=>number_format($cents/100,2,',','.').' €';
foreach([2025,2026] as $year){
    $catalog=TaxCatalog::accounts($year);$accounts=[];$expected=[];
    foreach($cases['automatic'] as $suffix=>$codes)foreach($codes as $code){
        $key='account-'.$code;
        $accounts[$key]=['name'=>$catalog[$code]['name'],'group'=>'expense','skr_code'=>$code,'amount_cents'=>1234];
        $expected[$key]=target($suffix);
        check(isset($targets['expense'][target($suffix)]),'target exposed in editor '.$year.' '.$code);
        check(array_keys(M::choices($year,$accounts[$key],$targets))===[target($suffix)],'precise account offers no unrelated override '.$year.' '.$code);
    }
    $before=serialize($accounts);$preview=M::preview($year,$accounts);
    check($preview['mapping']===$expected&&$preview['review_count']===0,'all precise operating accounts assigned '.$year);
    check(serialize($accounts)===$before,'mapping never changes book/tax amounts '.$year);
    $account=$accounts['account-51290'];$default=$preview['rows']['account-51290'];$choices=M::choices($year,$account,$targets);$fieldName='mapping[material]';$selected='';
    ob_start();require __DIR__.'/../views/Finance/Balance/balance-tax-account.php';$html=ob_get_clean();
    check(!str_contains($html,'<select')&&str_contains($html,'Automatisch:'),'known no-deduction account needs no manual tax selection '.$year);
    $selected='is.netIncome.regular.operatingTC.otherCost.administration';
    ob_start();require __DIR__.'/../views/Finance/Balance/balance-tax-account.php';$html=ob_get_clean();
    check(str_contains($html,'role="alert"')&&str_contains($html,'<select')&&!str_contains($html,'type="hidden"'),'old contradictory mapping not silently retained '.$year);
    $negative=$accounts;foreach($negative as &$a)$a['amount_cents']=-1234;unset($a);
    check(M::preview($year,$negative)['mapping']===$expected,'refund/reversal keeps original cost category '.$year);
    foreach($cases['details'] as $code=>$suffixes){
        $account=['name'=>'Synthetisches Sammelkonto','group'=>'expense','skr_code'=>(string)$code,'amount_cents'=>1234];
        $p=M::preview($year,['detail'=>$account]);
        check($p['mapping']===[]&&$p['review_count']===1&&$p['rows']['detail']['message']!=='Zuordnung durch die Buchhaltung prüfen.','specific question instead of guessed classification '.$year.' '.$code);
        $actual=M::choices($year,$account,$targets);$allowed=array_map('target',$suffixes);$keys=array_keys($actual);sort($keys);sort($allowed);
        check($keys===$allowed,'all and only permitted details in editor '.$year.' '.$code);
        foreach($actual as $label)check(is_string($label)&&trim($label)!==''&&!str_starts_with($label,'is.'),'readable detail label '.$code);
        $default=$p['rows']['detail'];$choices=$actual;$fieldName='mapping[detail]';$selected='';
        $account['name']='<img src=x onerror=alert(1)>';
        ob_start();require __DIR__.'/../views/Finance/Balance/balance-tax-account.php';$html=ob_get_clean();
        check(str_contains($html,e($default['message'])),'specific question rendered beside selection '.$year.' '.$code);
        check(str_contains($html,'&lt;img')&&!str_contains($html,'<img'),'account caption escaped '.$code);
        $dom=new DOMDocument();libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($dom);
        check($xp->query('//label/select/option')->length===count($allowed)+1&&$xp->query('//form')->length===0,'one labeled selection without nested forms '.$code);
        check(M::choices($year,$account+[],['expense'=>[]])===[],'no invented editor targets '.$code);
    }
    foreach(['60020','60040'] as $code){
        $p=M::preview($year,[$code=>['name'=>'Synthetische Vergütung','group'=>'expense','skr_code'=>$code,'amount_cents'=>1234]]);
        check(($p['mapping'][$code]??null)===target('staff.salaries.misc'),'reviewed volunteer remuneration remains assigned '.$year.' '.$code);
    }
    foreach(['50000','61000','62500','69700'] as $code){
        $p=M::preview($year,[$code=>['name'=>'Synthetischer Sonderfall','group'=>'expense','skr_code'=>$code,'amount_cents'=>1234]]);
        check($p['mapping']===[],'unreviewed related account remains unresolved '.$year.' '.$code);
    }
    $receivableDepreciation=M::preview($year,['loss'=>['name'=>'Synthetic receivable impairment','group'=>'expense','skr_code'=>'62800','amount_cents'=>1234]]);
    check(($receivableDepreciation['mapping']['loss']??null)===target('deprAmort.currAss.receiv.other'),'later reviewed receivable impairment rule remains available');
    // Guard every target, including conditional options (previously only default targets were checked).
    foreach($registry['rules'] as $rule){
        if(isset($rule['years'])&&!in_array($year,$rule['years'],true))continue;
        foreach($rule['allowed_targets']??[$rule['target']] as $t)
            check(isset($targets[$rule['group']][$t]),'registry option supported by editor '.$t);
        foreach($rule['target_labels']??[] as $t=>$label){
            if(in_array($t,$rule['allowed_targets']??[$rule['target']],true))continue;
            foreach($rule['codes'] as $code){
                $account=['name'=>'Synthetic caption check','group'=>$rule['group'],'skr_code'=>$code,'amount_cents'=>1234];
                check(!array_key_exists($t,M::choices($year,$account,$targets)),'caption alone cannot offer excluded target '.$t);
            }
        }
    }
}
echo "$n operating account/detail checks passed; synthetic and catalog data only\n";
