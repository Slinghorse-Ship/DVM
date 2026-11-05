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
$before=$l->report($pid);$first=$l->importSource($pid,$before['version'],'invoice',$entry,$options,$p['hash'],true);
$accepted=$l->report($pid);check($accepted['version']===$before['version']+1&&$accepted['calculation']['annual_result_cents']===-11900,'actual first original invoice import really commits');
$whole=MySqlFixture::snapshot($db);$repeat=$l->importSource($pid,$before['version'],'invoice',$entry,$options,$p['hash'],true);
check(is_array($first)&&$repeat===$first,'actual identical original source retry returns the first complete stored event');
check(MySqlFixture::snapshot($db)===$whole,'actual identical original source retry changes no whole original row');

$firstArgs=[$pid,$before['version'],'invoice',$entry,$options,$p['hash'],true];
check($first===array_slice($l->history($pid),-1)[0],'first source outcome is the actual whole stored event');
check($l->importSource(...[...$firstArgs,$before['journal_sha256']])===$first,'optional original journal hash binds the same first source event');
$unchanged=MySqlFixture::snapshot($db);
denied(fn()=>$l->importSource(...[...$firstArgs,str_repeat('0',64)]),'wrong original journal cannot borrow first source');
$changed=$firstArgs;$changed[6]=false;denied(fn()=>$l->importSource(...$changed),'unchecked source cannot borrow first source');
$changed=$firstArgs;$changed[4]=array_replace($options,['recognised_on'=>'2025-11-02']);denied(fn()=>$l->importSource(...$changed),'changed original source options cannot borrow first source');
$changed=$firstArgs;$changed[5]=str_repeat('0',64);denied(fn()=>$l->importSource(...$changed),'changed original source digest cannot borrow first source');
$db->exec("INSERT INTO actors(id,club_id,role,is_superuser) VALUES('peer','a','treasurer',1)");$peer=new L($db,'peer');$unchanged=MySqlFixture::snapshot($db);
denied(fn()=>$peer->importSource(...$firstArgs),'different actual financial actor cannot borrow first source');
denied(fn()=>(new L($db,'other'))->importSource(...$firstArgs),'foreign actual club cannot borrow first source');
check(MySqlFixture::snapshot($db)===$unchanged,'all actual wrong-original retries preserve every whole row');
$decisions=[];
$import=function(string$type,string$id,array$opts=[])use($l,$s,$pid,&$decisions):array{$before=$l->report($pid);$proposal=$s->preview($before['period'],$type,$id,$opts);if($proposal['issues'])throw new RuntimeException(implode(' ',$proposal['issues']));$args=[$pid,$before['version'],$type,$id,$opts,$proposal['hash'],true];$event=$l->importSource(...[...$args,$before['journal_sha256']]);$decisions[]=[$args,$event];return$event;};
$payments=[];
foreach(['50.00','69.00']as$amount){$tx=$f->createTransaction(['account'=>'bank','booked_on'=>'2025-12-01','direction'=>'expense','amount'=>$amount,'counterparty'=>'Original supplier','description'=>'Actual original payment '.$amount],keyId());$f->allocate($tx,1,$entry,(int)$f->entry($entry)['version'],$amount,keyId());$payments[]=$tx;$import('transaction',$tx);}
$current=$l->report($pid);check($current['calculation']['annual_result_cents']===-11900&&$current['calculation']['accounts'][$bank]['closing_cents']===988100,'two actual original partial payments clear debt without a second expense');
$whole=MySqlFixture::snapshot($db);check($l->importSource(...$firstArgs)===$first&&MySqlFixture::snapshot($db)===$whole,'first actual invoice outcome survives later real bank imports');
$beforeCorrection=$l->report($pid);$newOptions=array_replace($options,['recognised_on'=>'2025-11-02']);$proposal=$s->preview($period,'invoice',$entry,$newOptions);$correctArgs=[$pid,$beforeCorrection['version'],'invoice',$entry,$newOptions,$proposal['hash'],true,$p['hash'],'Actual original correction <&>'];$corrected=$l->correctSource(...[...$correctArgs,$beforeCorrection['journal_sha256']]);
check($corrected['payload']['kind']==='source_corrected'&&count($corrected['payload']['data']['entries'])===2,'real source correction returns its actual reversal and replacement originals');
$whole=MySqlFixture::snapshot($db);check($l->correctSource(...$correctArgs)===$corrected&&$l->importSource(...$firstArgs)===$first&&MySqlFixture::snapshot($db)===$whole,'both original outcomes survive actual source drift without changing current originals');
foreach($payments as$tx){$current=$l->report($pid);$binding=$s->binding('transaction',$tx);$fresh=$s->preview($period,'transaction',$tx);$args=[$pid,$current['version'],'transaction',$tx,[],$fresh['hash'],true,$binding['proposal']['hash'],'Actual invoice correction reconciled'];$event=$l->correctSource(...$args);$decisions[]=[$args,$event];}
$current=$l->report($pid);check(!$current['issues'],'actual invoice and both original bank corrections reconcile current sources');$closed=$l->close($pid,$current['version'],$current['journal_sha256'],array_fill_keys(['complete','valuation','cutoff','tax'],true),'Actual all original invoices and payments reviewed');
check($closed['payload']['kind']==='closed'&&$l->report($pid)['state']==='closed','actual complete original source journal closes');
$whole=MySqlFixture::snapshot($db);check($l->importSource(...$firstArgs)===$first&&$l->correctSource(...$correctArgs)===$corrected,'first actual source import and correction outcomes survive real closing');
foreach($decisions as[$args,$event])check(($event['payload']['kind']==='source_corrected'?$l->correctSource(...$args):$l->importSource(...$args))===$event,'actual first bank source event survives later correction and closing');
check(MySqlFixture::snapshot($db)===$whole,'all late original outcomes change no whole source owner event audit or financial original');
$db->exec("UPDATE actors SET role='member',is_superuser=0 WHERE id='admin'");$whole=MySqlFixture::snapshot($db);denied(fn()=>$l->importSource(...$firstArgs),'current withdrawn rights still deny original source replay');check(MySqlFixture::snapshot($db)===$whole,'withdrawn source replay remains entirely read-only');$db->exec("UPDATE actors SET role='treasurer',is_superuser=1 WHERE id='admin'");
putenv('VEREIN_BALANCE_PERIODS=0');denied(fn()=>$l->importSource(...$firstArgs),'current disabled period gate still denies original source replay');putenv('VEREIN_BALANCE_PERIODS=1');
restore_error_handler();echo json_encode(['suite'=>'balance-period-source-decisions','checks'=>$n,'external_actions'=>0],JSON_THROW_ON_ERROR),PHP_EOL;}
