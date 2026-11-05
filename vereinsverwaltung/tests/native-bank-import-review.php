<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';require dirname(__DIR__).'/src/bootstrap.php';
use Kulturhus\Native\{TestSupport,Installer\SchemaRegistry};
use Kulturhus\Verein\{Finance,BankImport};
$db=TestSupport::db();SchemaRegistry::install($db);$n=0;
$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException($label);++$n;echo "PASS $label\n";};
$deny=static function(callable $run,string $label)use($check):void{try{$run();}catch(DomainException){$check(true,$label);return;}throw new RuntimeException('Accepted: '.$label);};$key=static fn()=>bin2hex(random_bytes(16));
$db->exec("INSERT INTO clubs VALUES('own-list-club','Own import club'),('import-foreign','Foreign import club');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('own-list-admin','own-list-club','member',1),('own-list-reader','own-list-club','member',0),('own-list-foreign','import-foreign','member',1);INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,version) VALUES('own-list-club','import-reader','Read finance','','none','read',0,1);INSERT INTO settings_role_assignments VALUES('own-list-reader','own-list-club','import-reader',1)");
$f=new Finance($db,'own-list-admin');$service=new BankImport($db,'own-list-admin');$bank=$f->createAccount('Own imported bank <em>0</em>','offline','1000','2020-01-01');$other=$f->createAccount('Second own bank','offline','0','2020-01-01');
$remote=['uid'=>'own-session','name'=>'Own original FinTS account','account_id'=>['iban'=>'DE89370400440532013000']];$mapping=$service->map('fints','PRODUCTION',$remote,$bank);
$rows=[];for($i=0;$i<225;$i++)$rows[]=['entry_reference'=>'','transaction_amount'=>['currency'=>'EUR','amount'=>'10.00'],'credit_debit_indicator'=>'CRDT','status'=>'BOOK','booking_date'=>'2020-06-01','debtor'=>['name'=>'Own imported party '.$i],'remittance_information'=>[$i===0?'Oldest source % 0 ä🙂':'Own imported original '.$i]];
$run=$service->fetch($mapping,'PRODUCTION',$remote,'2020-01-01','2020-12-31',$key(),static fn()=>['transactions'=>$rows]);
$record=$db->query("SELECT id FROM bank_import_records WHERE description='Oldest source % 0 ä🙂'")->fetchColumn();$candidates=[];
for($i=0;$i<125;$i++)$candidates[]=$f->createTransaction(['account'=>$bank,'booked_on'=>'2020-06-01','direction'=>'income','amount'=>'10','counterparty'=>'Own existing party','description'=>'Own existing payment '.$i],$key());
$query=$db->prepare('SELECT id FROM finance_transactions WHERE account_id=? ORDER BY booked_on,id');$query->execute([$bank]);$candidates=$query->fetchAll(PDO::FETCH_COLUMN);$db->prepare('UPDATE finance_transactions SET description=? WHERE id=?')->execute(['Selected old existing % 0 ä🙂',$candidates[124]]);
$check(method_exists($service,'recordsPage'),'normal FinTS import review has a complete bounded original catalogue');
$page=$service->recordsPage(['account'=>$bank,'state'=>'needs_review']);$check($page['total']===225&&count($page['rows'])===20&&$page['pages']===12,'all 225 original bank rows remain reachable in bounded pages');
$last=$service->recordsPage(['account'=>$bank,'state'=>'needs_review'],12);$check(count($last['rows'])===5,'last original page is complete beyond the old two hundred rows');
$found=$service->recordsPage(['account'=>$bank,'q'=>'%']);$check($found['total']===1&&$found['rows'][0]['id']===$record,'literal wildcard and Unicode lookup reaches the original source');
$check($service->recordsPage(['account'=>$other])['total']===0,'own account scope never substitutes unrelated bank originals');
$page=$service->candidatesPage($record);$check($page['total']===125&&count($page['rows'])===20&&$page['pages']===7,'all existing payment candidates are reachable beyond the old first 101');
$selected=$service->candidatesPage($record,1,20,'%');$check($selected['total']===1&&$selected['rows'][0]['id']===$candidates[124],'targeted original payment search remains complete');
$detail=$service->reviewDetail($record);$check($detail['record']['id']===$record&&$detail['account']['id']===$bank&&$detail['observations']['total']===1,'provider-independent detail joins only its actual account and original observation');
$original=$service->observation($record,$run['id'],0);$check(hash('sha256',$original['payload'])===$original['payload_sha256']&&json_decode($original['payload'],true)['remittance_information'][0]==='Oldest source % 0 ä🙂','private exact bank JSON is independently hash verified');
$deny(fn()=>(new BankImport($db,'own-list-reader'))->recordsPage(),'finance reader cannot cross the existing bank-original write boundary');
$deny(fn()=>(new BankImport($db,'own-list-foreign'))->reviewDetail($record),'foreign original detail denied');
$deny(fn()=>(new BankImport($db,'own-list-foreign'))->observation($record,$run['id'],0),'foreign original download denied');
foreach([['state'=>'unknown'],['q'=>[]],['from'=>'2020-02-30'],['from'=>'2021-01-01','to'=>'2020-01-01']] as $bad)$deny(fn()=>$service->recordsPage($bad),'invalid bank review filter rejected');
$deny(fn()=>$service->recordsPage([],0),'invalid bank review page rejected');
$before=$db->query('SELECT * FROM finance_transactions ORDER BY id')->fetchAll();$service->decide($record,1,'existing',$candidates[124],'Actual existing payment reviewed');$service->decide($record,1,'existing',$candidates[124],'Actual existing payment reviewed');
$check($db->query('SELECT * FROM finance_transactions ORDER BY id')->fetchAll()===$before&&$service->record($record)['state']==='imported','existing payment reconciliation and exact replay preserve all original transaction columns');
$check((int)$db->query('SELECT COUNT(*) FROM bank_import_decisions')->fetchColumn()===1,'exact accepted replay creates one original review decision');
$deny(fn()=>$service->decide($record,1,'existing',$candidates[123],'Actual existing payment reviewed'),'retry cannot substitute another payment');
$deny(fn()=>$service->decide($record,1,'existing',$candidates[124],'Changed reason'),'retry cannot substitute another review reason');
$find=static function(string $description)use($db):string{$q=$db->prepare('SELECT id FROM bank_import_records WHERE club_id=? AND description=?');$q->execute(['own-list-club',$description]);return (string)$q->fetchColumn();};
$newRecord=$find('Own imported original 1');$count=(int)$db->query('SELECT COUNT(*) FROM finance_transactions')->fetchColumn();$service->decide($newRecord,1,'new','','Additional actual payment');$service->decide($newRecord,1,'new','','Additional actual payment');
$check((int)$db->query('SELECT COUNT(*) FROM finance_transactions')->fetchColumn()===$count+1,'explicit additional payment and accepted replay create exactly one new movement');
$ignored=$find('Own imported original 2');$reason=str_repeat('🙂',499).'0';$service->decide($ignored,1,'ignore','',$reason);
$check($service->reviewDetail($ignored)['decisions']['rows'][0]['reason']===$reason,'all five hundred Unicode review characters and zero are preserved');
final class OwnedImportConnection extends PDO{
 public ?Closure $afterBegin=null;public bool $loseCommit=false;
 public function beginTransaction():bool{$ok=parent::beginTransaction();if($this->afterBegin){$run=$this->afterBegin;$this->afterBegin=null;$run();}return $ok;}
 public function commit():bool{$ok=parent::commit();if($this->loseCommit){$this->loseCommit=false;throw new PDOException('Owned lost import commit acknowledgement');}return $ok;}
}
$cfg=TestSupport::config();$second=new OwnedImportConnection('mysql:host='.$cfg['host'].';port='.$cfg['port'].';dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$otherService=new BankImport($second,'own-list-admin');
$lost=$find('Own imported original 3');$count=(int)$db->query('SELECT COUNT(*) FROM finance_transactions')->fetchColumn();$second->loseCommit=true;
try{$otherService->decide($lost,1,'new','','Lost accepted original');throw new RuntimeException('Missing lost commit');}catch(PDOException){}
$otherService->decide($lost,1,'new','','Lost accepted original');$check((int)$db->query('SELECT COUNT(*) FROM finance_transactions')->fetchColumn()===$count+1&&$service->reviewDetail($lost)['decisions']['total']===1,'actual lost COMMIT acknowledgement cannot duplicate the original bank movement or decision');
$locked=$find('Own imported original 4');$db->beginTransaction();$db->prepare('SELECT id FROM finance_transactions WHERE id=? FOR UPDATE NOWAIT')->execute([$candidates[123]]);
try{$deny(fn()=>$otherService->decide($locked,1,'existing',$candidates[123],'Locked actual source'),'independent original payment lock protects bank reconciliation');}finally{$db->rollBack();}
$check(!$second->inTransaction()&&$service->record($locked)['state']==='review','source lock failure preserves the unreviewed original and releases its transaction');
$db->exec("CREATE TRIGGER import_review_fail BEFORE INSERT ON audit_events FOR EACH ROW BEGIN IF NEW.action='bank.review.decided' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Owned import review failure'; END IF; END");$count=(int)$db->query('SELECT COUNT(*) FROM finance_transactions')->fetchColumn();
try{$service->decide($locked,1,'new','','Fail complete source');throw new RuntimeException('Audit failure accepted');}catch(PDOException){}finally{$db->exec('DROP TRIGGER import_review_fail');}
$check((int)$db->query('SELECT COUNT(*) FROM finance_transactions')->fetchColumn()===$count&&$service->record($locked)['state']==='review'&&$service->reviewDetail($locked)['decisions']['total']===0,'actual SQL failure rolls back money provenance decision and original version together');
$original=$service->reviewDetail($locked)['observations']['rows'][0];$db->prepare('UPDATE bank_import_observations SET payload=CONCAT(payload,?) WHERE club_id=? AND run_id=? AND ordinal=?')->execute([' ','own-list-club',$original['run_id'],$original['ordinal']]);
try{$deny(fn()=>$service->observation($locked,$original['run_id'],$original['ordinal']),'changed original bytes cannot be downloaded as a verified bank original');$deny(fn()=>$service->decide($locked,1,'ignore','','Changed source rejected'),'changed original bytes prevent a bank review decision');}finally{$db->prepare('UPDATE bank_import_observations SET payload=? WHERE club_id=? AND run_id=? AND ordinal=?')->execute([$original['payload'],'own-list-club',$original['run_id'],$original['ordinal']]);}
$check($service->record($locked)['state']==='review','rejected corrupt source never changes bank or money state');
$stable=static function(string $amount)use($rows):array{return array_replace($rows[0],['entry_reference'=>'Own stable conflict','transaction_amount'=>['currency'=>'EUR','amount'=>$amount],'remittance_information'=>['Own stable conflict original']]);};
$fetch=static fn(array $values)=>$service->fetch($mapping,'PRODUCTION',$remote,'2020-01-01','2020-12-31',$key(),static fn()=>['transactions'=>$values]);
$fetch([$stable('31')]);$conflict=$find('Own stable conflict original');$check($service->record($conflict)['state']==='review','actual thirty-one euro bank line with possible original portions needs conscious review');$service->decide($conflict,1,'new','','Own separately evidenced actual new31 rather than parts of earlier tens');$fetch([$stable('32')]);$version=(int)$service->record($conflict)['version'];$conflictDecisions=$service->reviewDetail($conflict)['decisions'];
$second->afterBegin=static function()use($second,$fetch,$stable):void{$second->query('SELECT COUNT(*) FROM bank_import_records')->fetchColumn();$fetch([$stable('33')]);};
$deny(fn()=>$otherService->decide($conflict,$version,'keep','','Older bank source'),'actual newer bank response cannot be hidden by an older decision snapshot');
$check(!$second->inTransaction()&&$service->record($conflict)['state']==='conflict'&&$service->reviewDetail($conflict)['decisions']===$conflictDecisions,'stale conflict decision preserves the complete newer original and no false acknowledgement');
$service->decide($conflict,(int)$service->record($conflict)['version'],'keep','','Actual changed bank values compared');$check($service->record($conflict)['state']==='ignored'&&$service->reviewDetail($conflict)['transaction']['cents']===3100,'explicit conflict acknowledgement keeps the original actual thirty-one-euro movement');
$unicode=$rows[0];$unicode['remittance_information']=[str_repeat('🙂',499).'0'];$fetch([$unicode]);$check($service->recordsPage(['q'=>str_repeat('🙂',3)])['total']===1,'bank ingestion accepts the full five hundred Unicode source characters without truncation');
if(getenv('DVM_BANK_IMPORT_REVIEW_BROWSER')==='1'){
 $cases=[];foreach(['front-nojs','front-js','direct-nojs','direct-js'] as $i=>$case){
  $tag='Own import '.$case;$caseRows=[];
  foreach(['existing','new','ignore','conflict'] as $kind){$value=$rows[0];$value['debtor']['name']=$tag.' party';$value['remittance_information']=[$tag.' '.$kind.' <em>0</em> ä🙂'];$value['transaction_amount']['amount']=$kind==='ignore'?'30':($kind==='new'?'20':($kind==='conflict'?(string)(37+$i):'10'));
   if($kind==='new'){$value['credit_debit_indicator']='DBIT';$value['creditor']=['name'=>$tag.' expense'];}
   if($kind==='conflict')$value['entry_reference']='Own UI conflict '.$case;$caseRows[$kind]=$value;
  }
  $fetch(array_values($caseRows));$data=['tag'=>$tag,'existing_payment'=>$candidates[123-$i],'changed_amount'=>38+$i];
  foreach($caseRows as $kind=>$value)$data[$kind]=$find($value['remittance_information'][0]);
  $service->decide($data['conflict'],1,'new','','Own separately evidenced new browser conflict original before later changed bank response');
  $changed=$caseRows['conflict'];$changed['transaction_amount']['amount']=(string)(38+$i);$fetch([$changed]);
  if($i===0)for($j=0;$j<13;$j++)$fetch([$caseRows['existing']]);
  $data['original_sha256']=$service->reviewDetail($data['existing'])['observations']['rows'][0]['payload_sha256'];$cases[$case]=$data;
 }
 $selected=getenv('DVM_BANK_IMPORT_REVIEW_CASE');if($selected!==false&&$selected!==''){if(!isset($cases[$selected]))throw new RuntimeException('Unknown owned bank review case');$cases=[$selected=>$cases[$selected]];}
 $wholeProof=static function(string $table,string $where='')use($db):array{$columns=$db->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_COLUMN);$q=$db->query('SELECT SHA2(CAST(JSON_ARRAY('.implode(',',array_map(static fn($name)=>'`'.$name.'`',$columns)).') AS CHAR),256) AS proof FROM '.$table.$where.' ORDER BY 1');return $q->fetchAll(PDO::FETCH_COLUMN);};
 $moneyBefore=$wholeProof('finance_transactions');$originalsBefore=$wholeProof('bank_import_observations');$runsBefore=$wholeProof('bank_import_runs');$originalIds=$db->query('SELECT id FROM finance_transactions')->fetchAll(PDO::FETCH_COLUMN);
 require __DIR__.'/support/free-list-interface-browser-fixture.php';$fixture=ownFreeListInterfaceBrowser($db,['bank'=>$bank,'mapping'=>$mapping,'cases'=>$cases,'old_record'=>$record]);
 try{
  $process=proc_open(['node',__DIR__.'/bank-import-review-browser.mjs',$fixture['input']['root'].'/fixture.json'],[0=>['file','/dev/null','r'],1=>STDOUT,2=>STDERR],$pipes);
  if(!is_resource($process)||proc_close($process)!==0){foreach(['front','direct'] as $app)echo substr((string)file_get_contents($fixture['input']['root'].'/'.$app.'.log'),-2000);throw new RuntimeException('Actual bank import review browser failed');}
  foreach($cases as $case){$existing=$service->reviewDetail($case['existing']);$additional=$service->reviewDetail($case['new']);$ignored=$service->reviewDetail($case['ignore']);$changed=$service->reviewDetail($case['conflict']);
   $check($existing['record']['state']==='imported'&&$existing['transaction']['id']===$case['existing_payment']&&$existing['decisions']['total']===1,'browser existing payment is linked once without a new money movement');
   $check($additional['record']['state']==='imported'&&(int)$additional['transaction']['cents']===-2000&&$additional['decisions']['total']===1,'browser confirmed additional actual expense is imported exactly once');
   $check($ignored['record']['state']==='ignored'&&$ignored['transaction']===null&&$changed['record']['state']==='ignored'&&(int)$changed['transaction']['cents']===($case['changed_amount']-1)*100,'browser ignore and conflict acknowledgement never invent additional money');
  }
  $sqlIds=implode(',',array_map(static fn($id)=>$db->quote($id),$originalIds));$check($wholeProof('finance_transactions',' WHERE id IN ('.$sqlIds.')')===$moneyBefore,'every original financial transaction column remains byte identical after all browser decisions');
  $check($wholeProof('bank_import_observations')===$originalsBefore&&$wholeProof('bank_import_runs')===$runsBefore,'all whole bank JSON observations and source runs remain byte identical after browser review and downloads');
  $check((int)$db->query('SELECT COUNT(*) FROM finance_transactions')->fetchColumn()===count($originalIds)+count($cases),'only explicitly confirmed additional actual browser payments create money movements');
 }finally{foreach($fixture['servers'] as [$server,$log]){proc_terminate($server);proc_close($server);fclose($log);}}
}
echo json_encode(['suite'=>'native-bank-import-review','checks'=>$n,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
