<?php declare(strict_types=1);
require __DIR__.'/balance-tax-sale-values.php';
use Kulturhus\Verein\{BalanceTaxValues as T,BalanceDisposalAccounts as D};
foreach(['cross','gain','loss','zero','faster','cents','after'] as $case){
    [$r,$v]=ebilanzFixture('asset-tax-mixed-'.$case);$before=$r;$values=T::build($r,$v);
    check($before===$r,'Mixed sources unchanged '.$case);
    check(count(array_filter($r['accounts'],static fn($a)=>$a['skr_code']==='68950'))===2,'Separate rest-value accounts '.$case);
    check($values['book']['difference_cents']===0&&$values['tax']['difference_cents']===0,'Both ledgers balanced '.$case);
    $items=$values['asset_tax']['tax_schedule']['items'];$sold=$items['sold'];$scrapped=$items['scrapped'];
    $sale=$values['asset_tax']['items']['sold']['sale'];
    $soldLoss=$sold['disposal_accounts']['68950'];$scrapLoss=$scrapped['disposal_accounts']['68950'];
    check($soldLoss!==$scrapLoss,'Each event retains its own rest-value account '.$case);
    check($values['tax']['accounts'][$scrapLoss]['amount_cents']===$scrapped['book_disposals_cents'],'Scrapping uses only its own rest-value account '.$case);
    check($values['tax']['accounts'][$soldLoss]['amount_cents']===($sale&&!$sale['tax']['gain']?$sold['book_disposals_cents']:0),'Sale loss remains separate '.$case);
    D::checkMovements($values['tax']['accounts'],$items,$values['year']);check(true,'Tax movements reconciled by account '.$case);
    if($case==='after')check($values['current_adjustments']===[]&&array_sum($values['asset_tax']['tax_schedule']['totals'])===0,'No duplicated disposal next year');
}
[$r,$v]=ebilanzFixture('asset-tax-mixed-cross');
foreach(['missing','empty','other-same-code','wrong-code','unknown-key','wrong-type'] as $case){
    $bad=$r;$item=&$bad['asset_schedule']['items']['scrapped'];
    if($case==='missing')unset($item['disposal_accounts']);
    elseif($case==='empty')$item['disposal_accounts']=[];
    elseif($case==='other-same-code')$item['disposal_accounts']['68950']=$r['asset_schedule']['items']['sold']['disposal_accounts']['68950'];
    elseif($case==='wrong-code')$item['disposal_accounts']['68950']='expense';
    elseif($case==='unknown-key')$item['disposal_accounts']['12345']='expense';
    else $item['disposal_accounts']['68950']=true;
    unset($item);rejects(fn()=>T::build($bad,$v),'Invalid/ambiguous frozen account binding '.$case);
}
// A missing counterpart must not be silently found in another disposal's account.
$bad=$r;unset($bad['asset_schedule']['items']['sold']['disposal_accounts']['68950']);
rejects(fn()=>T::build($bad,$v),'Partial sale map cannot borrow scrapping account');
// Existing single-account fixtures keep their byte-exact prior result.
[$old,$review]=ebilanzFixture('asset-tax-sale-cross');$value=T::build($old,$review);
check(!isset($value['asset_schedule']['items']['asset-0']['disposal_accounts']),'Legacy schedule not retroactively amended');
echo "$count combined disposal account checks passed; synthetic only\n";
