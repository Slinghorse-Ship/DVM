<?php declare(strict_types=1);
require __DIR__.'/fixtures/asset-partial-disposal-data.php';
use Kulturhus\Verein\{AssetPartialDisposal as Partial,AssetBooks,AssetDisposal};
$count=0;
function check(bool $ok,string $label):void{global $count;if(!$ok)throw new RuntimeException($label);++$count;}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
function preview(array $f):array{return Partial::preview($f['plan'],$f['proof'],$f['year'],$f['tax_review']??null);}
function conserved(array $s):void{
    foreach($s as $k=>$v)if($k!=='year')check(is_int($v)&&$v>=0,'Nonnegative integer '.$k);
    check($s['cost_opening_cents']+$s['additions_cents']-$s['disposals_cents']===$s['cost_closing_cents'],'Gross costs roll forward');
    check($s['accumulated_opening_cents']+$s['depreciation_cents']-$s['accumulated_disposals_cents']===$s['accumulated_closing_cents'],'Accumulated depreciation rolls forward');
    check($s['book_opening_cents']+$s['additions_cents']-$s['depreciation_cents']-$s['book_disposals_cents']===$s['book_closing_cents'],'Net book rolls forward');
    check($s['cost_closing_cents']-$s['accumulated_closing_cents']===$s['book_closing_cents'],'Gross less accumulated is book');
    check($s['disposals_cents']-$s['accumulated_disposals_cents']===$s['book_disposals_cents'],'Part cost less allocated depreciation is disposal');
    check($s['book_closing_cents']>=$s['remaining_residual_cents'],'Remaining residual never depreciated');
}
$f=partialDisposalFixture();$before=$f;$r=preview($f);
check($f===$before,'Inputs and original plan never mutated');
check($r['postable']===false&&$r['exportable']===false,'Preview is not a release or posting');
check($r['book']['depreciation_cents']===5000&&$r['book']['book_disposals_cents']===1000&&$r['book']['book_closing_cents']===0,'Known book example');
check($r['tax']['depreciation_cents']===2500&&$r['tax']['book_disposals_cents']===2500&&$r['tax']['book_closing_cents']===4000,'Known tax example');
check($r['differences']===['opening_cents'=>3000,'depreciation_cents'=>-2500,'disposal_cents'=>1500,'closing_cents'=>4000,'result_cents'=>1000],'Complete signed tax difference');
check($r===preview($f),'Deterministic repeated preview');
check($r['plan_sha256']===Partial::hash($f['plan'])&&$r['proof_sha256']===Partial::hash($f['proof'])&&$r['tax_review_sha256']===Partial::hash($f['tax_review']),'Every input bound to preview');
conserved($r['book']);conserved($r['tax']);
$f['year']=2024;$r=preview($f);$original=AssetBooks::schedule($f['plan'],2024);
check($r['book']['depreciation_cents']===$original['depreciation_cents']&&$r['book']['book_closing_cents']===$original['closing_cents']&&$r['book']['disposals_cents']===0,'Closed prior year unchanged');
$f['year']=2026;$r=preview($f);
check($r['book']['disposals_cents']===0&&$r['tax']['book_disposals_cents']===0,'No repeat disposal next year');
check($r['tax']['book_opening_cents']===4000&&$r['tax']['depreciation_cents']===2000,'Only remaining tax asset depreciated');
$f['tax_review']=null;$r=preview($f);check($r['tax']===null&&$r['differences']===null&&$r['tax_review_sha256']===null,'No inferred tax-life decision');

foreach([
    ['same-month',['acquired_on'=>'2025-06-15'],4000,0,'2025-06-15'],
    ['first-month',['acquired_on'=>'2025-01-31'],4000,0,'2025-01-31'],
    ['last-month',[],4000,0,'2025-12-31'],
    ['fully-depreciated',['acquired_on'=>'2020-01-01'],4000,0,'2025-06-15'],
    ['residual',['residual_cents'=>300],4000,100,'2025-06-15'],
    ['cents',['basis_cents'=>12001,'residual_cents'=>100,'months'=>36],4000,33,'2025-06-15'],
    ['one-cent-part',['basis_cents'=>3],1,0,'2025-01-15'],
    ['one-cent-remaining',['basis_cents'=>3],2,0,'2025-01-15'],
    ['large',['basis_cents'=>100000000000,'residual_cents'=>37,'months'=>1200],33333333333,12,'2025-06-15'],
] as [$label,$changes,$cost,$residual,$date]){
    $f=partialDisposalFixture($changes);$f['proof']['date']=$date;$f['proof']['allocation']['cost_cents']=$cost;$f['proof']['allocation']['residual_cents']=$residual;
    $start=(int)substr($f['plan']['acquired_on'],0,4);$last=$start+max($f['plan']['months'],$f['tax_review']['months'])/12+2;
    $previous=null;$total=['book'=>['depreciation'=>0,'disposal'=>0],'tax'=>['depreciation'=>0,'disposal'=>0]];
    for($year=$start;$year<=$last;$year++){
        $f['year']=$year;$r=preview($f);
        foreach(['book','tax'] as $basis){
            conserved($r[$basis]);
            if($previous)foreach(['cost','accumulated','book'] as $k)check($previous[$basis][$k.'_closing_cents']===$r[$basis][$k.'_opening_cents'],$label.' continuity '.$basis.' '.$k);
            $total[$basis]['depreciation']+=$r[$basis]['depreciation_cents'];$total[$basis]['disposal']+=$r[$basis]['book_disposals_cents'];
        }
        check($r['differences']['opening_cents']+$r['differences']['result_cents']===$r['differences']['closing_cents'],'Signed tax reconciliation '.$label);$previous=$r;
    }
    foreach(['book','tax'] as $basis)check($total[$basis]['depreciation']+$total[$basis]['disposal']+$r[$basis]['book_closing_cents']===$f['plan']['basis_cents'],'Lifetime value conservation '.$label.' '.$basis);
}

