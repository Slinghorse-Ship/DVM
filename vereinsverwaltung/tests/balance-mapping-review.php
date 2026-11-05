<?php declare(strict_types=1);
require_once __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/../src/Finance/Balance/BalanceMappingReview.php';require_once __DIR__.'/mysql-bootstrap.php';
use Kulturhus\Verein\{Database,SettingsSchema,BalanceLedger,BalanceMappingReview,BalanceXmlOverview,BalanceAssetSchedule,BalanceTaxFiling};
use Kulturhus\Verein\Tests\MySqlFixture;
$db=MySqlFixture::connect();Database::migrate($db);$checks=0;
function check(bool $ok,string $msg):void{global $checks;if(!$ok)throw new RuntimeException($msg);$checks++;}
function denied(callable $f,string $msg):void{try{$f();}catch(DomainException){check(true,$msg);return;}throw new RuntimeException($msg);}
$db->exec("INSERT INTO clubs VALUES('a','Synthetic mapping club'),('b','Other mapping club');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('board','a','treasurer',1),('reader','a','member',0),('other','b','treasurer',1);INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,version) VALUES('a','reader','Reader','','none','read',0,1);INSERT INTO settings_role_assignments VALUES('reader','a','reader',1)");
$tax=SettingsSchema::defaults('tax');$tax['accounting_basis']='balance';$db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES('a','tax',?,1,?)")->execute([json_encode($tax),gmdate('c')]);
putenv('VEREIN_MODE=synthetic');putenv('VEREIN_BALANCE_WORKSPACE=1');putenv('VEREIN_TAX_AUTO_TEST=synthetic-only');
$ledger=new BalanceLedger($db,'board');$ledger->create(2025);$ledger->setup(2025,1,BalanceLedger::defaultChart(),[['account'=>'cash','debit_cents'=>100000,'credit_cents'=>0,'sphere'=>''],['account'=>'equity','debit_cents'=>0,'credit_cents'=>100000,'sphere'=>'']],'equity','Synthetic reviewed opening',true);
$report=fn()=>$ledger->report(2025);$r=$report();$service=new BalanceMappingReview($db,'board');$state=$service->read($r);$map=['cash'=>'bs.ass.currAss.cashEquiv.cash','equity'=>'bs.eqLiab.equity.association'];
check($state['version']===0&&!$state['current'],'Empty preparation is not approved');
denied(fn()=>$service->save(2025,2,0,$state['digest'],$map,false),'Explicit review required');
denied(fn()=>(new BalanceMappingReview($db,'reader'))->save(2025,2,0,$state['digest'],$map,true),'Reader cannot write');
denied(fn()=>(new BalanceMappingReview($db,'other'))->save(2025,2,0,$state['digest'],$map,true),'Other club cannot reuse source');
denied(fn()=>$service->save(2025,1,0,$state['digest'],$map,true),'Stale ledger version rejected');
denied(fn()=>$service->save(2025,2,0,str_repeat('0',64),$map,true),'Stale digest rejected');
denied(fn()=>$service->save(2025,2,0,$state['digest'],['cash'=>$map['cash']],true),'Active account cannot be omitted');
denied(fn()=>$service->save(2025,2,0,$state['digest'],['cash'=>'bs.eqLiab.equity.association','equity'=>$map['equity']],true),'Wrong side rejected');
$service->save(2025,2,0,$state['digest'],$map,true);check($service->read($r)['current'],'Current review retained');$o=(new BalanceXmlOverview($db,'board'))->read($r);check($o['mapping']['review_count']===0&&$o['mapping']['saved_count']===2,'Overview displays only valid review');
denied(fn()=>$service->save(2025,2,0,$state['digest'],$map,true),'Concurrent review version rejected');
$ledger->post(2025,2,bin2hex(random_bytes(16)),'2025-03-01','Synthetic income','Synthetic receipt',[['account'=>'cash','debit_cents'=>5000,'credit_cents'=>0,'sphere'=>''],['account'=>'revenue','debit_cents'=>0,'credit_cents'=>5000,'sphere'=>'4']]);$r=$report();check(!$service->read($r)['current'],'New booking invalidates approval');$state=$service->read($r);$map['revenue']='is.netIncome.regular.operatingTC.otherOpRevenue.miscellaneous';$service->save(2025,3,1,$state['digest'],$map,true);
check((int)$db->query('SELECT COUNT(*) FROM balance_mapping_review_history')->fetchColumn()===2,'Versions retained in history');
$ledger->close(2025,3,['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic year-end review');$r=$report();check($service->read($r)['current'],'Closing unchanged values preserves prepared choices');
$filing=new BalanceTaxFiling($db,'board');$id=$filing->create(2025,'complete_club_ledger');$f=(new Kulturhus\Verein\TaxFilings($db,'board'))->get($id);check($f['form_data']['balance_preparation']['mapping']==$map,'New whole-club draft inherits current choices');
denied(fn()=>$service->save(2025,4,2,$service->read($r)['digest'],$map,true),'Closed year mappings protected');
check((int)$db->query("SELECT COUNT(*) FROM balance_mapping_reviews WHERE club_id='b'")->fetchColumn()===0,'No cross-club write');echo "$checks mapping review checks passed.\n";
