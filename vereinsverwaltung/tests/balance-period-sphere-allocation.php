<?php declare(strict_types=1);
require __DIR__.'/balance-period-sphere-change.php';
require_once __DIR__.'/../src/Finance/Balance/BalancePeriodAssetAllocation.php';
use Kulturhus\Verein\{BalancePeriodAssetAllocation as A,BalancePeriodSphereAllocation as SA,BalancePeriodAssetUse as U,BalancePeriod as P,BalancePeriodSphereChange as S,BalanceSegmentCalculation as H};
$base=$n;$n=0;
$period=P::calendar(2025);$plan=['basis_cents'=>120001,'residual_cents'=>1,'months'=>60,'acquired_on'=>'2025-01-18','account'=>'06300','sphere'=>'1','method'=>'linear_monthly'];
$proof=S::build($plan,$period,[change('2025-07-01','4','taxable_value',90001,45)]);
$item=['item_id'=>'device','name'=>'Synthetic device','fixed_account'=>'fixed','plan'=>$plan,'schedule'=>$proof['book']['schedule'],'posted'=>true,'sphere_change'=>$proof];
$units=['club'=>['name'=>'Club','sphere'=>'1'],'businessa'=>['name'=>'Business A','sphere'=>'4'],'businessb'=>['name'=>'Business B','sphere'=>'4']];
$report=['year'=>2025,'period'=>$period,'asset_schedule'=>['period'=>$period,'items'=>['device'=>$item],'issues'=>[]],
    'accounts'=>['fixed'=>['group'=>'fixed_assets','skr_code'=>'06300']],
    'calculation'=>['accounts'=>['fixed'=>['group'=>'fixed_assets','opening_cents'=>0,'closing_cents'=>$item['schedule']['book_closing_cents']]]]];
$share=['item_sha256'=>H::hash($item),'weights'=>['club'=>1],'measure'=>'hours','reason'=>'Synthetic initial use'];
$changes=[['on'=>'2025-07-01','weights'=>['businessa'=>2,'businessb'=>1],'reason'=>'Synthetic movement'],['on'=>'2025-10-01','weights'=>['businessb'=>1],'reason'=>'Synthetic new use within same sphere']];
check(S::rebuild($proof)===$proof,'independent proof replay');
check(A::build($report,$units,[],['device'=>$share],null,false)['issues']!==[],'missing destination stays an editable blocker');
$share['changes']=$changes;$assets=A::build($report,$units,[],['device'=>$share],null,false);
check(!$assets['issues'],'cross-sphere then same-sphere allocation reconciles');
foreach(A::FIELDS as $f){$total=0;foreach($assets['schedules'] as $unit)$total+=$unit['items']['device'][$f]??0;check($total===($item['schedule'][$f]??0),'whole cost and values unchanged '.$f);}
foreach(['1','4'] as $sphere){$dep=0;foreach($assets['schedules'] as $u=>$schedule)if($units[$u]['sphere']===$sphere)$dep+=$schedule['items']['device']['depreciation_cents'];check($dep===$proof['book']['depreciation_by_sphere'][$sphere],'original sphere expense '.$sphere);}
SA::checkMovements($item,$period,$units,$assets['movements']['device']);
check(SA::suggest($proof,$units,[])===[],'ambiguous business never guessed');
$sole=$units;unset($sole['businessb']);$suggest=SA::suggest($proof,$sole,[]);check(count($suggest)===1&&$suggest[0]['weights']===['businessa'=>1],'one eligible business suggested');
check(SA::suggest($proof,$units,$changes)===$changes,'explicit shared-use evidence retained');
foreach(['2025-06-01','2025-08-01'] as $date){$bad=$share;$bad['changes'][0]['on']=$date;
    if($date<'2025-07-01')denied(fn()=>A::build($report,$units,[],['device'=>$bad],null,false),'cannot transfer earlier than tax evidence');
    else check((bool)A::build($report,$units,[],['device'=>$bad],null,false)['issues'],'cannot omit actual transfer month');}
