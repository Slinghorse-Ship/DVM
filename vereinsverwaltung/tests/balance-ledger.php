<?php declare(strict_types=1);
ob_start();require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/mysql-bootstrap.php';
use Kulturhus\Verein\{BalanceLedger,Database,SettingsSchema,TaxFilings,TaxFilingScope,TaxPreparation};
use Kulturhus\Verein\Tests\MySqlFixture;
$checks=0;function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";$checks++;}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
function pair(string $debit,string $credit,int $amount,string $sphere='1'):array{return [['account'=>$debit,'debit_cents'=>$amount,'credit_cents'=>0,'sphere'=>in_array($debit,['expense','revenue'],true)?$sphere:''],['account'=>$credit,'debit_cents'=>0,'credit_cents'=>$amount,'sphere'=>in_array($credit,['expense','revenue'],true)?$sphere:'']];}
function keyId():string{return bin2hex(random_bytes(16));}
$db=MySqlFixture::connect();Database::migrate($db);Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic Balance Club'),('b','Synthetic EÜR Club');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('finance','a','treasurer',0),('board','a','treasurer',1),('member','a','member',0),('editor','a','editor',0),('other','b','treasurer',0)");
$f=new BalanceLedger($db,'finance');$board=new BalanceLedger($db,'board');$other=new BalanceLedger($db,'other');
denied(fn()=>$f->create(2025),'unreleased pilot cannot write without explicit operator flag');putenv('VEREIN_BALANCE_WORKSPACE=1');
denied(fn()=>$f->create(2025),'EÜR default never changed by balance creation');
check((int)$db->query('SELECT COUNT(*) FROM balance_years')->fetchColumn()===0,'no balance writes for EÜR');
$tax=SettingsSchema::defaults('tax');$tax['accounting_basis']='balance';$q=$db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES('a','tax',?,1,?)");$q->execute([json_encode($tax),gmdate('c')]);
denied(fn()=>(new BalanceLedger($db,'member'))->create(2025),'member cannot create balance year');denied(fn()=>(new BalanceLedger($db,'editor'))->report(2025),'editor cannot read balance data');
$f->create(2025);check($f->report(2025)['state']==='setup','year starts with explicitly unconfirmed opening');
denied(fn()=>$f->create(2025),'duplicate year rejected');denied(fn()=>$f->create(2026),'cannot skip unclosed prior year');
$chart=BalanceLedger::defaultChart();$opening=pair('cash','equity',100000);
denied(fn()=>$f->post(2025,1,keyId(),'2025-01-01','Invoice','Synthetic receipt',pair('expense','liabilities',10000)),'no bookings before opening confirmation');
denied(fn()=>$f->setup(2025,1,$chart,$opening,'equity','Opening evidence',false),'opening review required');
denied(fn()=>$f->setup(2025,2,$chart,$opening,'equity','Opening evidence',true),'stale setup rejected');
$f->setup(2025,1,$chart,$opening,'equity','Synthetic opening statement',true);
denied(fn()=>$f->setup(2025,2,$chart,[],'equity','Overwrite',true),'confirmed opening cannot be silently replaced');
$entry=$f->post(2025,2,keyId(),'2025-01-02','Supplier invoice','Synthetic invoice A',pair('expense','liabilities',10000));
denied(fn()=>$f->post(2025,2,keyId(),'2025-01-02','Duplicate','Synthetic invoice',pair('expense','liabilities',10000)),'concurrent/stale request rejected');
denied(fn()=>$f->post(2025,3,$entry,'2025-01-02','Duplicate','Synthetic invoice',pair('expense','liabilities',10000)),'same request key cannot double book');
denied(fn()=>$other->post(2025,3,keyId(),'2025-01-02','Attack','Other club',pair('expense','liabilities',10000)),'cross-club posting rejected');
check($other->report(2025)['state']==='missing','other club cannot read existing ledger');
$f->post(2025,3,keyId(),'2025-02-02','Supplier paid','Synthetic bank statement',pair('liabilities','cash',10000));
$r=$f->report(2025);check($r['calculation']['annual_result_cents']===-10000&&$r['calculation']['accounts']['cash']['amount_cents']===90000,'persisted invoice/payment counted once');
$advance=$f->post(2025,4,keyId(),'2025-03-01','Mara paid privately','Synthetic invoice B',pair('expense','member_payables',103550));
$f->post(2025,5,keyId(),'2025-03-03','Partial reimbursement','Synthetic reimbursement',pair('member_payables','cash',5000));
check($f->report(2025)['calculation']['accounts']['member_payables']['amount_cents']===98550,'member reimbursement balance persists correctly');
$err=$f->post(2025,6,keyId(),'2025-04-01','Incorrect fee','Synthetic incorrect invoice',pair('expense','liabilities',37));
denied(fn()=>$f->reverse(2025,7,$err,'2025-03-01','Premature',keyId()),'reversal before original date rejected');
$rev=$f->reverse(2025,7,$err,'2025-04-02','Incorrect fee reversed',keyId());
denied(fn()=>$f->reverse(2025,8,$err,'2025-04-03','Again',keyId()),'double reversal rejected');
denied(fn()=>$f->reverse(2025,8,$rev,'2025-04-03','Reverse reversal',keyId()),'reversal chain rejected');
check(count($f->report(2025)['entries'])===6,'originals and reversal retained');
$all=['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true];
denied(fn()=>$f->close(2025,8,$all,'Attempt'),'treasurer cannot finalise without explicit board authority');
denied(fn()=>$board->close(2025,8,[],'Incomplete review'),'incomplete closing checks rejected');
$board->close(2025,8,$all,'Synthetic full review with all sources');$closed=$board->report(2025);
check($closed['state']==='closed'&&!$closed['ready_for_tax_export'],'locked ledger never falsely claims ELSTER readiness');
denied(fn()=>$f->post(2025,9,keyId(),'2025-12-31','Late edit','No',pair('expense','liabilities',1)),'posted year immutable');
denied(fn()=>$f->reverse(2025,9,$entry,'2025-12-31','Late reversal',keyId()),'closed year reversal denied');
$f->create(2026);$next=$f->report(2026);
check($next['state']==='open'&&$next['calculation']['annual_result_cents']===0&&$next['calculation']['accounts']['member_payables']['amount_cents']===98550,'prior year carries exact balances, not prior income');
check($next['previous_sha256']===$closed['closed_sha256'],'carry forward bound to immutable prior snapshot');
denied(fn()=>$board->close(2026,1,$all,'Too early'),'ongoing year cannot close');
$f->addAccount(2026,1,'second_bank','Second synthetic bank','cash');check(isset($f->report(2026)['accounts']['second_bank']),'separate bank account can be added with zero opening');
denied(fn()=>$f->addAccount(2026,2,'second_bank','Overwrite bank','liabilities'),'existing account and classification cannot be overwritten');
denied(fn()=>$f->addAccount(2025,9,'late','Late account','cash'),'closed chart cannot be extended');
$tax['fiscal_start']='2026-07-01';$db->prepare("UPDATE club_settings SET payload=? WHERE club_id='a' AND section='tax'")->execute([json_encode($tax)]);
denied(fn()=>$f->post(2026,2,keyId(),'2026-01-01','Wrong fiscal year','Evidence',pair('expense','liabilities',1)),'unsupported fiscal year blocked instead of calendar reinterpretation');
$tax['fiscal_start']='';$db->prepare("UPDATE club_settings SET payload=? WHERE club_id='a' AND section='tax'")->execute([json_encode($tax)]);
$rdb=MySqlFixture::restoreBackup($db);foreach(['balance_years','balance_entries'] as $table)check(MySqlFixture::rows($db,$table)===MySqlFixture::rows($rdb,$table),'restore exactly preserves '.$table);
check((new BalanceLedger($rdb,'finance'))->report(2025)['closed_sha256']===$closed['closed_sha256'],'closed journal verified after MySQL restore');
check(!MySqlFixture::foreignKeyErrors($db),'club and year foreign keys consistent');
$snapshot=MySqlFixture::snapshot($db);$f->report(2025);check(MySqlFixture::snapshot($db)===$snapshot,'report read has no database writes');
$rdb->beginTransaction();try{
    $tampered=pair('expense','liabilities',2);$rdb->prepare('UPDATE balance_entries SET posting_lines=? WHERE club_id=? AND tax_year=? AND id=?')->execute([json_encode($tampered),'a',2025,$err]);
    denied(fn()=>(new BalanceLedger($rdb,'finance'))->report(2025),'closed content tampering detected');
}finally{if($rdb->inTransaction())$rdb->rollBack();}
// Real preparation integration: no real members, mail, bank calls or tax transmissions.
$bundle=(new TaxPreparation($db,'finance'))->build(2025,2025,'Synthetic activity');
check(isset($bundle['files']['2025/bilanzjournal.json'])&&$bundle['snapshot']['reports'][2025]['balance']['state']==='closed','tax bundle includes calculated balance and exact journal');
check($bundle['snapshot']['form_mapping']['transmission']==='unavailable','incomplete balance tax mapping explicitly blocks export semantics');
$preview=$bundle['snapshot'];$preview['tax']['data']['fiscal_start']='2025-07-01';check(str_contains(TaxPreparation::summaryHtml($preview),'<strong>Abweichendes Wirtschaftsjahr:</strong>'),'non-calendar fiscal year warning uses actual settings data');
$euer=(new TaxPreparation($db,'other'))->build(2025,2025,'Synthetic EÜR activity');check(!isset($euer['snapshot']['reports'][2025]['balance']),'EÜR bundle unchanged by balance workspace');
function input(array $a,string $k,string $default=''):string{return is_string($a[$k]??null)?$a[$k]:$default;}
function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function hidden(string $k,mixed $v):string{return '<input type="hidden" name="'.e($k).'" value="'.e($v).'">';}
function csrf():string{return hidden('csrf','synthetic-csrf');}function url(array $v):string{return '?'.http_build_query($v);}
$render=static function(string $view,array $get,string $actor)use($db):string{$_GET=$get;$_POST=[];$_SESSION=['actor'=>$actor];$_SERVER['REQUEST_METHOD']='GET';ob_start();require \Kulturhus\Verein\Web\Templates::path($view);return ob_get_clean();};
$html=$render('balance-ledger',['balance_year'=>'2025'],'finance');check(str_contains($html,'Bilanzjournal festgeschrieben')&&!str_contains($html,'value="balance-post"'),'closed UI has no write actions');
$html=$render('balance-ledger',['balance_year'=>'2026'],'finance');check(str_contains($html,'synthetic-csrf')&&str_contains($html,'value="balance-post"'),'open UI has CSRF protected posting form');
$ans=array_fill_keys(array_keys(TaxFilingScope::questions()),'no');$ans['accounting']='euer';$ans['vat']='small';$ans['notices_checked']='yes';(new TaxFilings($db,'other'))->saveProfile(2025,$ans,0);
$html=$render('tax-filings',['tax_year'=>'2025'],'other');check(!str_contains($html,'Lohnsteuer-Anmeldung')&&!str_contains($html,'Lohnsteuerbescheinigungen')&&!str_contains($html,'ELStAM'),'no payroll form cards after confirmed no employees');
$ui=getenv('VEREIN_BALANCE_UI_OUTPUT');
if($ui){
    if(!is_dir($ui)||!str_starts_with(realpath($ui),'/workspace/.local/'))throw new RuntimeException('Private synthetic UI output required');
    $db->exec("INSERT INTO clubs VALUES('c','Synthetic Opening Club');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('opening-actor','c','treasurer',1)");
    $db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES('c','tax',?,1,?)")->execute([json_encode($tax),gmdate('c')]);(new BalanceLedger($db,'opening-actor'))->create(2025);
    foreach(['balance-closed'=>[2025,'finance'],'balance-open'=>[2026,'finance'],'balance-opening'=>[2025,'opening-actor']] as $name=>[$year,$actor]){
        $html=$render('balance-ledger',['balance_year'=>(string)$year],$actor);$css=(string)file_get_contents(__DIR__.'/../public/style.css');
        file_put_contents($ui.'/'.$name.'.html','<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Synthetic balance UI</title><style>'.$css.'</style><main style="margin:0;padding:16px">'.$html.'</main></html>');
    }
}
echo "$checks balance ledger checks passed; isolated MySQL and synthetic data only\n";
