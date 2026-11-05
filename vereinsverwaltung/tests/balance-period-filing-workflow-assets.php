<?php declare(strict_types=1);
// Real persisted invoice/depreciation/use-change chain, not a forged export packet.
require __DIR__.'/balance-period-asset-use-reviews.php';
require_once __DIR__.'/../src/Finance/Balance/BalanceTaxFiling.php';require __DIR__.'/fixtures/balance-offline-runtime.php';
$baseAssetChecks=$n;$n=0;putenv('VEREIN_TAX_AUTO_TEST=synthetic-only');
$datedFiling=new \Kulturhus\Verein\BalanceTaxFiling($db,'asset-admin');$datedTax=new \Kulturhus\Verein\TaxFilings($db,'asset-admin');
$assetProfile=['business_name'=>'','supplier'=>'Synthetischer Softwaretest','scope_confirmed'=>true,'asset_values_unchanged'=>true,'asset_tax_values_reviewed'=>false,'tax_privileged'=>true,'whole_business_name'=>'Synthetic shared assets','whole_book_standard'=>'HGBM',
    'company'=>['tax_number'=>'5192050001276','name'=>'Synthetischer Verein','street'=>'Testweg','house_number'=>'1','postal_code'=>'11111','city'=>'Teststadt','country'=>'Deutschland']];
$assetFilings=[];$originalEvents=\Kulturhus\Verein\Tests\MySqlFixture::rows($db,'balance_period_events');
foreach([$pid,$pid2] as $target){
    $fid=$datedFiling->createPeriod($target);$source=$datedFiling->sourceReport($datedTax->get($fid));$map=[];
    foreach($source['accounts'] as $key=>$account){$choice=match($account['group']){'cash'=>'bs.ass.currAss.cashEquiv.bank','equity'=>'bs.eqLiab.equity.retainedEarnings','liabilities'=>'bs.eqLiab.liab.trade.genOther','member_payables'=>'bs.eqLiab.liab.other.other',default=>null};if($choice!==null)$map[$key]=$choice;}
    $datedFiling->save($fid,1,$assetProfile,$map,['complete'=>true]);
    $value=$datedFiling->assertCurrent($datedTax->get($fid));check($value['whole']['asset_schedule']===$source['asset_schedule'],'saved shared assets preserve original plans schedules and use proof');
    check($datedTax->validate($fid,2)['valid'],'real generator accepts persisted shared linear GWG degressive assets and dated use carry');
    file_put_contents(__DIR__.'/balance-period-workflow-assets-'.($target===$pid?'first':'successor').'.xml',$datedTax->get($fid)['xml_data']);
    $generated=$datedTax->get($fid)['form_data']['balance_preparation']['generated'];check(($generated['asset_items']??0)===count($source['asset_schedule']['items']),'generated whole-asset count is not doubled by area shares');
    $datedFiling->approve($fid,3,true);check($datedTax->ready($fid,4,'SEND-TEST',true,true)['id']===$fid,'dated asset filing approval ready for simulated transport');
    $renderFiling=static function(string $id)use($db):string{$_SESSION=['actor'=>'asset-admin','csrf'=>'synthetic-assets'];$_GET=['tax_year'=>'2026','filing'=>$id];$_POST=[];$_SERVER['REQUEST_METHOD']='GET';ob_start();require __DIR__.'/../views/Finance/Tax/tax-filings.php';return ob_get_clean();};$html=$renderFiling($fid);
    if($target===$pid)file_put_contents(__DIR__.'/balance-period-workflow-assets-ui.html',$html);
    check(!preg_match('/(?:Warning|Notice|Fatal error):/',$html)&&str_contains($html,'Synthetic amplifier')&&str_contains($html,'Wert am Anfang')&&str_contains($html,'Abschreibung im Zeitraum'),'real dated asset view renders names and period values without PHP diagnostics');
    $assetFilings[$target]=$fid;
}
check($originalEvents===\Kulturhus\Verein\Tests\MySqlFixture::rows($db,'balance_period_events'),'filing shared assets never changes journal');
check(\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db)===[],'dated asset filings stay tenant bound');
echo "$n dated asset filing checks passed plus $baseAssetChecks use-review checks; synthetic MySQL, simulated transport only\n";
