<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/../src/Finance/Balance/BalanceClubTaxReport.php';require_once __DIR__.'/../src/Finance/Balance/BalanceSegmentExport.php';require __DIR__.'/fixtures/balance-club-tax.php';require __DIR__.'/fixtures/balance-shared-disposals.php';
use Kulturhus\Verein\{BalanceAssetAllocation as A,BalanceAssetAllocationDisposal as D,BalanceSegmentCalculation as S,BalanceClubTaxReport as R,BalanceSegmentExport as E};
$checks=0;function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);++$checks;}
function denied(callable $f,string $label):void{try{$f();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
foreach(['scrap','gwg-scrap','degressive-scrap','sale-gain','sale-loss','sale-exempt','software','partial'] as $kind){
    $state=sharedDisposalFixture($kind);$a=$state['allocation'];$r=$state['journal'];
    check($a['complete'],'shared disposal reconciles '.$kind.': '.json_encode($a['issues']??[]));$projected=R::fromState($state);check(S::rebuild($r,$a)===$a,'rebuild deterministic');
    $id=array_key_first(array_filter($r['asset_schedule']['items'],fn($i)=>isset($i['disposal'])));$item=$r['asset_schedule']['items'][$id];$weights=$a['asset_shares'][$id]['weights'];$parts=A::item($item,$weights,2025);
    foreach(array_merge(A::FIELDS,D::FIELDS) as $f){check(array_sum(array_column($parts,$f))===$item[$f],'item cents retained '.$f);check(array_sum(array_map(fn($s)=>$s['totals'][$f],$a['asset_schedules']))===$r['asset_schedule']['totals'][$f],'whole cents retained '.$f);}
    foreach($parts as $u=>$v){check(min($v)>=0,'no negative item share');check($kind==='partial'?$v['book_closing_cents']>0:$v['book_closing_cents']===0,'complete or partial item disposed');}
    $next=sharedAssetNext($state);$nr=R::fromState($next,$projected);check($next['allocation']['complete'],'carry accepted '.$kind);
    foreach($a['asset_schedules'] as $u=>$s)foreach($s['items'] as $key=>$v)foreach(['cost','accumulated','book'] as $f)check($next['allocation']['asset_schedules'][$u]['items'][$key][$f.'_opening_cents']===$v[$f.'_closing_cents'],'carry without hidden cents');
    check(count($nr['asset_schedule']['items'])===1,'original item retained once in next-year proof');
    check($nr['asset_schedule']['totals']['disposals_cents']===0,'no second disposal');
    foreach(['disposal_hash','disposal','basis_cents','disposal_accounts'] as $f){$bad=$next['journal'];unset($bad['asset_schedule']['items'][$id][$f]);denied(fn()=>S::carry($r,$a,$bad),'prior event cannot disappear '.$f);}
    foreach(['disposal_hash'=>str_repeat('x',64),'disposal'=>null,'basis_cents'=>true,'disposals_cents'=>-1,'accumulated_disposals_cents'=>'0'] as $f=>$value){$bad=$item;$bad[$f]=$value;denied(fn()=>A::item($bad,$weights,2025),'strict original proof '.$f);}
    $bad=$item;$bad['disposal']['date']='2026-01-01';denied(fn()=>A::checkYear($bad,2025),'future disposal not applied early');
    $bad=$item;$bad['book_disposals_cents']++;denied(fn()=>A::item($bad,$weights),'disposal rollforward bound');
    $file=E::files($state+['carry_current'=>true,'carry_forward'=>null]);check(str_contains($file['bereiche/anlagenanteile.csv'],'Abgang Buchwert Cent'),'disposal amounts visible in export');
    // Balanced unit ledgers alone must not permit moving a sale or rest-value charge between units.
    $alloc=$a['allocations'];$entry=hash('md5',str_starts_with($kind,'sale')?'synthetic-sale':'synthetic-rest-value');
    if(isset($alloc[$entry.':0'])&&isset($alloc[$entry.':1'])){
        foreach([0,1] as $o){$row=&$alloc[$entry.':'.$o]['parts'];$keys=array_keys($row);if(count($keys)===2&&$row[$keys[1]]>1){++$row[$keys[0]];--$row[$keys[1]];}unset($row);}
        check(!S::build($r,$a['units'],$alloc,[],[],$a['asset_shares'])['complete'],'misallocated book disposal or sale blocks review');
    }
}
// Compare the fast bounded routine against a deliberately slow independent seat allocator.
foreach([['a'=>1,'b'=>2],['a'=>7,'b'=>3,'c'=>1],['a'=>1,'b'=>1000000]] as $w)foreach([0,1,5,17] as $limit){
    $caps=[];foreach($w as $u=>$unused)$caps[$u]=$u==='a'?$limit:19;$slow=array_fill_keys(array_keys($w),0);
    for($total=0;$total<=array_sum($caps);$total++){
        if($total){$winner=null;foreach($w as $u=>$weight)if($slow[$u]<$caps[$u]&&($winner===null||$weight*($slow[$winner]+1)>$w[$winner]*($slow[$u]+1)))$winner=$u;++$slow[$winner];}
        check(D::capped($total,$w,$caps)===$slow,'bounded shares equal sequential highest averages');
    }
}
// Small cent values, unequal residuals, multiple businesses and pre-event carry.
$template=sharedDisposalFixture('partial');$base=array_values(array_filter($template['journal']['asset_schedule']['items'],fn($i)=>isset($i['partial_plan'])))[0];
for($case=1;$case<=60;$case++){
    $p=$base['partial_plan'];$p['basis_cents']=2+$case*7;$p['residual_cents']=$case%($p['basis_cents']-1);$p['months']=36;$p['acquired_on']='2025-03-15';
    $proof=$base['disposal'];$proof['date']='2026-04-17';$proof['allocation']['cost_cents']=1+($case*3)%($p['basis_cents']-1);$proof['allocation']['residual_cents']=min($p['residual_cents'],$proof['allocation']['cost_cents']-1);
    if($p['residual_cents']-$proof['allocation']['residual_cents']>=$p['basis_cents']-$proof['allocation']['cost_cents'])continue;
    $proof['plan_sha256']=\Kulturhus\Verein\AssetPartialDisposal::hash($p);$item=array_replace($base,['partial_plan'=>$p,'disposal'=>$proof,'basis_cents'=>$p['basis_cents'],'residual_cents'=>$p['residual_cents'],'acquired_on'=>$p['acquired_on'],'months'=>$p['months']]);
    $weights=['a'=>1,'b'=>2,'c'=>5];$previous=null;$removed=0;$depreciated=0;
    for($year=2025;$year<=2030;$year++){
        $annual=$item;
        if($year===2025){foreach(['partial_plan','disposal','basis_cents','disposal_hash','disposal_accounts',...D::FIELDS] as $f)unset($annual[$f]);$annual['cost_closing_cents']=$p['basis_cents'];$annual=sharedAssetYear($annual,$year);}
        else $annual=sharedAssetYear($annual,$year);
        A::checkYear($annual,$year);$parts=A::item($annual,$weights,$year);
        foreach(D::fields($annual) as $f)check(array_sum(array_column($parts,$f))===$annual[$f],'component cents retained in every year');
        foreach($parts as $u=>$v){check(min($v)>=0,'remaining component has no negative share');if($previous)foreach(['cost','accumulated','book'] as $f)check($v[$f.'_opening_cents']===$previous[$u][$f.'_closing_cents'],'partial event never rewrites an earlier closing');}
        $removed+=$annual['book_disposals_cents']??0;$depreciated+=$annual['depreciation_cents'];$previous=$parts;
    }
    check($depreciated+$removed+$annual['book_closing_cents']===$p['basis_cents'],'lifetime retained cost accounted once');
}
echo "$checks shared-disposal checks passed; synthetic, no database or transport\n";
