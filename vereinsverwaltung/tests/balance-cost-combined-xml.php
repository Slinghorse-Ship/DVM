<?php declare(strict_types=1);
require __DIR__.'/balance-cost-xml.php';
use Kulturhus\Verein\{BalanceSegments,BalanceSegmentCalculation,BalanceTaxFiling,BalanceTaxValues};
use Kulturhus\Verein\Tests\MySqlFixture;
$segments=new BalanceSegments($db,'admin');$state=$segments->get(2025);
$units=['ideal'=>['name'=>'Synthetic ideal','sphere'=>'1'],'capital'=>['name'=>'Synthetic capital','sphere'=>'2'],
    'culture'=>['name'=>'Synthetic culture','sphere'=>'3'],'food'=>['name'=>'Synthetic food','sphere'=>'4'],'shop'=>['name'=>'Synthetic shop','sphere'=>'4']];
$vouchers=[];foreach($firstReport['entries'] as $entry){$u='food';foreach($entry['lines'] as $line)if(($firstReport['accounts'][$line['account']]['skr_code']??null)==='09200')$u='shop';$vouchers[$entry['id']]=$u;}
$alloc=[];foreach(BalanceSegmentCalculation::sources($firstReport) as $key=>$row)$alloc[$key]=['parts'=>[$vouchers[$row['voucher']]??'food'=>$row['cents']],'reason'=>'Synthetic original cost source allocation'];
$segments->save(2025,$state['version'],$units,$alloc);$state=$segments->get(2025);
$segments->confirm(2025,$state['version'],true,'Synthetic complete cost allocation reviewed by board');
$cid=$svc->create(2025,BalanceTaxFiling::COMBINED_SOURCE);$filing=$tax->get($cid);$review=$filing['form_data']['balance_preparation']['review'];
unset($review['year'],$review['journal_sha256']);$review['complete']=true;
$combinedProfile=array_replace($profile,['tax_privileged'=>true,'whole_business_name'=>'Synthetic complete cultural club','whole_book_standard'=>'HGBM']);
$svc->save($cid,1,$combinedProfile,$map,$review,$map);$before=MySqlFixture::rows($db,'balance_years');$svc->validate($cid,2);
$generated=$tax->get($cid);$values=$svc->assertCurrent($generated);
check($values['whole']['book']===$values['whole']['tax']&&$values['whole']['current_adjustments']===[],'whole cost books remain original in actual combined filing');
check($values['whole']['book']['annual_result_cents']!==$values['taxable']['tax']['annual_result_cents'],'actual combined XML retains distinct cost book and tax results');
check($generated['form_data']['balance_preparation']['generated']['asset_items']===2&&$generated['form_data']['balance_preparation']['generated']['taxable_asset_items']===2,'combined stored XML verifies both original cost assets and both taxable assets');
check(MySqlFixture::rows($db,'balance_years')===$before,'combined output preserves closed original journal');
denied(fn()=>(new BalanceTaxFiling($db,'reader'))->validate($cid,3),'reader cannot validate combined cost XML');
denied(fn()=>(new BalanceTaxFiling($db,'other'))->validate($cid,3),'foreign club cannot validate combined cost XML');
file_put_contents(dirname(__DIR__,2).'/cost-combined.xml',$generated['xml_data']);
echo "$n combined cost XML workflow checks passed; synthetic MySQL, no transport\n";
