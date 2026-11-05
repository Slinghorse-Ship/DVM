<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/../src/Finance/Balance/BalanceClubTaxReport.php';require_once __DIR__.'/../src/Finance/Balance/BalancePeriodDisposal.php';
require __DIR__.'/fixtures/balance-club-tax.php';require __DIR__.'/fixtures/balance-shared-disposals.php';
use Kulturhus\Verein\{BalanceAssetAllocation as A,BalanceAssetAllocationDisposal as D,BalancePeriodDisposal as P,AssetPartialDisposal as Partial,TaxCatalog};
$checks=0;function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);++$checks;}
$template=sharedDisposalFixture('partial');$base=array_values(array_filter($template['journal']['asset_schedule']['items'],fn($i)=>isset($i['partial_plan'])))[0];
for($case=1;$case<=60;$case++){
    $p=$base['partial_plan'];$p['basis_cents']=2+$case*7;$p['residual_cents']=$case%($p['basis_cents']-1);$p['months']=36;$p['acquired_on']='2025-03-15';
    $proof=$base['disposal'];$proof['date']='2026-04-17';$proof['allocation']['cost_cents']=1+($case*3)%($p['basis_cents']-1);$proof['allocation']['residual_cents']=min($p['residual_cents'],$proof['allocation']['cost_cents']-1);
    if($p['residual_cents']-$proof['allocation']['residual_cents']>=$p['basis_cents']-$proof['allocation']['cost_cents'])continue;
    $proof['plan_sha256']=Partial::hash($p);$weights=['a'=>1,'b'=>2,'c'=>5];$prior=null;$priorParts=null;$depreciated=$removed=0;
    $periods=[['start'=>'2025-01-01','end'=>'2025-06-30'],['start'=>'2025-07-01','end'=>'2026-06-30'],['start'=>'2026-07-01','end'=>'2026-12-31']];
    for($year=2027;$year<=2030;$year++)$periods[]=['start'=>$year.'-01-01','end'=>$year.'-12-31'];
    foreach($periods as $period){
        $event=$proof['date']<=$period['end']?$proof:null;$s=P::calculate($p,$period,$prior,$event);
        $item=array_replace($base,$s,['residual_cents'=>$p['residual_cents'],'acquired_on'=>$p['acquired_on'],'months'=>$p['months'],'expected_depreciation_cents'=>$s['depreciation_cents']]);
        if($event)$item=array_replace($item,['partial_plan'=>$p,'disposal'=>$event,'basis_cents'=>$p['basis_cents']]);
        else foreach(['partial_plan','disposal','basis_cents','disposal_hash','disposal_accounts',...D::FIELDS] as $f)unset($item[$f]);
        $parts=A::item($item,$weights,TaxCatalog::documentYear($period['end']),$period);
        foreach(D::fields($item) as $f)check(array_sum(array_column($parts,$f))===$item[$f],'whole item retained '.$f);
        foreach($parts as $u=>$v){check(min($v)>=0,'no negative use share');if($priorParts)foreach(['cost','accumulated','book'] as $f)check($v[$f.'_opening_cents']===$priorParts[$u][$f.'_closing_cents'],'exact prior closing retained '.$f);}
        $depreciated+=$s['depreciation_cents'];$removed+=$s['book_disposals_cents']??0;$prior=$s;$priorParts=$parts;
    }
    check($depreciated+$removed+$s['book_closing_cents']===$p['basis_cents'],'lifetime original cost accounted for once');
}
echo "$checks dated shared partial-disposal checks passed; synthetic, no database or transport\n";
