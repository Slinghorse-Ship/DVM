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
restore_error_handler();}
