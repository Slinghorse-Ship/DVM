<?php declare(strict_types=1);
require __DIR__.'/balance-capital-filing.php';require __DIR__.'/fixtures/balance-capital-carry.php';
use Kulturhus\Verein\{BalanceLedger,BalanceSegments,BalanceTaxFiling,TaxFilings,SettingsSchema,BalanceSegmentExport};
use Kulturhus\Verein\Tests\MySqlFixture;
$carryMapping=$mapping;unset($carryMapping['equity']);
$carryMapping['liabilities']='bs.eqLiab.liab.trade.genOther';
$carryRender=static function(string $actor,int $year)use($db):string{
    $_GET=['segment_voucher'=>'opening','segment_line'=>'opening:0'];$_POST=[];$_SESSION=['actor'=>$actor];
    ob_start();require __DIR__.'/../views/Finance/Balance/balance-segments.php';return ob_get_clean();
};
foreach(['zero'=>[10000,10000,false],'gain'=>[20000,10000,false],'loss'=>[10000,20000,true]] as $label=>[$income,$loss,$cross]){
    $club='offset-'.$label;$actor=$club.'-board';$treasurer=$club.'-finance';
    $db->prepare('INSERT INTO clubs VALUES(?,?)')->execute([$club,'Synthetic opposing capital '.$label]);
    $db->prepare('INSERT INTO actors(id,club_id,role,is_superuser) VALUES(?,?,?,?),(?,?,?,?)')->execute([$actor,$club,'treasurer',1,$treasurer,$club,'treasurer',0]);
    $db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES(?,'tax',?,1,?)")->execute([$club,json_encode(array_replace(SettingsSchema::defaults('tax'),['accounting_basis'=>'balance'])),gmdate('c')]);
    $case=capitalCarryFixture($income,$loss,$cross);$source=$case['journal'];
    $ledger=new BalanceLedger($db,$actor);$segments=new BalanceSegments($db,$actor);$service=new BalanceTaxFiling($db,$treasurer);$approver=new BalanceTaxFiling($db,$actor);$filings=new TaxFilings($db,$treasurer);
    $ledger->create(2025);$ledger->setup(2025,1,$source['accounts'],$source['opening'],'result','Synthetic opposing capital opening',true);$version=2;
    foreach($source['entries'] as $entry)$ledger->post(2025,$version++,$entry['id'],$entry['date'],$entry['description'],'Synthetic original',$entry['lines']);
    $ledger->close(2025,$version,['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic complete review');
    $segments->save(2025,0,$case['allocation']['units'],$case['allocation']['allocations']);$segments->confirm(2025,1,true,'Synthetic exact allocation');
    $id=$service->create(2025,BalanceTaxFiling::COMBINED_SOURCE);$service->save($id,1,$combinedProfile,$carryMapping,$clearReview,$carryMapping);
    check($filings->validate($id,2)['valid'],'opposing-capital prior XML generated');$approver->approve($id,3,true);
    check($filings->send($id,4,'dummy','123456','SEND-TEST',true,true)['state']==='sent_test','prior receipt uses only offline test adapter');
    $ledger->create(2026);$ledger->close(2026,1,['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic following year');
    $journalBefore=MySqlFixture::rows($db,'balance_years');$entriesBefore=MySqlFixture::rows($db,'balance_entries');
    $segments->carry(2026,0,true);$s=$segments->get(2026);
    check($s['allocation']['complete']&&$s['allocation']['schema']===2&&$s['carry_forward']['revision']===2,'opposing capital saved and tied to exact prior review');
    denied(fn()=>(new BalanceSegments($db,$treasurer))->confirm(2026,1,true,'Unauthorized'),'treasurer cannot approve carried capital');
    $segments->confirm(2026,1,true,'Synthetic independently reviewed capital');$s=$segments->get(2026);
    $html=$carryRender($actor,2026);
    check(str_contains($html,'Kapital je Bereich aus 2025')&&str_contains($html,'Negative Kapitalbestände'),'UI explains retained negative capital');
    check(!str_contains($html,'value="balance-segment-line"')&&!str_contains($html,'value="balance-segment-voucher"'),'carried openings do not offer unusable edit forms');
    check(!str_contains($html,'Anfangsbestände · offen'),'carried capital never falsely marked unassigned');
    if($label==='zero')file_put_contents(__DIR__.'/balance-capital-carry-ui.html',$html);
    $files=BalanceSegmentExport::files($s);check(isset($files['bereiche/kapitalvortrag.csv']),'real saved working papers contain capital proof');
    // A forged opening allocation may not duplicate the immutable capital carry.
    $bad=$s['allocation']['allocations'];$key='opening:0';$unit=array_key_first($bad[$key]['parts']);$bad[$key]['parts'][$unit]++;
    denied(fn()=>$segments->save(2026,2,$s['allocation']['units'],$bad),'saved carried opening cannot be overwritten');
    $next=$service->create(2026,BalanceTaxFiling::COMBINED_SOURCE);$service->save($next,1,$combinedProfile,$carryMapping,$clearReview,$carryMapping);$service->carryForward($next,2,$id,true);
    $nextReview=$filings->get($next)['form_data']['balance_preparation']['review'];unset($nextReview['year'],$nextReview['journal_sha256']);$nextReview['complete']=true;
    $service->save($next,3,$combinedProfile,$carryMapping,$nextReview,$carryMapping);$values=$service->assertCurrent($filings->get($next));
    check($values['taxable']['book']['accounts']['result']['opening_cents']===($cross?$loss:$loss-$income),'taxable opening keeps only correct areas');
    check($values['whole']['book']['accounts']['result']['opening_cents']===$loss-$income,'whole opening does not inherit taxable-only result');
    check($values['whole']['book']['annual_result_cents']===0&&$values['taxable']['book']['annual_result_cents']===0,'neither scope counts carry as new income');
    check($filings->validate($next,4)['valid'],'actual persisted opposing carry passes independent Python generator');$approver->approve($next,5,true);
    check($filings->ready($next,6,'SEND-TEST',true,true)['id']===$next,'current exact carry can reach readiness in isolated fixture');
    check(MySqlFixture::rows($db,'balance_years')===$journalBefore&&MySqlFixture::rows($db,'balance_entries')===$entriesBefore,'allocation and filing never rewrite the source journal');
    if($label==='zero'){
        $p=$segments->get(2025);$changed=$p['allocation']['allocations'];
        foreach($source['entries'][0]['lines'] as $i=>$_)$changed[$source['entries'][0]['id'].':'.$i]['parts']=['shop'=>$income];
        $segments->save(2025,2,$p['allocation']['units'],$changed);
        check(!$segments->get(2026)['current_approval'],'prior reallocation revokes subsequent approval');
        denied(fn()=>$filings->ready($next,6,'SEND-TEST',true,true),'prior unit change blocks already validated filing');
        denied(fn()=>$segments->rebase(2026,2,true,'Not reviewed yet'),'unapproved prior cannot replace capital carry');
        $segments->confirm(2025,3,true,'Synthetic corrected gain ownership');$segments->rebase(2026,2,true,'Synthetic reviewed ownership correction');
        $rebased=$segments->get(2026);check($rebased['allocation']['schema']===1&&empty($rebased['allocation']['capital_carry']),'rebase removes only obsolete opposing capital');
        check($rebased['allocation']['complete']&&!$rebased['current_approval'],'corrected unit opening balanced but requires fresh approval');
        check(isset($rebased['history'][0]['allocation']['capital_carry']),'old capital carry retained in immutable history');
        $segments->confirm(2026,3,true,'Synthetic successor reviewed');check($segments->get(2026)['current_approval'],'corrected carry can be approved');
    }
}
$before=MySqlFixture::rows($db,'balance_segment_reviews');$restored=MySqlFixture::restoreBackup($db);
check(MySqlFixture::rows($restored,'balance_segment_reviews')===$before,'encrypted restore keeps both capital carry schema versions');
check((new BalanceSegments($restored,'offset-loss-board'))->get(2026)['allocation']===$segments->get(2026)['allocation'],'restored signed capital recomputes identically');
echo "$checks total capital carry workflow checks passed; secondary MySQL, offline transport only\n";
