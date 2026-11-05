<?php declare(strict_types=1);
// Actual retained MySQL originals and both annual valuations; transport is a fixture.
require __DIR__.'/asset-cost-valuation.php';
require __DIR__.'/fixtures/cost-xml-runtime.php';
use Kulturhus\Verein\{BalanceTaxFiling,TaxFilings};
costXmlRuntime();
$svc=new BalanceTaxFiling($db,'admin');$tax=new TaxFilings($db,'admin');
$id=$svc->create(2025,'complete_club_ledger');$filing=$tax->get($id);$review=$filing['form_data']['balance_preparation']['review'];
unset($review['year'],$review['journal_sha256']);$review['complete']=true;
$profile=['business_name'=>'Synthetic full club','supplier'=>'Synthetic source','company'=>['tax_number'=>'5192050001276','name'=>'Synthetic full club','street'=>'Testweg','house_number'=>'1','postal_code'=>'11111','city'=>'Teststadt','country'=>'Deutschland'],
    'scope_confirmed'=>true,'tax_privileged'=>false,'asset_values_unchanged'=>false,'asset_tax_values_reviewed'=>true];
$map=['equity'=>'bs.eqLiab.equity.retainedEarnings'];
foreach($firstReport['accounts'] as $key=>$account)if($account['group']==='liabilities'&&$account['skr_code']==='')$map[$key]='bs.eqLiab.liab.trade.genOther';
$svc->save($id,1,$profile,$map,$review);
$before=Kulturhus\Verein\Tests\MySqlFixture::rows($db,'balance_years');$svc->validate($id,2);$generated=$tax->get($id);
check(str_contains($generated['xml_data'],'landWithoutBuildings')&&str_contains($generated['xml_data'],'financialsExcept.other'),
    'stored full-club XML contains original land and financial valuation purposes');
check($generated['form_data']['balance_preparation']['generated']['asset_items']===2,'normal filing workflow includes both actual retained cost assets');
check(Kulturhus\Verein\Tests\MySqlFixture::rows($db,'balance_years')===$before,'XML generation never rewrites closed original books');
denied(fn()=>(new BalanceTaxFiling($db,'reader'))->validate($id,3),'read-only role cannot generate cost XML');
denied(fn()=>(new BalanceTaxFiling($db,'other'))->validate($id,3),'foreign club cannot generate cost XML');
file_put_contents(dirname(__DIR__,2).'/cost-annual.xml',$generated['xml_data']);
echo "$n retained cost and XML workflow checks passed; synthetic MySQL, no transport\n";
