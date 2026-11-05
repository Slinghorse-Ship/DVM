<?php declare(strict_types=1);
require __DIR__.'/fixtures/ebilanz-period-whole.php';
require_once __DIR__.'/../src/Finance/Balance/BalancePeriodWholeFiling.php';
use Kulturhus\Verein\{BalancePeriodWholeFiling as W,BalanceWholeClubReport as R,BalancePeriod as P};
$n=0;function wpCheck(bool $ok):void{if(!$ok)throw new RuntimeException('Whole dated preparation regression');++$GLOBALS['n'];}
foreach(['book','tax','book-successor','tax-successor'] as $variant){
    $v=wholePeriodFixture($variant);$p=$v['period_source'];$r=R::fromReport($p['source']);
    $b=['review'=>$p['review']];if($p['previous'])$b['carry_forward']=['period_values'=>$p['previous']];
    wpCheck(W::values($b,$r)===$v);$preview=W::values($b,$r,true);
    wpCheck($preview['book']===$v['book']&&$preview['tax']===$v['tax']&&$preview['capital_comparison']===$v['capital_comparison']);
    $before=serialize($v);$map=\Kulturhus\Verein\BalanceTaxMapping::preview($v['year'],$v['tax']['accounts'],W::mappingSchedule($v));
    wpCheck($map['rows']['dep']['status']==='assigned'&&$map['rows']['softwaredep']['status']==='assigned');wpCheck(serialize($v)===$before);
    if($p['previous']){
        $carry=W::carry($p['previous']['period_source']['source'],$p['previous'],$r);
        wpCheck($p['review']['opening_adjustments']===($carry['entry']?[$carry['entry']]:[]));
        wpCheck($carry['period_values']===$p['previous']);
    }
}
foreach(['period','missing','hash'] as $fault){
    $bad=wholePeriodFixture('tax');
    if($fault==='period')$bad['asset_tax']['tax_schedule']['period']['start']='2025-08-01';
    elseif($fault==='missing')array_pop($bad['asset_tax']['tax_schedule']['items']);
    else $bad['asset_tax']['tax_schedule']['items'][array_key_first($bad['asset_tax']['tax_schedule']['items'])]['original_sha256']=str_repeat('f',64);
    try{W::mappingSchedule($bad);throw new RuntimeException('Unbound asset mapping accepted');}catch(DomainException){wpCheck(true);}
}
function input(array $a,string $key,string $default=''):string{return is_string($a[$key]??null)?$a[$key]:$default;}
function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function hidden(string $key,mixed $v):string{return '<input type="hidden" name="'.e($key).'" value="'.e($v).'">';}
foreach(['book','tax','tax-successor'] as $variant){
    $values=wholePeriodFixture($variant);$b=['review'=>$values['period_source']['review']];$report=R::fromReport($values['period_source']['source']);$fid='synthetic';$hidden='';$editable=true;$_POST=[];$money=fn(int $v):string=>number_format($v/100,2,',','.').' €';
    ob_start();require __DIR__.'/../views/Finance/Balance/balance-tax-assets.php';$html=ob_get_clean();wpCheck(str_contains($html,'abweichende Abschreibung')&&str_contains($html,'tax_years'));
}
wpCheck(P::label(['start'=>'2025-07-01','end'=>'2026-06-30'])==='01.07.2025 – 30.06.2026');
// No connection is constructed: disabled environments must fail BEFORE any DB call.
class UnopenedWholePDO extends PDO{public function __construct(){}}
foreach([['synthetic','0'],['production','0']] as [$mode,$flag]){
    putenv('VEREIN_BALANCE_WORKSPACE=1');putenv('VEREIN_MODE='.$mode);putenv('VEREIN_BALANCE_PERIODS='.$flag);
    try{(new W(new UnopenedWholePDO(),'synthetic'))->current('20250701-20260630');throw new RuntimeException('Period gate bypassed');}
    catch(DomainException){wpCheck(true);}
}
echo "$n dated whole preparation checks passed; no database or transport\n";
