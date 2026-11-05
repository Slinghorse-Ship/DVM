<?php declare(strict_types=1);
namespace {
require __DIR__.'/fixtures/asset-partial-review-setup.php';
use Kulturhus\Verein\{AssetBooks,AssetDisposal,AssetPartialDisposal,AssetPartialDisposalReview,BalanceLedger,BalanceSources,Database,Documents,Finance,SettingsSchema};
use Kulturhus\Verein\Tests\MySqlFixture;
$n=0;
function check(bool $ok,string $label):void{global $n;if(!$ok)throw new \RuntimeException($label);++$n;echo "PASS $label\n";}
function keyId():string{return bin2hex(random_bytes(16));}
extract(\Kulturhus\Verein\Tests\partialReviewSetup());
$review=new AssetPartialDisposalReview($db,'admin');
$run=fn(?string $hash=null)=>$review->preview('partial',1,2025,$proof,$hash);
$reject=function(callable $call,string $label)use($db):void{
    $before=MySqlFixture::snapshot($db);$failed=false;
    try{$call();}catch(\DomainException){$failed=true;}
    check($failed,$label);check(!$db->inTransaction()&&MySqlFixture::snapshot($db)===$before,$label.' leaves no changes/transaction');
};
$reject($run,'unposted acquisition refused');
$import=function(int $year,string $type,string $id,array $options=[])use($sources,$ledger):void{
    $p=$sources->preview($year,$type,$id,$options);check(!$p['issues'],'synthetic source ready');
    $ledger->importSource($year,$ledger->report($year)['version'],$type,$id,$options,$p['hash'],true);
};
$import(2025,'invoice',$invoice,['recognised_on'=>'2025-01-15','private_paid_on'=>'']);
$before=MySqlFixture::snapshot($db);$p=$run();
check(!$p['postable']&&!$p['exportable']&&!$p['calculation']['postable']&&$p['calculation']['tax']===null,'review never grants posting, export or tax approval');
check($p['calculation']['book']['depreciation_cents']===5000&&$p['calculation']['book']['book_disposals_cents']===3000&&$p['calculation']['book']['book_closing_cents']===4000,'first-year component loss and remaining depreciation exact');
check($p['purchase']['entry_id']===$invoice&&$p['evidence']['allocation']['document_id']===$purchaseDoc,'review binds actual purchase source and accessible original');
check($run($p['hash'])===$p,'unchanged evidence produces deterministic digest');
check(MySqlFixture::snapshot($db)===$before,'successful review writes no rows and leaves annual plans untouched');
check((new AssetPartialDisposalReview($db,'writer'))->preview('partial',1,2025,$proof)===$p,'ordinary authorised treasurer needs neither superuser nor settings administration');
$reject(fn()=>$run(str_repeat('0',64)),'stale review hash refused');
$reject(fn()=>$review->preview('partial',2,2025,$proof),'stale plan version refused');
$reject(fn()=>$review->preview('partial',1,2028,$proof),'current calendar year refused');
$reject(fn()=>$review->preview('partial',1,2026,$proof),'event year mismatch refused');
foreach(['other','member','editor','reader','financeonly'] as $actor)$reject(fn()=>(new AssetPartialDisposalReview($db,$actor))->preview('partial',1,2025,$proof),'unauthorised actor '.$actor.' refused');
$reject(fn()=>$review->preview('missing',1,2025,$proof),'missing asset refused');
foreach(['complete'=>true,'no_proceeds'=>false,'kind'=>'partial_sale','plan_sha256'=>str_repeat('0',64),'revision'=>2,'sha256'=>str_repeat('0',64)] as $key=>$value)
    $reject(fn()=>$review->preview('partial',1,2025,array_replace($proof,[$key=>$value])),'invalid proof '.$key.' refused');
foreach(['cost_cents'=>12000,'residual_cents'=>1,'same_use_and_life'=>false,'confirmed'=>false,'basis'=>'quantity','revision'=>2] as $key=>$value)
    $reject(fn()=>$review->preview('partial',1,2025,array_replace($proof,['allocation'=>array_replace($proof['allocation'],[$key=>$value])])),'invalid allocation '.$key.' refused');
$otherDoc=(new Documents($db,'other'))->upload('foreign.png',$png,['title'=>'Synthetic other club','kind'=>'invoice','scope'=>'finance'],keyId());
$unlinked=$docs->upload('unlinked.png',$png,['title'=>'Synthetic unrelated evidence','kind'=>'invoice','scope'=>'finance'],keyId());
foreach([$otherDoc,$unlinked] as $id)$reject(fn()=>$review->preview('partial',1,2025,array_replace($proof,['document_id'=>$id])),'foreign/unlinked disposal refused');
$reject(fn()=>$review->preview('partial',1,2025,array_replace($proof,['allocation'=>array_replace($proof['allocation'],$ref($unlinked))])),'unrelated cost evidence refused');
// Corruptions below exist only in this disposable synthetic database and are restored individually.
$mutation=function(string $sql,array $args,string $undo,array $undoArgs,string $label,?callable $attempt=null)use($db,&$run,$reject):void{
    $db->prepare($sql)->execute($args);try{$reject($attempt??$run,$label);}finally{$db->prepare($undo)->execute($undoArgs);}
    $run();check(true,$label.' restored baseline valid');
};
// Model bytes damaged in an old backup, not an application edit. The native
// update guard must first reject this write and must be restored identically.
// MySqlFixture rejects runtime databases; no production trigger is touched.
$corruptFile=function(string $id,string $label,?callable $attempt=null)use($db,$mutation,$png):void{
    MySqlFixture::dsn($db);
    $update=$db->prepare('UPDATE document_files SET bytes=? WHERE club_id=? AND document_id=? AND revision=1');
    $blocked=false;try{$update->execute(['corrupt','a',$id]);}catch(\PDOException $e){if($e->getCode()!=='45000')throw $e;$blocked=true;}
    check($blocked,'native document immutability enforced before corruption fixture');
    $definition=$db->query('SHOW CREATE TRIGGER document_files_no_update')->fetch()['SQL Original Statement'];
    $db->exec('DROP TRIGGER document_files_no_update');
    try{
        $mutation('UPDATE document_files SET bytes=? WHERE club_id=? AND document_id=? AND revision=1',['corrupt','a',$id],
            'UPDATE document_files SET bytes=? WHERE club_id=? AND document_id=? AND revision=1',[$png,'a',$id],$label,$attempt);
    }finally{$db->exec($definition);}
    check($db->query('SHOW CREATE TRIGGER document_files_no_update')->fetch()['SQL Original Statement']===$definition,'native document immutability restored identically');
};
$mutation('UPDATE documents SET state=? WHERE club_id=? AND id=?',['archived','a',$disposalDoc],
        'UPDATE documents SET state=? WHERE club_id=? AND id=?',['active','a',$disposalDoc],'withdrawn evidence refused');
$corruptFile($disposalDoc,'actual evidence bytes checked beyond metadata hash');
$separateCost=$docs->upload('cost.png',$png,['title'=>'Synthetic separate component evidence','kind'=>'invoice','scope'=>'finance','links'=>['item:partial']],keyId());
$separateProof=array_replace($proof,['allocation'=>array_replace($proof['allocation'],$ref($separateCost))]);
check($review->preview('partial',1,2025,$separateProof)['evidence']['allocation']['document_id']===$separateCost,'separate item-linked component evidence supported');
$corruptFile($purchaseDoc,'purchase bytes checked even with separate cost proof',
    fn()=>$review->preview('partial',1,2025,$separateProof));
$mutation('UPDATE accounting_positions SET description=? WHERE club_id=? AND entry_id=?',['Changed synthetic original','a',$invoice],
    'UPDATE accounting_positions SET description=? WHERE club_id=? AND entry_id=?',['Synthetic components','a',$invoice],'changed purchase position refused');
$mutation('UPDATE inventory_items SET ownership=? WHERE club_id=? AND id=?',['third_party','a','partial'],
    'UPDATE inventory_items SET ownership=? WHERE club_id=? AND id=?',['club','a','partial'],'borrowed property refused');
$binding=$sources->binding('invoice',$invoice);$entry=$binding['entry_ids'][0];
$mutation('UPDATE balance_entries SET evidence=? WHERE club_id=? AND id=?',['Corrupt synthetic evidence','a',$entry],
    'UPDATE balance_entries SET evidence=? WHERE club_id=? AND id=?',[$db->query("SELECT evidence FROM balance_entries WHERE id='".$entry."'")->fetchColumn(),'a',$entry],'actual journal evidence checked');
$euer=array_replace($tax,['accounting_basis'=>'euer']);
$mutation("UPDATE club_settings SET payload=? WHERE club_id='a' AND section='tax'",[json_encode($euer)],
    "UPDATE club_settings SET payload=? WHERE club_id='a' AND section='tax'",[json_encode($tax)],'EÜR never treated as balance');
$mutation("UPDATE club_settings SET payload=? WHERE club_id='a' AND section='tax'",[json_encode(array_replace($tax,['fiscal_start'=>'2025-07-01']))],
    "UPDATE club_settings SET payload=? WHERE club_id='a' AND section='tax'",[json_encode($tax)],'non-calendar financial year refused');
foreach([123,'bad-01-01','2025-02-30'] as $badFiscal)$mutation("UPDATE club_settings SET payload=? WHERE club_id='a' AND section='tax'",[json_encode(array_replace($tax,['fiscal_start'=>$badFiscal]))],
    "UPDATE club_settings SET payload=? WHERE club_id='a' AND section='tax'",[json_encode($tax)],'malformed financial year refused');
putenv('VEREIN_BALANCE_WORKSPACE=0');try{$reject($run,'disabled workspace refused');}finally{putenv('VEREIN_BALANCE_WORKSPACE=1');}
$db->beginTransaction();try{
    $blocked=false;try{$run();}catch(\DomainException){$blocked=true;}
    check($blocked&&$db->inTransaction(),'caller transaction not committed or rolled back');
}finally{$db->rollBack();}
// A new revision with identical bytes is still a different original review.
$docs->newVersion($disposalDoc,1,'disposal-v2.png',$png,'Synthetic evidence correction');
$reject($run,'new evidence revision invalidates old review');
$proof=array_replace($proof,$ref($disposalDoc));
$fresh=$review->preview('partial',1,2025,$proof);check($fresh['hash']!==$p['hash'],'changed document revision changes digest');
$reject(fn()=>$review->preview('partial',1,2025,$proof,$p['hash']),'old digest rejected with refreshed evidence');
// Next-year preview requires the full immutable previous-year chain, not a made-up opening.
$books->recordYear('partial',1,2025);
$proof['date']='2026-06-15';
$reject(fn()=>$review->preview('partial',1,2026,$proof),'missing open next year refused');
$import(2025,'depreciation','partial:2025');
$ledger->close(2025,$ledger->report(2025)['version'],['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic reviewed year');
$ledger->create(2026);
$p2=$review->preview('partial',1,2026,$proof);
check(count($p2['annual'])===1&&is_string($p2['annual'][0]['closed_sha256']),'previous confirmed annual value and closed balance hash bound');
check($p2['calculation']['book']['book_opening_cents']===6000&&$p2['calculation']['book']['depreciation_cents']===5000&&$p2['calculation']['book']['book_disposals_cents']===1000,'later year uses actual original opening and partial loss');
$run=fn(?string $hash=null)=>$review->preview('partial',1,2026,$proof,$hash);
$mutation("UPDATE asset_book_years SET closing_cents=closing_cents+1 WHERE club_id='a' AND item_id='partial' AND year=2025",[],
    "UPDATE asset_book_years SET closing_cents=closing_cents-1 WHERE club_id='a' AND item_id='partial' AND year=2025",[],'one-cent annual corruption refused');
$mutation("UPDATE balance_years SET closed_sha256=? WHERE club_id='a' AND tax_year=2025",[str_repeat('0',64)],
    "UPDATE balance_years SET closed_sha256=? WHERE club_id='a' AND tax_year=2025",[$p2['annual'][0]['closed_sha256']],'damaged closed balance refused');
$mutation("UPDATE balance_years SET version=version+1 WHERE club_id='a' AND tax_year=2026",[],
    "UPDATE balance_years SET version=version-1 WHERE club_id='a' AND tax_year=2026",[],'changed journal version invalidates old digest',fn()=>$run($p2['hash']));
$before=MySqlFixture::snapshot($db);
$reject(fn()=>$books->recordYear('partial',1,2026,['disposal_kind'=>'partial_scrap']),'existing posting route still rejects incomplete partial workflow');
check(MySqlFixture::snapshot($db)===$before,'review cannot accidentally enable journal posting');
// Fully depreciated assets retain their cost history. A zero-AfA year needs
// its confirmed asset/closing record, but no fabricated zero-euro journal row.
$books->recordYear('partial',1,2026);
$reject(fn()=>$review->preview('partial',1,2026,$proof),'already confirmed event year refused');
$import(2026,'depreciation','partial:2026');
$ledger->close(2026,$ledger->report(2026)['version'],['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic second-year review');
$ledger->create(2027);
$books->recordYear('partial',1,2027);
check($sources->binding('depreciation','partial:2027')===null,'zero depreciation creates no artificial source binding');
$ledger->close(2027,$ledger->report(2027)['version'],['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic fully depreciated year');
$ledger->create(2028);
$GLOBALS['partialReviewToday']='2029-01-15 UTC';$proof['date']='2028-06-15';
$run=fn(?string $hash=null)=>$review->preview('partial',1,2028,$proof,$hash);
$before=MySqlFixture::snapshot($db);$zero=$run();
check(count($zero['annual'])===3&&$zero['annual'][2]['source_hash']===null&&$zero['annual'][2]['history_sha256']===null,'zero-AfA year retains verified closing without invented journal history');
check($zero['calculation']['book']['depreciation_cents']===0&&$zero['calculation']['book']['book_disposals_cents']===0&&$zero['calculation']['book']['disposals_cents']===4000&&$zero['calculation']['book']['accumulated_disposals_cents']===4000,'fully depreciated partial disposal retains gross costs without a new loss');
check(MySqlFixture::snapshot($db)===$before&&!$zero['postable'],'zero-value preview remains read-only and gated');
$mutation("UPDATE asset_book_years SET depreciation_cents=1 WHERE club_id='a' AND item_id='partial' AND year=2027",[],
    "UPDATE asset_book_years SET depreciation_cents=0 WHERE club_id='a' AND item_id='partial' AND year=2027",[],'fabricated zero-year depreciation refused');
check(!MySqlFixture::foreignKeyErrors($db),'all synthetic foreign keys intact');
echo "$n partial disposal evidence checks passed; no production database, posting or sending\n";
}
