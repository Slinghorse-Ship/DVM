<?php declare(strict_types=1);
// Six actual invoice-backed calendar assets and two dated successors, all synthetic.
require __DIR__.'/balance-period-business-calendar-assets.php';
require_once __DIR__.'/../src/Finance/Balance/BalancePeriodAreas.php';require __DIR__.'/fixtures/period-area-asset-chain.php';
use Kulturhus\Verein\{BalanceSegments as Segments,BalanceSegmentCalculation as Allocation,BalanceTaxFiling as ClubFiling,BalanceFilingRevision as Revision};
$inherited=$n;$n=0;$legacy=new Segments($db,'carry-admin');$units=['club'=>['name'=>'Synthetic actual activity','sphere'=>'4']];$r=$calendar->report(2025);$rows=[];
foreach(Allocation::sources($r) as $key=>$row)$rows[$key]=['parts'=>['club'=>$row['cents']],'reason'=>'Synthetic original calendar activity'];
$legacy->save(2025,0,$units,$rows);
foreach($r['asset_schedule']['items'] as $item=>$source)$legacy->asset(2025,$legacy->get(2025)['version'],$item,['club'=>1],'uses','Synthetic original retained use',true);
$s=$legacy->get(2025);$legacy->confirm(2025,$s['version'],true,'Synthetic calendar board approval');
verifyAssetAreaChain($db,'carry-admin',$l,[$pid,$pid2],'4');
$before=[];foreach(['balance_years','balance_entries','balance_segment_reviews','balance_period_events','balance_period_area_reviews','asset_book_plans'] as $table)$before[$table]=\Kulturhus\Verein\Tests\MySqlFixture::rows($db,$table);
$pureProfile=$profile;
foreach(['approved_club_allocation',ClubFiling::COMBINED_SOURCE] as $scope){
    $combined=$scope===ClubFiling::COMBINED_SOURCE;$prefix=$combined?'combined':'club';$kind=$combined?ClubFiling::COMBINED:'club_taxable_businesses';
    $profile=$pureProfile;$profile['business_name']='Synthetic calendar '.$prefix;
    if($combined)$profile+=['whole_business_name'=>'Synthetic calendar whole club','whole_book_standard'=>'HGBM'];
    $rv=['complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[],'asset_tax_plans'=>['linear'=>['months'=>120,'depreciation_account'=>$dep,'reason'=>'Synthetic reviewed calendar life','confirmed'=>true]]];
    $calendarId=$f->create(2025,$scope);$f->save($calendarId,1,$profile,$map,$rv,$combined?$map:null);
    check($filings->validate($calendarId,2)['valid'],'actual calendar '.$prefix.' values generated');$f->approve($calendarId,3,true);$filings->send($calendarId,4,'dummy','123456','SEND-TEST',true,true);
    $retained=$filings->get($calendarId);$previous=$calendarId;$nextIds=[];
    foreach([$pid,$pid2] as $index=>$target){
        $fid=$f->createPeriod($target,$scope);$draft=$filings->get($fid);$b=$draft['form_data']['balance_preparation'];
        check(array_column($f->carryForwardChoices(2026,$b['period'],$kind),'id')===[$previous],'only adjacent matching '.$prefix.' receipt offered '.$index);
        check(str_contains($render($fid),'Vorherigen Abschluss übernehmen'),'guided original calendar carry '.$prefix.' '.$index);
        denied(fn()=>$f->carryForward($fid,1,$oldId,true),'separate-business receipt not accepted as '.$prefix);
        check($filings->get($fid)===$draft,'rejected profile leaves draft identity and version unchanged '.$prefix.' '.$index);
        unset($draft);
        $f->carryForward($fid,1,$previous,true);$b=$filings->get($fid)['form_data']['balance_preparation'];
        if($index===0)check($b['carry_forward']['period_values']['taxable']===($f->assertCurrent($retained)['taxable']??$f->assertCurrent($retained)),'calendar values and hash not rewritten '.$prefix);
        $review=$b['review'];unset($review['year'],$review['journal_sha256'],$review['period']);$review['complete']=true;$f->save($fid,2,$profile,$map,$review,$combined?$map:null);
        $values=$f->assertCurrent($filings->get($fid));$t=$values['taxable']??$values;
        check(count($t['asset_schedule']['items'])===6&&$t['asset_tax']['tax_schedule']['items']['linear']['schedule']['depreciation_cents']===6000,'six retained assets and actual tax period '.$prefix.' '.$index);
        // These objects belong to completed simulated requests. Historical
        // invariants below retain their own explicit snapshots.
        unset($b,$values,$t);
        denied(fn()=>$f->assetTaxPlan($fid,3,'linear',null),'legacy reviewed tax life cannot be silently removed '.$prefix.' '.$index);
        check($filings->validate($fid,3)['valid'],'actual '.$prefix.' calendar-period XML '.$index);
        file_put_contents(__DIR__.'/balance-period-workflow-'.$prefix.'-calendar-assets'.($index?'-successor':'').'.xml',$filings->get($fid)['xml_data']);
        $html=$render($fid);check(str_contains($html,'Synthetic actual activity')&&str_contains($html,'getrennten Buch- und Steuerwerte'),'calendar asset UI '.$prefix.' '.$index);
        $f->approve($fid,4,true);check($filings->send($fid,5,'dummy','123456','SEND-TEST',true,true)['state']==='sent_test','simulated '.$prefix.' calendar-period receipt '.$index);$previous=$fid;$nextIds[]=$fid;
    }
    $history=$render($nextIds[0]);
    $changed=$f->create(2025,$scope);$rv['asset_tax_plans']['linear']['months']=144;$f->save($changed,1,$profile,$map,$rv,$combined?$map:null);
    $f->revision($changed,2,['status'=>'B','reason'=>'Synthetic corrected calendar valuation','legal_basis'=>'Synthetic original expert evidence','source_id'=>$calendarId,'confirmed'=>true]);
    check($filings->validate($changed,3)['valid'],'corrected '.$prefix.' calendar tax life generated');$f->approve($changed,4,true);$filings->send($changed,5,'dummy','123456','SEND-TEST',true,true);
    denied(fn()=>$f->assertCurrent($filings->get($nextIds[1])),'superseded legacy receipt blocks dependent '.$prefix.' sends');
    check($history===$render($nextIds[0])&&$filings->get($calendarId)===$retained,'old '.$prefix.' receipt and display preserved');
    $amend=$f->createPeriod($pid,$scope);denied(fn()=>$f->carryForward($amend,1,$calendarId,true),'old '.$prefix.' calendar receipt not reused');$f->carryForward($amend,1,$changed,true);
    $rv=$filings->get($amend)['form_data']['balance_preparation']['review'];unset($rv['year'],$rv['journal_sha256'],$rv['period']);$rv['complete']=true;$f->save($amend,2,$profile,$map,$rv,$combined?$map:null);
    $f->revision($amend,3,['status'=>'B','reason'=>'Synthetic dependent correction','legal_basis'=>'Synthetic corrected prior tax values','source_id'=>$nextIds[0],'confirmed'=>true]);
    check($filings->validate($amend,4)['valid'],'corrected legacy value reaches dated '.$prefix.' amendment');
    file_put_contents(__DIR__.'/balance-period-workflow-'.$prefix.'-calendar-correction.xml',$filings->get($amend)['xml_data']);
}
foreach($before as $table=>$rows)check($rows===\Kulturhus\Verein\Tests\MySqlFixture::rows($db,$table),'calendar profile bridge does not mutate '.$table);
check(memory_get_peak_usage(true)<=128*1024*1024,'complete calendar correction chain stays within 128 MiB');
echo 'Peak memory: '.memory_get_peak_usage(true)." bytes\n";
echo "$n calendar aggregate/combined asset checks passed plus $inherited separate-business checks; simulated transport only\n";
