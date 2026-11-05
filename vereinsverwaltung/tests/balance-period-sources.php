<?php declare(strict_types=1);
namespace Kulturhus\Verein {function date(string $format,?int $time=null):string{return \gmdate($format,$time??\strtotime('2029-01-15 UTC'));}}
namespace {
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/mysql-bootstrap.php';require_once __DIR__.'/../src/Finance/Balance/BalancePeriodLedger.php';require_once __DIR__.'/../src/Finance/Balance/BalancePeriodExport.php';
use Kulturhus\Verein\{BalancePeriodLedger as L,BalancePeriodSources as S,Database,SettingsSchema,Finance,Documents,ExpenseReimbursements,BalancePeriodExport};
use Kulturhus\Verein\Tests\MySqlFixture;
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$n=0;function check(bool $ok,string $why):void{global $n;if(!$ok)throw new RuntimeException($why);$n++;echo "PASS $why\n";}
function denied(callable $f,string $why):void{try{$f();}catch(DomainException){check(true,$why);return;}throw new RuntimeException($why);}
function keyId():string{return bin2hex(random_bytes(16));}
$db=MySqlFixture::connect();Database::migrate($db);Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic Period Sources'),('b','Synthetic Foreign Club'); INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','treasurer',1),('other','b','treasurer',1),('member','a','member',0); INSERT INTO finance_accounts VALUES('bank','a','Synthetic bank','offline',1000000,'2025-07-01')");
$tax=array_replace(SettingsSchema::defaults('tax'),['accounting_basis'=>'balance','input_tax_eligibility'=>'no','fiscal_start'=>'2025-07-01']);
$db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES('a','tax',?,1,?)")->execute([json_encode($tax),gmdate('c')]);
putenv('VEREIN_BALANCE_WORKSPACE=1');putenv('VEREIN_BALANCE_PERIODS=1');putenv('VEREIN_MODE=synthetic');
$l=new L($db,'admin');$s=new S($db,'admin');$f=new Finance($db,'admin');$period=['start'=>'2025-07-01','end'=>'2026-06-30'];
$pid=$l->create($period,'Synthetic approved period',true);$bank=array_key_first((new \Kulturhus\Verein\BalanceSources($db,'admin'))->bankAccounts());
$l->setup($pid,1,[['account'=>$bank,'debit_cents'=>1000000,'credit_cents'=>0,'sphere'=>''],['account'=>'equity','debit_cents'=>0,'credit_cents'=>1000000,'sphere'=>'']],'Synthetic opening',true);
$make=function(string $amount='119.00',string $date='2025-11-01')use($db,$f):string{
    $id=$f->createEntry(['kind'=>'expense','description'=>'Synthetic invoice','amount'=>$amount,'document_date'=>$date,'due_date'=>$date,'category'=>'general','positions'=>[['description'=>'Synthetic original item','amount'=>$amount,'tax_rate'=>'19','sphere'=>'1','account'=>'68150','booking_key'=>'','input_tax'=>'none']]],keyId());
    (new Documents($db,'admin'))->upload('invoice.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='),['title'=>'Synthetic original','kind'=>'invoice','scope'=>'finance','links'=>['entry:'.$id]],keyId());return $id;
};
$options=['recognised_on'=>'2025-11-01','private_paid_on'=>''];$entry=$make();$p=$s->preview($period,'invoice',$entry,$options);
check(!$p['issues'],'cross-calendar invoice proposal: '.implode(' ',$p['issues']));check($p['year']===2026&&$p['snapshot']['source_catalog_year']===2025,'source catalog and closing year distinguished');
check(array_sum(array_column($p['vouchers'][0]['lines'],'debit_cents'))===11900&&!isset($p['accounts']['src_input_vat']),'no input VAT claim retains gross expense');
$state=MySqlFixture::snapshot($db);$s->preview($period,'invoice',$entry,$options);check($state===MySqlFixture::snapshot($db),'preview reads only');
$import=function(string $type,string $id,array $opts=[],?string $target=null)use($s,$l,$pid):array{$target??=$pid;$r=$l->report($target);$p=$s->preview($r['period'],$type,$id,$opts);if($p['issues'])throw new RuntimeException(implode(' ',$p['issues']));$l->importSource($target,$r['version'],$type,$id,$opts,$p['hash'],true);return $l->report($target);};
denied(fn()=>$l->importSource($pid,2,'invoice',$entry,$options,$p['hash'],false),'explicit review required');
denied(fn()=>$l->importSource($pid,2,'invoice',$entry,$options,str_repeat('0',64),true),'stale hash rejected');
denied(fn()=>$l->importSource($pid,2,'invoice',$entry,array_replace($options,['recognised_on'=>'2025-11-02']),$p['hash'],true),'edited date requires fresh review');
check($state===MySqlFixture::snapshot($db),'rejected requests leave every row unchanged');
denied(fn()=>(new S($db,'member'))->candidates($period),'member cannot list sources');check((bool)(new S($db,'other'))->preview($period,'invoice',$entry,$options)['issues'],'other tenant cannot see original');
$r=$import('invoice',$entry,$options);check($r['calculation']['annual_result_cents']===-11900&&count($r['sources'])===1,'original invoice posted once in actual period');
$b=$s->binding('invoice',$entry);$payable=$b['proposal']['snapshot']['settlement_account'];check($b['period_id']===$pid&&$b['first_revision']===3,'immutable source ownership resolves original event');
denied(fn()=>$import('invoice',$entry,$options),'same source cannot post twice');
denied(fn()=>$l->post($pid,3,keyId(),'2025-11-01','Synthetic forbidden','Synthetic',[],$b['entry_ids'][0]),'manual reversal of source posting refused');
$tx=$f->createTransaction(['account'=>'bank','booked_on'=>'2026-01-05','direction'=>'expense','amount'=>'50','counterparty'=>'Synthetic supplier','description'=>'Partial payment'],keyId());
check((bool)$s->preview($period,'transaction',$tx)['issues'],'unallocated payment refused');$f->allocate($tx,1,$entry,1,'50',keyId());$r=$import('transaction',$tx);
check($r['calculation']['annual_result_cents']===-11900&&$r['calculation']['accounts'][$payable]['amount_cents']===6900,'partial payment only reduces debt');
check(!$r['issues'],'allocation version changes do not invalidate invoice');
$db->exec("INSERT INTO contacts(id,club_id,kind,name,email,address) VALUES('mara','a','person','Mara Synthetic','mara@example.invalid','Synthetic road'); INSERT INTO memberships(id,club_id,contact_id,member_number,status,starts_on) VALUES('member-mara','a','mara','M-1','aktiv','2025-01-01')");
$private=$make('238');(new ExpenseReimbursements($db,'admin'))->createForEntry($private,'mara','member',keyId());
check(($s->preview($period,'invoice',$private,$options)['snapshot']['claim']['contact_name']??'')==='Mara Synthetic','incomplete private date preserves member context for visible input');
check((bool)$s->preview($period,'invoice',$private,$options)['issues'],'private payment date not invented');$privateOptions=array_replace($options,['private_paid_on'=>'2025-12-01']);
$state=MySqlFixture::snapshot($db);$db->exec("CREATE TRIGGER synthetic_anchor_failure BEFORE INSERT ON balance_period_sources FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic anchor failure'");
try{try{$import('invoice',$private,$privateOptions);throw new RuntimeException('missing failure');}catch(PDOException $e){check(str_contains($e->getMessage(),'Synthetic anchor failure'),'source anchor failure injected');}}finally{$db->exec('DROP TRIGGER synthetic_anchor_failure');}
check($state===MySqlFixture::snapshot($db),'failed anchor rolls back event, two bookings, accounts and audit');
$r=$import('invoice',$private,$privateOptions);$b=$s->binding('invoice',$private);check(count($b['entry_ids'])===2&&$r['calculation']['annual_result_cents']===-35700,'private payment is separate debt reclassification without duplicate expense');
$refund=$f->createTransaction(['account'=>'bank','booked_on'=>'2026-01-10','direction'=>'expense','amount'=>'238','counterparty'=>'Mara Synthetic','description'=>'Reimbursement'],keyId());$f->allocate($refund,1,$private,(int)$f->entry($private)['version'],'238',keyId());$r=$import('transaction',$refund);
check($r['calculation']['accounts'][$b['proposal']['snapshot']['settlement_account']]['amount_cents']===0&&$r['calculation']['annual_result_cents']===-35700,'refund clears member payable only');
$retained=$r['entries'];$anchor=MySqlFixture::rows($db,'balance_period_sources');$oldHash=$s->binding('invoice',$entry)['proposal']['hash'];
$db->prepare('UPDATE accounting_positions SET description=? WHERE club_id=? AND entry_id=?')->execute(['Synthetic corrected wording','a',$entry]);
check((bool)$l->report($pid)['source_issues'],'source drift blocks approval');denied(fn()=>(new BalancePeriodExport($db,'admin'))->build($pid),'changed source blocks export');
$fresh=$s->preview($period,'invoice',$entry,$options);$l->correctSource($pid,$r['version'],'invoice',$entry,$options,$fresh['hash'],true,$oldHash,'Synthetic original checked');$r=$l->report($pid);
check(array_intersect_key($r['entries'],$retained)===$retained&&count($r['entries'])===count($retained)+2,'correction retains original and appends exact reversal plus replacement');
check(MySqlFixture::rows($db,'balance_period_sources')===$anchor,'correction never changes ownership anchor');check($r['calculation']['annual_result_cents']===-35700&&$r['source_issues'],'dependent bank source requires renewed review, no new expense');
$oldHash=$s->binding('transaction',$tx)['proposal']['hash'];$fresh=$s->preview($period,'transaction',$tx);$l->correctSource($pid,$r['version'],'transaction',$tx,[],$fresh['hash'],true,$oldHash,'Synthetic bank allocation confirmed');$r=$l->report($pid);
check(!$r['issues']&&$r['calculation']['accounts'][$payable]['amount_cents']===6900,'dependent payment correction restores reconciled journal');
$export=(new BalancePeriodExport($db,'admin'))->build($pid);check(count($export['snapshot']['history'])===$r['version']&&count($export['snapshot']['report']['sources'])===4,'working papers retain original and corrected source history');
$all=['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true];$l->close($pid,$r['version'],$r['journal_sha256'],$all,'Synthetic board closing');
$pid2=$l->create(['start'=>'2026-07-01','end'=>'2026-12-31'],'Synthetic short following period',true);
$last=$f->createTransaction(['account'=>'bank','booked_on'=>'2026-07-05','direction'=>'expense','amount'=>'69','counterparty'=>'Synthetic supplier','description'=>'Final payment'],keyId());$f->allocate($last,1,$entry,(int)$f->entry($entry)['version'],'69',keyId());$r2=$import('transaction',$last,[],$pid2);
check($r2['calculation']['annual_result_cents']===0&&$r2['calculation']['accounts'][$payable]['amount_cents']===0,'next-period payment clears carried debt, not current expense');
check(!$l->report($pid)['issues'],'later payment does not change closed invoice proof');
denied(fn()=>$l->correctSource($pid,$l->report($pid)['version'],'invoice',$entry,$options,$fresh['hash'],true,str_repeat('0',64),'Synthetic'),'closed period cannot change source');
$missing=$make('10','2026-08-01');denied(fn()=>$l->close($pid2,$r2['version'],$r2['journal_sha256'],$all,'Synthetic premature'),'unimported original prevents closing');
require_once __DIR__.'/../src/Web/View.php';$_SESSION=['actor'=>'admin','csrf'=>'synthetic'];$_SERVER['REQUEST_METHOD']='GET';$_POST=[];
$_GET=['periods'=>'1','period_id'=>$pid2,'period_source'=>'invoice:'.$missing];ob_start();require __DIR__.'/../views/Finance/Balance/balance-ledger.php';$html=ob_get_clean();
check(str_contains($html,'value="balance-period-source-import"')&&str_contains($html,'In diesen Zeitraum übernehmen'),'open-period view offers reviewed invoice takeover');
$_GET=['periods'=>'1','period_id'=>$pid,'period_source'=>'invoice:'.$private];ob_start();require __DIR__.'/../views/Finance/Balance/balance-ledger.php';$html=ob_get_clean();check(!str_contains($html,'value="balance-period-source-import"'),'closed view offers no source writes');
$old=MySqlFixture::snapshot($db);$opts=['recognised_on'=>'2026-08-01','private_paid_on'=>''];$proposal=$s->preview(['start'=>'2026-07-01','end'=>'2026-12-31'],'invoice',$missing,$opts);
denied(fn()=>$l->importSource($pid2,0,'invoice',$missing,$opts,$proposal['hash'],true),'stale period version rejected');
putenv('VEREIN_BALANCE_PERIODS=0');denied(fn()=>$l->importSource($pid2,$l->report($pid2)['version'],'invoice',$missing,$opts,$proposal['hash'],true),'disabled period adapter cannot write');putenv('VEREIN_BALANCE_PERIODS=1');check(MySqlFixture::snapshot($db)===$old,'stale and disabled requests are no-ops');
check(!$s->preview(['start'=>'2026-07-01','end'=>'2026-12-31'],'invoice',$missing,['recognised_on'=>'2026-08-01','private_paid_on'=>''])['issues'],'current-year original available');
foreach(['DELETE FROM balance_period_sources',"UPDATE balance_period_sources SET source_id=source_id"] as $sql){try{$db->exec($sql);throw new RuntimeException('anchor writable');}catch(PDOException){check(true,'native source ownership immutable');}}
check(!MySqlFixture::foreignKeyErrors($db),'period source foreign keys intact');
echo "$n period source checks passed; synthetic MySQL only\n";
}
