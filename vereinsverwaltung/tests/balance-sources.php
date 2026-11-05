<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/mysql-bootstrap.php';
use Kulturhus\Verein\{BalanceLedger,BalanceSources,Database,Documents,Finance,SettingsSchema,Settings,AssetBooks,ExpenseReimbursements};
use Kulturhus\Verein\Tests\MySqlFixture;
$n=0;function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
function denied(callable $f,string $label):void{try{$f();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
function keyId():string{return bin2hex(random_bytes(16));}
if(($argv[1]??'')==='--race'){
    $job=json_decode(base64_decode($argv[2]),true,64,JSON_THROW_ON_ERROR);putenv('VEREIN_BALANCE_WORKSPACE=1');
    $conn=MySqlFixture::existing($job['dsn']);
    try{(new BalanceLedger($conn,'admin'))->importSource(2026,$job['version'],'invoice',$job['id'],$job['options'],$job['hash'],true);echo 'posted';}
    catch(DomainException){echo 'rejected';}exit;
}
$db=MySqlFixture::connect();Database::migrate($db);Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic balance'),('b','Synthetic EÜR');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','treasurer',1),('other','b','treasurer',1),('member','a','member',0);INSERT INTO finance_accounts VALUES('bank','a','Synthetic bank','offline',1000000,'2025-01-01')");
$tax=SettingsSchema::defaults('tax');$tax['accounting_basis']='balance';$tax['input_tax_eligibility']='no';$tax['depreciation_enabled']=true;
$db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES('a','tax',?,1,?)")->execute([json_encode($tax),gmdate('c')]);
putenv('VEREIN_BALANCE_WORKSPACE=1');$ledger=new BalanceLedger($db,'admin');$sources=new BalanceSources($db,'admin');$f=new Finance($db,'admin');
$ledger->create(2025);$chart=$ledger->report(2025)['accounts'];$bank=array_key_first($sources->bankAccounts());
$ledger->setup(2025,1,$chart,[['account'=>$bank,'debit_cents'=>1000000,'credit_cents'=>0,'sphere'=>''],['account'=>'equity','debit_cents'=>0,'credit_cents'=>1000000,'sphere'=>'']],'equity','Synthetic opening bank statement',true);
$make=function(string $amount='119.00',string $code='68150',string $kind='expense',string $date='2025-02-01')use($f,$db):string{
    $id=$f->createEntry(['kind'=>$kind,'description'=>'Synthetic invoice','amount'=>$amount,'document_date'=>$date,'due_date'=>$date,'category'=>'general','positions'=>[['description'=>'Synthetic item','amount'=>$amount,'tax_rate'=>'19','sphere'=>'1','account'=>$code,'booking_key'=>$kind==='expense'?'':'3','input_tax'=>$kind==='expense'?'none':'unknown']]],keyId());
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
    (new Documents($db,'admin'))->upload('invoice.png',$png,['title'=>'Synthetic invoice','kind'=>'invoice','scope'=>'finance','links'=>['entry:'.$id]],keyId());return $id;
};
$import=function(string $type,string $id,array $options=[],int $year=2025)use($sources,$ledger):array{
    $p=$sources->preview($year,$type,$id,$options);if($p['issues'])throw new RuntimeException(implode(' ',$p['issues']));
    return $ledger->importSource($year,$ledger->report($year)['version'],$type,$id,$options,$p['hash'],true);
};
$options=['recognised_on'=>'2025-02-01','private_paid_on'=>''];$entry=$make();$p=$sources->preview(2025,'invoice',$entry,$options);
check(!$p['issues']&&count($p['vouchers'])===1,'invoice proposal uses existing position data: '.implode(' ',$p['issues']));
check(array_sum(array_column($p['vouchers'][0]['lines'],'debit_cents'))===11900&&!isset($p['accounts']['src_input_vat']),'non-deductible VAT remains gross expense');
$state=MySqlFixture::snapshot($db);$sources->preview(2025,'invoice',$entry,$options);check($state===MySqlFixture::snapshot($db),'preview is read-only');
denied(fn()=>$ledger->importSource(2025,2,'invoice',$entry,$options,$p['hash'],false),'review confirmation required');
denied(fn()=>$ledger->importSource(2025,2,'invoice',$entry,$options,str_repeat('0',64),true),'stale or forged preview rejected');
denied(fn()=>$ledger->importSource(2025,2,'invoice',$entry,['recognised_on'=>'2025-02-02','private_paid_on'=>''],$p['hash'],true),'edited date cannot silently reuse old preview confirmation');
check((int)$db->query('SELECT COUNT(*) FROM balance_sources')->fetchColumn()===0,'failed proposal leaves no binding');
$invoiceIds=$import('invoice',$entry,$options);check($ledger->report(2025)['calculation']['annual_result_cents']===-11900,'invoice affects profit at recognition');
denied(fn()=>$import('invoice',$entry,$options),'same invoice cannot post twice with a new request');
$foreign=(new BalanceSources($db,'other'))->preview(2025,'invoice',$entry,$options);check((bool)$foreign['issues']&&!$foreign['snapshot'],'other club cannot inspect invoice data');
denied(fn()=>(new BalanceSources($db,'member'))->candidates(2025),'member cannot inspect financial source list');
$tx=$f->createTransaction(['account'=>'bank','booked_on'=>'2025-02-05','direction'=>'expense','amount'=>'50','counterparty'=>'Synthetic supplier','description'=>'Partial payment'],keyId());
check((bool)$sources->preview(2025,'transaction',$tx)['issues'],'unallocated bank amount cannot be silently expensed');
$f->allocate($tx,1,$entry,1,'50',keyId());$import('transaction',$tx);
$r=$ledger->report(2025);$payable=$sources->binding('invoice',$entry)['snapshot']['settlement_account'];
check($r['calculation']['annual_result_cents']===-11900&&$r['calculation']['accounts'][$payable]['amount_cents']===6900,'partial payment reduces only the original payable');
check(!$sources->reviewYear(2025)['issues'],'payment/version flags do not invalidate original source');
$second=$f->createTransaction(['account'=>'bank','booked_on'=>'2025-02-06','direction'=>'expense','amount'=>'69','counterparty'=>'Synthetic supplier','description'=>'Remaining payment'],keyId());
$f->allocate($second,1,$entry,(int)$f->entry($entry)['version'],'69',keyId());$import('transaction',$second);
check($ledger->report(2025)['calculation']['accounts'][$payable]['amount_cents']===0,'final payment clears payable');
$db->exec("INSERT INTO contacts(id,club_id,kind,name,email,address) VALUES('mara','a','person','Mara Synthetic','mara@example.invalid','Synthetic road');INSERT INTO memberships(id,club_id,contact_id,member_number,status,starts_on) VALUES('member-mara','a','mara','M-1','aktiv','2025-01-01')");
$private=$make('238.00');(new ExpenseReimbursements($db,'admin'))->createForEntry($private,'mara','member',keyId());
check((bool)$sources->preview(2025,'invoice',$private,$options)['issues'],'private payment date cannot be invented from claim date');
$privateOptions=['recognised_on'=>'2025-02-01','private_paid_on'=>'2025-02-02'];
$beforeFailure=MySqlFixture::snapshot($db);
$db->exec("CREATE TRIGGER synthetic_fail_balance BEFORE INSERT ON balance_entries FOR EACH ROW BEGIN IF NEW.description LIKE 'Private Zahlung%' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic failure on second posting'; END IF; END");
try{try{$import('invoice',$private,$privateOptions);throw new RuntimeException('Synthetic failure not triggered');}catch(PDOException $e){check(str_contains($e->getMessage(),'Synthetic failure'),'second posting failure injected in isolated MySQL');}}
finally{$db->exec('DROP TRIGGER synthetic_fail_balance');}
check(MySqlFixture::snapshot($db)===$beforeFailure,'second posting failure rolls back journal, chart, version, audit and source binding');
$privateIds=$import('invoice',$private,$privateOptions);
check(count($privateIds)===2,'later private payment is a separate liability reclassification');
$privateTx=$f->createTransaction(['account'=>'bank','booked_on'=>'2025-02-10','direction'=>'expense','amount'=>'238','counterparty'=>'Mara Synthetic','description'=>'Reimbursement'],keyId());
$f->allocate($privateTx,1,$private,(int)$f->entry($private)['version'],'238',keyId());$import('transaction',$privateTx);
$memberAccount=$sources->binding('invoice',$private)['snapshot']['settlement_account'];
check($ledger->report(2025)['calculation']['accounts'][$memberAccount]['amount_cents']===0&&$ledger->report(2025)['calculation']['annual_result_cents']===-35700,'reimbursement clears member debt without duplicate expense');
// Fixed asset uses the recorded acquisition cost, never estimated inventory value.
$assetEntry=$make('1190','06300','expense','2025-03-01');
check((bool)$sources->preview(2025,'invoice',$assetEntry,['recognised_on'=>'2025-03-01','private_paid_on'=>''])['issues'],'asset cannot be capitalised without a supported valuation');
$db->exec("INSERT INTO inventory_items(club_id,id,code,name,kind) VALUES('a','asset','INV-1','Synthetic amplifier','reusable')");
$books=new AssetBooks($db,'admin');$books->save('asset',0,['basis'=>'1190','residual'=>'0','acquired_on'=>'2025-03-01','months'=>'60','sphere'=>'1','account'=>'06300','reason'=>'Synthetic confirmed acquisition and useful life']);
$db->prepare('INSERT INTO invoice_position_items VALUES(?,?,?,?,?,?,?,?)')->execute(['a',$assetEntry,1,'asset',1,'Synthetic amplifier','admin',gmdate('c')]);
$assetIds=$import('invoice',$assetEntry,['recognised_on'=>'2025-03-01','private_paid_on'=>'']);
check($ledger->report(2025)['calculation']['annual_result_cents']===-35700,'capitalised acquisition is not immediately expensed');
$assetSchedule=new \Kulturhus\Verein\BalanceAssetSchedule($db,'admin');$pending=$assetSchedule->build(2025);
check($pending['items']['asset']['book_opening_cents']===0&&$pending['items']['asset']['additions_cents']===119000,'acquisition year starts at zero, not at full invoice cost');
check($pending['items']['asset']['expected_depreciation_cents']===19833&&$pending['items']['asset']['depreciation_cents']===0&&$pending['issues'],'unposted depreciation shown as outstanding, not invented as booked');
denied(fn()=>$ledger->close(2025,$ledger->report(2025)['version'],['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic missing depreciation'),'missing annual depreciation prevents closing even before a year record exists');
check($ledger->report(2025)['state']==='open','failed asset review does not lock year');
$books->recordYear('asset',1,2025);$import('depreciation','asset:2025');
check($ledger->report(2025)['calculation']['annual_result_cents']===-55533,'exact annual depreciation is expensed once');
$assetProof=$assetSchedule->build(2025);check(!$assetProof['issues']&&$assetProof['items']['asset']['book_closing_cents']===99167&&$assetProof['totals']['accumulated_closing_cents']===19833,'gross cost and accumulated depreciation reconcile with net ledger balance');
check($assetProof['items']['asset']['name']==='INV-1 · Synthetic amplifier','readable inventory designation shown instead of opaque ID');
$assetSnapshot=MySqlFixture::snapshot($db);$assetSchedule->build(2025);check($assetSnapshot===MySqlFixture::snapshot($db),'asset schedule is read-only');
denied(fn()=>(new \Kulturhus\Verein\BalanceAssetSchedule($db,'member'))->build(2025),'asset proof requires financial access');
check(!(new \Kulturhus\Verein\BalanceAssetSchedule($db,'other'))->build(2025)['items'],'asset proof cannot expose other club assets');
denied(fn()=>$import('depreciation','asset:2025'),'annual asset depreciation cannot be doubled');
// Readable original metadata, source positions and posting lines are checked, not silently repaired.
$saved=MySqlFixture::rows($db,'balance_entries');
$db->prepare('UPDATE accounting_positions SET description=? WHERE club_id=? AND entry_id=?')->execute(['Changed source','a',$entry]);
check((bool)$sources->reviewYear(2025)['issues'],'changed original position detected before closing');
denied(fn()=>$ledger->close(2025,$ledger->report(2025)['version'],['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic close'),'changed source blocks finalisation');
check($saved===MySqlFixture::rows($db,'balance_entries'),'source drift does not overwrite journal history');
$db->prepare('UPDATE accounting_positions SET description=? WHERE club_id=? AND entry_id=?')->execute(['Synthetic item','a',$entry]);
check(!$sources->reviewYear(2025)['issues'],'unchanged sources reconcile');
if(getenv('VEREIN_TEST_SKIP_BACKUP_ROUNDTRIP')==='1'){$restore=$db;echo "SKIP optional backup roundtrip: dedicated synthetic database\n";}
else{$restore=MySqlFixture::restoreBackup($db);check(MySqlFixture::rows($db,'balance_sources')===MySqlFixture::rows($restore,'balance_sources'),'MySQL restore preserves full source evidence');}
$restore->beginTransaction();try{
    $tampered=json_decode($saved[0]['posting_lines'],true);$tampered[0]['debit_cents']++;
    $restore->prepare('UPDATE balance_entries SET posting_lines=? WHERE club_id=? AND tax_year=? AND id=?')->execute([json_encode($tampered),'a',2025,$saved[0]['id']]);
    check((bool)(new BalanceSources($restore,'admin'))->reviewYear(2025)['issues'],'modified journal lines fail source reconciliation');
}finally{if($restore->inTransaction())$restore->rollBack();}
check(!MySqlFixture::foreignKeyErrors($db),'all source bindings retain club/year references');
$ledger->close(2025,$ledger->report(2025)['version'],['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic complete year');
check(count($ledger->report(2025)['sources'])===7,'closed journal preserves all source snapshots');
check($ledger->report(2025)['asset_schedule']===$assetProof,'closing retains exact reviewed asset schedule');
$db->exec("UPDATE inventory_items SET name='Renamed later' WHERE club_id='a' AND id='asset'");
check($assetSchedule->build(2025)===$assetProof,'later inventory labels cannot rewrite a closed asset schedule');
$assetBundle=(new \Kulturhus\Verein\TaxPreparation($db,'admin'))->build(2025,2025,'Synthetic asset activity');
check(isset($assetBundle['files']['2025/bilanz-anlagenverzeichnis.csv'])&&json_decode($assetBundle['files']['2025/bilanz-anlagenverzeichnis.json'],true)===$assetProof,'tax bundle retains complete reviewed cost and depreciation proof');
$ledger->create(2026);check($ledger->report(2026)['calculation']['annual_result_cents']===0,'carry-forward clears P&L while retaining asset/payable balances');
$nextAssets=$assetSchedule->build(2026);check($nextAssets['items']['asset']['book_opening_cents']===99167&&$nextAssets['items']['asset']['additions_cents']===0&&$nextAssets['items']['asset']['cost_opening_cents']===119000,'next year carries gross cost and accumulated depreciation, not a new purchase');
$cross=$f->createTransaction(['account'=>'bank','booked_on'=>'2026-01-10','direction'=>'expense','amount'=>'1190','counterparty'=>'Synthetic supplier','description'=>'Prior-year invoice paid'],keyId());
$f->allocate($cross,1,$assetEntry,(int)$f->entry($assetEntry)['version'],'1190',keyId());$import('transaction',$cross,[],2026);
check($ledger->report(2026)['calculation']['annual_result_cents']===0,'payment of prior-year liability is not new-year expense');
$tax['input_tax_eligibility']='yes';$db->prepare("UPDATE club_settings SET payload=? WHERE club_id='a' AND section='tax'")->execute([json_encode($tax)]);
check(!$sources->reviewYear(2025)['issues'],'later eligibility change does not alter original non-deductible expenses');
$vatEntry=$make('119','68150','expense','2026-02-01');
$f->updatePositions('entry',$vatEntry,(int)$f->entry($vatEntry)['version'],[['description'=>'Synthetic item','amount'=>'119','tax_rate'=>'19','sphere'=>'4','account'=>'68150','input_tax'=>'deductible','booking_key'=>'9']],'Synthetic deductible expense');
$vatOptions=['recognised_on'=>'2026-02-01','private_paid_on'=>''];$import('invoice',$vatEntry,$vatOptions,2026);
check($ledger->report(2026)['calculation']['annual_result_cents']===-10000&&$ledger->report(2026)['calculation']['accounts']['src_input_vat']['amount_cents']===1900,'deductible expense posts net cost and separate exact VAT');
$income=$make('238','42000','income','2026-02-01');$import('invoice',$income,$vatOptions,2026);
check($ledger->report(2026)['calculation']['annual_result_cents']===10000&&$ledger->report(2026)['calculation']['accounts']['src_output_vat']['amount_cents']===3800,'sales invoice separates output VAT from revenue');
$incomeTx=$f->createTransaction(['account'=>'bank','booked_on'=>'2026-02-10','direction'=>'income','amount'=>'238','counterparty'=>'Synthetic customer','description'=>'Invoice received'],keyId());
$f->allocate($incomeTx,1,$income,(int)$f->entry($income)['version'],'238',keyId());$import('transaction',$incomeTx,[],2026);
check($ledger->report(2026)['calculation']['annual_result_cents']===10000,'receipt of invoiced revenue does not duplicate sales');
$race=$make('10','68150','expense','2026-02-01');$proposal=$sources->preview(2026,'invoice',$race,$vatOptions);
$job=['dsn'=>MySqlFixture::dsn($db),'id'=>$race,'options'=>$vatOptions,'hash'=>$proposal['hash'],'version'=>$ledger->report(2026)['version']];$processes=[];
$db->beginTransaction();$db->query("SELECT id FROM clubs WHERE id='a' FOR UPDATE")->fetchColumn();
for($i=0;$i<2;$i++){$proc=proc_open([PHP_BINARY,__FILE__,'--race',base64_encode(json_encode($job))],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('Unable to start synthetic concurrent writer');fclose($pipes[0]);$processes[]=[$proc,$pipes];}
usleep(250000);$db->commit();$verdicts=[];
foreach($processes as [$proc,$pipes]){$verdicts[]=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($proc)===0&&$err==='','concurrent writer exits cleanly');}
sort($verdicts);check($verdicts===['posted','rejected'],'two simultaneous source imports produce exactly one booking');
check(!$sources->reviewYear(2026)['issues'],'source and journal reconciliation after concurrent import');
$badCash=$ledger->report(2026)['calculation'];$badCash['accounts'][$bank]['closing_cents']++;
check((bool)$sources->reviewYear(2026,false,$badCash)['issues'],'one-cent bank imbalance blocks year reconciliation');
check($ledger->report(2025)['ready_for_tax_export']===false,'source integration does not claim XBRL readiness');
$ui=getenv('VEREIN_BALANCE_UI_OUTPUT');
if($ui){
    if(!is_dir($ui)||!str_starts_with(realpath($ui),'/workspace/.local/'))throw new RuntimeException('Private synthetic UI output required');
    require __DIR__.'/../src/Web/View.php';$_SESSION=['actor'=>'admin','csrf'=>'synthetic-csrf'];$_SERVER['REQUEST_METHOD']='GET';$_POST=[];
    $uiEntry=$make('119','68150','expense','2026-02-01');(new ExpenseReimbursements($db,'admin'))->createForEntry($uiEntry,'mara','member',keyId());
    foreach(['source-date-missing'=>'','source-ready'=>'2026-02-01'] as $fixtureName=>$privateDate){
        $_GET=['balance_year'=>'2026','balance_source'=>'invoice:'.$uiEntry,'recognised_on'=>'2026-02-01','private_paid_on'=>$privateDate];
        ob_start();require __DIR__.'/../views/Finance/Balance/balance-ledger.php';$html=ob_get_clean();
        check(str_contains($html,'Vom Mitglied bezahlt am'),'private payment date visible without failing POST');
        check(str_contains($html,'In Bilanz übernehmen')===($privateDate!==''),'source posting shown only after complete date proposal');
        $css=file_get_contents(__DIR__.'/../public/style.css');
        file_put_contents($ui.'/'.$fixtureName.'.html','<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Synthetic balance source UI</title><style>'.$css.'</style></head><body><main style="margin:0;padding:16px">'.$html.'</main></body></html>');
    }
}
echo "$n source integration checks passed; synthetic MySQL only\n";
