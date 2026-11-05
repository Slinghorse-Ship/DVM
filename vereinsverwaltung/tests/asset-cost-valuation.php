<?php declare(strict_types=1);
require __DIR__.'/fixtures/cost-assets-setup.php';require __DIR__.'/fixtures/partial-ui.php';
use Kulturhus\Verein\{AssetBooks,AssetTypes,AssetValuation,AssetValuationEvidence,BalanceAssetSchedule,BalanceCostAssetSources,BalanceTaxValues};
use Kulturhus\Verein\Tests\MySqlFixture;
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$n=0;function check(bool $ok,string $why):void{global $n;if(!$ok)throw new RuntimeException($why);$n++;echo "PASS $why\n";}
function denied(callable $fn,string $why):void{try{$fn();}catch(DomainException){check(true,$why);return;}throw new RuntimeException($why);}
extract(Kulturhus\Verein\Tests\costSetup());
check(!array_diff(['02150','02350'],AssetTypes::codes('land'))&&!array_diff(['09000','09200'],AssetTypes::codes('financial')),'original source reviewed cost capabilities remain available');
check(AssetTypes::depreciationCode('02150')===null&&AssetTypes::depreciationCode('09200')===null,'cost assets have no fabricated ordinary expense account');
$land=$make('land','02150','land');$financial=$make('financial','09200','financial');$proofDoc=$document('Synthetic signed impairment and recovery');$ref=$evidence->reference($proofDoc);
$event=static fn(string $kind,string $on,int $book,int $tax,array $extra=[])=>['id'=>bin2hex(random_bytes(16)),'on'=>$on,'kind'=>$kind,'book_cents'=>$book,'tax_cents'=>$tax,
 'reason'=>'Synthetic evidenced '.$kind,'legal_basis'=>'Synthetic HGB 253 / EStG 6 decision','document'=>$ref,'confirmed'=>true]+$extra;
$landEvents=[$event('impairment','2025-06-15',9000,12000,['permanent'=>true,'tax_election'=>false])];
$financialEvents=[$event('impairment','2025-05-01',8000,12000,['permanent'=>false,'tax_election'=>false]),
 $event('impairment','2025-06-01',8000,9000,['permanent'=>true,'tax_election'=>true]),
 $event('disposal','2025-07-01',2000,2200,['cost_cents'=>3000,'allocation_document'=>$ref,'no_proceeds'=>true]),
 $event('disposal','2025-09-01',1000,1000,['cost_cents'=>1000,'allocation_document'=>$ref,'no_proceeds'=>true])];
