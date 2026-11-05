<?php declare(strict_types=1);
require __DIR__.'/fixtures/asset-partial-review-setup.php';
require __DIR__.'/../src/Finance/Assets/AssetPartialDisposalRecords.php';
use Kulturhus\Verein\{AssetPartialDisposalReview,AssetPartialDisposalRecords,BalanceAssetSchedule,BalanceLedger,BalanceTaxValues};
use Kulturhus\Verein\Tests\MySqlFixture;
require __DIR__.'/fixtures/partial-ui.php';
require_once __DIR__.'/../src/Finance/Balance/BalanceTaxValues.php';
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$n=0;
function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
function exportCheck(array $values):void{
    $python=getenv('VEREIN_PYTHON');if(!$python||!is_file($python))throw new RuntimeException('Synthetic Python interpreter required');
    $p=proc_open([$python,'-B',__DIR__.'/fixtures/partial-export.py'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($p))throw new RuntimeException('Synthetic export process unavailable');
    fwrite($pipes[0],json_encode($values,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($p);
    if($exit!==0||$err!=='')throw new RuntimeException('Synthetic export: '.$err.' '.$out);
    $r=json_decode($out,true,16,JSON_THROW_ON_ERROR);check($r['environment']==='test'&&$r['transmission']==='unavailable'&&$r['asset_items']===1,'actual MySQL annual values generate independently verified XBRL without transport');
}
extract(\Kulturhus\Verein\Tests\partialReviewSetup());
$reject=function(callable $fn,string $label,string $exception=DomainException::class)use($db):void{
    $before=MySqlFixture::snapshot($db);$failed=false;try{$fn();}catch(Throwable $e){if(!$e instanceof $exception)throw $e;$failed=true;}
    check($failed,$label);check(!$db->inTransaction()&&MySqlFixture::snapshot($db)===$before,$label.' is atomic');
};
$import=function(int $year,string $type,string $id,array $options=[])use($ledger,$sources):void{
    $p=$sources->preview($year,$type,$id,$options);if($p['issues'])throw new RuntimeException(implode(' ',$p['issues']));
    $ledger->importSource($year,$ledger->report($year)['version'],$type,$id,$options,$p['hash'],true);
};
$import(2025,'invoice',$invoice,['recognised_on'=>'2025-01-15','private_paid_on'=>'']);
$review=(new AssetPartialDisposalReview($db,'writer'))->preview('partial',1,2025,$proof);
$records=new AssetPartialDisposalRecords($db,'writer');
$form=['action'=>'asset-partial-prepare','item'=>'partial','year'=>'2025','version'=>'1','component_cost'=>'40','component_residual'=>'0',
    'component_document'=>$purchaseDoc,'disposal_document'=>$disposalDoc,'disposal_date'=>'2025-06-15','disposal_reason'=>'Synthetic component <script>alert(1)</script>','partial_confirmed'=>'1'];
$action=\Kulturhus\Verein\FinanceWorkflowActions::handle($db,'writer',$form);
$r=$records->get($action['route']['partial_review']);
check($r['item_id']==='partial'&&$action['route']['year']==='2025','HTTP action resolves proof and opens exact review');
$before=MySqlFixture::snapshot($db);
check(\Kulturhus\Verein\FinanceWorkflowActions::handle($db,'writer',$form)===$action&&MySqlFixture::snapshot($db)===$before,'repeated calculate action does not duplicate preparation');
$html=partialRender($db,'writer',2025,$r['id']);
check(str_contains($html,'Teilabgang und Jahreswert buchen')&&str_contains($html,'synthetic-csrf'),'actual view exposes explicit protected booking');
check(!str_contains($html,'<script>alert(1)</script>')&&str_contains($html,'&lt;script&gt;'),'user evidence escaped in actual view');
check(!str_contains($html,'value="asset-book-year"')&&!str_contains($html,'value="asset-book-save"'),'review hides competing annual and original-plan forms');
check(substr_count($html,'Verbleibender Buchwert am Jahresende')===1&&str_contains($html,'15.06.2025'),'review shows only the recalculated closing value and German date');
check(str_contains($html,'type="hidden" name="component_residual" value="0,00"')&&!str_contains($html,'Geplanter Restwert dieses Teils'),'zero original residual requires no redundant amount entry');
check(!str_contains(partialRender($db,'reader',2025),'name="partial_book_confirmed"'),'reader sees no booking action');
check(str_contains($html,'Zurück zum Gegenstand'),'review keeps a non-posting return path');
file_put_contents(__DIR__.'/partial-ui.html',$html);
$book=fn()=>$records->book($r['id'],$r['sha256'],true);
$reject(fn()=>$records->book($r['id'],$r['sha256'],false),'confirmation required');
$reject(fn()=>$records->book($r['id'],str_repeat('0',64),true),'changed review hash refused');
foreach(['reader','member','editor','other','financeonly'] as $actor)$reject(fn()=>(new AssetPartialDisposalRecords($db,$actor))->book($r['id'],$r['sha256'],true),'role '.$actor.' cannot book protected partial disposal');
$reject(fn()=>$ledger->importSourceWithinTransaction(2025,1,'depreciation','partial:2025',[],'',true),'joined source import requires caller transaction');
foreach(['balance_entries','balance_sources','balance_source_history','audit_events'] as $table){
    $db->exec("CREATE TRIGGER partial_posting_fail BEFORE INSERT ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic atomic posting failure'");
    try{$reject($book,'failure in '.$table.' retains neither annual nor journal',PDOException::class);}finally{$db->exec('DROP TRIGGER partial_posting_fail');}
}
$originals=MySqlFixture::rows($db,'document_files');$plans=MySqlFixture::rows($db,'asset_book_plans');
$route=\Kulturhus\Verein\FinanceWorkflowActions::handle($db,'writer',['action'=>'asset-partial-book','partial_review'=>$r['id'],'partial_sha256'=>$r['sha256'],'partial_book_confirmed'=>'1']);
check($route['route']['item']==='partial'&&count($sources->binding('depreciation','partial:2025')['entry_ids'])===1,'single confirmation creates annual and journal together');
check(MySqlFixture::rows($db,'document_files')===$originals&&MySqlFixture::rows($db,'asset_book_plans')===$plans,'original evidence and acquisition plan untouched');
$b=$sources->binding('depreciation','partial:2025');$p=$sources->preview(2025,'depreciation','partial:2025');
check(!$p['issues']&&$p['hash']===$b['source_hash'],'posted source can be independently revalidated');
check($b['snapshot']['partial_review']===['id'=>$r['id'],'sha256'=>$r['sha256']],'journal binds exact immutable review');
$reject($book,'repeated adoption never duplicates posting');
$s=(new BalanceAssetSchedule($db,'admin'))->build(2025);check(!$s['issues'],'year of partial disposal reconciles');
$v=$s['items']['partial'];
check([$v['additions_cents'],$v['disposals_cents'],$v['depreciation_cents'],$v['accumulated_disposals_cents'],$v['book_disposals_cents'],$v['cost_closing_cents'],$v['book_closing_cents']] === [12000,4000,5000,1000,3000,8000,4000],'only documented component is removed and remaining component depreciates');
check($ledger->report(2025)['calculation']['annual_result_cents']===-8000,'result comprises annual depreciation plus removed book value');
$checks=['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true];
$ledger->close(2025,$ledger->report(2025)['version'],$checks,'Synthetic complete close');
$closed=$ledger->report(2025);
$taxReview=['year'=>2025,'journal_sha256'=>$closed['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[]];
exportCheck(BalanceTaxValues::build($closed,$taxReview));
$dep=array_key_first(array_filter($closed['accounts'],fn($a)=>$a['skr_code']==='62200'));
$taxReview['asset_tax_plans']=['partial'=>['months'=>48,'depreciation_account'=>$dep,'reason'=>'Synthetic separate tax useful life','confirmed'=>true]];
$taxValues=BalanceTaxValues::build($closed,$taxReview);exportCheck($taxValues);
check($taxValues['asset_tax']['tax_schedule']['items']['partial']['book_closing_cents']===6000,'actual journal yields separate tax residual after component disposal');
$ledger->create(2026);
$html=partialRender($db,'writer',2026);
check(str_contains($html,'Jahresstand 2026 feststellen')===(2026<(int)date('Y'))&&!str_contains($html,'name="disposal_kind"'),'following year UI respects actual calendar and refuses a second unsupported disposal');
check(str_contains($html,'Teilweise ausgemustert'),'UI distinguishes partial disposal from complete disposal');
$reject(fn()=>$books->recordYear('partial',1,2026,['disposal_kind'=>'scrap']),'second disposal requires separate handling');
$books->recordYear('partial',1,2026);$import(2026,'depreciation','partial:2026');
$s=(new BalanceAssetSchedule($db,'admin'))->build(2026);check(!$s['issues'],'following year reconciles with retained partial event');
$v=$s['items']['partial'];
check([$v['cost_opening_cents'],$v['book_opening_cents'],$v['disposals_cents'],$v['depreciation_cents'],$v['cost_closing_cents'],$v['book_closing_cents']] === [8000,4000,0,4000,8000,0],'following year retains only remaining original cost and depreciation');
check($ledger->report(2025)===$closed,'following-year posting cannot rewrite closed previous year');
$ledger->close(2026,$ledger->report(2026)['version'],$checks,'Synthetic next year close');
$nextReport=$ledger->report(2026);$carry=BalanceTaxValues::carryForward($closed,$taxReview,$nextReport);
$nextReview=array_replace($taxReview,['year'=>2026,'journal_sha256'=>$nextReport['closed_sha256'],'opening_adjustments'=>$carry['entry']?[$carry['entry']]:[]]);
$nextValues=BalanceTaxValues::build($nextReport,$nextReview);exportCheck($nextValues);
check($nextValues['asset_tax']['tax_schedule']['items']['partial']['book_closing_cents']===4000,'tax carry keeps remaining component without duplicate opening adjustment');
$ledger->create(2027);$books->recordYear('partial',1,2027);
$s=(new BalanceAssetSchedule($db,'admin'))->build(2027);check(!$s['issues'],'fully depreciated remaining asset stays in register without fake journal');
check($s['items']['partial']['cost_closing_cents']===8000&&$s['items']['partial']['book_closing_cents']===0,'historical cost remains after depreciation ends');
$ledger->close(2027,$ledger->report(2027)['version'],$checks,'Synthetic zero-value year close');
check($ledger->report(2027)['state']==='closed','zero-depreciation following year closes without fictitious import');
check(!MySqlFixture::foreignKeyErrors($db),'all references remain tenant-consistent');
echo "$n partial disposal posting checks passed; synthetic MySQL only\n";
