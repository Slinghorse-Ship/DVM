<?php declare(strict_types=1);
require __DIR__.'/asset-partial-disposal-posting.php';
require_once __DIR__.'/../src/Finance/Balance/BalanceSegments.php';require_once __DIR__.'/../src/Finance/Balance/BalanceClubTaxReport.php';require_once __DIR__.'/../src/Finance/Balance/BalanceSegmentExport.php';
use Kulturhus\Verein\{BalanceAssetAllocation as Shares,BalanceSegments,BalanceSegmentCalculation as Seg,BalanceClubTaxReport,BalanceSegmentExport};
use Kulturhus\Verein\Tests\MySqlFixture;
$originalYears=MySqlFixture::rows($db,'balance_years');$originalEntries=MySqlFixture::rows($db,'balance_entries');$originalPlans=MySqlFixture::rows($db,'asset_book_plans');
$units=['food'=>['name'=>'Essen <Test>','sphere'=>'4'],'drinks'=>['name'=>'Getränke','sphere'=>'4']];$weights=['drinks'=>2,'food'=>1];
$editor=new BalanceSegments($db,'writer');$board=new BalanceSegments($db,'admin');$previous=null;$projection=null;
foreach([2025,2026,2027] as $year){
    $report=$ledger->report($year);$item=$report['asset_schedule']['items']['partial'];$parts=Shares::item($item,$weights,$year);$state=$editor->get($year);
    if($year>2025){$editor->carry($year,0,true);$state=$editor->get($year);}
    $alloc=$state['allocation']['allocations']??[];$sourceRows=Seg::sources($report);$voucherCosts=[];
    foreach($sourceRows as $key=>$source){
        if($source['voucher']==='opening')continue;
        $code=$report['accounts'][$source['line']['account']]['skr_code'];$field=match($code){'62200'=>'depreciation_cents','68950'=>'book_disposals_cents',default=>null};
        if($field)foreach($parts as $u=>$v)$voucherCosts[$source['voucher']][$u]=($voucherCosts[$source['voucher']][$u]??0)+$v[$field];
    }
    foreach($sourceRows as $key=>$source){
        if(isset($alloc[$key])||$source['voucher']==='opening')continue;
        $account=$report['accounts'][$source['line']['account']];$field=match($account['skr_code']){'62200'=>'depreciation_cents','68950'=>'book_disposals_cents',default=>null};
        if($field){$split=[];foreach($parts as $u=>$v)$split[$u]=$v[$field];}
        elseif($account['group']==='fixed_assets'&&$source['line']['credit_cents'])$split=$voucherCosts[$source['voucher']];
        else $split=Shares::cents($source['cents'],$weights);
        $alloc[$key]=['parts'=>array_filter($split),'reason'=>'Synthetic original shared component evidence'];
    }
    $editor->save($year,$year===2025?0:1,$units,$alloc);
    if($year===2025)$editor->asset($year,1,'partial',$weights,'hours','Synthetic use <not-html>',true);
    $state=$editor->get($year);check($state['allocation']['complete'],'persisted shared component and original vouchers reconcile '.$year);
    $snapshot=MySqlFixture::snapshot($db);$denied=false;try{(new BalanceSegments($db,'member'))->asset($year,$state['version'],'partial',$weights,'hours','No authority',true);}catch(DomainException){$denied=true;}
    check($denied&&MySqlFixture::snapshot($db)===$snapshot,'member cannot change shared component history');
    $board->confirm($year,$state['version'],true,'Synthetic board component approval');$state=$board->get($year);
    check($state['current_approval'],'exact component history approved '.$year);
    if($year<=2026){$projection=BalanceClubTaxReport::fromState($state,$projection);check(count($projection['asset_schedule']['items'])===1,'tax scope keeps one original component asset');}
    if($previous)foreach($parts as $u=>$v)foreach(['cost','accumulated','book'] as $f)check($v[$f.'_opening_cents']===$previous[$u][$f.'_closing_cents'],'stored shares carry without changes');
    $previous=$parts;
    $files=BalanceSegmentExport::files($state);check(str_contains($files['bereiche/anlagenanteile.csv'],'Abgang Buchwert Cent'),'shared component movements exported');
    $_SESSION=['actor'=>'writer','csrf'=>'synthetic'];$_GET=['asset_item'=>'partial'];$_POST=[];ob_start();require __DIR__.'/../views/Finance/Balance/balance-segments.php';$html=ob_get_clean();
    check(str_contains($html,'Teilweise ausgemustert')&&str_contains($html,'Ausgebuchter Restwert im Jahr'),'actual view shows component disposal and annual remaining-value charge');
    check(!str_contains($html,'<not-html>')&&!str_contains($html,'<Test>'),'shared component evidence escaped');
}
check(MySqlFixture::rows($db,'balance_years')===$originalYears&&MySqlFixture::rows($db,'balance_entries')===$originalEntries&&MySqlFixture::rows($db,'asset_book_plans')===$originalPlans,'shared disposal never changes original journal or acquisition plan');
check(!MySqlFixture::foreignKeyErrors($db),'shared disposal history retains tenant-consistent references');
echo "$n shared-disposal workflow checks passed; synthetic MySQL, no mail, bank or transport\n";
