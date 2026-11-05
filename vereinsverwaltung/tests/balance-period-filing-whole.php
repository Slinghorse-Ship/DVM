<?php declare(strict_types=1);
require __DIR__.'/fixtures/ebilanz-period-whole.php';
use Kulturhus\Verein\{BalancePeriodFilingValues as Filing,BalanceWholeClubReport as Whole};
$n=0;
function wholeCheck(bool $ok):void{if(!$ok)throw new RuntimeException('Dated whole-club boundary regression');++$GLOBALS['n'];}
function wholeDenied(callable $action):void{try{$action();}catch(DomainException){wholeCheck(true);return;}throw new RuntimeException('Invalid whole-club packet accepted');}
foreach(['book','tax','book-successor','tax-successor'] as $variant){
    $value=wholePeriodFixture($variant);$before=serialize($value);$p=$value['period_source'];
    wholeCheck(Filing::whole($p['source'],$p['review'],$p['previous'])===$value);
    wholeCheck(serialize($value)===$before&&$value['transmission']==='unavailable');
    wholeCheck(Whole::rebuild($value['whole_club_scope'])['calculation']===$value['book']);
    wholeDenied(fn()=>Filing::build($p['source'],$p['review'],$p['previous']));
}
$v=wholePeriodFixture('tax');$p=$v['period_source'];
foreach(['missing','unreviewed','wrong-kind','unknown','amount'] as $kind){
    $r=$p['review'];
    if($kind==='missing')unset($r['capital_movements']);
    if($kind==='unreviewed')$r['capital_movements']=[];
    if($kind==='wrong-kind')$r['capital_movements']['tax:synthetic-tax-capital:1']['kind']='withdrawal';
    if($kind==='unknown')$r['capital_movements']['foreign']=['kind'=>'contribution','reason'=>'Synthetic evidence'];
    if($kind==='amount')$r['capital_movements']['tax:synthetic-tax-capital:1']['amount']=300;
    wholeDenied(fn()=>Filing::whole($p['source'],$r));
}
$v=wholePeriodFixture('tax-successor');$p=$v['period_source'];
wholeDenied(fn()=>Filing::whole($p['source'],$p['review']));
$bad=$p['previous'];$bad['capital_comparison']['contributions_cents']++;
wholeDenied(fn()=>Filing::whole($p['source'],$p['review'],$bad));
$r=$p['review'];$r['opening_adjustments']=[];
wholeDenied(fn()=>Filing::whole($p['source'],$r,$p['previous']));
echo "$n dated whole-club generator-boundary checks passed; no database or transport\n";
