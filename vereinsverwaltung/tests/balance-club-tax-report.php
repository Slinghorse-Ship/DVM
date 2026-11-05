<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/../src/Finance/Balance/BalanceTaxValues.php';require __DIR__.'/fixtures/balance-club-tax.php';
use Kulturhus\Verein\{BalanceClubTaxReport as R,BalanceSegmentCalculation as S,BalanceCalculation as C,BalanceTaxValues as T};
$n=0;function check(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException($label);$n++;}
function denied(callable $f,string $label):void{try{$f();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$state=clubTaxFixture();$r=R::fromState($state);
check($r['calculation']['income_cents']===2009&&$r['calculation']['expense_cents']===1003,'All taxable businesses, shared expense counted once');
check($r['calculation']['asset_total_cents']===41006&&$r['calculation']['capital_total_cents']===40000,'Opening cash and capital both included');
check(count($r['entries'])===3&&$r['closed_sha256']!==$state['journal']['closed_sha256'],'Separate hash and original voucher ids');
foreach(['1','2','3'] as $sphere)check($r['calculation']['spheres'][$sphere]['income_cents']===0,'Excluded sphere not declared');
foreach($r['calculation']['accounts'] as $id=>$a)foreach(['opening_cents','debit_cents','credit_cents','amount_cents'] as $f)check($a[$f]===$state['allocation']['segments']['food']['calculation']['accounts'][$id][$f]+$state['allocation']['segments']['shop']['calculation']['accounts'][$id][$f],'Every account reconciles');
check(R::rebuild($r['club_scope'])===$r&&clubTaxFixture()===$state,'Deterministic, no mutation');
$review=['year'=>2025,'journal_sha256'=>$r['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[]];
$v=T::build($r,$review);check($v['club_scope']===$r['club_scope'],'Lineage included in tax value digest');
foreach(['draft','revoked','incomplete','revision','review-hash'] as $case){$bad=$state;
    if($case==='draft')$bad['state']='draft';if($case==='revoked')$bad['current_approval']=false;if($case==='incomplete')$bad['allocation']['complete']=false;if($case==='revision')$bad['version']++;if($case==='review-hash')$bad['history'][0]['sha256']=str_repeat('0',64);
    denied(fn()=>R::fromState($bad),'Unapproved '.$case.' blocked');
}
$bad=$r;$bad['entries'][0]['lines'][0]['debit_cents']++;denied(fn()=>T::build($bad,$review),'Altered projected journal cannot be used');
$bad=$r['club_scope'];unset($bad['allocation']['units']['shop']);denied(fn()=>R::rebuild($bad),'Business cannot be omitted');
$bad=$state;unset($bad['journal']['asset_schedule']);denied(fn()=>R::fromState($bad),'Missing asset history not treated as empty');
$bad=$r['club_scope'];$bad['review']['revision']=true;denied(fn()=>R::rebuild($bad),'Boolean revision blocked');
$assets=clubTaxFixture(true);$p=R::fromState($assets);check(count($p['asset_schedule']['items'])===1&&count($assets['journal']['asset_schedule']['items'])===2,'Only taxable asset included, original unchanged');
$item=array_key_first($p['asset_schedule']['items']);check($p['asset_schedule']['totals']['book_closing_cents']===9600&&$p['calculation']['accounts']['fixed_assets']['amount_cents']===9600,'Assets reconcile by scope');
check($p['asset_schedule']['items'][$item]===$assets['journal']['asset_schedule']['items'][$item],'Original source hashes retained');
$bad=$assets;$bad['journal']['asset_schedule']['items'][$item]['sphere']='3';denied(fn()=>R::fromState($bad),'Wrong asset scope blocked');
$bad=$assets;$bad['journal']['asset_schedule']['totals']['book_closing_cents']++;denied(fn()=>R::fromState($bad),'Whole-club asset total tampering blocked');
$taxReview=$review;$taxReview['journal_sha256']=$p['closed_sha256'];$taxReview['asset_tax_plans']=[$item=>['months'=>120,'depreciation_account'=>'depreciation','reason'=>'Synthetic reviewed tax useful life','confirmed'=>true]];
$tax=T::build($p,$taxReview);check($tax['asset_tax']['tax_schedule']['totals']['book_closing_cents']===10800&&$tax['book']['accounts']['fixed_assets']['amount_cents']===9600,'Tax asset values separate from club book values');
$next=$state['journal'];$next['year']=2026;$next['previous_sha256']=$next['closed_sha256'];$next['closed_sha256']=hash('sha256','synthetic next club year');$next['opening']=C::carryForward($next['calculation'],'equity');$next['entries']=[];$next['asset_schedule']['year']=2026;$next['calculation']=C::build(2026,$next['accounts'],$next['opening'],[]);
$carry=S::carry($state['journal'],$state['allocation'],$next);$nextState=clubTaxState($next,$carry['units'],$carry['allocations']);$nr=R::fromState($nextState,$r);
check($nr['previous_sha256']===$r['closed_sha256']&&$nr['calculation']['income_cents']===0&&$nr['calculation']['capital_total_cents']===41006,'Previous taxable result carried into equity, not income');
denied(fn()=>R::fromState($nextState),'Missing prior aggregate blocked');
$bad=$nextState;$bad['journal']['previous_sha256']=str_repeat('0',64);denied(fn()=>R::fromState($bad,$r),'Foreign previous journal blocked');
$carryValues=T::carryForward($r,$review,$nr);check($carryValues['entry']===null,'No fictitious tax difference on carry');
echo "$n club tax projection checks passed; no database or transport\n";
