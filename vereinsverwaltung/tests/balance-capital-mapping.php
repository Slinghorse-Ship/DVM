<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/../src/Finance/Balance/BalanceTaxFiling.php';
require __DIR__.'/fixtures/balance-club-tax.php';require __DIR__.'/fixtures/balance-club-capital.php';
use Kulturhus\Verein\{BalanceTaxMapping as M,BalanceTaxFiling as F,BalanceWholeClubReport as W,BalanceTaxValues as T,BalanceCombinedClubValues as V,BalanceClubTaxReport as R,BalanceCalculation as C};
$n=0;function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);++$n;}
function denied(callable $f,string $label):void{try{$f();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
[$state,$decisions]=clubCapitalFixture(clubTaxFixture());$state['journal']['previous_sha256']=null;$r=W::fromReport($state['journal']);$before=$r;
$targets=F::targets();$equity='bs.eqLiab.equity.';$reserve=$equity.'revenueRes.';
$expected=['bound'=>$reserve.'charityAppropriated.project','replacement'=>$reserve.'charityAppropriated.replacement','working'=>$reserve.'charityAppropriated.workingCapital',
    'free'=>$reserve.'unappropriated.assetManagement','free_other'=>$reserve.'unappropriated.otherMeansDueCourse','shares'=>$reserve.'charityAppropriated.shareAcquisition',
    'bound_capital'=>$equity.'statutoryBoundCapital','reallocation'=>$equity.'reallocationSurplus','equity'=>$equity.'association',
    'investment'=>$reserve.'investment','other'=>$reserve.'other','other_second'=>$reserve.'other','statutory'=>$reserve.'statutory','carry_gain'=>$equity.'SurplusOfUnspentFunds','carry_loss'=>$equity.'SurplusOfUnspentFunds'];
$checked=new ReflectionMethod(F::class,'checkedMapping');
foreach([2025,2026] as $year){
    $choices=M::chartChoices($year);$out=M::preview($year,$r['calculation']['accounts']);
    foreach($expected as $id=>$target){
        $a=$r['accounts'][$id];check($choices[$a['skr_code']]['group']===$a['group'],"$year $id capital chart group");
        check(isset(M::choices($year,$a,$targets)[$target]),"$year $id permitted target");
        $ambiguous=in_array($id,['bound','free'],true);
        check($ambiguous?!isset($out['mapping'][$id]):$out['mapping'][$id]===$target,"$year $id exact default or explicit question");
        check(count(M::choices($year,$a,$targets))===($ambiguous?2:1),"$year $id limited relevant choices");
    }
    check(str_contains($out['rows']['bound']['message'],'Vorhaben')&&str_contains($out['rows']['free']['message'],'Woher'),'plain language reserve questions');
    check($checked->invoke(null,$expected,array_replace($r,['year'=>$year]),$r['calculation']['accounts'])===$expected,'server accepts same complete reviewed mapping');
    denied(fn()=>$checked->invoke(null,$expected,array_replace($r,['year'=>$year]),[]),'unvalued chart cannot stand in for reviewed balances');
    foreach(['equity'=>$equity.'retainedEarnings','bound'=>$reserve.'unappropriated.assetManagement','free'=>$reserve.'charityAppropriated.workingCapital','replacement'=>$reserve.'other'] as $id=>$target)
        denied(fn()=>$checked->invoke(null,[$id=>$target],array_replace($r,['year'=>$year]),$r['calculation']['accounts']),'server rejects incompatible capital meaning '.$id);
}
$review=['year'=>2025,'journal_sha256'=>$r['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[],'capital_movements'=>$decisions];$v=T::build($r,$review);
check($v['tax']['annual_result_cents']===4012&&$v['capital_comparison']['contributions_cents']===0&&$v['capital_comparison']['withdrawals_cents']===0,'reserve transfers are neither income nor outside capital movements');
check($v['capital_comparison']['complete']&&$v['tax']['accounts']['replacement']['amount_cents']===650,'reserve formation and release reconciled');
check($v['tax']['accounts']['carry_loss']['amount_cents']===-500,'loss carry sign preserved');
$bad=$review;unset($bad['capital_movements']);check(!T::build($r,$bad)['capital_comparison']['complete'],'unreviewed capital movements block completion');
check($r===$before,'mapping and capital evidence do not change original journal');
$carry=C::carryForward($v['tax'],'carry_gain');$next=C::build(2026,$r['accounts'],$carry,[]);
check($next['accounts']['carry_gain']['amount_cents']===4512&&$next['accounts']['carry_loss']['amount_cents']===-500&&$next['annual_result_cents']===0,'next year carries profit once and retains separate loss');
check($next['accounts']['replacement']['amount_cents']===650,'reserve balance remains intact at year change');
$taxable=R::fromState($state);$allValues=V::build($state,['year'=>2025,'journal_sha256'=>$r['closed_sha256'],'complete'=>true,'taxable_review'=>['year'=>2025,'journal_sha256'=>$taxable['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[]]]);
check($allValues['whole']['tax']['accounts']['replacement']['amount_cents']===650&&$allValues['taxable']['tax']['accounts']['replacement']['amount_cents']===260,'whole reserves and taxable share stay separate');
function e(mixed $s):string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$money=static fn($c)=>number_format($c/100,2,',','.').' €';$year=2025;$wholeMapping=['bound'=>$expected['bound'],'free'=>$expected['free']];
ob_start();require __DIR__.'/../views/Finance/Balance/balance-tax-combined.php';$html=ob_get_clean();
$dom=new DOMDocument();libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($dom);
check($xp->query('//select[@name="whole_mapping[bound]"]/option')->length===3&&$xp->query('//select[@name="whole_mapping[free]"]/option')->length===3,'UI has only question plus two matching choices');
check($xp->query('//select[@name="whole_mapping[equity]"]')->length===0&&$xp->query('//input[@type="hidden"][@name="whole_mapping[equity]"]')->length===1,'known club capital has no unnecessary selector and keeps form value');
check($xp->query('//select[@name="whole_mapping[bound]"]/option[@selected]')->item(0)->textContent==='Vorhaben / Projekt'&&$xp->query('//select[@name="whole_mapping[free]"]/option[@selected]')->item(0)->textContent==='Vermögensverwaltung','short reserve answers fit mobile fields');
check(str_contains($html,'Wofür ist das Geld')&&str_contains($html,'Woher stammt die freie Rücklage'),'UI actually shows the questions');
check(!str_contains($html,'Gewinnrücklagen</option>'),'incorrect retained earnings label removed');
echo "$n capital mapping checks passed; synthetic, no database or transport\n";
