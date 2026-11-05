<?php declare(strict_types=1);
require __DIR__.'/fixtures/asset-partial-review-setup.php';
require __DIR__.'/../src/Finance/Assets/AssetPartialDisposalRecords.php';
use Kulturhus\Verein\{AssetPartialDisposal,AssetPartialDisposalReview,AssetPartialDisposalRecords,Database};
use Kulturhus\Verein\Tests\MySqlFixture;
$n=0;
function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
function keyId():string{return bin2hex(random_bytes(16));}
extract(\Kulturhus\Verein\Tests\partialReviewSetup());
$unchanged=MySqlFixture::snapshot($db);Database::migrate($db);
check(MySqlFixture::snapshot($db)===$unchanged,'repeat additive migration leaves all rows unchanged');
$reject=function(callable $call,string $label)use($db):void{
    $before=MySqlFixture::snapshot($db);$failed=false;try{$call();}catch(DomainException){$failed=true;}
    check($failed,$label);check(!$db->inTransaction()&&MySqlFixture::snapshot($db)===$before,$label.' leaves no partial writes or transaction');
};
$sqlReject=function(callable $call,string $label,string $state)use($db):void{
    $before=MySqlFixture::snapshot($db);$failed=false;try{$call();}catch(PDOException $e){if($e->getCode()!==$state)throw $e;$failed=true;}
    check($failed&&MySqlFixture::snapshot($db)===$before,$label);
};
$options=['recognised_on'=>'2025-01-15','private_paid_on'=>''];$purchase=$sources->preview(2025,'invoice',$invoice,$options);
$ledger->importSource(2025,$ledger->report(2025)['version'],'invoice',$invoice,$options,$purchase['hash'],true);
$review=new AssetPartialDisposalReview($db,'writer');$p=$review->preview('partial',1,2025,$proof);
$records=new AssetPartialDisposalRecords($db,'writer');$key=keyId();
$save=fn()=>$records->save('partial',1,2025,$proof,$p['hash'],0,$key,true);
$reject(fn()=>$review->previewWithinTransaction('partial',1,2025,$proof,$p['hash']),'joined review needs an existing transaction');
$db->beginTransaction();try{
    $before=MySqlFixture::snapshot($db);check($review->previewWithinTransaction('partial',1,2025,$proof,$p['hash'])===$p&&$db->inTransaction(),'joined review retains caller transaction');
    $failed=false;try{$review->previewWithinTransaction('partial',1,2025,$proof,str_repeat('0',64));}catch(DomainException){$failed=true;}
    check($failed&&$db->inTransaction()&&MySqlFixture::snapshot($db)===$before,'joined failure neither commits nor rolls back caller work');
    $failed=false;try{$save();}catch(DomainException){$failed=true;}check($failed&&$db->inTransaction(),'record save cannot nest a caller transaction');
}finally{$db->rollBack();}
$reject(fn()=>$records->save('partial',1,2025,$proof,$p['hash'],0,$key,false),'explicit confirmation required');
$reject(fn()=>$records->save('partial',1,2025,$proof,str_repeat('0',64),0,$key,true),'stale review cannot be stored');
$reject(fn()=>$records->save('partial',1,2025,$proof,$p['hash'],0,'bad',true),'invalid request identity refused');
foreach(['reader','member','editor','other','financeonly'] as $actor)$reject(fn()=>(new AssetPartialDisposalRecords($db,$actor))->save('partial',1,2025,$proof,$p['hash'],0,$key,true),'actor '.$actor.' cannot prepare this asset');
$financialState=function()use($db):array{
    $out=[];foreach(['asset_book_plans','asset_book_years','asset_book_history','balance_years','balance_entries','balance_sources','balance_source_history','finance_entries','finance_transactions','accounting_positions','documents','document_files','document_history','asset_sale_links'] as $table)$out[$table]=MySqlFixture::rows($db,$table);return $out;
};
$financial=$financialState();
$db->exec("CREATE TRIGGER partial_test_fail_audit BEFORE INSERT ON audit_events FOR EACH ROW BEGIN IF NEW.action='finance.asset.partial.prepared' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic partial review audit failure'; END IF; END");
try{$sqlReject($save,'audit failure rolls back record and request identity','45000');}finally{$db->exec('DROP TRIGGER partial_test_fail_audit');}
$r=$save();$id=$r['id'];
check($r['revision']===1&&$r['previous_sha256']===null&&$r['state']==='prepared'&&!$r['postable']&&!$r['exportable'],'stored preparation never claims a booking or export');
check($r['review']===$p&&$r['created_by']==='writer','ordinary treasurer retains exact reviewed evidence');
check($financialState()===$financial,'saving preparation leaves all accounting, asset and original-document tables untouched');
$before=MySqlFixture::snapshot($db);check($save()===$r&&MySqlFixture::snapshot($db)===$before,'exact retry creates no duplicate record or audit');
$secondDb=MySqlFixture::existing(MySqlFixture::dsn($db));
check((new AssetPartialDisposalRecords($secondDb,'writer'))->save('partial',1,2025,$proof,$p['hash'],0,$key,true)===$r,'retry from a separate database connection returns same record');$secondDb=null;
check((new AssetPartialDisposalRecords($db,'reader'))->get($id)['id']===$id,'finance reader with original-document rights may read retained preparation');
foreach(['member','editor','other','financeonly'] as $actor)$reject(fn()=>(new AssetPartialDisposalRecords($db,$actor))->get($id),'actor '.$actor.' cannot read protected preparation');
$reject(fn()=>(new AssetPartialDisposalRecords($db,'reader'))->forecast($id,2026),'read permission is not renewed review authority');
$reject(fn()=>(new AssetPartialDisposalRecords($db,'admin'))->save('partial',1,2025,$proof,$p['hash'],0,$key,true),'request cannot be replayed under another actor');
$changed=array_replace($proof,['reason'=>'Another confirmed reason']);
$reject(fn()=>$records->save('partial',1,2025,$changed,$p['hash'],0,$key,true),'request identity cannot conceal changed proof');
$reject(fn()=>$records->save('partial',1,2025,$proof,$p['hash'],0,keyId(),true),'parallel stale base revision refused');
$reject(fn()=>$records->save('partial',1,2025,$proof,$p['hash'],1,keyId(),true),'same review cannot be appended twice');
$before=MySqlFixture::snapshot($db);
foreach([2025=>[0,5000,3000,4000],2026=>[4000,4000,0,0],2027=>[0,0,0,0]] as $year=>$amounts){
    $f=$records->forecast($id,$year);$b=$f['calculation']['book'];
    check([$b['book_opening_cents'],$b['depreciation_cents'],$b['book_disposals_cents'],$b['book_closing_cents']]===$amounts,'retained forecast exact '.$year);
    check(!$f['postable']&&!$f['exportable']&&$f['calculation']['tax']===null,'forecast is not posting or separate-tax approval '.$year);
}
check(MySqlFixture::snapshot($db)===$before,'forecasts leave every database row unchanged');
$reject(fn()=>$records->forecast($id,2024),'forecast before partial event refused');
$sqlReject(fn()=>$db->prepare('UPDATE asset_partial_reviews SET payload=payload WHERE club_id=? AND id=?')->execute(['a',$id]),'native update guard protects even no-op change','45000');
$sqlReject(fn()=>$db->prepare('DELETE FROM asset_partial_reviews WHERE club_id=? AND id=?')->execute(['a',$id]),'native deletion guard retains history','45000');
$sqlReject(fn()=>$db->prepare('INSERT INTO asset_partial_reviews SELECT ?,?,item_id,tax_year,revision,?,request_sha256,payload,sha256 FROM asset_partial_reviews WHERE club_id=? AND id=?')->execute(['b',keyId(),keyId(),'a',$id]),'native foreign keys prevent cross-club asset reference','23000');

