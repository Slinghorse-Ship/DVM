<?php declare(strict_types=1);
namespace Kulturhus\Verein {function date(string $format,?int $time=null):string{return \gmdate($format,$time??\strtotime('2029-01-15 UTC'));}}
namespace {
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/mysql-bootstrap.php';require_once __DIR__.'/../src/Finance/Balance/BalancePeriodLedger.php';require_once __DIR__.'/../src/Finance/Balance/BalancePeriodExport.php';
use Kulturhus\Verein\{BalancePeriodLedger as L,BalancePeriodSources as S,BalanceSources,Database,SettingsSchema,Finance,Registry,Settings,ContributionRates,BalancePeriodExport};
use Kulturhus\Verein\Tests\MySqlFixture;
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$n=0;function check(bool $ok,string $why):void{global $n;if(!$ok)throw new RuntimeException($why);$n++;echo "PASS $why\n";}
function denied(callable $fn,string $why):void{try{$fn();}catch(DomainException){check(true,$why);return;}throw new RuntimeException($why);}
function keyId():string{return bin2hex(random_bytes(16));}
$db=MySqlFixture::connect();Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic period noninvoice'),('b','Synthetic foreign');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','treasurer',1),('other','b','treasurer',1),('member','a','member',0);INSERT INTO finance_accounts VALUES('bank','a','Synthetic bank','offline',0,'2025-07-01')");
$tax=array_replace(SettingsSchema::defaults('tax'),['accounting_basis'=>'balance','input_tax_eligibility'=>'no','fiscal_start'=>'2025-07-01']);
$db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES('a','tax',?,1,?)")->execute([json_encode($tax),gmdate('c')]);
putenv('VEREIN_BALANCE_WORKSPACE=1');putenv('VEREIN_BALANCE_PERIODS=1');putenv('VEREIN_MODE=synthetic');
$l=new L($db,'admin');$s=new S($db,'admin');$f=new Finance($db,'admin');$registry=new Registry($db,'admin');$period=['start'=>'2025-07-01','end'=>'2026-06-30'];
$pid=$l->create($period,'Synthetic fiscal year',true);$l->setup($pid,1,[],'Synthetic zero opening',true);$bank=array_key_first((new BalanceSources($db,'admin'))->bankAccounts());
$import=function(string $type,string $id,array $options=[],?string $target=null)use($l,$s,$pid):array{
    $target??=$pid;$r=$l->report($target);$p=$s->preview($r['period'],$type,$id,$options);if($p['issues'])throw new RuntimeException(implode(' ',$p['issues']));
    $l->importSource($target,$r['version'],$type,$id,$options,$p['hash'],true);return $l->report($target);
};
$person=function(string $name)use($registry):string{$id=$registry->saveContact(null,0,'person','Synthetic '.$name,strtolower($name).'@example.invalid','');$registry->addMembership($id,'','aktiv','2025-07-01');return $id;};
$pay=function(array $allocations,string $date)use($f):string{
    $sum=array_sum($allocations);$tx=$f->createTransaction(['account'=>'bank','booked_on'=>$date,'direction'=>'income','amount'=>Finance::decimal($sum),'counterparty'=>'Synthetic payer','description'=>'Synthetic mixed receipt'],keyId());
    foreach($allocations as $entry=>$cents)$f->allocate($tx,(int)$f->transactionDetail($tx)['version'],$entry,(int)$f->entry($entry)['version'],Finance::decimal($cents),keyId());return $tx;
};
$member=$person('Member');$single=$f->createAnnualContribution($member,2025,'','2025-08-01');$future=$f->createAnnualContribution($member,2026,'','2025-12-20');
$opts=['recognised_on'=>'2025-08-01','private_paid_on'=>''];$futureOpts=['recognised_on'=>'2026-01-01','private_paid_on'=>''];
$p=$s->preview($period,'invoice',$single,$opts);check(!$p['issues'],'period contribution uses genuine annual assessment without supplier PDF');
check($p['year']===2026&&$p['snapshot']['source_catalog_year']===2025&&$p['snapshot']['assessment']['year']===2025,'assessment, source catalog and closing year remain distinct');
check(isset($p['accounts']['src_code_40000'])&&!isset($p['accounts']['src_output_vat']),'contribution derives 40000 and no VAT');
$before=MySqlFixture::snapshot($db);$s->preview($period,'invoice',$single,$opts);check($before===MySqlFixture::snapshot($db),'assessment preview reads only');
check((bool)$s->preview($period,'invoice',$single,$futureOpts)['issues'],'cannot move 2025 assessment into 2026 even within same period');
check((bool)$s->preview($period,'invoice',$single,$opts+['invented'=>'value'])['issues'],'unexpected invoice options rejected');
check((bool)$s->preview($period,'invoice',$single,array_replace($opts,['private_paid_on'=>'2025-08-02']))['issues'],'contribution cannot become private reimbursement');
check((bool)(new S($db,'other'))->preview($period,'invoice',$single,$opts)['issues'],'foreign club cannot access assessment');
denied(fn()=>(new S($db,'member'))->candidates($period),'member cannot list sources');
$import('invoice',$single,$opts);$recv=$s->binding('invoice',$single)['proposal']['snapshot']['settlement_account'];
check($l->report($pid)['calculation']['accounts'][$recv]['amount_cents']===4800,'unpaid contribution is receivable not cash');
$rates=ContributionRates::all($db,'a');$settings=new Settings($db,'admin');$rate=array_column($settings->catalog(),null,'id')[$rates['single']['id']];$data=$rate['data'];$data['amount']='60.00';$settings->saveCatalog($rate['id'],$rate['version'],'contribution',$data);
check($s->preview($period,'invoice',$single,$opts)['hash']===$p['hash'],'new tariff does not rewrite assessed fee');
$futureP=$s->preview($period,'invoice',$future,$futureOpts);check(!$futureP['issues']&&$futureP['snapshot']['source_catalog_year']===2026,'pre-issued next-year contribution uses assessed-year catalog');
$candidate=array_column($s->candidates($period)['invoices'],null,'id');check($candidate[$future]['performance_date']==='2026-01-01','pre-issued contribution proposes assessed year not notice date');
$import('invoice',$future,$futureOpts);
$payer=$person('Family');$child=$person('Child');$registry->assignFamily($payer,'','new','Synthetic family');$family=$registry->family($payer);$registry->assignFamily($child,'',$family['id']);
$familyEntry=$f->createAnnualContribution($payer,2025,$family['id'],'2025-09-01');check($f->createAnnualContribution($child,2025,$family['id'],'2025-09-01')===$familyEntry,'one annual family assessment not one per person');
$familyOpts=['recognised_on'=>'2025-09-01','private_paid_on'=>''];$familyP=$s->preview($period,'invoice',$familyEntry,$familyOpts);check(!$familyP['issues']&&count($familyP['snapshot']['covered_members'])===2,'family evidence retains covered members');
$r=$import('invoice',$familyEntry,$familyOpts);check($r['calculation']['annual_result_cents']===16800,'two annual single claims plus one family claim recognised exactly once');
$notes=$f->entry($familyEntry)['notes'];$invalid=json_decode($notes,true);$invalid['payer']='missing';$db->prepare('UPDATE finance_entries SET notes=? WHERE club_id=? AND id=?')->execute([json_encode($invalid),'a',$familyEntry]);
check((bool)$l->report($pid)['source_issues'],'broken assessment blocks closing');$db->prepare('UPDATE finance_entries SET notes=? WHERE club_id=? AND id=?')->execute([$notes,'a',$familyEntry]);
$gift=$f->createEntry(['kind'=>'donation','description'=>'Synthetic free gift','amount'=>'100','category'=>'donations','document_date'=>'2025-08-01','due_date'=>'2025-08-01','positions'=>[['description'=>'Synthetic gift','amount'=>'100','tax_rate'=>'0','sphere'=>'1','account'=>'40400','booking_key'=>'']]],keyId());
check(!in_array($gift,array_column($s->candidates($period)['invoices'],'id'),true),'unpaid gift is not an invoice candidate or closing blocker');
check((bool)$s->preview($period,'invoice',$gift,$opts)['issues'],'gift cannot create unsupported receivable');
$tx=$pay([$single=>2000,$gift=>3000],'2026-02-01');$giftOpts=['donation_use'=>'unrestricted'];$p=$s->preview($period,'transaction',$tx);
check((bool)$p['issues']&&isset($p['snapshot']['donations'][$gift]),'factual donation question available before attempted import');
foreach([['donation_use'=>'needs_review'],['donation_use'=>'invented'],['donation_use'=>'unrestricted','account'=>'equity']] as $bad)check((bool)$s->preview($period,'transaction',$tx,$bad)['issues'],'unclear or forged gift answer rejected');
denied(fn()=>S::formOptions('transaction',['donation_use'=>['unrestricted']]),'array answer rejected');check(S::formOptions('transaction',['donation_use'=>''])===[],'empty answer preserves plain payment options');
$p=$s->preview($period,'transaction',$tx,$giftOpts);check(!$p['issues'],'mixed contribution and cash gift payment preview: '.implode(' ',$p['issues']));
check($p['snapshot']['donation_catalogs'][$gift]['source_year']===2025&&isset($p['accounts']['src_code_40400']),'old gift catalog retained at later receipt');
$before=MySqlFixture::snapshot($db);$s->preview($period,'transaction',$tx,$giftOpts);check($before===MySqlFixture::snapshot($db),'mixed payment preview reads only');
denied(fn()=>$l->importSource($pid,$l->report($pid)['version'],'transaction',$tx,$giftOpts,str_repeat('0',64),true),'stale mixed payment hash rejected');
check($before===MySqlFixture::snapshot($db),'rejected import changes no rows');
$r=$import('transaction',$tx,$giftOpts);check($r['calculation']['annual_result_cents']===19800&&$r['calculation']['accounts'][$recv]['amount_cents']===2800&&$r['calculation']['accounts'][$bank]['amount_cents']===5000,'mixed receipt adds only actual donation and settles assessed contribution');
denied(fn()=>$import('transaction',$tx,$giftOpts),'same receipt cannot post twice');
$tx2=$pay([$gift=>2000],'2026-02-03');$r=$import('transaction',$tx2,$giftOpts);check($r['calculation']['annual_result_cents']===21800,'second partial gift adds only actual receipt');
$originals=$r['entries'];$bindings=$r['sources'];$db->prepare('UPDATE finance_entries SET description=? WHERE club_id=? AND id=?')->execute(['Synthetic corrected gift','a',$gift]);
check(count($l->report($pid)['source_issues'])===2,'changed gift invalidates both partial receipt reviews');
foreach([$tx,$tx2] as $id){$r=$l->report($pid);$p=$s->preview($period,'transaction',$id,$giftOpts);$l->correctSource($pid,$r['version'],'transaction',$id,$giftOpts,$p['hash'],true,$bindings['transaction:'.$id]['proposal']['hash'],'Synthetic receipt review renewed');if($id===$tx)check((bool)$l->report($pid)['source_issues'],'first correction does not conceal second unreviewed receipt');}
$r=$l->report($pid);check(!$r['issues']&&$r['calculation']['annual_result_cents']===21800,'renewed gift evidence preserves profit and reconciles');check(array_intersect_key($r['entries'],$originals)===$originals,'correction keeps original journal entries byte-identical');
$db->prepare("UPDATE accounting_positions SET sphere='4' WHERE club_id=? AND entry_id=?")->execute(['a',$gift]);check((bool)$s->preview($period,'transaction',$tx,$giftOpts)['issues'],'commercial assignment cannot silently become free gift');$db->prepare("UPDATE accounting_positions SET sphere='1',tax_rate=19,tax_cents=1597 WHERE club_id=? AND entry_id=?")->execute(['a',$gift]);check((bool)$s->preview($period,'transaction',$tx,$giftOpts)['issues'],'tax-bearing payment cannot silently become tax-free gift');$db->prepare('UPDATE accounting_positions SET tax_rate=0,tax_cents=0 WHERE club_id=? AND entry_id=?')->execute(['a',$gift]);
$foreign=(new S($db,'other'))->preview($period,'transaction',$tx,$giftOpts);check((bool)$foreign['issues']&&!$foreign['snapshot'],'foreign club sees no donation details');
require_once __DIR__.'/../src/Web/View.php';$_SESSION=['actor'=>'admin','csrf'=>'synthetic'];$_SERVER['REQUEST_METHOD']='GET';$_POST=[];
$pending=$pay([$familyEntry=>1000,$gift=>1000],'2026-03-01');
foreach(['','needs_review','unrestricted'] as $answer){$_GET=['periods'=>'1','period_id'=>$pid,'period_source'=>'transaction:'.$pending,'donation_use'=>$answer];ob_start();require __DIR__.'/../views/Finance/Balance/balance-ledger.php';$html=ob_get_clean();check(str_contains($html,'Darf der Verein die Geldspenden'),'factual question before POST: '.$answer);check(str_contains($html,'value="balance-period-source-import"')===($answer==='unrestricted'),'only reviewed gift can be imported: '.$answer);if($answer==='unrestricted'){check(str_contains($html,'name="donation_use" value="unrestricted"'),'confirmed factual answer carried to POST');file_put_contents(__DIR__.'/balance-period-noninvoice-ui.html',$html);}}
$import('transaction',$pending,$giftOpts);
$_GET=['periods'=>'1','period_id'=>$pid,'period_source'=>'invoice:'.$future];ob_start();require __DIR__.'/../views/Finance/Balance/balance-ledger.php';$html=ob_get_clean();check(str_contains($html,'Beitragsforderung entstanden am')&&str_contains($html,'Mitgliedsbeitrag 2026'),'contribution UI uses contribution not delivery language');
$r=$l->report($pid);check(!$s->missing($period)&&!$s->bankIssues($period,$r['calculation']),'source completeness and cash reconcile without paid or promised gift duplication');
$checks=['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true];$l->close($pid,$r['version'],$r['journal_sha256'],$checks,'Synthetic reviewed closing');$closed=$l->history($pid);
$period2=['start'=>'2026-07-01','end'=>'2026-12-31'];$pid2=$l->create($period2,'Synthetic short following year',true);
check(!in_array($future,array_column($s->candidates($period2)['invoices'],'id'),true),'assessed fee does not reappear in following short year');
$last=$pay([$single=>2800,$gift=>4000],'2026-07-05');$r2=$import('transaction',$last,$giftOpts,$pid2);
check($r2['calculation']['annual_result_cents']===4000&&$r2['calculation']['accounts'][$recv]['amount_cents']===0,'later receipt recognises only remaining gift and clears old dues');
check($l->history($pid)===$closed&&!$l->report($pid)['issues'],'later allocation does not mutate or invalidate closed earlier receipts');
$p=$s->preview($period2,'transaction',$last,$giftOpts);$original=$f->entry($gift)['description'];$db->prepare('UPDATE finance_entries SET description=? WHERE club_id=? AND id=?')->execute(['Changed old gift','a',$gift]);
check((bool)$l->report($pid)['source_issues']&&(bool)$l->report($pid2)['predecessor_issues'],'changed closed donor proof blocks following approval');$db->prepare('UPDATE finance_entries SET description=? WHERE club_id=? AND id=?')->execute([$original,'a',$gift]);
$bundle=(new BalancePeriodExport($db,'admin'))->build($pid2);check(str_contains($bundle['files']['pruefverlauf.json'],$gift)&&str_contains($bundle['files']['pruefverlauf.json'],'donation_catalogs'),'working papers retain donation and catalog provenance');
$newGift=$f->createEntry(['kind'=>'donation','description'=>'Synthetic 2026 gift','amount'=>'5','category'=>'donations','document_date'=>'2026-07-10','due_date'=>'2026-07-10','positions'=>[['description'=>'Synthetic gift','amount'=>'5','tax_rate'=>'0','sphere'=>'1','account'=>'40400','booking_key'=>'']]],keyId());
$splitTx=$f->createTransaction(['account'=>'bank','booked_on'=>'2026-07-10','direction'=>'income','amount'=>'5','counterparty'=>'Synthetic donor','description'=>'Two allocations for one gift'],keyId());
foreach(['2','3'] as $amount)$f->allocate($splitTx,(int)$f->transactionDetail($splitTx)['version'],$newGift,(int)$f->entry($newGift)['version'],$amount,keyId());
$splitP=$s->preview($period2,'transaction',$splitTx,$giftOpts);check(!$splitP['issues']&&array_sum(array_column($splitP['vouchers'][0]['lines'],'credit_cents'))===500,'two allocation rows for same gift are combined once');
$r2=$import('transaction',$splitTx,$giftOpts,$pid2);check($r2['calculation']['annual_result_cents']===4500,'2025 and 2026 catalog labels share one verified donation account');
$ordinary=$pay([$familyEntry=>1000],'2026-08-01');
$_GET=['periods'=>'1','period_id'=>$pid2,'period_source'=>'transaction:'.$ordinary,'donation_use'=>'unrestricted'];ob_start();require __DIR__.'/../views/Finance/Balance/balance-ledger.php';$html=ob_get_clean();
check(str_contains($html,'Auswahl zurücksetzen')&&str_contains($html,'donation_use=#period-sources'),'obsolete donation answer can be explicitly cleared');
$_GET['donation_use']='';ob_start();require __DIR__.'/../views/Finance/Balance/balance-ledger.php';$html=ob_get_clean();check(str_contains($html,'value="balance-period-source-import"'),'ordinary payment becomes importable after clearing obsolete answer');
$r2=$import('transaction',$ordinary,[],$pid2);check($r2['calculation']['annual_result_cents']===4500,'ordinary contribution payment still creates no new revenue');
$r2=$l->report($pid2);$l->close($pid2,$r2['version'],$r2['journal_sha256'],$checks,'Synthetic second closing');check($l->report($pid2)['state']==='closed','two fiscal periods reconcile with unpaid carried contributions');
foreach(['club_outbox','contribution_deliveries','outgoing_invoice_deliveries'] as $table)check((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'no mail queued in '.$table);
check(!MySqlFixture::foreignKeyErrors($db),'source references preserve tenant boundaries');
echo "$n period contribution and donation checks passed; synthetic MySQL only\n";
}
