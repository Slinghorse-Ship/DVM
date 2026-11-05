<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/../src/Finance/Balance/BalanceClubTaxReport.php';require_once __DIR__.'/../src/Finance/Balance/BalanceSegmentExport.php';require __DIR__.'/fixtures/balance-club-tax.php';require __DIR__.'/fixtures/balance-shared-assets.php';
use Kulturhus\Verein\{BalanceAssetAllocation as A,BalanceSegmentCalculation as S,BalanceClubTaxReport as R,BalanceSegmentExport as E};
$checks=0;function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);++$checks;}
function denied(callable $f,string $label):void{try{$f();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
foreach([['a'=>1,'b'=>2],['a'=>1,'b'=>1,'c'=>1],['a'=>7,'b'=>3,'c'=>1],['a'=>1,'b'=>1000000]] as $weights){
    $previous=array_fill_keys(array_keys($weights),0);$slow=$previous;
    for($total=0;$total<=301;$total++){
        $v=A::cents($total,$weights);check(array_sum($v)===$total,'all cents retained');
        if($total){$winner=array_key_first($weights);foreach($weights as $u=>$w)if($w*($slow[$winner]+1)>$weights[$winner]*($slow[$u]+1))$winner=$u;++$slow[$winner];}
        check($v===$slow,'fast algorithm equals sequential highest-average allocation');
        foreach($v as $u=>$n)check($n>=$previous[$u],'cumulative allocation never decreases');$previous=$v;
    }
    check(array_sum(A::cents(100000000000,$weights))===100000000000,'maximum amount without float rounding');
}
$state=sharedAssetFixture();$r=$state['journal'];$a=$state['allocation'];$projected=R::fromState($state);
check($a['schema']===4&&$a['complete'],'shared linear asset allocation complete');check(S::rebuild($r,$a)===$a,'deterministic schema 4');
check(count($projected['asset_schedule']['items'])===1,'one taxable asset not duplicated per business');
check($projected['asset_schedule']['totals']['cost_closing_cents']===12001,'unrounded original cost retained in tax projection');
foreach(A::FIELDS as $f)check(array_sum(array_map(fn($v)=>$v['totals'][$f],$a['asset_schedules']))===$r['asset_schedule']['totals'][$f],'whole schedule reconciles '.$f);
$next=sharedAssetNext($state);$nr=R::fromState($next,$projected);check($next['allocation']['complete'],'following year complete');
foreach($a['asset_schedules'] as $u=>$s)foreach($s['items'] as $id=>$v)check($next['allocation']['asset_schedules'][$u]['items'][$id]['book_opening_cents']===$v['book_closing_cents'],'individual item/unit opening equals prior closing');
$files=E::files($state+['carry_current'=>true,'carry_forward'=>null]);check(isset($files['bereiche/anlagenanteile.csv']),'usage proof exported');
$id=array_key_first($a['asset_shares']);$shares=$a['asset_shares'];
foreach(['',true,str_repeat('x',1001),"bad\nline"] as $value){$b=$shares;$b[$id]['reason']=$value;denied(fn()=>S::build($r,$a['units'],$a['allocations'],[],[],$b),'invalid evidence');}
foreach([0,-1,1.0,'1',true,1000001] as $value){$b=$shares;$unit=array_key_first($b[$id]['weights']);$b[$id]['weights'][$unit]=$value;denied(fn()=>S::build($r,$a['units'],$a['allocations'],[],[],$b),'invalid weight');}
$b=$shares;$b[$id]['weights']=['club'=>1];denied(fn()=>S::build($r,$a['units'],$a['allocations'],[],[],$b),'cross sphere cannot be inferred from use');
$b=$shares;$b[$id]['item_sha256']=str_repeat('0',64);denied(fn()=>S::build($r,$a['units'],$a['allocations'],[],[],$b),'changed original source');
$b=$shares;unset($b[$id]);check(!S::build($r,$a['units'],$a['allocations'],[],[],$b)['complete'],'unassigned item blocks full review');
$b=$shares;foreach($b as &$share)if(count($share['weights'])===2)$share['weights']=['food'=>1,'shop'=>1];unset($share);check(!S::build($r,$a['units'],$a['allocations'],[],[],$b)['complete'],'changed use cannot override already allocated ledger');
$item=$r['asset_schedule']['items'][$id];foreach(['degressive','gwg_immediate'] as $method){$bad=$item;$bad['method']=$method;denied(fn()=>A::item($bad,['a'=>1,'b'=>2]),'unsupported plan not guessed');}
$bad=$item;$bad['disposal']=['kind'=>'partial_scrap'];denied(fn()=>A::item($bad,['a'=>1,'b'=>2]),'disposal not silently split');
foreach([true,(float)$item['expected_depreciation_cents'],(string)$item['expected_depreciation_cents']] as $value){$bad=$item;$bad['expected_depreciation_cents']=$value;denied(fn()=>A::item($bad,['a'=>1,'b'=>2]),'expected depreciation must be exact integer');}
$badReport=$r;$badReport['asset_schedule']['items'][$id]='invalid';denied(fn()=>A::build($badReport,$a['units'],$a['segments'],$shares),'malformed original item rejected');
$funded=sharedAssetFixture(true);$fundedTax=R::fromState($funded);$fundedNext=sharedAssetNext($funded);$fundedNextTax=R::fromState($fundedNext,$fundedTax);
check($funded['allocation']['complete']&&count($funded['allocation']['transfers'])===1,'shared assets and internal funding coexist in schema 4');
check($fundedNext['allocation']['complete']&&$fundedNext['allocation']['asset_shares']!==[],'funded shared assets carry equity and use shares together');
check(count($fundedNextTax['asset_schedule']['items'])===1,'funding and carry never duplicate original taxable item');
foreach(['gwg_immediate','degressive'] as $method){
    foreach([false,true] as $funding){
        $s=sharedAssetFixture($funding,$method);$next=sharedAssetNext($s);$prior=R::fromState($s);$projected=R::fromState($next,$prior);
        check($s['allocation']['complete']&&$next['allocation']['complete'],'method and funding reconcile in both years: '.$method);
        check(count($prior['asset_schedule']['items'])===1&&count($projected['asset_schedule']['items'])===1,'method retains original taxable item');
        check(S::rebuild($next['journal'],$next['allocation'])===$next['allocation'],'method carry deterministic');
        foreach($s['allocation']['asset_schedules'] as $u=>$schedule)foreach($schedule['items'] as $key=>$v)foreach(['cost','accumulated','book'] as $f)
            check($next['allocation']['asset_schedules'][$u]['items'][$key][$f.'_opening_cents']===$v[$f.'_closing_cents'],'per-unit original movement carries exactly');
    }
    $items=$s['journal']['asset_schedule']['items'];$key=array_key_first(array_filter($items,fn($i)=>$i['sphere']==='4'));$item=$items[$key];
    $previous=null;$depTotal=0;
    for($year=2025;$year<=2031;$year++){
        $annual=sharedAssetYear($item,$year);A::checkYear($annual,$year);$parts=A::item($annual,['a'=>1,'b'=>2]);$depTotal+=$annual['depreciation_cents'];
        foreach(A::FIELDS as $f)check(array_sum(array_column($parts,$f))===$annual[$f],'method all cents retained '.$year.' '.$f);
        if($previous)foreach($parts as $u=>$v)foreach(['cost','accumulated','book'] as $f)check($v[$f.'_opening_cents']===$previous[$u][$f.'_closing_cents'],'degressive switch and GWG zero carry');
        $previous=$parts;
    }
    check($depTotal===12001&&array_sum(array_column($parts,'book_closing_cents'))===0,'depreciation never exceeds original cost');
    $bad=$item;$bad['depreciation_cents']++;$bad['expected_depreciation_cents']++;$bad['accumulated_closing_cents']++;$bad['book_closing_cents']--;
    denied(fn()=>A::checkYear($bad,2025),'balanced but wrong annual charge blocked');
    denied(fn()=>A::checkYear($item,2024),'future acquisition blocked');
    $badNext=$next['journal'];$badNext['asset_schedule']['items'][$key]['months']++;
    denied(fn()=>S::carry($s['journal'],$s['allocation'],$badNext),'inherited method cannot be changed');
    if($method==='gwg_immediate'){
        $bad=$item;$bad['gwg']['net_cents']=80001;$bad['cost_closing_cents']=$bad['additions_cents']=$bad['depreciation_cents']=$bad['accumulated_closing_cents']=$bad['expected_depreciation_cents']=80001;
        denied(fn()=>A::item($bad,['a'=>1,'b'=>2]),'original cost above GWG limit cannot be divided into eligible assets');
        foreach(['independent_movable'=>false,'no_pool'=>false,'net_cents'=>true,'deductible_cents'=>1,'unknown'=>1] as $f=>$value){$bad=$item;$bad['gwg'][$f]=$value;denied(fn()=>A::item($bad,['a'=>1,'b'=>2]),'GWG full proof '.$f);}
    }else{
        foreach(['rate_basis_points'=>3001,'movable'=>false,'same_book_tax'=>false,'switch_to_linear'=>1,'unknown'=>1] as $f=>$value){$bad=$item;$bad['degressive'][$f]=$value;denied(fn()=>A::item($bad,['a'=>1,'b'=>2]),'degressive full proof '.$f);}
        $bad=$item;$bad['acquired_on']='2025-06-30';denied(fn()=>A::item($bad,['a'=>1,'b'=>2]),'ineligible original acquisition blocked');
        $bad=$item;$bad['months']=240;denied(fn()=>A::item($bad,['a'=>1,'b'=>2]),'triple linear rate applies to original plan');
    }
    foreach(['disposal','partial_plan','disposals_cents'] as $f){$bad=$item;$bad[$f]=null;denied(fn()=>A::item($bad,['a'=>1,'b'=>2]),'unsupported disposal field cannot be ignored');}
}
echo "$checks shared-asset checks passed; synthetic, no database or transport\n";