$f=partialDisposalFixture();
foreach($f['proof'] as $key=>$_){$bad=$f;unset($bad['proof'][$key]);denied(fn()=>preview($bad),'Missing proof '.$key);}
foreach($f['proof']['allocation'] as $key=>$_){$bad=$f;unset($bad['proof']['allocation'][$key]);denied(fn()=>preview($bad),'Missing allocation '.$key);}
foreach(['schema'=>2,'kind'=>'sale','complete'=>true,'no_proceeds'=>false,'date'=>'2025-02-30','reason'=>'','revision'=>'1','sha256'=>'x','plan_sha256'=>'x','allocation'=>null,'future_extra'=>true] as $key=>$v){
    $bad=$f;$bad['proof'][$key]=$v;denied(fn()=>preview($bad),'Invalid proof '.$key);
}
foreach(['date'=>'2023-12-31','document_id'=>str_repeat('a',31),'revision'=>true,'reason'=>"bad\0reason"] as $key=>$v){$bad=$f;$bad['proof'][$key]=$v;denied(fn()=>preview($bad),'Invalid evidence '.$key);}
foreach(['cost_cents'=>[0,-1,12000,12001,'4000',4000.0,true],'residual_cents'=>[-1,1,'0',false],'basis'=>['quantity','percent'],
    'same_use_and_life'=>[false,1],'confirmed'=>[false,1],'reason'=>['',str_repeat('x',2001)],'document_id'=>[''],'revision'=>[0]] as $key=>$values){
    foreach($values as $v){$bad=$f;$bad['proof']['allocation'][$key]=$v;denied(fn()=>preview($bad),'Invalid cost proof '.$key);}
}
foreach(['basis_cents'=>[0,100000000001,true],'residual_cents'=>[-1,12000],'months'=>[0,13,1201,'24'],'sphere'=>['9',4],
    'account'=>['06700','01350'],'method'=>['gwg_immediate','degressive'],'acquired_on'=>['1999-01-01','2025-02-30']] as $key=>$values){
    foreach($values as $v){$bad=partialDisposalFixture([$key=>$v]);denied(fn()=>preview($bad),'Unsupported plan '.$key);}
}
$bad=$f;$bad['plan']['basis_cents']++;denied(fn()=>preview($bad),'Original plan hash must still match');
$bad=$f;$bad['proof']['allocation']['quantity']=2;denied(fn()=>preview($bad),'No guessed quantity allocation');
$bad=partialDisposalFixture(['residual_cents'=>8000]);$bad['proof']['allocation']['residual_cents']=0;denied(fn()=>preview($bad),'Remaining part must be depreciable');
$bad=partialDisposalFixture(['residual_cents'=>4000]);$bad['proof']['allocation']['residual_cents']=4000;denied(fn()=>preview($bad),'Disposed part must be depreciable');
foreach(['months'=>[24,13,0,'48',true],'confirmed'=>[false,1],'reason'=>['',"bad\x01reason"],'basis_cents'=>[999]] as $key=>$values){
    foreach($values as $v){$bad=$f;$bad['tax_review'][$key]=$v;denied(fn()=>preview($bad),'Tax review '.$key);}
}
$bad=partialDisposalFixture(['sphere'=>'1']);denied(fn()=>preview($bad),'No unreviewed transfer to another sphere');
foreach([1999,2023,2201] as $year){$bad=$f;$bad['year']=$year;denied(fn()=>preview($bad),'Invalid preview year');}
AssetDisposal::check($f['plan'],$f['proof']);
check(AssetDisposal::schedule($f['plan'],2025,$f['proof'])['closing_cents']===preview($f)['book']['book_closing_cents'],'Annual adapter retains partial remaining value');
check(preview($f)['postable']===false,'Arithmetic alone remains no posting authority');
echo "$count partial-disposal calculation checks passed; no database or live changes\n";
