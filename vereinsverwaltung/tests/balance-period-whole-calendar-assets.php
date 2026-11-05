<?php declare(strict_types=1);
require __DIR__.'/balance-period-asset-carry.php';
require_once __DIR__.'/../src/Finance/Balance/BalanceTaxFiling.php';require __DIR__.'/fixtures/balance-offline-runtime.php';
use Kulturhus\Verein\{BalanceTaxFiling as F,TaxFilings,Tests\MySqlFixture};
putenv('VEREIN_TAX_AUTO_TEST=synthetic-only');$base=$n;$n=0;
$f=new F($db,'carry-admin');$filings=new TaxFilings($db,'carry-admin');$before=[];
foreach(['balance_years','balance_entries','balance_period_events','asset_book_years','asset_book_plans'] as $table)$before[$table]=MySqlFixture::rows($db,$table);
$profile=['business_name'=>'Synthetic whole calendar assets','supplier'=>'Synthetischer Softwaretest','scope_confirmed'=>true,'asset_values_unchanged'=>true,'asset_tax_values_reviewed'=>false,'tax_privileged'=>false,
    'company'=>['tax_number'=>'5192050001276','name'=>'Synthetischer Verein','street'=>'Testweg','house_number'=>'1','postal_code'=>'11111','city'=>'Teststadt','country'=>'Deutschland']];
$review=['complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[],'asset_tax_plans'=>[],'capital_movements'=>[]];
$oldId=$f->create(2025,'complete_club_ledger');$map=[];
$accounts=$f->sourceReport($filings->get($oldId))['accounts'];
foreach($accounts as $key=>$account){
    $choice=match($account['group']){'cash'=>'bs.ass.currAss.cashEquiv.bank','equity'=>'bs.eqLiab.equity.retainedEarnings','liabilities'=>'bs.eqLiab.liab.trade.genOther','member_payables'=>'bs.eqLiab.liab.other.other',default=>null};
    if($choice!==null)$map[$key]=$choice;
}
$dep=array_key_first(array_filter($accounts,fn($a)=>$a['group']==='expense'&&$a['skr_code']==='62200'));
$review['asset_tax_plans']['linear']=['months'=>120,'depreciation_account'=>$dep,'reason'=>'Synthetic whole-club tax life','confirmed'=>true];
$profile['asset_values_unchanged']=false;$profile['asset_tax_values_reviewed']=true;
$f->save($oldId,1,$profile,$map,$review);
check($filings->validate($oldId,2)['valid'],'real synthetic calendar invoices and six assets pass whole-club generator');$f->approve($oldId,3,true);$filings->send($oldId,4,'dummy','123456','SEND-TEST',true,true);
$source=$oldId;
foreach([$pid,$pid2] as $index=>$target){
    $fid=$f->createPeriod($target,'complete_club_ledger');$f->carryForward($fid,1,$source,true);
    $review=$filings->get($fid)['form_data']['balance_preparation']['review'];unset($review['year'],$review['journal_sha256'],$review['period']);$review['complete']=true;$f->save($fid,2,$profile,$map,$review);
    $v=$f->assertCurrent($filings->get($fid));check(count($v['asset_schedule']['items'])===6,'six original assets preserved in whole period '.$index);
    check($v['asset_schedule']['items']['linear']['plan']['sphere']==='1'&&$v['asset_tax']['tax_schedule']['items']['linear']['schedule']['depreciation_cents']===6000,'whole-taxable corporation continues its reviewed non-4 asset plan '.$index);
    denied(fn()=>$f->assetTaxPlan($fid,3,'linear',null),'inherited calendar tax life cannot be erased '.$index);
    check($v['asset_schedule']['items']['gwg']['schedule']['depreciation_cents']===0&&$v['asset_schedule']['items']['scrap']['schedule']['book_closing_cents']===0,'written-off and disposed calendar assets not acquired again '.$index);
    check($filings->validate($fid,3)['valid'],'calendar acquisition through dated whole workflow generator '.$index);
    file_put_contents(__DIR__.'/balance-period-workflow-whole-calendar-assets'.($index?'-successor':'').'.xml',$filings->get($fid)['xml_data']);
    $f->approve($fid,4,true);check($filings->send($fid,5,'dummy','123456','SEND-TEST',true,true)['state']==='sent_test','calendar asset successor simulated receipt '.$index);$source=$fid;
}
foreach($before as $table=>$rows)check(MySqlFixture::rows($db,$table)===$rows,'whole asset carry never rewrites '.$table);
echo "$n whole calendar asset workflow checks passed plus $base asset checks; simulated transport only\n";
