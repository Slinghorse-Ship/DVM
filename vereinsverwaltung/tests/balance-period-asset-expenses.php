<?php declare(strict_types=1);
require __DIR__.'/fixtures/ebilanz-period-spheres.php';
use Kulturhus\Verein\{BalanceClubTaxReconciliation as R,BalanceSegmentCalculation as H};
$n=0;
function check(bool $ok,string $why):void{global $n;if(!$ok)throw new RuntimeException($why);++$n;}
function denied(callable $fn,string $why):void{try{$fn();}catch(DomainException){check(true,$why);return;}throw new RuntimeException($why);}
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
foreach(['entry','fast-book','exit-gain','exit-loss','book-return','value-return','later-return','exempt','multiple'] as $case)foreach([false,true] as $next){
    $base=spherePeriodFixture($case,$next,true,true);$v=spherePeriodFixture($case,$next,true,true,'current');$s=$v['subsidiary'];$old=$base['subsidiary'];
    check($v['whole']===$base['whole'],'original whole books unchanged');
    check($s['valuation']===$old['valuation'],'no tax value or capital change');
    check($s['sphere_reconciliation']===$old['sphere_reconciliation'],'sphere bridge not counted twice');
    check($s['result']['taxable_income_cents']===$old['result']['taxable_income_cents']+$s['result']['additions_cents'],'only explicit disallowance adds to income');
    $choices=R::correctionSources($old);$seen=[];
    foreach($choices as $key=>$row){
        check($row['account']!=='dep','ambiguous raw expense not offered');
        if(!isset($row['asset_id']))continue;
        $seen[$row['asset_id']]=$row;
        $expected=$row['asset_id']==='device'?$old['sphere_reconciliation']['items']['device']['tax']['depreciation_cents']:$old['valuation']['asset_tax']['tax_schedule']['items']['ordinary']['schedule']['depreciation_cents'];
        check($row['cents']===$expected&&$row['account']===null,'specific real tax amount without guessed ledger account');
        $review=$old['review'];$entry=['id'=>'test','kind'=>'non_deductible_expense','sources'=>[$key=>$expected],'reason'=>'Synthetic checked expense','legal_basis'=>'Synthetic reviewed rule','confirmed'=>true];
        $review['off_balance_adjustments']=[$entry];$full=R::build($old['income'],$review,$old['previous']);
        check($full['result']['additions_cents']===$expected,'full available amount accepted');
        foreach(['over','negative','twice','income','unconfirmed','stale'] as $bad){
            $r=$review;
            if($bad==='over')$r['off_balance_adjustments'][0]['sources'][$key]++;
            if($bad==='negative')$r['off_balance_adjustments'][0]['sources'][$key]=-1;
            if($bad==='twice')$r['off_balance_adjustments'][]=array_replace($entry,['id'=>'again']);
            if($bad==='income')$r['off_balance_adjustments'][0]['kind']='tax_exempt_income';
            if($bad==='unconfirmed')$r['off_balance_adjustments'][0]['confirmed']=false;
            if($bad==='stale')$r['off_balance_adjustments'][0]['sources']=['asset-depreciation:'.str_repeat('f',64)=>1];
            denied(fn()=>R::build($old['income'],$r,$old['previous']),'invalid specific correction '.$bad);
        }
    }
    check(isset($seen['ordinary']),'ordinary asset sharing affected account remains usable');
    if($old['sphere_reconciliation']['items']['device']['tax']['depreciation_cents']===0)check(!isset($seen['device']),'no zero tax expense invented');
    // Empty changes preserve the existing immutable version, including its hash.
    check(R::build($old['income'],$old['review'],$old['previous'])===$old,'unchanged snapshot stays byte-identical');
    $removed=$s['review'];$removed['off_balance_adjustments']=[];
    check(R::build($s['income'],$removed,$s['previous'])['off_balance']===$old['off_balance'],'removal restores original current-period source shape');
}
$s=spherePeriodFixture('entry',true,true,true,'prior-only')['subsidiary'];
check($s['previous']['result']['additions_cents']>0&&$s['result']['additions_cents']===0,'prior disallowance is not repeated or carried into assets');
$good=spherePeriodFixture('entry',false,true,true,'current')['subsidiary'];
foreach(['cents','asset_id','asset_sha256','tax_value_sha256','name'] as $field){
    $bad=$good;$key=array_key_first($bad['review']['off_balance_adjustments'][0]['sources']);
    $bad['off_balance']['sources'][$key][$field]=$field==='cents'?1:'forged';unset($bad['sha256']);$bad['sha256']=H::hash($bad);
    denied(fn()=>R::verify($bad),'resealed expense evidence rejected '.$field);
}
echo "$n item-specific tax expense checks passed; synthetic data only\n";
