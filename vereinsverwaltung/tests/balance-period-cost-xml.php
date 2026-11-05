<?php declare(strict_types=1);
require __DIR__.'/balance-period-cost-valuation.php';
require __DIR__.'/fixtures/cost-xml-runtime.php';
require_once __DIR__.'/../src/Finance/Balance/BalancePeriodWholeFiling.php';
use Kulturhus\Verein\{BalanceTaxFiling,TaxFilings};
costXmlRuntime();
$svc=new BalanceTaxFiling($db,'admin');$tax=new TaxFilings($db,'admin');$id=$svc->createPeriod($pid,'complete_club_ledger');
$filing=$tax->get($id);$review=$filing['form_data']['balance_preparation']['review'];unset($review['year'],$review['journal_sha256'],$review['period']);$review['complete']=true;
$profile=['business_name'=>'Synthetic full dated club','supplier'=>'Synthetic source','company'=>['tax_number'=>'5192050001276','name'=>'Synthetic full dated club','street'=>'Testweg','house_number'=>'1','postal_code'=>'11111','city'=>'Teststadt','country'=>'Deutschland'],
    'scope_confirmed'=>true,'tax_privileged'=>false,'asset_values_unchanged'=>false,'asset_tax_values_reviewed'=>true];
$map=['equity'=>'bs.eqLiab.equity.retainedEarnings'];foreach($firstReport['accounts'] as $key=>$account)if($account['group']==='liabilities'&&$account['skr_code']==='')$map[$key]='bs.eqLiab.liab.trade.genOther';
$svc->save($id,1,$profile,$map,$review);
$before=Kulturhus\Verein\Tests\MySqlFixture::rows($db,'balance_periods');$svc->validate($id,2);$generated=$tax->get($id);
check($generated['state']==='validated'&&str_contains($generated['xml_data'],'2025-02-15')&&str_contains($generated['xml_data'],'landWithoutBuildings'),
    'normal full-club period XML reconstructs actual original corrected cost journal');
check($generated['form_data']['balance_preparation']['generated']['asset_items']===2,'period XML includes monetary and no-posting cost assets');
check(Kulturhus\Verein\Tests\MySqlFixture::rows($db,'balance_periods')===$before,'period XML leaves original correction and closed journal untouched');
file_put_contents(dirname(__DIR__,2).'/cost-period.xml',$generated['xml_data']);
echo "$n retained period cost XML workflow checks passed; synthetic MySQL, no transport\n";
