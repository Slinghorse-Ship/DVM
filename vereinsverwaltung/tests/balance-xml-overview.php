<?php declare(strict_types=1);
require_once __DIR__.'/../src/bootstrap.php';
require_once __DIR__.'/../src/Finance/Balance/BalanceXmlOverview.php';
require_once __DIR__.'/mysql-bootstrap.php';
use Kulturhus\Verein\{BalanceXmlOverview as O,BalanceLedger,BalanceTaxFiling,TaxFilings,Database,SettingsSchema};
use Kulturhus\Verein\Tests\MySqlFixture;
$checks=0;
function check(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);++$checks;}
function denied(callable $f,string $message):void{try{$f();}catch(DomainException){check(true,$message);return;}throw new RuntimeException($message);}
$db=$xmlOverviewTestDb??MySqlFixture::connect();
if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')throw new RuntimeException('MySQL required');
Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic XML Club'),('b','Other Synthetic Club');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('board','a','treasurer',1),('reader','a','member',0),('member','a','member',0),('other','b','treasurer',1)");
$db->exec("INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,version) VALUES('a','xml-reader','XML reader','','none','read',0,1);INSERT INTO settings_role_assignments VALUES('reader','a','xml-reader',1)");
$settings=SettingsSchema::defaults('tax');$settings['accounting_basis']='balance';
$db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES('a','tax',?,1,?)")->execute([json_encode($settings),gmdate('c')]);
putenv('VEREIN_BALANCE_WORKSPACE=1');putenv('VEREIN_TAX_AUTO_TEST=synthetic-only');putenv('VEREIN_MODE=synthetic');
$ledger=new BalanceLedger($db,'board');$ledger->create(2025);
$pair=static fn($d,$c,$n)=>[['account'=>$d,'debit_cents'=>$n,'credit_cents'=>0,'sphere'=>in_array($d,['expense','revenue'])?'4':''],['account'=>$c,'debit_cents'=>0,'credit_cents'=>$n,'sphere'=>in_array($c,['expense','revenue'])?'4':'']];
$chart=BalanceLedger::defaultChart();$chart['unused-one']=['name'=>'Unused original account one','group'=>'other_assets','skr_code'=>''];$chart['unused-two']=['name'=>'Unused original account two','group'=>'liabilities','skr_code'=>''];
$ledger->setup(2025,1,$chart,$pair('cash','equity',100000),'equity','Synthetic opening evidence',true);
$ledger->post(2025,2,bin2hex(random_bytes(16)),'2025-01-02','Synthetic income','Synthetic invoice',$pair('cash','revenue',25000));
$ledger->post(2025,3,bin2hex(random_bytes(16)),'2025-01-03','Synthetic expense','Synthetic invoice',$pair('expense','cash',5000));
$ledger->close(2025,4,['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic accounting review');
$report=$ledger->report(2025);$report['asset_schedule']=(new Kulturhus\Verein\BalanceAssetSchedule($db,'board'))->build(2025);
$tax=new TaxFilings($db,'board');$svc=new BalanceTaxFiling($db,'board');
$id=$svc->create(2025,'complete_club_ledger');
$mapping=['cash'=>'bs.ass.currAss.cashEquiv.cash','equity'=>'bs.eqLiab.equity.retainedEarnings','expense'=>'is.netIncome.regular.operatingTC.otherCost.otherOrdinary','revenue'=>'is.netIncome.regular.operatingTC.otherOpRevenue.miscellaneous'];
$profile=['business_name'=>'Synthetic whole club','supplier'=>'Synthetic test supplier','scope_confirmed'=>true,'asset_values_unchanged'=>true,'tax_privileged'=>false,'company'=>['tax_number'=>'5192050001276','name'=>'Synthetic club','street'=>'Testweg','house_number'=>'1','postal_code'=>'11111','city'=>'Teststadt','country'=>'Deutschland']];
$svc->save($id,1,$profile,$mapping,['complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[]]);
$r=$tax->get($id);$reader=new BalanceTaxFiling($db,'reader');
check($reader->assertCurrent($r,true)===$svc->assertCurrent($r),'read-only and writable source verification agree');
denied(fn()=>$reader->assertCurrent($r),'default approval path still requires write rights');
denied(fn()=>(new BalanceTaxFiling($db,'other'))->assertCurrent($r,true),'read-only verification remains club-isolated');
$before=MySqlFixture::snapshot($db);$out=(new O($db,'reader'))->read($report);
check($out['mapping']['review_count']===0&&$out['mapping']['saved_count']===4&&$out['mapping']['unused_count']===12,'four saved choices resolve sixteen misleading open rows');
check(count($out['filings'])===1&&$out['filings'][0]['id']===$id&&!$out['filings'][0]['current'],'draft linked without claiming native validation');
check(MySqlFixture::snapshot($db)===$before,'overview is read-only across every MySQL table');
denied(fn()=>(new O($db,'member'))->read($report),'member cannot inspect finance overview');
check((new O($db,'other'))->read($report)['filings']===[],'other tenant cannot borrow mapping');
$row=$report['calculation']['accounts']['inventory'];check(O::unused($row),'explicit zero account unused');
foreach(['opening_cents','debit_cents','credit_cents','closing_cents','amount_cents'] as $key){$bad=$row;unset($bad[$key]);check(!O::unused($bad),'missing amount is not zero '.$key);$bad=$row;$bad[$key]='0';check(!O::unused($bad),'string amount is not verified integer '.$key);}
$bad=$report;$bad['calculation']['accounts']['inventory']['debit_cents']=100;$bad['calculation']['accounts']['inventory']['credit_cents']=100;
check(O::mapping($bad,$mapping)['review_count']===1,'zero-net active account still requires mapping');
$bad=$mapping;$bad['cash']='bs.eqLiab.liab.trade';check(O::mapping($report,$bad)['review_count']===1,'invalid stored target not accepted');
check(O::sameWholeSource($report,$r),'exact whole source matches');
$open=$report;$open['state']='open';check(!O::sameWholeSource($open,$r),'open journal cannot inherit closed XML status');
foreach(['journal_sha256'=>str_repeat('0',64),'year'=>2026,'period'=>['start'=>'2025-01-01','end'=>'2025-12-31'],'period_id'=>'different'] as $key=>$value){$bad=$r;$bad['form_data']['balance_preparation'][$key]=$value;check(!O::sameWholeSource($report,$bad),'different binding excluded '.$key);}
foreach(['kind'=>'club_taxable_businesses','tax_privileged'=>true,'asset_scope'=>'single_reported_business','source_scope'=>'separate_business_ledger'] as $key=>$value){$bad=$r;$bad['form_data']['balance_preparation']['profile'][$key]=$value;check(!O::sameWholeSource($report,$bad),'partial scope excluded '.$key);}
$xml='<synthetic/>';$hash=hash('sha256',$xml);$valid=['state'=>'validated','xml_data'=>$xml,'xml_sha256'=>$hash,'runtime_sha256'=>'runtime','validation'=>['code'=>0,'xml_sha256'=>$hash,'environment'=>'test','sent'=>false,'transport_attempted'=>false]];
check(O::validationCurrent($valid,'runtime'),'complete bound validation evidence recognized');
foreach(['state'=>'draft','xml_data'=>'changed','xml_sha256'=>'changed','runtime_sha256'=>'old'] as $key=>$value){$bad=$valid;$bad[$key]=$value;check(!O::validationCurrent($bad,'runtime'),'stale evidence rejected '.$key);}
foreach(['code'=>1,'xml_sha256'=>'other','environment'=>'production','sent'=>true,'transport_attempted'=>true] as $key=>$value){$bad=$valid;$bad['validation'][$key]=$value;check(!O::validationCurrent($bad,'runtime'),'native evidence checked '.$key);}
function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function url(array $v):string{return '?'.http_build_query($v);}
$_SESSION['actor']='reader';$balanceReport=$report;ob_start();require __DIR__.'/../views/Finance/Balance/balance-tax-mapping.php';$html=ob_get_clean();
check(str_contains($html,'4 gespeicherte Zuordnungen übernommen')&&str_contains($html,'0 aktive Konten offen')&&str_contains($html,'filing='.$id),'rendered overview links exact draft and explains active coverage');
check(str_contains($html,'2 Originalkonten noch ohne geprüfte XML-Regel'),'qualified R93 catalog gaps remain independently visible');
$second=$svc->create(2025,'complete_club_ledger');$ambiguous=(new O($db,'reader'))->read($report);
check($ambiguous['ambiguous']&&$ambiguous['mapping']['saved_count']===0,'multiple matching drafts do not silently select one');
echo json_encode(['passed'=>true,'checks'=>$checks,'database'=>'MySQL','native_validation_run'=>false,'sent'=>false],JSON_THROW_ON_ERROR)."\n";
