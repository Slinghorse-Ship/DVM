<?php declare(strict_types=1);
define('SYNTHETIC_BUSINESS_ASSET_CARRY',true);
require __DIR__.'/balance-period-asset-carry.php';
require_once __DIR__.'/../src/Finance/Balance/BalanceTaxFiling.php';require __DIR__.'/fixtures/balance-offline-runtime.php';
use Kulturhus\Verein\{BalanceTaxFiling as F,TaxFilings,Tests\MySqlFixture};
putenv('VEREIN_TAX_AUTO_TEST=synthetic-only');$base=$n;$n=0;
$f=new F($db,'carry-admin');$filings=new TaxFilings($db,'carry-admin');$before=[];
foreach(['balance_years','balance_entries','balance_period_events','asset_book_years','asset_book_plans'] as $table)$before[$table]=MySqlFixture::rows($db,$table);
$profile=['business_name'=>'Synthetic separately kept business','supplier'=>'Synthetischer Softwaretest','scope_confirmed'=>true,'asset_values_unchanged'=>false,'asset_tax_values_reviewed'=>true,'tax_privileged'=>true,
    'company'=>['tax_number'=>'5192050001276','name'=>'Synthetischer Verein','street'=>'Testweg','house_number'=>'1','postal_code'=>'11111','city'=>'Teststadt','country'=>'Deutschland']];
$review=['complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[],'asset_tax_plans'=>[]];
$oldId=$f->create(2025,'separate_business_ledger');$map=[];$accounts=$f->sourceReport($filings->get($oldId))['accounts'];
foreach($accounts as $key=>$account){$choice=match($account['group']){'cash'=>'bs.ass.currAss.cashEquiv.bank','equity'=>'bs.eqLiab.equity.retainedEarnings','liabilities'=>'bs.eqLiab.liab.trade.genOther','member_payables'=>'bs.eqLiab.liab.other.other',default=>null};if($choice!==null)$map[$key]=$choice;}
$dep=array_key_first(array_filter($accounts,fn($a)=>$a['group']==='expense'&&$a['skr_code']==='62200'));
$review['asset_tax_plans']['linear']=['months'=>120,'depreciation_account'=>$dep,'reason'=>'Synthetic reviewed tax life','confirmed'=>true];
$f->save($oldId,1,$profile,$map,$review);check($filings->validate($oldId,2)['valid'],'calendar separate business with six real assets generated');$f->approve($oldId,3,true);$filings->send($oldId,4,'dummy','123456','SEND-TEST',true,true);
$original=$filings->get($oldId);$source=$oldId;
require_once __DIR__.'/../src/Web/View.php';
$render=static function(string $fid)use($db):string{$_SESSION=['actor'=>'carry-admin','csrf'=>'synthetic'];$_GET=['tax_year'=>'2026','filing'=>$fid];$_POST=[];$_SERVER['REQUEST_METHOD']='GET';ob_start();require __DIR__.'/../views/Finance/Tax/tax-filings.php';return ob_get_clean();};
foreach([$pid,$pid2] as $index=>$target){
    $fid=$f->createPeriod($target,'separate_business_ledger');$draft=$filings->get($fid);$b=$draft['form_data']['balance_preparation'];
    check(array_column($f->carryForwardChoices(2026,$b['period'],'single_taxable_business'),'id')===[$source],'calendar or exact preceding business offered '.$index);
    check(str_contains($render($fid),'Vorherigen Abschluss übernehmen'),'no invented asset zero values before carry '.$index);
    $f->carryForward($fid,1,$source,true);$b=$filings->get($fid)['form_data']['balance_preparation'];
    $rv=$b['review'];unset($rv['year'],$rv['journal_sha256'],$rv['period']);$rv['complete']=true;$f->save($fid,2,$profile,$map,$rv);
    $v=$f->assertCurrent($filings->get($fid));
    check(count($v['asset_schedule']['items'])===6&&$v['asset_tax']['tax_schedule']['items']['linear']['schedule']['depreciation_cents']===6000,'all six original assets and tax life retained '.$index);
    check($v['asset_schedule']['items']['gwg']['schedule']['depreciation_cents']===0&&$v['asset_schedule']['items']['scrap']['schedule']['book_closing_cents']===0,'written-off assets not reacquired '.$index);
    denied(fn()=>$f->assetTaxPlan($fid,3,'linear',null),'cannot erase inherited business tax life '.$index);
    check($filings->validate($fid,3)['valid'],'business calendar asset bridge real XML '.$index);
    file_put_contents(__DIR__.'/balance-period-workflow-business-calendar-assets'.($index?'-successor':'').'.xml',$filings->get($fid)['xml_data']);
    $html=$render($fid);check(str_contains($html,'Synthetic linear')&&str_contains($html,'Anschaffungs-/Herstellungskosten')&&str_contains($html,'getrennten Buch- und Steuerwerte'),'dated asset display includes cost values and separate book/tax review '.$index);
    $f->approve($fid,4,true);check($filings->send($fid,5,'dummy','123456','SEND-TEST',true,true)['state']==='sent_test','business assets simulated receipt '.$index);$source=$fid;
}
check($filings->get($oldId)===$original,'historical calendar tax values XML and approval unchanged');
foreach($before as $table=>$rows)check(MySqlFixture::rows($db,$table)===$rows,'business filing leaves '.$table.' unchanged');
check(MySqlFixture::foreignKeyErrors($db)===[],'business calendar foreign keys intact');
echo "$n business calendar asset workflow checks passed plus $base asset checks; simulated transport only\n";
