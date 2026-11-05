<?php declare(strict_types=1);
namespace Kulturhus\Verein { function date(string $format,?int $time=null):string{return \gmdate($format,$time??\strtotime('2028-01-15 UTC'));} }
namespace {
require __DIR__.'/balance-tax-filing.php';
use Kulturhus\Verein\{AssetBooks,BalanceAssetAllocation as A,BalanceLedger,BalanceSources,BalanceSegments,BalanceSegmentCalculation as S,BalanceTaxFiling,TaxFilings,Finance,Documents,SettingsSchema,BalanceSegmentExport};
use Kulturhus\Verein\Tests\MySqlFixture;
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
$units=['food'=>['name'=>'Essen','sphere'=>'4'],'drinks'=>['name'=>'Getränke','sphere'=>'4']];$weights=['drinks'=>2,'food'=>1];$retained=[];
foreach(['gwg_immediate','degressive'] as $method){
    $club=$method==='gwg_immediate'?'g':'d';$board=$club.'board';$finance=$club.'finance';$member=$club.'member';$itemId=$club.'-item';
    $db->prepare('INSERT INTO clubs VALUES(?,?)')->execute([$club,'Synthetic shared '.$method]);
    foreach([[$board,'treasurer',1],[$finance,'treasurer',0],[$member,'member',0]] as [$actor,$role,$super])$db->prepare('INSERT INTO actors(id,club_id,role,is_superuser) VALUES(?,?,?,?)')->execute([$actor,$club,$role,$super]);
    $db->prepare('INSERT INTO club_settings(club_id,section,version,payload,updated_at) VALUES(?,?,1,?,?)')->execute([$club,'tax',json_encode(array_replace(SettingsSchema::defaults('tax'),['accounting_basis'=>'balance','input_tax_eligibility'=>'no','depreciation_enabled'=>true])),gmdate('c')]);
    $ledger=new BalanceLedger($db,$board);$sources=new BalanceSources($db,$board);$books=new AssetBooks($db,$board);$f=new Finance($db,$board);
    $editor=new BalanceSegments($db,$finance);$segments=new BalanceSegments($db,$board);$svc=new BalanceTaxFiling($db,$finance);$bs=new BalanceTaxFiling($db,$board);$tax=new TaxFilings($db,$finance);
    $code=$method==='gwg_immediate'?'06700':'06300';$depCode=$method==='gwg_immediate'?'62600':'62200';$acquired='2025-07-01';
    $ledger->create(2025);$r=$ledger->report(2025);$ledger->setup(2025,$r['version'],$r['accounts'],[],'equity','Synthetic opening',true);
    $purchase=$f->createEntry(['kind'=>'expense','description'=>'Synthetic shared '.$method,'amount'=>'120.01','document_date'=>$acquired,'due_date'=>'2025-12-31','category'=>'general',
        'positions'=>[['description'=>'Synthetic original item','amount'=>'120.01','tax_rate'=>'0','sphere'=>'4','account'=>$code,'input_tax'=>'none']]],bin2hex(random_bytes(16)));
    (new Documents($db,$board))->upload('synthetic.png',$png,['title'=>'Synthetic original','kind'=>'invoice','scope'=>'finance','links'=>['entry:'.$purchase]],bin2hex(random_bytes(16)));
    $db->prepare('INSERT INTO inventory_items(club_id,id,code,name,kind) VALUES(?,?,?,?,?)')->execute([$club,$itemId,'SYN-'.$club,'Synthetic shared '.$method,'reusable']);
    $db->prepare('INSERT INTO inventory_locations(club_id,id,name) VALUES(?,?,?)')->execute([$club,$club.'-store','Synthetic store']);
    $db->prepare('INSERT INTO inventory_stock(club_id,item_id,location_id,shelf) VALUES(?,?,?,1)')->execute([$club,$itemId,$club.'-store']);
    $input=['method'=>$method,'basis'=>'120.01','residual'=>'0','months'=>$method==='gwg_immediate'?'1':'60','acquired_on'=>$acquired,'sphere'=>'4','account'=>$code,'reason'=>'Synthetic confirmed original method',
        'asset_reviewed'=>'1','gwg_net'=>'120.01','gwg_vat'=>'0','gwg_deductible'=>'0','gwg_independent'=>'1','gwg_no_pool'=>'1',
        'degressive_rate'=>'30','degressive_movable'=>'1','degressive_same_values'=>'1','degressive_switch'=>'1'];
    $books->save($itemId,0,$input);$db->prepare('INSERT INTO invoice_position_items VALUES(?,?,?,?,?,?,?,?)')->execute([$club,$purchase,1,$itemId,1,'Synthetic original',$board,gmdate('c')]);
    $import=static function(int $year,string $type,string $id,array $options=[])use($ledger,$sources):void{
        $p=$sources->preview($year,$type,$id,$options);if($p['issues'])throw new RuntimeException(implode(' ',$p['issues']));
        $ledger->importSource($year,$ledger->report($year)['version'],$type,$id,$options,$p['hash'],true);
    };
    $import(2025,'invoice',$purchase,['recognised_on'=>$acquired,'private_paid_on'=>'']);$books->recordYear($itemId,1,2025);$import(2025,'depreciation',$itemId.':2025');
    $ledger->close(2025,$ledger->report(2025)['version'],['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic whole original asset review');
    $report=$ledger->report(2025);$original=$report['asset_schedule']['items'][$itemId];$alloc=[];
    check($original['method']===$method&&$original['skr_code']===$code,'original method proof retained from invoice and annual source: '.$method);
    foreach(S::sources($report) as $key=>$row)$alloc[$key]=['parts'=>array_filter(A::cents($row['cents'],$weights)),'reason'=>'Synthetic first-year source allocation'];
    $editor->save(2025,0,$units,$alloc);$before=MySqlFixture::snapshot($db);
    denied(fn()=>(new BalanceSegments($db,$member))->asset(2025,1,$itemId,$weights,'hours','Synthetic',true),'member cannot split method');
    denied(fn()=>(new BalanceSegments($db,'other'))->asset(2025,1,$itemId,$weights,'hours','Synthetic',true),'other club cannot split method');
    check(MySqlFixture::snapshot($db)===$before,'denied method edits leave all rows unchanged');
    $originalPlan=$books->get($itemId);$originalYears=MySqlFixture::rows($db,'balance_years');$originalEntries=MySqlFixture::rows($db,'balance_entries');
    $editor->asset(2025,1,$itemId,$weights,'hours','Synthetic use record',true);check($segments->get(2025)['allocation']['complete'],'persisted method allocation reconciles');
    denied(fn()=>$editor->confirm(2025,2,true,'Not board'),'treasurer cannot approve own method allocation');$segments->confirm(2025,2,true,'Synthetic board approval');
    $html=(static function()use($db,$finance,$itemId):string{$_SESSION=['actor'=>$finance];$_GET=['asset_item'=>$itemId];$_POST=[];$year=2025;ob_start();require __DIR__.'/../views/Finance/Balance/balance-segments.php';return ob_get_clean();})();
    check(str_contains($html,$method==='gwg_immediate'?'Vollständig im Anschaffungsjahr (GWG)':'Anfangs höher, später geringer (degressiv)'),'readonly method uses plain language');
    check(!str_contains($html,'name="method"'),'allocation UI cannot elect a different depreciation method');
    $settlement=$sources->binding('invoice',$purchase)['snapshot']['settlement_account'];$methodMapping=['equity'=>'bs.eqLiab.equity.retainedEarnings',$settlement=>'bs.eqLiab.liab.trade.genOther'];
    $cp=$profile+['whole_business_name'=>'Synthetic shared method club','whole_book_standard'=>'HGBM'];$cp['asset_values_unchanged']=true;
    $clear=['complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[]];$cid=$svc->create(2025,BalanceTaxFiling::COMBINED_SOURCE);$svc->save($cid,1,$cp,$methodMapping,$clear,$methodMapping);
    check($tax->validate($cid,2)['valid'],'actual combined generator accepts persisted method');$bs->approve($cid,3,true);
    $values=$svc->assertCurrent($tax->get($cid));check(count($values['taxable']['asset_schedule']['items'])===1&&$values['taxable']['asset_schedule']['items'][$itemId]['method']===$method,'tax projection retains one original method asset');
    check($tax->ready($cid,4,'SEND-TEST',true,true)['id']===$cid,'method ready at offline fixture boundary only');
    check(MySqlFixture::rows($db,'balance_years')===$originalYears&&MySqlFixture::rows($db,'balance_entries')===$originalEntries&&$books->get($itemId)===$originalPlan,'method allocation never rewrites original journal or plan');
    $editor->asset(2025,3,$itemId,$weights,'hours','Synthetic new review',true);denied(fn()=>$tax->ready($cid,4,'SEND-TEST',true,true),'new usage revision invalidates filing approval');$segments->confirm(2025,4,true,'Synthetic renewed board review');
    denied(fn()=>$svc->save($cid,4,$cp,$methodMapping,$clear,$methodMapping),'changed source cannot silently replace an existing filing');
    $cid=$svc->create(2025,BalanceTaxFiling::COMBINED_SOURCE);$svc->save($cid,1,$cp,$methodMapping,$clear,$methodMapping);$tax->validate($cid,2);$bs->approve($cid,3,true);
    check($tax->send($cid,4,'dummy','123456','SEND-TEST',true,true)['state']==='sent_test','prior-year receipt simulated by offline fixture only');
    $ledger->create(2026);$books->recordYear($itemId,1,2026);
    if($method==='gwg_immediate')check((bool)$sources->preview(2026,'depreciation',$itemId.':2026')['issues']&&!$sources->binding('depreciation',$itemId.':2026'),'fully expensed GWG has no fabricated zero journal entry');
    else $import(2026,'depreciation',$itemId.':2026');
    $ledger->close(2026,$ledger->report(2026)['version'],['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic following year');
    $segments->carry(2026,0,true);$s=$segments->get(2026);$report=$ledger->report(2026);$item=$report['asset_schedule']['items'][$itemId];$parts=A::item($item,$weights);$alloc=$s['allocation']['allocations'];
    foreach(S::sources($report) as $key=>$row)if(!isset($alloc[$key])){$amounts=[];foreach($parts as $u=>$v)if($v['depreciation_cents'])$amounts[$u]=$v['depreciation_cents'];$alloc[$key]=['parts'=>$amounts,'reason'=>'Synthetic cumulative method shares'];}
    $editor->save(2026,1,$units,$alloc);check($segments->get(2026)['allocation']['complete'],'method following year reconciles including zero GWG');$segments->confirm(2026,2,true,'Synthetic next-year approval');
    check($item['depreciation_cents']===($method==='gwg_immediate'?0:3060),'correct next-year depreciation on original whole cost');
    $nid=$svc->create(2026,BalanceTaxFiling::COMBINED_SOURCE);$svc->save($nid,1,$cp,$methodMapping,array_replace($clear,['complete'=>false]),$methodMapping);$svc->carryForward($nid,2,$cid,true);
    $b=$tax->get($nid)['form_data']['balance_preparation'];$review=$b['review'];unset($review['year'],$review['journal_sha256']);$review['complete']=true;
    $svc->save($nid,3,$cp,$methodMapping,$review,$methodMapping);check($tax->validate($nid,4)['valid'],'actual generator accepts carried original method');$bs->approve($nid,5,true);
    check($tax->ready($nid,6,'SEND-TEST',true,true)['id']===$nid,'carried method retains exact approved offline draft');
    check(isset(BalanceSegmentExport::files($segments->get(2026))['bereiche/anlagenanteile.csv']),'method carry evidence exported');
    $retained[$board]=$segments->get(2026);
}
$restored=MySqlFixture::restoreBackup($db);foreach($retained as $actor=>$s)check((new BalanceSegments($restored,$actor))->get(2026)===$s,'encrypted restore retains complete method source and approval history');
echo "$checks shared-method workflow checks passed; synthetic MySQL, actual generator, offline transport fixture only\n";
}