// A corrected preparation is a new immutable revision; earlier records remain readable.
$proof2=array_replace($proof,['allocation'=>array_replace($proof['allocation'],['cost_cents'=>3000])]);
$p2=$review->preview('partial',1,2025,$proof2);$key2=keyId();
$r2=$records->save('partial',1,2025,$proof2,$p2['hash'],1,$key2,true);
check($r2['revision']===2&&$r2['previous_sha256']===$r['sha256'],'new preparation chains to previous immutable digest');
check(!$records->get($id)['is_latest']&&$records->get($id)['review']===$p,'superseded evidence stays readable and unchanged');
$reject(fn()=>$records->forecast($id,2026),'superseded preparation cannot produce a current forecast');
check($records->forecast($r2['id'],2026)['calculation']['book']['book_opening_cents']===4500,'revised forecast uses only new documented component costs');
check(!$save()['is_latest'],'retry of old saved request does not promote it to latest');
$mutation=function(string $sql,array $args,string $undo,array $undoArgs,callable $attempt,string $label)use($db,$reject):void{
    $db->prepare($sql)->execute($args);try{$reject($attempt,$label);}finally{$db->prepare($undo)->execute($undoArgs);}
};
$mutation("UPDATE balance_years SET version=version+1 WHERE club_id='a' AND tax_year=2025",[],
    "UPDATE balance_years SET version=version-1 WHERE club_id='a' AND tax_year=2025",[],fn()=>$records->forecast($r2['id'],2026),'journal change requires a fresh review');
