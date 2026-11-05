<?php declare(strict_types=1);
require __DIR__.'/fixtures/ebilanz-values.php';
use Kulturhus\Verein\BalanceTaxValues as T;
$count=0;
function check(bool $ok,string $label):void{global $count;if(!$ok)throw new RuntimeException($label);$count++;}
function rejects(callable $fn,string $label):void{try{$fn();}catch(DomainException $e){check(true,$label);return;}throw new RuntimeException('Accepted: '.$label);}
foreach(['slower'=>500,'faster'=>-250,'prior'=>5000,'software'=>500,'cents'=>496,'fully'=>-3000,'carry'=>5000] as $case=>$difference){
    [$report,$review]=ebilanzFixture('asset-tax-'.$case);$original=$report;$values=T::build($report,$review);
    check($report===$original,'Closed report immutable '.$case);
    check($values['result_difference_cents']===$difference,'Profit difference '.$case);
    check($values['tax']['difference_cents']===0,'Balanced '.$case);
    check(T::build($report,$review)===$values,'Deterministic '.$case);
    check($values['asset_schedule']===$report['asset_schedule'],'Original schedule retained '.$case);
    check($values['asset_tax']['tax_schedule']['items']['asset-0']['book_closing_cents']===$values['tax']['accounts'][$case==='software'?'software-0':'equipment-0']['amount_cents'],'Tax closing '.$case);
    if($case==='carry')check($values['asset_tax']['generated_opening']===[]&&count($values['opening_adjustments'])===1,'No duplicate opening carry');
}
[$report,$review]=ebilanzFixture('asset-tax-slower');
foreach(['months'=>13,'confirmed'=>false,'reason'=>'','depreciation_account'=>'expense','surprise'=>'value'] as $key=>$value){
    $bad=$review;$bad['asset_tax_plans']['asset-0'][$key]=$value;rejects(fn()=>T::build($report,$bad),'Invalid plan '.$key);
}
foreach([true,'24',0,1201,12] as $months){$bad=$review;$bad['asset_tax_plans']['asset-0']['months']=$months;rejects(fn()=>T::build($report,$bad),'Invalid life');}
$bad=$review;$bad['asset_tax_plans']['unknown']=$bad['asset_tax_plans']['asset-0'];rejects(fn()=>T::build($report,$bad),'Unknown item');
foreach([null,'wrong',[false],[['lines'=>[null]]]] as $rows){$bad=$review;$bad['current_adjustments']=$rows;rejects(fn()=>T::build($report,$bad),'Malformed rows');}
$bad=$review;$bad['current_adjustments']=[['id'=>'manual','reason'=>'Would double count','lines'=>[
    ['account'=>'equipment-0','debit_cents'=>1,'credit_cents'=>0,'sphere'=>''],['account'=>'equipment-depreciation','debit_cents'=>0,'credit_cents'=>1,'sphere'=>'4']]]];
rejects(fn()=>T::build($report,$bad),'Manual overlap');
$bad=$review;$bad['asset_tax_plans']['asset-0']['reason']=str_repeat('ü',1400);check(isset(T::build($report,$bad)['sha256']),'Longest reason with prefix');
$bad['asset_tax_plans']['asset-0']['reason'].='ü';rejects(fn()=>T::build($report,$bad),'Reason too long');
foreach(['asset-gwg','asset-degressive','asset-sale','asset-scrap-gwg','asset-scrap-degressive'] as $case){[$r,$v]=ebilanzFixture($case);$v['asset_tax_plans']=$review['asset_tax_plans'];rejects(fn()=>T::build($r,$v),'Excluded case '.$case);}
[$r,$v]=ebilanzFixture('asset-tax-carry');$v['asset_tax_plans']['asset-0']['months']=36;rejects(fn()=>T::build($r,$v),'Changed carried life');
[$r,$v]=ebilanzFixture('asset-tax-prior');unset($r['result_account']);rejects(fn()=>T::build($r,$v),'Missing equity account');
[$r,$v]=ebilanzFixture('asset-december');$normal=T::build($r,$v);$v['asset_tax_plans']=[];check($normal===T::build($r,$v),'Legacy no-plan values and hash unchanged');
echo "{$count} separate tax asset checks passed\n";
