<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/mysql-bootstrap.php';
use Kulturhus\Verein\{BalanceLedger,BalanceSources,BalanceSourceHistory,Database,Documents,Finance,SettingsSchema,AssetBooks,ExpenseReimbursements};
use Kulturhus\Verein\Tests\MySqlFixture;
$n=0;
function check(bool $ok,string $name):void{global $n;if(!$ok)throw new RuntimeException($name);++$n;echo "PASS $name\n";}
function denied(callable $f,string $name):void{try{$f();}catch(DomainException){check(true,$name);return;}throw new RuntimeException($name);}
function keyId():string{return bin2hex(random_bytes(16));}
if(($argv[1]??'')==='--race'){
    $j=json_decode(base64_decode($argv[2]),true,64,JSON_THROW_ON_ERROR);putenv('VEREIN_BALANCE_WORKSPACE=1');$db=MySqlFixture::existing($j['dsn']);
    try{(new BalanceLedger($db,'admin'))->correctSource(2025,$j['version'],'invoice',$j['id'],$j['options'],$j['hash'],'Synthetic concurrent correction',true);echo 'corrected';}catch(DomainException){echo 'rejected';}exit;
}
$db=MySqlFixture::connect();Database::migrate($db);Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic balance'),('b','Other synthetic club');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','treasurer',1),('other','b','treasurer',1),('member','a','member',0);INSERT INTO finance_accounts VALUES('bank','a','Synthetic bank','offline',1000000,'2025-01-01')");
$tax=SettingsSchema::defaults('tax');$tax['accounting_basis']='balance';$tax['input_tax_eligibility']='yes';$tax['depreciation_enabled']=true;
$db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES('a','tax',?,1,?)")->execute([json_encode($tax),gmdate('c')]);
putenv('VEREIN_BALANCE_WORKSPACE=1');$ledger=new BalanceLedger($db,'admin');$sources=new BalanceSources($db,'admin');$f=new Finance($db,'admin');
$ledger->create(2025);$chart=$ledger->report(2025)['accounts'];$bank=array_key_first($sources->bankAccounts());
$ledger->setup(2025,1,$chart,[['account'=>$bank,'debit_cents'=>1000000,'credit_cents'=>0,'sphere'=>''],['account'=>'equity','debit_cents'=>0,'credit_cents'=>1000000,'sphere'=>'']],'equity','Synthetic opening statement',true);
$options=['recognised_on'=>'2025-02-01','private_paid_on'=>''];
$positions=static fn(string $amount='119',string $code='68150',string $deduction='none',string $sphere='1'):array=>[['description'=>'Synthetic item','amount'=>$amount,'tax_rate'=>'19','sphere'=>$sphere,'account'=>$code,'booking_key'=>$deduction==='deductible'?'9':'','input_tax'=>$deduction]];
$make=function(string $amount='119',string $code='68150',string $deduction='none')use($f,$db,$positions):string{
    $id=$f->createEntry(['kind'=>'expense','description'=>'Synthetic invoice','amount'=>$amount,'document_date'=>'2025-02-01','due_date'=>'2025-02-01','category'=>'general','positions'=>$positions($amount,$code,$deduction,$deduction==='deductible'?'4':'1')],keyId());
    (new Documents($db,'admin'))->upload('invoice.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='),['title'=>'Synthetic invoice','kind'=>'invoice','scope'=>'finance','links'=>['entry:'.$id]],keyId());return $id;
};
$import=function(string $type,string $id,array $opts=[])use($ledger,$sources):array{
    $p=$sources->preview(2025,$type,$id,$opts);if($p['issues'])throw new RuntimeException(implode(' ',$p['issues']));return $ledger->importSource(2025,$ledger->report(2025)['version'],$type,$id,$opts,$p['hash'],true);
};
$pay=function(string $id,string $amount)use($f,$import):string{
    $tx=$f->createTransaction(['account'=>'bank','booked_on'=>'2025-02-10','direction'=>'expense','amount'=>$amount,'counterparty'=>'Synthetic recipient','description'=>'Synthetic payment'],keyId());
    $f->allocate($tx,1,$id,(int)$f->entry($id)['version'],$amount,keyId());$import('transaction',$tx);return $tx;
};
$correct=function(string $type,string $id,array $opts=[])use($ledger,$sources):array{
    $p=$sources->correctionPreview(2025,$type,$id,$opts);if($p['issues'])throw new RuntimeException(implode(' ',$p['issues']));return $ledger->correctSource(2025,$ledger->report(2025)['version'],$type,$id,$opts,$p['hash'],'Synthetic correction after original review',true);
};
$entry=$make('119','68150','deductible');$ids=$import('invoice',$entry,$options);$payment=$pay($entry,'119');
$history=(new BalanceSourceHistory($db,'admin'))->checked($sources->binding('invoice',$entry));check(count($history)===1,'initial proposal retained with journal links');
denied(fn()=>$ledger->reverse(2025,$ledger->report(2025)['version'],$ids[0],'2025-02-20','No detached reversal',keyId()),'source-linked journal cannot be detached from payment by manual reversal');
check((bool)$sources->correctionPreview(2025,'invoice',$entry,$options)['issues'],'unchanged source does not generate correction noise');
$f->updatePositions('entry',$entry,(int)$f->entry($entry)['version'],$positions('119','68150','none'),'Synthetic input tax correction');
$plan=$sources->correctionPreview(2025,'invoice',$entry,$options);check(!$plan['issues']&&count($plan['items'])===2,'invoice correction includes its paid bank allocation');
$ui=getenv('VEREIN_BALANCE_UI_OUTPUT');
if($ui){
    if(!is_dir($ui)||!str_starts_with(realpath($ui),'/workspace/.local/'))throw new RuntimeException('Private synthetic output directory required');
    require __DIR__.'/../src/Web/View.php';$_SESSION=['actor'=>'admin','csrf'=>'synthetic-csrf'];$_SERVER['REQUEST_METHOD']='GET';$_POST=[];
    $_GET=['balance_year'=>'2025','balance_source'=>'invoice:'.$entry];
    $html=(static function(PDO $db):string{ob_start();require __DIR__.'/../views/Finance/Balance/balance-ledger.php';return ob_get_clean();})($db);
    check(str_contains($html,'Korrektur übernehmen')&&!str_contains($html,'value="balance-source-import"'),'changed source offers correction rather than a second import');
    check(str_contains($html,'Zuordnung vorher und nachher')&&str_contains($html,'Betroffene Belege: 2.'),'UI exposes old and new posting details including dependent payment');
    check(str_contains($html,'Bisherige Fassungen (1)'),'UI retains access to previous source revision');
    check(str_contains($html,'name="correction_reason"')&&str_contains($html,'name="correction_hash"'),'correction form binds preview and requires reason');
    check(substr_count($html,'name="recognised_on"')===1&&!str_contains($html,'type="hidden" name="recognised_on"'),'date editing has no hidden stale date copy');
    file_put_contents($ui.'/correction-ready.html','<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Synthetic correction UI</title><style>'.file_get_contents(__DIR__.'/../public/style.css').'</style></head><body><main style="margin:0;padding:16px">'.$html.'</main></body></html>');
}
$before=MySqlFixture::snapshot($db);$sources->correctionPreview(2025,'invoice',$entry,$options);check(MySqlFixture::snapshot($db)===$before,'correction preview makes no database changes');
denied(fn()=>$ledger->correctSource(2025,$ledger->report(2025)['version'],'invoice',$entry,$options,$plan['hash'],'Synthetic correction',false),'correction requires explicit review');
denied(fn()=>$ledger->correctSource(2025,$ledger->report(2025)['version'],'invoice',$entry,$options,$plan['hash'],'',true),'correction requires a reason');
denied(fn()=>$ledger->correctSource(2025,$ledger->report(2025)['version'],'invoice',$entry,$options,str_repeat('0',64),'Synthetic correction',true),'stale correction hash rejected');
denied(fn()=>(new BalanceLedger($db,'member'))->correctSource(2025,$ledger->report(2025)['version'],'invoice',$entry,$options,$plan['hash'],'Synthetic correction',true),'member cannot correct journal');
check((bool)(new BalanceSources($db,'other'))->correctionPreview(2025,'invoice',$entry,$options)['issues'],'other club cannot read correction proposals');
check(MySqlFixture::snapshot($db)===$before,'all rejected attempts leave complete state unchanged');
$db->prepare('UPDATE accounting_positions SET description=? WHERE club_id=? AND entry_id=?')->execute(['Changed after preview','a',$entry]);
denied(fn()=>$ledger->correctSource(2025,$ledger->report(2025)['version'],'invoice',$entry,$options,$plan['hash'],'Synthetic stale source',true),'source change after preview invalidates correction confirmation');
$db->prepare('UPDATE accounting_positions SET description=? WHERE club_id=? AND entry_id=?')->execute(['Synthetic item','a',$entry]);
$journal=MySqlFixture::rows($db,'balance_entries');$cash=$ledger->report(2025)['calculation']['accounts'][$bank]['closing_cents'];
// Fail after original invoice reversal/replacement, while replacing the dependent payment.
$db->exec("CREATE TRIGGER synthetic_fail_correction BEFORE INSERT ON balance_entries FOR EACH ROW BEGIN IF NEW.description='Zahlung zu Rechnungen' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic dependent failure'; END IF; END");
try{try{$correct('invoice',$entry,$options);throw new RuntimeException('Expected synthetic failure');}catch(PDOException $e){check(str_contains($e->getMessage(),'Synthetic dependent failure'),'dependent payment failure injected');}}finally{$db->exec('DROP TRIGGER synthetic_fail_correction');}
check(MySqlFixture::snapshot($db)===$before,'failed cascade rolls back reversals, new entries, history, pointers, accounts and audit');
$result=$correct('invoice',$entry,$options);check(count($result)===2,'invoice and dependent payment corrected atomically');
foreach($journal as $row){$q=$db->prepare('SELECT * FROM balance_entries WHERE club_id=? AND tax_year=? AND id=?');$q->execute(['a',2025,$row['id']]);check($q->fetch()===$row,'original posting unchanged byte-for-byte');}
$r=$ledger->report(2025);check($r['calculation']['annual_result_cents']===-11900&&$r['calculation']['accounts']['src_input_vat']['amount_cents']===0,'VAT correction moves exact cents to gross expense');
check($r['calculation']['accounts'][$bank]['closing_cents']===$cash,'correction does not create a second payment');
check(!$sources->reviewYear(2025)['issues'],'corrected source and history reconcile');
$history=(new BalanceSourceHistory($db,'admin'))->checked($sources->binding('invoice',$entry));check(count($history)===2&&$history[1]['payload']['reason']==='Synthetic correction after original review','original and corrected proposals retained with reason');
// Repeated correction, including changed sphere, is another revision rather than an overwrite.
$f->updatePositions('entry',$entry,(int)$f->entry($entry)['version'],$positions('119','68150','deductible','4'),'Synthetic second correction');
$correct('invoice',$entry,$options);check(count((new BalanceSourceHistory($db,'admin'))->checked($sources->binding('invoice',$entry)))===3,'repeated correction appends a third verified revision');
check($ledger->report(2025)['calculation']['annual_result_cents']===-10000,'repeated correction restores exact net expense');
$late=['recognised_on'=>'2025-02-11','private_paid_on'=>''];
check((bool)$sources->correctionPreview(2025,'invoice',$entry,$late)['issues'],'new recognition date after actual payment is rejected');
// Private reimbursement identity cannot be silently changed after payment.
$db->exec("INSERT INTO contacts(id,club_id,kind,name,email,address) VALUES('mara','a','person','Mara Synthetic','mara@example.invalid','Synthetic road'),('otto','a','person','Otto Synthetic','otto@example.invalid','Synthetic road');INSERT INTO memberships(id,club_id,contact_id,member_number,status,starts_on) VALUES('m-mara','a','mara','M-1','aktiv','2025-01-01'),('m-otto','a','otto','M-2','aktiv','2025-01-01')");
$private=$make('238');(new ExpenseReimbursements($db,'admin'))->createForEntry($private,'mara','member',keyId());$po=['recognised_on'=>'2025-02-01','private_paid_on'=>'2025-02-02'];$import('invoice',$private,$po);$pay($private,'238');
$db->prepare('UPDATE finance_reimbursement_claims SET contact_id=? WHERE club_id=? AND entry_id=?')->execute(['otto','a',$private]);
check((bool)$sources->correctionPreview(2025,'invoice',$private,$po)['issues'],'paid reimbursement cannot be reassigned to a different member automatically');
$db->prepare('UPDATE finance_reimbursement_claims SET contact_id=? WHERE club_id=? AND entry_id=?')->execute(['mara','a',$private]);
$f->updatePositions('entry',$private,(int)$f->entry($private)['version'],$positions('238','68150','none','3'),'Synthetic reimbursement sphere correction');
check(count($correct('invoice',$private,$po))===2,'private payment reclassification and reimbursement corrected together');
// Fixed asset corrections also carry the annual depreciation source reference forward.
$asset=$make('1190','06300');$db->exec("INSERT INTO inventory_items(club_id,id,code,name,kind) VALUES('a','asset','INV-1','Synthetic amplifier','reusable')");
$books=new AssetBooks($db,'admin');$books->save('asset',0,['basis'=>'1190','residual'=>'0','acquired_on'=>'2025-02-01','months'=>'60','sphere'=>'1','account'=>'06300','reason'=>'Synthetic supported acquisition']);
$db->prepare('INSERT INTO invoice_position_items VALUES(?,?,?,?,?,?,?,?)')->execute(['a',$asset,1,'asset',1,'Synthetic amplifier','admin',gmdate('c')]);
$import('invoice',$asset,$options);$books->recordYear('asset',1,2025);$import('depreciation','asset:2025');
$db->prepare('UPDATE accounting_positions SET description=? WHERE club_id=? AND entry_id=?')->execute(['Corrected synthetic description','a',$asset]);
$beforeResult=$ledger->report(2025)['calculation']['annual_result_cents'];
check(count($correct('invoice',$asset,$options))===2,'asset invoice correction includes recorded annual depreciation');
check($ledger->report(2025)['calculation']['annual_result_cents']===$beforeResult,'metadata correction does not duplicate depreciation');
$planRow=$db->query("SELECT payload FROM asset_book_plans WHERE club_id='a' AND item_id='asset'")->fetchColumn();$bad=json_decode($planRow,true);$bad['months']=120;
$db->prepare("UPDATE asset_book_plans SET payload=? WHERE club_id='a' AND item_id='asset'")->execute([json_encode($bad)]);
check((bool)$sources->correctionPreview(2025,'invoice',$asset,$options)['issues'],'changed useful life cannot bypass an already recorded annual depreciation plan');
$db->prepare("UPDATE asset_book_plans SET payload=? WHERE club_id='a' AND item_id='asset'")->execute([$planRow]);
// Two editors confirm one source change at once. Exactly one correction commits.
$f->updatePositions('entry',$entry,(int)$f->entry($entry)['version'],$positions('119','68150','deductible','3'),'Synthetic concurrent sphere correction');
$plan=$sources->correctionPreview(2025,'invoice',$entry,$options);$job=['dsn'=>MySqlFixture::dsn($db),'version'=>$ledger->report(2025)['version'],'id'=>$entry,'options'=>$options,'hash'=>$plan['hash']];
$processes=[];$db->beginTransaction();$db->query("SELECT id FROM clubs WHERE id='a' FOR UPDATE")->fetchColumn();
for($i=0;$i<2;$i++){$proc=proc_open([PHP_BINARY,__FILE__,'--race',base64_encode(json_encode($job))],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('Synthetic concurrent writer failed to start');fclose($pipes[0]);$processes[]=[$proc,$pipes];}
usleep(250000);$db->commit();$verdicts=[];
foreach($processes as [$proc,$pipes]){$verdicts[]=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($proc)===0&&$err==='','concurrent correction writer exits without warnings');}
sort($verdicts);check($verdicts===['corrected','rejected'],'two editors cause exactly one correction');
// A moved payment is found via its OLD source snapshot, not just current allocations.
$alternate=$make('119');$import('invoice',$alternate,$options);
$move=function(string $from,string $to)use($f,$payment):void{
    $active=array_values(array_filter($f->allocations($payment,true),static fn($v)=>!$v['voided_at']));
    $f->releaseAllocation($active[0]['id'],(int)$f->transactionDetail($payment)['version'],(int)$f->entry($from)['version'],'Synthetic assignment corrected');
    $f->allocate($payment,(int)$f->transactionDetail($payment)['version'],$to,(int)$f->entry($to)['version'],'119',keyId());
};
$beforeMove=$ledger->report(2025)['calculation'];$move($entry,$alternate);
$f->updatePositions('entry',$entry,(int)$f->entry($entry)['version'],$positions('119','68150','deductible','4'),'Synthetic correction after payment reassignment');
check(count($correct('invoice',$entry,$options))===2,'previously linked but now moved payment is included in invoice correction');
$oldPayable=$sources->binding('invoice',$entry)['snapshot']['settlement_account'];$newPayable=$sources->binding('invoice',$alternate)['snapshot']['settlement_account'];
$moved=$ledger->report(2025)['calculation'];check($moved['accounts'][$oldPayable]['amount_cents']===11900&&$moved['accounts'][$newPayable]['amount_cents']===0,'reassignment reopens original payable and clears the actual assigned invoice');
check($moved['annual_result_cents']===$beforeMove['annual_result_cents']&&$moved['accounts'][$bank]['closing_cents']===$beforeMove['accounts'][$bank]['closing_cents'],'payment reassignment changes neither expense nor cash');
$move($alternate,$entry);check(count($correct('transaction',$payment))===1,'a changed bank allocation can also be corrected directly');
check(!$sources->reviewYear(2025)['issues'],'all corrected invoices, bank postings and depreciation reconcile');
if(getenv('VEREIN_TEST_SKIP_BACKUP_ROUNDTRIP')==='1'){$restore=$db;echo "SKIP optional backup roundtrip: dedicated synthetic database\n";}
else{$restore=MySqlFixture::restoreBackup($db);check(MySqlFixture::rows($db,'balance_source_history')===MySqlFixture::rows($restore,'balance_source_history'),'MySQL backup restores exact correction history');}
check(!(new BalanceSources($restore,'admin'))->reviewYear(2025)['issues'],'restored history reconciles with original journal');
$restore->beginTransaction();try{
$savedHistory=$restore->prepare('SELECT payload FROM balance_source_history WHERE club_id=? AND source_type=? AND source_id=? AND revision=1');$savedHistory->execute(['a','invoice',$entry]);$savedPayload=$savedHistory->fetchColumn();
$restore->prepare('UPDATE balance_source_history SET payload=? WHERE club_id=? AND source_type=? AND source_id=? AND revision=1')->execute(['{}','a','invoice',$entry]);
check((bool)(new BalanceSources($restore,'admin'))->correctionPreview(2025,'invoice',$entry,$options)['issues'],'structurally broken but valid JSON history is blocked without a PHP warning');
$restore->prepare('UPDATE balance_source_history SET payload=? WHERE club_id=? AND source_type=? AND source_id=? AND revision=1')->execute([$savedPayload,'a','invoice',$entry]);
$oldJournal=$restore->prepare('SELECT id,posting_lines FROM balance_entries WHERE club_id=? AND tax_year=? AND id=?');$oldJournal->execute(['a',2025,$ids[0]]);$oldRow=$oldJournal->fetch();$badLines=json_decode($oldRow['posting_lines'],true);$badLines[0]['debit_cents']++;
$restore->prepare('UPDATE balance_entries SET posting_lines=? WHERE club_id=? AND tax_year=? AND id=?')->execute([json_encode($badLines),'a',2025,$ids[0]]);
check((bool)(new BalanceSources($restore,'admin'))->reviewYear(2025)['issues'],'tampering with an already reversed original is detected');
$restore->prepare('UPDATE balance_entries SET posting_lines=? WHERE club_id=? AND tax_year=? AND id=?')->execute([$oldRow['posting_lines'],'a',2025,$ids[0]]);
$restore->prepare('DELETE FROM balance_source_history WHERE club_id=? AND source_type=? AND source_id=?')->execute(['a','invoice',$entry]);
check((bool)(new BalanceSources($restore,'admin'))->reviewYear(2025)['issues'],'missing history cannot masquerade as a legacy source');
}finally{if($restore->inTransaction())$restore->rollBack();}
check(!MySqlFixture::foreignKeyErrors($db),'all correction references preserve tenant and source boundaries');
$ledger->close(2025,$ledger->report(2025)['version'],['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic reconciled year');$closed=$ledger->report(2025);
$db->prepare('UPDATE accounting_positions SET description=? WHERE club_id=? AND entry_id=?')->execute(['Changed after year close','a',$entry]);
denied(fn()=>$ledger->correctSource(2025,$closed['version'],'invoice',$entry,$options,'ignored','No closed-year rewriting',true),'closed year rejects correction even for superuser');
check($ledger->report(2025)['closed_sha256']===$closed['closed_sha256'],'closed year evidence remains unchanged');
echo "$n correction checks passed; synthetic MySQL only\n";
