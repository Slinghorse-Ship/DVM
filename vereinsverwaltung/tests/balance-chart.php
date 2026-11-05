<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/mysql-bootstrap.php';
use Kulturhus\Verein\{BalanceLedger,Database,SettingsSchema};
use Kulturhus\Verein\Tests\MySqlFixture;
$db=MySqlFixture::connect();Database::migrate($db);$n=0;
function check(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$db->exec("INSERT INTO clubs VALUES('a','Synthetic chart');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('finance','a','treasurer',1)");
$tax=SettingsSchema::defaults('tax');$tax['accounting_basis']='balance';$db->prepare("INSERT INTO club_settings VALUES('a','tax',?,1,?)")->execute([json_encode($tax),gmdate('c')]);
putenv('VEREIN_BALANCE_WORKSPACE=1');$l=new BalanceLedger($db,'finance');$l->create(2025);
$l->addAccount(2025,1,'bank2','','revenue','18000');$r=$l->report(2025);
check($r['accounts']['bank2']['group']==='cash','server derives group from code, not browser');
check($r['accounts']['bank2']['skr_code']==='18000'&&$r['accounts']['bank2']['name']!=='','code and catalog name retained');
function input(array $a,string $k,string $default=''):string{return is_string($a[$k]??null)?$a[$k]:$default;}
function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function hidden(string $k,mixed $v):string{return '<input type="hidden" name="'.e($k).'" value="'.e($v).'">';}
function csrf():string{return hidden('csrf','synthetic');}function url(array $v):string{return '?'.http_build_query($v);}
$_GET=['balance_year'=>'2025'];$_POST=[];$_SESSION=['actor'=>'finance'];$_SERVER['REQUEST_METHOD']='GET';
ob_start();require __DIR__.'/../views/Finance/Balance/balance-ledger.php';$html=ob_get_clean();
check((bool)preg_match('/<label>[^<]*18000[^<]*<input name="opening_amounts\[bank2\]"/',$html),'numbered opening balance clearly identifies SKR42 code');
denied(fn()=>$l->addAccount(2025,2,'bad','Wrong','cash','99999'),'unreviewed code rejected');
denied(fn()=>$l->addAccount(2025,1,'stale','Stale','cash','18000'),'stale form rejected');
$opening=[['account'=>'bank2','debit_cents'=>10000,'credit_cents'=>0,'sphere'=>''],['account'=>'equity','debit_cents'=>0,'credit_cents'=>10000,'sphere'=>'']];
$l->setup(2025,2,$r['accounts'],$opening,'equity','Synthetic statement',true);
check($l->report(2025)['calculation']['accounts']['bank2']['amount_cents']===10000,'numbered account survives opening workflow');
denied(fn()=>$l->addAccount(2025,3,'bank2','Duplicate','cash','18100'),'existing account cannot be reclassified');
$l->addAccount(2025,3,'bank3','Second bank','cash','18100');check($l->report(2025)['accounts']['bank3']['name']==='Second bank','custom label retained');
$l->addAccount(2025,4,'building','','','03000');$r=$l->report(2025);
check($r['accounts']['building']['skr_code']==='03000'&&$r['accounts']['building']['group']==='fixed_assets','printed PDF position supplies group without user tax knowledge');
check(\Kulturhus\Verein\BalanceTaxMapping::preview(2025,$r['accounts'])['mapping']['building']==='bs.ass.fixAss.tan.landBuildings.buildingsOnOwnLand.buildings','independently reviewed building rule remains available');
$costAccount=['land'=>['name'=>'Synthetic land','group'=>'fixed_assets','skr_code'=>'02350']];
check(\Kulturhus\Verein\BalanceTaxMapping::preview(2025,$costAccount)['rows']['land']['status']==='review','printed cost position alone cannot grant XML approval');
denied(fn()=>$l->addAccount(2025,5,'statistic','','equity','90000'),'statistical transfer account refused');
echo "$n chart checks passed\n";
