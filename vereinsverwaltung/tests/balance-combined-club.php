<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/../src/Finance/Balance/BalanceCombinedClubValues.php';require __DIR__.'/fixtures/balance-club-tax.php';
use Kulturhus\Verein\{BalanceCombinedClubValues as V,BalanceClubTaxReport as R,BalanceSegmentCalculation as S,BalanceCalculation as C};
$n=0;
function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);++$n;}
function denied(callable $f,string $label):void{try{$f();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
function state(bool $assets=false):array{$s=clubTaxFixture($assets);$s['journal']['previous_sha256']=null;return $s;}
function review(array $s,?array $previous=null):array{
    $r=R::fromState($s,$previous===null?null:R::rebuild($previous['taxable']['club_scope']));
    return ['year'=>$r['year'],'journal_sha256'=>$s['journal']['closed_sha256'],'complete'=>true,
        'taxable_review'=>['year'=>$r['year'],'journal_sha256'=>$r['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[]]];
}
$s=state();$r=review($s);$before=$s;$v=V::build($s,$r);
check($s===$before,'original state unchanged');check($v===V::build($s,$r),'deterministic');
check($v['whole']['book']['annual_result_cents']===4012&&$v['taxable']['tax']['annual_result_cents']===1006,'whole and taxable results differ correctly');
check($v['reconciliation']['result']===['whole'=>4012,'taxable_book'=>1006,'other_areas'=>3006,'tax_adjustment'=>0,'taxable_tax'=>1006],'explicit result reconciliation');
check($v['whole']['book']===$v['whole']['tax']&&$v['whole']['current_adjustments']===[],'whole books have no tax adjustment');
check($v['transmission']==='unavailable','not a transmission service');
foreach(['complete'=>false,'year'=>2026,'journal_sha256'=>str_repeat('0',64)] as $key=>$value){$bad=$r;$bad[$key]=$value;denied(fn()=>V::build($s,$bad),'binding '.$key);}
$bad=$r;$bad['opening_adjustments']=[];denied(fn()=>V::build($s,$bad),'no whole tax changes');
$bad=$r;$bad['taxable_review']['capital_movements']=[];denied(fn()=>V::build($s,$bad),'wrong review kind');
$bad=$r;$bad['taxable_review']['journal_sha256']=$r['journal_sha256'];denied(fn()=>V::build($s,$bad),'whole hash does not confirm taxable values');
$bad=$s;$bad['current_approval']=false;denied(fn()=>V::build($bad,$r),'unapproved allocation');
$bad=$s;$bad['version']++;denied(fn()=>V::build($bad,$r),'stale approval');
$bad=$s;$bad['journal']['entries'][0]['lines'][0]['debit_cents']++;denied(fn()=>V::build($bad,$r),'changed source');
$bad=$s;unset($bad['allocation']['units']['shop']);denied(fn()=>V::build($bad,$r),'no omitted taxable business');
$bad=$r;$bad['taxable_review']['current_adjustments']=[['id'=>'wrong-sphere','reason'=>'Synthetic wrong sphere','lines'=>[
    ['account'=>'expense','debit_cents'=>100,'credit_cents'=>0,'sphere'=>'1'],['account'=>'cash','debit_cents'=>0,'credit_cents'=>100,'sphere'=>'']]]];
denied(fn()=>V::build($s,$bad),'tax correction cannot affect exempt area');
$a=state(true);$ar=review($a);$av=V::build($a,$ar);check(count($av['whole']['asset_schedule']['items'])===2&&count($av['taxable']['asset_schedule']['items'])===1,'all assets retained separately');
$id=array_key_first($av['taxable']['asset_schedule']['items']);$ar['taxable_review']['asset_tax_plans']=[$id=>['months'=>120,'depreciation_account'=>'depreciation','reason'=>'Synthetic tax useful life','confirmed'=>true]];
$tv=V::build($a,$ar);check($tv['whole']===$av['whole'],'tax plan never changes whole');
check($tv['taxable']['tax']['accounts']['fixed_assets']['amount_cents']===10800&&$tv['whole']['tax']['accounts']['fixed_assets']['amount_cents']===14400,'different asset tax and book values');
check($tv['reconciliation']['result']['tax_adjustment']===1200,'tax result difference accounted once');
$foreign=array_keys($tv['whole']['asset_schedule']['items']);$foreign=array_values(array_diff($foreign,[$id]))[0];$bad=$ar;$bad['taxable_review']['asset_tax_plans'][$foreign]=$ar['taxable_review']['asset_tax_plans'][$id];denied(fn()=>V::build($a,$bad),'exempt asset cannot receive taxable correction');
$next=$s['journal'];$next['year']=2026;$next['previous_sha256']=$next['closed_sha256'];$next['closed_sha256']=hash('sha256','synthetic combined next year');$next['opening']=C::carryForward($next['calculation'],'equity');$next['entries']=[];$next['asset_schedule']['year']=2026;$next['calculation']=C::build(2026,$next['accounts'],$next['opening'],[]);
$carry=S::carry($s['journal'],$s['allocation'],$next);$ns=clubTaxState($next,$carry['units'],$carry['allocations']);$nr=review($ns,$v);$nv=V::build($ns,$nr,$v);
check($nv['whole']['book']['capital_total_cents']===104012&&$nv['taxable']['book']['capital_total_cents']===41006,'both prior results carried once');
denied(fn()=>V::build($ns,$nr),'missing whole prior');
$bad=$v;$bad['sha256']=str_repeat('0',64);denied(fn()=>V::build($ns,$nr,$bad),'changed prior');
$bad=$v;$bad['whole']['book']['annual_result_cents']++;$p=$bad;unset($p['sha256'],$p['transmission']);$bad['sha256']=S::hash($p);denied(fn()=>V::build($ns,$nr,$bad),'rehashed changed prior books');
denied(fn()=>V::build($s,$r,$v),'foreign prior on initial year');
echo "$n combined whole-club checks passed; no database or transport\n";
