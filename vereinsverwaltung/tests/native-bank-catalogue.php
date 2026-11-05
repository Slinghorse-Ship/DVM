<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';require dirname(__DIR__).'/src/bootstrap.php';
use Kulturhus\Native\{TestSupport,Installer\SchemaRegistry};
use Kulturhus\Verein\Finance;
$db=TestSupport::db();SchemaRegistry::install($db);$n=0;
$check=static function(bool $v,string $label)use(&$n){if(!$v)throw new RuntimeException($label);++$n;echo "PASS $label\n";};
$deny=static function(callable $fn,string $label)use($check){try{$fn();}catch(DomainException){$check(true,$label);return;}throw new RuntimeException('Accepted: '.$label);};
$key=static fn()=>bin2hex(random_bytes(16));
$db->exec("INSERT INTO clubs VALUES('catalogue-own','Own catalogue'),('catalogue-foreign','Foreign catalogue');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('catalogue-admin','catalogue-own','member',1),('catalogue-reader','catalogue-own','member',0),('catalogue-foreign','catalogue-foreign','member',1);INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,version) VALUES('catalogue-own','read-catalogue','Read catalogue','','none','read',0,1);INSERT INTO settings_role_assignments VALUES('catalogue-reader','catalogue-own','read-catalogue',1)");
$f=new Finance($db,'catalogue-admin');$foreign=new Finance($db,'catalogue-foreign');
$bank=$f->createAccount('Katalogbank','offline','1000','2020-01-01');$second=$f->createAccount('Eigenes Gegenkonto','offline','0','2020-01-01');$cash=$f->createAccount('Kasse','cash','100','2020-01-01');$stress=$f->createAccount('Große Originale','offline','0','2020-01-01');$other=$foreign->createAccount('Fremdbank','offline','0','2020-01-01');
$insert=$db->prepare('INSERT INTO finance_transactions(id,club_id,account_id,booked_on,counterparty,description,cents,source,request_key,fingerprint) VALUES(?,?,?,?,?,?,?,?,?,?)');
$entry=$db->prepare('INSERT INTO finance_entries(id,club_id,number,kind,description,counterparty,amount_cents,document_date,due_date,status,category,request_key,display_number) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
$db->beginTransaction();for($i=0;$i<1005;$i++){
    foreach([$bank,$second] as $j=>$account){$id=($j?'counter-':'bank-').sprintf('%04d',$i);$insert->execute([$id,'catalogue-own',$account,'2021-06-01','Zahler '.$i,$i===0?'Katalog % 0':'Umsatz '.$i,250,'manual',$key(),hash('sha256',$id)]);}
    $entry->execute(['receipt-'.$i,'catalogue-own',$i+1,'income',$i===0?'Ältester Sollbeleg 0':'Sollbeleg '.$i,'Zahler '.$i,250,'2021-06-01','2021-06-01','open','general',$key(),'CAT-'.sprintf('%05d',$i)]);
}$db->commit();
$f->allocate('bank-0001',1,'receipt-1',1,'1',$key());$f->allocate('bank-0002',1,'receipt-2',1,'2.50',$key());$f->reverse('receipt-2',(int)$f->entry('receipt-2')['version'],'2021-06-02','Actual correction');
$foreignTx=$foreign->createTransaction(['account'=>$other,'booked_on'=>'2021-06-01','direction'=>'income','amount'=>'2.50','description'=>'Katalog % 0'],$key());
$page=$f->transactionsPage(['account'=>$bank,'direction'=>'income']);
$check($page['total']===1005&&count($page['rows'])===40&&$page['pages']===26,'more than 1000 bank rows have complete bounded pages');
$check($page['counts']===['open'=>1003,'partial'=>1,'assigned'=>0,'reversal'=>1],'status facets distinguish partial allocations and reversals');
$last=$f->transactionsPage(['account'=>$bank,'direction'=>'income'],26);$check(count($last['rows'])===5&&in_array('bank-0000',array_column($last['rows'],'id'),true),'oldest bank originals remain reachable');
$check($f->transactionsPage(['account'=>$bank,'assignment'=>'open'])['total']===1003,'open facet count equals exact unallocated result count');
$check($f->transactionsPage(['account'=>$bank,'assignment'=>'pending'])['total']===1004,'pending contains open and partial, excludes corrected rows');
$check($f->transactionsPage(['account'=>$bank,'assignment'=>'reversal'])['total']===2,'both original and correction have a real reversal filter');
$check($f->transactionsPage(['q'=>'%'])['total']===2,'unfiltered search never includes matching foreign originals');
$literal=$f->transactionsPage(['account'=>$bank,'q'=>'%']);$check($literal['total']===1&&$literal['rows'][0]['id']==='bank-0000','literal wildcard search searches complete original descriptions');
$check($f->transactionsPage(['account'=>$bank,'q'=>'0'])['total']>0,'zero is a real search term');
$check($f->transactionsPage(['account'=>$bank,'q'=>'Katalogbank','from'=>'2021-06-01','to'=>'2021-06-01','direction'=>'income','amount'=>'2,50'])['total']===1005,'account name, inclusive dates, direction and exact decimal amount combine');
$check($f->transactionsPage(['account'=>$bank,'from'=>'2022-01-01'])['total']===0,'empty period returns an explicit empty catalogue');
$check($f->transactionsPage(['account'=>$bank],1000000)['page']===26,'page beyond last clamps without losing older rows');
$check($f->transactionsPage(['account'=>$bank,'preferred'=>'bank-0000'])['rows'][0]['id']==='bank-0000','explicit compatible invoice candidate is reachable on first page');
$receiptPage=$f->paymentCandidatesPage('bank-0000',51);$check($receiptPage['total']===1004&&count($receiptPage['rows'])===4&&in_array('receipt-0',array_column($receiptPage['rows'],'id'),true),'more than 1000 open receipts remain reachable including the oldest');
$check($f->paymentCandidatesPage('bank-0000',1,'CAT-00000')['rows'][0]['id']==='receipt-0','receipt lookup includes the exact original number');
$own=$f->ownMovementCandidatesPage('bank-0002',51,'',$second);$check($own['total']===0,'own movement candidates require the opposite direction');
$out=$f->createTransaction(['account'=>$bank,'booked_on'=>'2021-06-01','direction'=>'expense','amount'=>'2.50','description'=>'Actual own transfer'],$key());
$own=$f->ownMovementCandidatesPage($out,51,'',$second);$check($own['total']===1005&&count($own['rows'])===5&&in_array('counter-0000',array_column($own['rows'],'id'),true),'oldest own counterpart remains reachable beyond the former first 40');
$check($f->ownMovementCandidatesPage($out,1,'%',$second)['rows'][0]['id']==='counter-0000','own counterpart search covers the full ledger');
$check((new Finance($db,'catalogue-reader'))->transactionsPage(['account'=>$bank])['total']===1007,'finance reader can inspect the complete own ledger');
$deny(fn()=>$foreign->transactionsPage(['account'=>$bank]),'foreign account selector rejected');
$deny(fn()=>$f->ownMovementCandidatesPage($out,1,'',$other),'foreign counterpart selector rejected');
foreach([0,'0','-1','1.2','1000001',[]] as $bad)$deny(fn()=>$f->transactionsPage([],$bad),'invalid page rejected');
foreach([['q'=>[]],['assignment'=>'unknown'],['direction'=>'unknown'],['from'=>'2021-02-30'],['from'=>'2022-01-01','to'=>'2021-01-01'],['amount'=>'1.234'],['q'=>str_repeat('x',151)]] as $bad)$deny(fn()=>$f->transactionsPage($bad),'invalid filter rejected');
$db->prepare("INSERT INTO finance_transactions(id,club_id,account_id,booked_on,counterparty,description,cents,source,request_key,fingerprint) SELECT CONCAT('stress-',id),club_id,?,'2020-06-01',counterparty,REPEAT('x',32768),cents,source,LEFT(SHA2(CONCAT('stress-key-',id),256),32),SHA2(CONCAT('stress-',id),256) FROM finance_transactions WHERE club_id='catalogue-own' AND account_id=? AND cents>0")->execute([$stress,$second]);
$hash=static function()use($db){$h=hash_init('sha256');foreach($db->query('SELECT id,SHA2(CAST(JSON_ARRAY('.implode(',',array_map(static fn($column)=>'`'.$column.'`',$db->query('SHOW COLUMNS FROM finance_transactions')->fetchAll(PDO::FETCH_COLUMN))).') AS CHAR),256) AS proof FROM finance_transactions ORDER BY club_id,id') as $row)hash_update($h,$row['id'].$row['proof']);return hash_final($h);};$before=$hash();
$f->transactionsPage(['account'=>$stress],51,20);$check($hash()===$before,'all original transaction values remain byte-identical after full ledger reads');
$probe=proc_open([...TestSupport::phpCommand(),'-d','memory_limit=24M',__DIR__.'/support/bank-catalogue-memory.php',$stress],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($probe))throw new RuntimeException('Memory probe unavailable');$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$check(proc_close($probe)===0&&$errors===''&&json_decode($output,true)['total']===1005,'real 24-MB process pages over more than 32 MB of original descriptions');
$db->exec("UPDATE finance_transactions SET description='CAT-01004' WHERE club_id='catalogue-own' AND id='bank-1004';UPDATE finance_transactions SET description='CAT-01003' WHERE club_id='catalogue-own' AND id='bank-1003'");
$deny(fn()=>$f->matchRecordedTransactions($bank,['bank-1003',$foreignTx]),'whole explicit automatic selection validated before any allocation');
$check($f->transactionDetail('bank-1003')['remaining_cents']===250,'invalid mixed selection cannot partially classify an earlier own original');
$deny(fn()=>$f->matchRecordedTransactions($bank,array_fill(0,41,'bank-1004')),'automatic selection is bounded to one visible page');
$deny(fn()=>$f->matchRecordedTransactions($bank,['bank-1004','bank-1004']),'duplicate automatic selection rejected');
$deny(fn()=>(new Finance($db,'catalogue-reader'))->matchRecordedTransactions($bank,['bank-1004']),'read-only actor cannot run automatic matching');
$check($f->matchRecordedTransactions($bank,['bank-1004'])===1&&$f->entry('receipt-1004')['payment_state']==='paid'&&$f->transactionDetail('bank-1003')['remaining_cents']===250,'automatic matching covers an explicit page in a large ledger and leaves unselected originals alone');
require_once dirname(__DIR__).'/src/Web/View.php';
$failedForm=(static function(Finance $finance)use($db):string{
    $saved=[$_GET,$_POST,$_SESSION??[]];$_GET=['transaction'=>'bank-0003','match_q'=>'CAT-00003'];$_POST=['action'=>'allocate','transaction_id'=>'bank-0003','entry_id'=>'receipt-3','transaction_version'=>'23','entry_version'=>'12','amount'=>'0.23','request_key'=>str_repeat('b',32)];$_SESSION=['actor'=>'catalogue-admin','csrf'=>str_repeat('a',64)];
    ob_start();try{require dirname(__DIR__).'/views/Finance/Accounting/transaction.php';return ob_get_clean();}catch(Throwable $failure){ob_end_clean();throw $failure;}finally{[$_GET,$_POST,$_SESSION]=$saved;}
})($f);
$dom=new DOMDocument();$dom->loadHTML($failedForm,LIBXML_NOERROR|LIBXML_NOWARNING);$xpath=new DOMXPath($dom);$form=$xpath->query('//form[input[@name="entry_id" and @value="receipt-3"]]')->item(0);
$check($form!==null&&$xpath->evaluate('string(.//input[@name="amount"]/@value)',$form)==='0.23'&&$xpath->evaluate('string(.//input[@name="transaction_version"]/@value)',$form)==='23'&&$xpath->evaluate('string(.//input[@name="entry_version"]/@value)',$form)==='12'&&$xpath->evaluate('string(.//input[@name="request_key"]/@value)',$form)===str_repeat('b',32),'failed bank allocation retains the actual amount, original versions and retry key');
final class OwnBankCatalogueSwitch extends PDO{
    public ?Closure $beforeBegin=null;public ?Closure $afterCommit=null;
    public function beginTransaction():bool{if($this->beforeBegin){$callback=$this->beforeBegin;$this->beforeBegin=null;$callback();}return parent::beginTransaction();}
    public function commit():bool{$ok=parent::commit();if($this->afterCommit){$callback=$this->afterCommit;$this->afterCommit=null;$callback();}return $ok;}
}
$cfg=TestSupport::config();$switch=new OwnBankCatalogueSwitch('mysql:host='.$cfg['host'].';port='.$cfg['port'].';dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$switch->beforeBegin=static fn()=>$db->exec("UPDATE actors SET club_id='catalogue-foreign' WHERE id='catalogue-admin'");$switch->afterCommit=static fn()=>$db->exec("UPDATE actors SET club_id='catalogue-own' WHERE id='catalogue-admin'");$original=$hash();
try{$deny(fn()=>(new Finance($switch,'catalogue-admin'))->transactionsPage(),'actual club change before BEGIN and after accepted COMMIT cannot return foreign bank originals');}finally{$db->exec("UPDATE actors SET club_id='catalogue-own' WHERE id='catalogue-admin'");}
$check(!$switch->inTransaction()&&$hash()===$original,'rejected cross-club read preserves every whole transaction and releases its snapshot');
if(getenv('DVM_BANK_CATALOGUE_BROWSER')==='1')require __DIR__.'/support/bank-catalogue-browser-fixture.php';
echo json_encode(['suite'=>'native-bank-catalogue','checks'=>$n,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
