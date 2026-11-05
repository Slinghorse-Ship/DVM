<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/native/bootstrap.php';require dirname(__DIR__).'/src/bootstrap.php';require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\{TestSupport,Installer\SchemaRegistry,Shared\Db};
use Kulturhus\Verein\Finance;
$cfg=TestSupport::config();if(($cfg['owner']??'')!=='isolated-native-ci'||($cfg['label']??'')!=='native-finance-business-day')throw new RuntimeException('Own financial clock context required.');
$db=TestSupport::db();$n=0;$check=static function(bool$ok,string$label)use(&$n):void{if(!$ok)throw new RuntimeException($label);++$n;echo 'PASS '.$label.PHP_EOL;};
$check($db->query('SHOW TABLES')->fetchAll()===[],'fresh own financial clock database');
$zone=date_default_timezone_get();$moments=[
 '2026-01-01T22:59:59Z'=>'2026-01-01','2026-01-01T23:00:00Z'=>'2026-01-02',
 '2026-06-01T21:59:59Z'=>'2026-06-01','2026-06-01T22:00:00Z'=>'2026-06-02',
 '2026-03-28T22:59:59Z'=>'2026-03-28','2026-03-28T23:00:00Z'=>'2026-03-29',
 '2026-03-29T00:59:59Z'=>'2026-03-29','2026-03-29T01:00:00Z'=>'2026-03-29',
 '2026-10-24T21:59:59Z'=>'2026-10-24','2026-10-24T22:00:00Z'=>'2026-10-25',
 '2026-10-25T00:30:00Z'=>'2026-10-25','2026-10-25T01:30:00Z'=>'2026-10-25',
 '2026-12-31T22:59:59Z'=>'2026-12-31','2026-12-31T23:00:00Z'=>'2027-01-01',
];
try{foreach(['UTC','Pacific/Honolulu','Asia/Tokyo']as$host){date_default_timezone_set($host);foreach($moments as$moment=>$expected){$check(Finance::today(new DateTimeImmutable($moment))===$expected,'actual Berlin calendar at '.$moment.' is independent of '.$host);$form=\Kulturhus\Verein\Web\AccountCreateForm::read([],['view'=>'finance'],new DateTimeImmutable($moment));$check($form['values']['opened_on']===$expected,'actual account form starts with the same local business day under '.$host);}}}finally{date_default_timezone_set($zone);}
SchemaRegistry::install($db);
Db::run($db,"INSERT INTO clubs(id,name)VALUES('own-clock','Own business date fixture'),('own-clock-foreign','Own earlier foreign fixture')");
foreach(['own-clock-admin'=>'own-clock','own-clock-foreign-admin'=>'own-clock-foreign']as$actor=>$club)Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser)VALUES(?,?,'member',1)",[$actor,$club]);
$foreign=new Finance($db,'own-clock-foreign-admin');$foreignAccount=$foreign->createAccount('Own earlier original bank','offline','17','2020-01-01');
$oldFinance=new Finance($db,'own-clock-admin');$oldAccount=$oldFinance->createAccount('Own earlier exact home account','cash','23','2020-01-01');
$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);sort($tables);$old=[];
$allowed=['finance_accounts'=>'id','finance_transactions'=>'id','finance_account_settings'=>'account_id','cash_closures'=>'id','audit_events'=>'sequence','manager_notifications'=>'id','document_folders'=>'id','document_sources'=>'source_id'];
foreach($allowed as$table=>$column)$old[$table]=$db->query('SELECT '.$column.' FROM '.$table)->fetchAll(PDO::FETCH_COLUMN);
$filter=static function(string$table)use($allowed,$old):array{if(!isset($allowed[$table]))return['',[]];$ids=$old[$table];return[' WHERE club_id<>?'.($ids?' OR '.$allowed[$table].' IN ('.implode(',',array_fill(0,count($ids),'?')).')':''),['own-clock',...$ids]];};
$protectedCounts=static function()use($db,$tables,$filter):array{$out=[];foreach($tables as$table){[$where,$args]=$filter($table);$out[$table]=(int)Db::run($db,'SELECT COUNT(*) FROM `'.$table.'`'.$where,$args)->fetchColumn();}return$out;};$beforeCounts=$protectedCounts();
$original=ownOriginalDatabaseHash($db,$tables,$filter);$deny=static function(callable$action,string$label)use($db,$tables,$check):void{$before=ownOriginalDatabaseHash($db,$tables);try{$action();}catch(DomainException){$check(ownOriginalDatabaseHash($db,$tables)===$before,$label.' leaves every complete table exact');return;}throw new RuntimeException('Accepted '.$label);};
$key=static fn():string=>bin2hex(random_bytes(16));$accounts=[];$transactions=[];$cases=0;
try{
 foreach(['UTC','Pacific/Honolulu','Asia/Tokyo']as$host){date_default_timezone_set($host);
  foreach(['2030-01-01T23:30:00Z'=>'2030-01-02','2030-06-01T22:30:00Z'=>'2030-06-02','2030-12-31T23:30:00Z'=>'2031-01-01']as$moment=>$day){
   ++$cases;$finance=new Finance($db,'own-clock-admin',new DateTimeImmutable($moment));$tomorrow=(new DateTimeImmutable($day.'T12:00:00Z'))->modify('+1 day')->format('Y-m-d');$yesterday=(new DateTimeImmutable($day.'T12:00:00Z'))->modify('-1 day')->format('Y-m-d');
   $account=$finance->createAccount('Own Berlin midnight bank '.$cases,'offline','0',$day);$accounts[]=$account;
   $check($finance->account($account)['opened_on']===$day,'actual bank opens on Berlin day despite a previous UTC day and '.$host);
   $deny(fn()=>$finance->createAccount('Own refused future bank '.$cases,'offline','0',$tomorrow),'future account opening');
   $deny(fn()=>$finance->saveAccountSettings($account,0,true,'',true,false,null,null,null,$tomorrow),'future opening edit');
   $finance->saveAccountSettings($account,0,true,'',true,false,null,null,null,$day);
   $check((int)$finance->accountSettings($account)['version']===1&&$finance->account($account)['opened_on']===$day,'actual account settings accept the same current Berlin opening');
   $input=['account'=>$account,'booked_on'=>$day,'direction'=>'income','amount'=>'1','description'=>'Own exact local payment ä🙂','counterparty'=>'Own original payer'];
   $deny(fn()=>$finance->createTransaction(array_replace($input,['booked_on'=>$tomorrow]),$key()),'future actual booking');
   $deny(fn()=>$finance->createTransaction(array_replace($input,['booked_on'=>$yesterday]),$key()),'actual booking before opening');
   $request=$key();$transaction=$finance->createTransaction($input,$request);$transactions[]=$transaction;$row=$finance->transactionDetail($transaction);
   $check($row['booked_on']===$day&&(int)$row['cents']===100&&$row['description']===$input['description'],'actual accepted movement retains its Berlin booking day amount and human description');
   $before=ownOriginalDatabaseHash($db,$tables);$check($finance->createTransaction($input,$request)===$transaction&&ownOriginalDatabaseHash($db,$tables)===$before,'same actual booking key retains its one exact original');
   $cash=$finance->createAccount('Own Berlin midnight cash '.$cases,'cash','0',$day);$accounts[]=$cash;
   $deny(fn()=>$finance->closeCashbook($cash,$tomorrow,'0'),'future cash closing');$finance->closeCashbook($cash,$day,'0');
   $check((int)Db::run($db,'SELECT COUNT(*) FROM cash_closures WHERE account_id=? AND closed_through=?',[$cash,$day])->fetchColumn()===1,'actual empty cash closes exactly on the current Berlin date');
   if($cases===1&&ownOriginalDatabaseHash($db,$tables,$filter)!==$original){$afterCounts=$protectedCounts();$drift=[];foreach($beforeCounts as$table=>$count)if($afterCounts[$table]!==$count)$drift[$table]=[$count,$afterCounts[$table]];echo json_encode(['unplanned_complete_table_changes'=>$drift],JSON_THROW_ON_ERROR).PHP_EOL;throw new RuntimeException('Unplanned original drift after first actual clock case.');}

  }
 }
}finally{date_default_timezone_set($zone);}
$check(ownOriginalDatabaseHash($db,$tables,$filter)===$original,'all earlier own and foreign accounts schema identities and whole unrelated business tables remain exact');
$check(count($accounts)===18&&count($transactions)===9&&(int)$db->query("SELECT COUNT(*) FROM finance_accounts WHERE club_id='own-clock'")->fetchColumn()===19&&(int)$db->query("SELECT COUNT(*) FROM finance_transactions WHERE club_id='own-clock'")->fetchColumn()===9&&(int)$db->query("SELECT COUNT(*) FROM cash_closures WHERE club_id='own-clock'")->fetchColumn()===9,'nine genuine clock cases create exactly their eighteen accounts nine movements and nine closings');
$newSources=Db::run($db,'SELECT * FROM document_sources WHERE club_id=? AND source_id NOT IN ('.implode(',',array_fill(0,count($old['document_sources']),'?')).')',['own-clock',...$old['document_sources']])->fetchAll();
$check(count($newSources)===18&&array_diff(array_column($newSources,'source_id'),$accounts)===[],'new logical document sources belong exactly to the eighteen actual accounts');
$expectedFolders=[];
foreach($newSources as$source){$account=Db::run($db,'SELECT * FROM finance_accounts WHERE club_id=? AND id=?',['own-clock',$source['source_id']])->fetch();$folder=Db::run($db,'SELECT * FROM document_folders WHERE club_id=? AND id=?',['own-clock',$source['folder_id']])->fetch();$parent=Db::run($db,'SELECT * FROM document_folders WHERE club_id=? AND id=?',['own-clock',$folder['parent_id']])->fetch();$rootFolder=Db::run($db,'SELECT * FROM document_folders WHERE club_id=? AND id=?',['own-clock',$parent['parent_id']])->fetch();
 $check($source['source_type']==='account'&&$source['label']===$account['name']&&$source['document_date']===$account['opened_on']&&$source['scope']==='finance'&&(int)$source['available']===1&&$folder['name']===\Kulturhus\Verein\DocumentCatalog::segment($account['name']).' · '.substr($account['id'],0,8)&&$folder['scope']==='finance'&&$parent['name']===($account['kind']==='cash'?'Kassen':'Bankkonten')&&$parent['scope']==='finance'&&$rootFolder['name']==='Finanzen'&&$rootFolder['parent_id']===null,'each new account document path is bound to its exact original name opening and scope');
 foreach([$folder,$parent,$rootFolder]as$row)if(!in_array($row['id'],$old['document_folders'],true))$expectedFolders[$row['id']]=true;
}
$actualFolders=Db::run($db,'SELECT id FROM document_folders WHERE club_id=? AND id NOT IN ('.implode(',',array_fill(0,count($old['document_folders']),'?')).') ORDER BY id',['own-clock',...$old['document_folders']])->fetchAll(PDO::FETCH_COLUMN);$expectedFolderIds=array_keys($expectedFolders);sort($expectedFolderIds);
$check(count($expectedFolderIds)===19&&$actualFolders===$expectedFolderIds,'only eighteen derived account folders and their one new bank parent are added');
$check(date_default_timezone_get()===$zone,'hosting timezone remains unchanged');
echo json_encode(['suite'=>'native-finance-business-day','checks'=>$n,'host_timezones'=>3,'actual_finance_cases'=>$cases,'external_actions'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