denied(fn()=>$books->save('land',1,array_replace($land['input'],['valuation_document_id'=>'x'])),'missing original document rejected');
$bad=$land['input'];$bad['method']='linear_monthly';$bad+=['months'=>'60','residual'=>'0'];
denied(fn()=>AssetBooks::plan($bad),'land cannot use an invented ordinary life');
denied(fn()=>(new AssetBooks($db,'reader'))->recordYear('land',1,2025),'reader cannot confirm a cost valuation');
denied(fn()=>(new AssetBooks($db,'other'))->get('land'),'foreign club cannot inspect cost plan');
$_GET=['item'=>'land','year'=>'2025'];$_POST=[];$_SESSION=['actor'=>'admin'];$finance=new Kulturhus\Verein\Finance($db,'admin');
$before=MySqlFixture::snapshot($db);ob_start();require __DIR__.'/../views/Finance/Accounting/finance-book-values.php';$html=ob_get_clean();
check(str_contains($html,'valuation[0][kind]')&&str_contains($html,'Steuerwert')&&!str_contains($html,'name="disposal_kind"'),'actual cost page offers separate evidence-based valuation controls');
check(MySqlFixture::snapshot($db)===$before,'cost page is read only');
$options=['recognised_on'=>'2025-02-15','private_paid_on'=>''];
$import('invoice',$land['invoice'],$options);$import('invoice',$financial['invoice'],$options);
$missing=(new BalanceAssetSchedule($db,'admin'))->build(2025);check((bool)$missing['issues'],'unconfirmed no-depreciation assets still prevent closing');
$before=MySqlFixture::snapshot($db);$invalid=$landEvents;$invalid[0]['tax_cents']=13000;
denied(fn()=>$books->recordYear('land',1,2025,['valuation_events'=>json_encode($invalid)]),'invalid year valuation rejected');
check(MySqlFixture::snapshot($db)===$before,'invalid annual confirmation is atomic');
$books->recordYear('land',1,2025,['valuation_events'=>json_encode($landEvents)]);$books->recordYear('financial',1,2025,['valuation_events'=>json_encode($financialEvents)]);
$p=$sources->preview(2025,'depreciation','financial:2025');check(!$p['issues']&&count($p['vouchers'])===3,'multiple dated book events form original journal vouchers, tax-only event posts no book expense');
check($p['accounts']['src_asset_valuation_68970']['skr_code']==='68970','financial disposal uses original rest-book-value account');
$import('depreciation','land:2025');$import('depreciation','financial:2025');
$schedule=(new BalanceAssetSchedule($db,'admin'))->build(2025);
check(!$schedule['issues']&&$schedule['totals']['book_closing_cents']===14000&&$schedule['totals']['cost_closing_cents']===20000,'annual journal reconciles gross cost, multiple disposals and closing values');
check($schedule['items']['financial']['valuation']['tax']['book_closing_cents']===5800,'separate tax values are preserved without changing book journal');
$accounts=$ledger->report(2025)['calculation']['accounts'];$bad=$accounts;$bad['src_asset_valuation_72010']['amount_cents']++;
denied(fn()=>BalanceCostAssetSources::checkMovements($bad,$schedule['items']),'counterpart amount cannot be silently changed');
$bad=$accounts;$bad[$schedule['items']['land']['account']]['debit_cents']++;
denied(fn()=>BalanceCostAssetSources::checkFixed($bad,$schedule['items']),'unrelated gross asset movement rejected even at same net closing value');
Kulturhus\Verein\Tests\costCorruptOriginal($db,$proofDoc,fn()=>check((bool)$sources->preview(2025,'depreciation','land:2025')['issues'],'actual original bytes rechecked after confirmation'));
denied(fn()=>$books->save('land',1,$land['input']),'confirmed original plan stays immutable');
$ledger->close(2025,$ledger->report(2025)['version'],['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic cost year');
$firstReport=$ledger->report(2025);$firstReview=['year'=>2025,'journal_sha256'=>$firstReport['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[]];
$taxFirst=BalanceTaxValues::build($firstReport,$firstReview);
check($taxFirst['result_difference_cents']===3800&&$taxFirst['asset_tax']['tax_schedule']['totals']['book_closing_cents']===17800,'tax-only decisions enter actual tax ledger and retained tax asset schedule');
$badReview=$firstReview;$badReview['current_adjustments']=$taxFirst['asset_tax']['generated_current'];
denied(fn()=>BalanceTaxValues::build($firstReport,$badReview),'manual duplicate cost tax difference rejected');
$ledger->create(2026);
$recovery=[$event('writeup','2026-03-01',11000,12000,['permanent'=>false,'tax_election'=>false])];
$books->recordYear('land',1,2026,['valuation_events'=>json_encode($recovery)]);
$full=[$event('writeup','2026-03-01',6000,6800,['permanent'=>false,'tax_election'=>false]),$event('disposal','2026-04-01',6000,6800,['cost_cents'=>8000,'allocation_document'=>$ref,'no_proceeds'=>true])];
$books->recordYear('financial',1,2026,['valuation_events'=>json_encode($full)]);
$import('depreciation','land:2026',[],2026);$import('depreciation','financial:2026',[],2026);
$next=(new BalanceAssetSchedule($db,'admin'))->build(2026);
check(!$next['issues']&&$next['totals']['book_opening_cents']===14000&&$next['totals']['book_closing_cents']===11000,'next year carries actual retained values and separately journals recovery and full release');
check($next['items']['financial']['cost_closing_cents']===0&&$next['items']['financial']['valuation']['tax']['book_closing_cents']===0,'full release clears original cost and both valuations');
check($sources->binding('depreciation','financial:2025')['snapshot']['valuation']===$next['items']['financial']['valuation']['source']['previous'],'actual immutable annual valuation predecessor retained');
$ledger->close(2026,$ledger->report(2026)['version'],['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic following valuation year');
$nextReport=$ledger->report(2026);$carry=BalanceTaxValues::carryForward($firstReport,$firstReview,$nextReport);
$taxNext=BalanceTaxValues::build($nextReport,['year'=>2026,'journal_sha256'=>$nextReport['closed_sha256'],'complete'=>true,'opening_adjustments'=>[$carry['entry']],'current_adjustments'=>[]]);
check($taxNext['asset_tax']['tax_schedule']['totals']['book_opening_cents']===17800&&$taxNext['asset_tax']['tax_schedule']['totals']['book_closing_cents']===12000,'actual tax predecessor carried once through recovery and full disposal');
check($taxNext['result_difference_cents']===-2800,'current tax result excludes old opening valuation difference');
echo "$n cost asset lifecycle checks passed; synthetic MySQL only\n";