$mutation("UPDATE actors SET active=0 WHERE id='writer'",[],"UPDATE actors SET active=1 WHERE id='writer'",[],fn()=>$save(),'revoked user cannot retry saved preparation');
$mutation('UPDATE documents SET state=? WHERE club_id=? AND id=?',['archived','a',$disposalDoc],
    'UPDATE documents SET state=? WHERE club_id=? AND id=?',['active','a',$disposalDoc],fn()=>$records->forecast($r2['id'],2026),'withdrawn original prevents renewed forecast');

// Only this guarded synthetic fixture simulates historical corruption under the native guard.
MySqlFixture::dsn($db);$definition=$db->query('SHOW CREATE TRIGGER asset_partial_reviews_no_update')->fetch()['SQL Original Statement'];
$raw=$db->prepare('SELECT payload,sha256 FROM asset_partial_reviews WHERE club_id=? AND id=?');$raw->execute(['a',$id]);$original=$raw->fetch();
$db->exec('DROP TRIGGER asset_partial_reviews_no_update');
try{
    $bad=json_decode($original['payload'],true,512,JSON_THROW_ON_ERROR);$bad['review']['calculation']['book']['book_closing_cents']++;
    $mutation('UPDATE asset_partial_reviews SET payload=? WHERE club_id=? AND id=?',[json_encode($bad),'a',$id],
        'UPDATE asset_partial_reviews SET payload=? WHERE club_id=? AND id=?',[$original['payload'],'a',$id],fn()=>$records->get($r2['id']),'corruption in an earlier revision invalidates the whole chain');
    $bad=json_decode($original['payload'],true,512,JSON_THROW_ON_ERROR);$bad['review']['postable']=true;
    $inner=$bad['review'];unset($inner['hash']);$bad['review']['hash']=AssetPartialDisposal::hash($inner);
    $mutation('UPDATE asset_partial_reviews SET payload=?,sha256=? WHERE club_id=? AND id=?',[json_encode($bad),AssetPartialDisposal::hash($bad),'a',$id],
        'UPDATE asset_partial_reviews SET payload=?,sha256=? WHERE club_id=? AND id=?',[$original['payload'],$original['sha256'],'a',$id],fn()=>$records->get($id),'rehashed preparation cannot masquerade as posting approval');
}finally{$db->exec($definition);}
$sqlReject(fn()=>$db->prepare('UPDATE asset_partial_reviews SET payload=payload WHERE club_id=? AND id=?')->execute(['a',$id]),'native history guard restored after synthetic corruption','45000');
check($records->get($r2['id'])===$r2,'complete valid history recovered after corruption tests');
$before=MySqlFixture::snapshot($db);$restored=MySqlFixture::restoreBackup($db);
check(MySqlFixture::snapshot($restored)===$before,'encrypted MySQL roundtrip retains exact prepared records and all data');
check((new AssetPartialDisposalRecords($restored,'writer'))->forecast($r2['id'],2026)===$records->forecast($r2['id'],2026),'restored record produces identical future-year values');
check(!MySqlFixture::foreignKeyErrors($restored),'prepared records preserve foreign-key integrity');
check($financialState()===$financial,'full preparation workflow does not change actual bookkeeping or originals');
echo "$n partial review storage and forecast checks passed; synthetic MySQL, no posting or sending\n";
