<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/../src/Finance/Balance/BalanceTaxValues.php';require __DIR__.'/fixtures/balance-club-tax.php';
use Kulturhus\Verein\{BalanceWholeClubReport as W,BalanceClubTaxReport as R,BalanceTaxValues as T,BalanceCalculation as C};
$n=0;function check(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException($label);$n++;}
function denied(callable $f,string $label):void{try{$f();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$source=clubTaxFixture()['journal']+['previous_sha256'=>null];$r=W::fromReport($source);
check($r['calculation']===$source['calculation']&&$r['entries']===$source['entries'],'all original lines and spheres retained');
check($r['closed_sha256']===$source['closed_sha256']&&W::rebuild($r['whole_club_scope'])===$r,'original journal binding and deterministic proof');
$review=['year'=>2025,'journal_sha256'=>$r['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[]];
$v=T::build($r,$review);check($v['source_spheres']===['1','2','3','4']&&$v['book']['annual_result_cents']===4012,'all club results, not just taxable aggregate');
foreach(W::FIELDS as $key){$bad=$source;unset($bad[$key]);denied(fn()=>W::fromReport($bad),'required source field '.$key);}
$bad=$r;$bad['entries'][0]['lines'][0]['debit_cents']++;denied(fn()=>T::build($bad,$review),'changed original rejected');
$bad=$r;$bad['club_scope']=R::fromState(clubTaxFixture())['club_scope'];denied(fn()=>T::build($bad,$review),'mixed scope rejected');
$bad=$source;$bad['state']='draft';denied(fn()=>W::fromReport($bad),'open year rejected');
$bad=$source;$bad['asset_schedule']=null;denied(fn()=>W::fromReport($bad),'missing original assets not empty');
$assets=clubTaxFixture(true)['journal']+['previous_sha256'=>null];$ar=W::fromReport($assets);$plans=[];
foreach($ar['asset_schedule']['items'] as $id=>$item)$plans[$id]=['months'=>120,'depreciation_account'=>'depreciation','reason'=>'Synthetic reviewed tax life','confirmed'=>true];
$tv=T::build($ar,$review+['asset_tax_plans'=>$plans]);
check(count($tv['asset_schedule']['items'])===2&&$tv['asset_tax']['tax_schedule']['totals']['book_closing_cents']===16200,'both spheres in tax asset schedule');
$spheres=[];foreach($tv['current_adjustments'] as $entry)foreach($entry['lines'] as $line)if($line['sphere']!=='')$spheres[]=$line['sphere'];sort($spheres);
check($spheres===['3','4'],'tax depreciation preserves original sphere');
check($assets['asset_schedule']['totals']['book_closing_cents']===14400,'book asset values unchanged');
$next=$source;$next['year']=2026;$next['previous_sha256']=$source['closed_sha256'];$next['closed_sha256']=hash('sha256','synthetic whole carry');$next['opening']=C::carryForward($source['calculation'],'equity');$next['entries']=[];$next['asset_schedule']['year']=2026;$next['calculation']=C::build(2026,$next['accounts'],$next['opening'],[]);$next=W::fromReport($next);
$carry=T::carryForward($r,$review,$next);check($carry['entry']===null&&$next['calculation']['capital_total_cents']===104012,'whole prior profit carried once');
$capitalReview=$review;$lineId='capital-test';$capitalReview['current_adjustments']=[['id'=>$lineId,'reason'=>'Synthetic capital evidence','lines'=>[['account'=>'cash','debit_cents'=>1000,'credit_cents'=>0,'sphere'=>''],['account'=>'equity','debit_cents'=>0,'credit_cents'=>1000,'sphere'=>'']]]];
$cv=T::build($r,$capitalReview);check(!$cv['capital_comparison']['complete']&&$cv['tax']['annual_result_cents']===4012,'unresolved capital is not revenue');
$key='tax:'.$lineId.':1';$capitalReview['capital_movements']=[$key=>['kind'=>'contribution','reason'=>'Synthetic evidence']];$cv=T::build($r,$capitalReview);
check($cv['capital_comparison']['complete']&&$cv['capital_comparison']['contributions_cents']===1000&&$cv['capital_comparison']['tax_closing_cents']===105012,'capital comparison reconciles independently of profit');
foreach(['withdrawal','reclassification','unknown'] as $kind){$bad=$capitalReview;$bad['capital_movements'][$key]['kind']=$kind;
    if($kind==='reclassification')check(!T::build($r,$bad)['capital_comparison']['complete'],'unbalanced internal movement blocks completion');
    else denied(fn()=>T::build($r,$bad),'wrong capital classification '.$kind);
}
$bad=$capitalReview;$bad['capital_movements']['foreign']=$bad['capital_movements'][$key];denied(fn()=>T::build($r,$bad),'foreign capital line blocked');
$bad=$capitalReview;$bad['capital_movements'][$key]['reason']='';denied(fn()=>T::build($r,$bad),'capital evidence required');
$bad=$capitalReview;$bad['capital_movements'][$key]['amount']=99;denied(fn()=>T::build($r,$bad),'capital amount cannot be overridden');
echo "$n whole-club report checks passed; no database or transport\n";