$bad=$share;$bad['changes'][0]['weights']=['club'=>1,'businessa'=>1];denied(fn()=>A::build($report,$units,[],['device'=>$bad],null,false),'simultaneous partial tax spheres not treated as whole transfer');
$bad=$share;$bad['changes'][1]['weights']=['club'=>1];denied(fn()=>A::build($report,$units,[],['device'=>$bad],null,false),'cannot invent unproved return');
$bad=$assets['movements']['device'];$bad[0]['parts']['businessa']['cost_transfer_cents']++;denied(fn()=>SA::checkMovements($item,$period,$units,$bad),'tampered gross transfer rejected');
$bad=$assets['movements']['device'];$bad[0]['parts']['businessa']['book_transfer_cents']++;denied(fn()=>SA::checkMovements($item,$period,$units,$bad),'tampered net transfer rejected');
denied(fn()=>SA::checkMovements($item,$period,$units,[]),'omitted movement cannot pass clearing');
$bad=$proof;$bad['tax']['closing_cents']++;unset($bad['sha256']);$bad['sha256']=H::hash($bad);denied(fn()=>S::rebuild($bad),'rehashed invented tax values rejected');
$bad=$report;unset($bad['asset_schedule']['items']['device']['sphere_change']);$badShare=$share;$badShare['item_sha256']=H::hash($bad['asset_schedule']['items']['device']);denied(fn()=>A::build($bad,$units,[],['device'=>$badShare],null,false),'removing proof cannot legalize cross-sphere use');
$nextPeriod=P::calendar(2026);$nextProof=S::build($plan,$nextPeriod,[],$proof);$next=$item;$next['schedule']=$nextProof['book']['schedule'];$next['sphere_change']=$nextProof;
$nextReport=$report;$nextReport['year']=2026;$nextReport['period']=$nextReport['asset_schedule']['period']=$nextPeriod;$nextReport['asset_schedule']['items']['device']=$next;
$nextReport['calculation']['accounts']['fixed']['opening_cents']=$next['schedule']['book_opening_cents'];$nextReport['calculation']['accounts']['fixed']['closing_cents']=$next['schedule']['book_closing_cents'];
$nextShare=['item_sha256'=>H::hash($next),'weights'=>['businessb'=>1],'measure'=>'hours','reason'=>'Synthetic carry'];
$carried=A::build($nextReport,$units,[],['device'=>$nextShare],$assets,false);
check(!$carried['issues']&&!isset($carried['movements']),'next period keeps new sphere without repeating movement');
foreach($units as $u=>$_)foreach(['cost','accumulated','book'] as $f)check(($carried['schedules'][$u]['items']['device'][$f.'_opening_cents']??0)===($assets['schedules'][$u]['items']['device'][$f.'_closing_cents']??0),'rounded gross and net carry '.$u.' '.$f);
$bad=$nextReport;unset($bad['asset_schedule']['items']['device']['sphere_change']);$badShare=$nextShare;$badShare['item_sha256']=H::hash($bad['asset_schedule']['items']['device']);denied(fn()=>A::build($bad,$units,[],['device'=>$badShare],$assets,false),'cannot remove inherited sphere history');
$bad=$nextShare;$bad['weights']=['club'=>1];denied(fn()=>A::build($nextReport,$units,[],['device'=>$bad],$assets,false),'cannot silently revert to original plan sphere');
// Tiny cents, zero residual/net values and different positive use measures retain every cent.
for($cost=2;$cost<=95;$cost++){
    $p=array_replace($plan,['basis_cents'=>$cost,'residual_cents'=>$cost%2,'months'=>12]);$v=S::build($p,$period,[change('2025-07-01','4','taxable_value',100,12)]);
    $copy=array_replace($item,['plan'=>$p,'schedule'=>$v['book']['schedule'],'sphere_change'=>$v]);
    $split=U::split($copy,$period,['club'=>1],$changes,null);SA::checkMovements($copy,$period,$units,$split['movements']);
    foreach(A::FIELDS as $f)check(array_sum(array_column($split['parts'],$f))===($copy['schedule'][$f]??0),'tiny cent conservation '.$f);
}
echo "$n sphere allocation checks passed plus $base sphere arithmetic checks; no database or network\n";
