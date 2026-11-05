<?php declare(strict_types=1);
require __DIR__.'/balance-tax-asset-values.php';
use Kulturhus\Verein\BalanceTaxValues as T;
foreach(['same'=>[0,4000,8000],'prior'=>[-500,4500,7000],'faster'=>[250,4500,7000],
    'book-zero'=>[-6000,2250,3750],'tax-zero'=>[6000,0,0],'cents'=>[0,3967,8034],'after'=>[0,0,0]] as $case=>[$profit,$depreciation,$remaining]){
    [$r,$v]=ebilanzFixture('asset-tax-scrap-'.$case);$before=$r;$values=T::build($r,$v);$schedule=$values['asset_tax']['tax_schedule'];$item=$schedule['items']['asset-0'];
    check($r===$before&&$values['asset_schedule']===$before['asset_schedule'],'Original retained '.$case);
    check($values['tax']['difference_cents']===0&&$values['result_difference_cents']===$profit,'Balanced exact profit '.$case);
    check($item['depreciation_cents']===$depreciation&&$item['book_disposals_cents']===$remaining,'Tax depreciation / writeoff '.$case);
    check($item['book_closing_cents']===0&&$values['tax']['accounts']['equipment-0']['amount_cents']===0,'No remaining asset '.$case);
    check($item['cost_opening_cents']+$item['additions_cents']-$item['disposals_cents']===$item['cost_closing_cents'],'Gross rollforward '.$case);
    check($item['accumulated_opening_cents']+$item['depreciation_cents']-$item['accumulated_disposals_cents']===$item['accumulated_closing_cents'],'Depreciation rollforward '.$case);
    check(T::build($r,$v)===$values,'Deterministic '.$case);
    if($case==='after')check($values['opening_adjustments']===[]&&$values['current_adjustments']===[],'No duplicate expense in subsequent year');
}
[$r,$v]=ebilanzFixture('asset-tax-scrap-same');
foreach(['no_proceeds'=>false,'complete'=>false,'kind'=>'gift','date'=>'2024-01-01','surprise'=>true] as $key=>$value){
    $bad=$r;$bad['asset_schedule']['items']['asset-0']['disposal'][$key]=$value;rejects(fn()=>T::build($bad,$v),'Invalid disposal '.$key);
}
$bad=$r;$bad['asset_schedule']['items']['asset-0']['book_disposals_cents']++;rejects(fn()=>T::build($bad,$v),'Changed frozen remaining value');
foreach(['missing','wrong','ambiguous'] as $case){
    $bad=$r;
    if($case==='missing')unset($bad['accounts']['scrapping']);
    if($case==='wrong')$bad['accounts']['scrapping']['skr_code']='68900';
    if($case==='ambiguous')$bad['accounts']['scrapping2']=$bad['accounts']['scrapping'];
    // Check the pure asset preparation, without manufacturing an altered closed ledger.
    rejects(fn()=>Kulturhus\Verein\BalanceTaxAssetValues::prepare($bad,$v),'Unusable rest-value account '.$case);
}
$bad=$v;$bad['current_adjustments']=[['id'=>'overlap','reason'=>'Duplicate rest value','lines'=>[
    ['account'=>'scrapping','debit_cents'=>1,'credit_cents'=>0,'sphere'=>'4'],['account'=>'bank','debit_cents'=>0,'credit_cents'=>1,'sphere'=>'']]]];
rejects(fn()=>T::build($r,$bad),'Manual rest-value overlap');
echo "$count combined tax asset and scrapping checks passed\n";
