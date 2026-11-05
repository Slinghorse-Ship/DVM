<?php declare(strict_types=1);
// Original invoice -> dated depreciation -> shared use -> changed use -> carry.
define('SYNTHETIC_CLUB_ASSET_SCOPE',true);
require __DIR__.'/balance-period-asset-use-reviews.php';
require_once __DIR__.'/../src/Finance/Balance/BalanceTaxFiling.php';require __DIR__.'/fixtures/balance-offline-runtime.php';
$priorChecks=$n;$n=0;putenv('VEREIN_TAX_AUTO_TEST=synthetic-only');
$combined=defined('SYNTHETIC_PERIOD_COMBINED');$prefix=$combined?'combined':'club';
$service=new \Kulturhus\Verein\BalanceTaxFiling($db,'asset-admin');$taxService=new \Kulturhus\Verein\TaxFilings($db,'asset-admin');
$profile=['business_name'=>'Synthetic taxable businesses','supplier'=>'Synthetischer Softwaretest','scope_confirmed'=>true,'asset_values_unchanged'=>false,'asset_tax_values_reviewed'=>true,'tax_privileged'=>true,
    'company'=>['tax_number'=>'5192050001276','name'=>'Synthetischer Verein','street'=>'Testweg','house_number'=>'1','postal_code'=>'11111','city'=>'Teststadt','country'=>'Deutschland']];
if($combined)$profile+=['whole_business_name'=>'Synthetic whole club','whole_book_standard'=>'HGBM'];
$before=\Kulturhus\Verein\Tests\MySqlFixture::rows($db,'balance_period_events');$previous=null;
$render=static function(string $fid)use($db):string{$_SESSION=['actor'=>'asset-admin','csrf'=>'synthetic'];$_GET=['tax_year'=>'2026','filing'=>$fid];$_POST=[];$_SERVER['REQUEST_METHOD']='GET';ob_start();require __DIR__.'/../views/Finance/Tax/tax-filings.php';return ob_get_clean();};
foreach([$pid,$pid2] as $target){
    $id=$service->createPeriod($target,$combined?\Kulturhus\Verein\BalanceTaxFiling::COMBINED_SOURCE:'approved_club_allocation');
    if($previous!==null)$service->carryForward($id,1,$previous,true);
    $filing=$taxService->get($id);$b=$filing['form_data']['balance_preparation'];$report=$service->sourceReport($filing);$map=[];
    foreach($report['accounts'] as $key=>$account){$choice=match($account['group']){'cash'=>'bs.ass.currAss.cashEquiv.bank','equity'=>'bs.eqLiab.equity.retainedEarnings','liabilities'=>'bs.eqLiab.liab.trade.genOther','member_payables'=>'bs.eqLiab.liab.other.other',default=>null};if($choice!==null)$map[$key]=$choice;}
    $review=$b['review'];unset($review['year'],$review['journal_sha256'],$review['period']);$review['complete']=true;
    $dep=array_key_first(array_filter($report['accounts'],fn($row)=>$row['group']==='expense'&&$row['skr_code']==='62200'));
    if($previous===null)$review['asset_tax_plans']['amplifier']=['months'=>120,'depreciation_account'=>$dep,'reason'=>'Synthetic reviewed tax life','confirmed'=>true];
    $service->save($id,$filing['version'],$profile,$map,$review,$combined?$map:null);$filing=$taxService->get($id);$values=$service->assertCurrent($filing);
    if($combined){check($values['whole']['book']===$values['whole']['tax'],'combined whole asset books never inherit tax changes');$values=$values['taxable'];}
    check(count($values['asset_schedule']['items'])===4,'shared assets retained once, including zero-value items');
    check($values['asset_tax']['tax_schedule']['items']['amplifier']['schedule']['depreciation_cents']===($previous===null?9917:5950),'reviewed tax plan survives internal usage and actual short period');
    check($values['asset_schedule']['items']['gwg']['schedule']['depreciation_cents']===($previous===null?11900:0),'GWG does not restart when unit use changes');
    check($values['asset_schedule']['items']['degressive']['schedule']['depreciation_cents']===($previous===null?25000:11250),'degressive actual year computation retained');
    check($taxService->validate($id,$filing['version'])['valid'],'real aggregate generator accepts persisted original assets and changed use');
    file_put_contents(__DIR__.'/balance-period-workflow-'.$prefix.'-assets-'.($previous===null?'first':'successor').'.xml',$taxService->get($id)['xml_data']);
    $filing=$taxService->get($id);$service->approve($id,$filing['version'],true);$filing=$taxService->get($id);
    $html=$render($id);
    check(str_contains($html,'Synthetic amplifier')&&str_contains($html,'getrennten Buch- und Steuerwerte')&&str_contains($html,'Vereinsarbeit A')&&str_contains($html,'Vereinsarbeit B'),'aggregate asset UI shows names and original tax comparison');
    if($combined)check(str_contains($html,'Ergebnis des Zeitraums')&&str_contains($html,'Abschreibung im Zeitraum'),'combined assets use actual-period labels');
    check($taxService->send($id,$filing['version'],'dummy','123456','SEND-TEST',true,true)['state']==='sent_test','aggregate asset simulated receipt');$previous=$id;
}
check($before===\Kulturhus\Verein\Tests\MySqlFixture::rows($db,'balance_period_events'),'aggregate asset tax review never rewrites original journal');
check(\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db)===[],'aggregate asset references stay tenant bound');
echo "$n aggregate asset workflow checks passed plus $priorChecks use-review checks; simulated transport only\n";
