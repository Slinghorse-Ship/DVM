<?php declare(strict_types=1);
require __DIR__.'/balance-tax-asset-values.php';
use Kulturhus\Verein\{BalanceTaxValues as T,BalanceTaxAssetValues};

foreach(['cross'=>[0,4000,8000,true,false],'gain'=>[0,4000,8000,true,true],
    'loss'=>[0,4000,8000,false,false],'faster'=>[250,4500,7000,false,true],
    'book-zero'=>[-6000,2250,3750,true,true],'tax-zero'=>[6000,0,0,true,true],
    'cents'=>[0,3967,8034,true,false],'even'=>[0,4000,8000,true,true],
    'vat-zero'=>[0,4000,8000,true,false],'after'=>[0,0,0,null,null]] as $case=>[$profit,$depreciation,$remaining,$bookGain,$taxGain]){
    [$r,$v]=ebilanzFixture('asset-tax-sale-'.$case);$before=$r;$values=T::build($r,$v);
    $item=$values['asset_tax']['tax_schedule']['items']['asset-0'];$sale=$values['asset_tax']['items']['asset-0']['sale'];
    check($r===$before&&$values['asset_schedule']===$before['asset_schedule'],'Original schedule and invoice evidence retained '.$case);
    check($values['tax']['difference_cents']===0&&$values['result_difference_cents']===$profit,'Balanced exact profit '.$case);
    check($item['depreciation_cents']===$depreciation&&$item['book_disposals_cents']===$remaining,'Independent tax depreciation and carrying value '.$case);
    check($item['book_closing_cents']===0&&$values['tax']['accounts']['equipment-0']['amount_cents']===0,'Sold asset cannot remain '.$case);
    check($item['cost_opening_cents']+$item['additions_cents']-$item['disposals_cents']===$item['cost_closing_cents'],'Gross rollforward '.$case);
    check($item['accumulated_opening_cents']+$item['depreciation_cents']-$item['accumulated_disposals_cents']===$item['accumulated_closing_cents'],'Accumulated depreciation rollforward '.$case);
    check(T::build($r,$v)===$values,'Deterministic '.$case);
    if($case==='after'){
        check($sale===null&&$values['opening_adjustments']===[]&&$values['current_adjustments']===[],'No disposal or profit repeated next year');
        continue;
    }
    check($sale['book']['gain']===$bookGain&&$sale['tax']['gain']===$taxGain,'Both gain/loss classifications '.$case);
    foreach(['gross_cents','tax_cents','net_cents'] as $key)check($sale['book'][$key]===$sale['tax'][$key],'Sale consideration unchanged '.$key.' '.$case);
    foreach($sale['accounts'] as $code=>$account){
        $expected=0;
        if((string)$code===$sale['tax']['sale_code'])$expected=$sale['tax']['net_cents']*($taxGain?1:-1);
        if((string)$code===$sale['tax']['book_code'])$expected=$remaining*($taxGain?-1:1);
        check($values['tax']['accounts'][$account]['amount_cents']===$expected,'Tax classification exact without duplicated proceeds '.$code.' '.$case);
    }
    if(isset($values['book']['accounts']['sale-vat']))check($values['book']['accounts']['sale-vat']===$values['tax']['accounts']['sale-vat'],'VAT untouched '.$case);
}
[$r,$v]=ebilanzFixture('asset-tax-sale-cross');
foreach(['missing','wrong','ambiguous'] as $case){
    $bad=$r;
    if($case==='missing')unset($bad['accounts']['reserved-68950']);
    if($case==='wrong')$bad['accounts']['reserved-68950']['group']='revenue';
    if($case==='ambiguous')$bad['accounts']['duplicate']=$bad['accounts']['reserved-68950'];
    rejects(fn()=>BalanceTaxAssetValues::prepare($bad,$v),'Requires unique reserved loss account '.$case);
}
foreach(['complete'=>false,'kind'=>'gift','date'=>'2026-01-01','tax_cents'=>0,'rate'=>7,'sale_hash'=>'','surprise'=>true] as $key=>$value){
    $bad=$r;$bad['asset_schedule']['items']['asset-0']['disposal'][$key]=$value;
    rejects(fn()=>T::build($bad,$v),'Invalid sale proof '.$key);
}
foreach(['sale-proceeds','scrapping','reserved-68850','reserved-68950'] as $account){
    $bad=$v;$bad['current_adjustments']=[['id'=>'overlap','reason'=>'Duplicate disposal adjustment','lines'=>[
        ['account'=>$account,'debit_cents'=>1,'credit_cents'=>0,'sphere'=>'4'],['account'=>'bank','debit_cents'=>0,'credit_cents'=>1,'sphere'=>'']]]];
    rejects(fn()=>T::build($r,$bad),'Manual sale overlap '.$account);
}
echo "$count combined tax asset and sale checks passed\n";
