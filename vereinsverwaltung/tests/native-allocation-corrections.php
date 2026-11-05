<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
require dirname(__DIR__).'/src/bootstrap.php';
use Kulturhus\Native\{TestSupport,Installer\SchemaRegistry};
use Kulturhus\Verein\Finance;
$db=TestSupport::db();SchemaRegistry::install($db);$n=0;
$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException($label);++$n;echo "PASS $label\n";};
$deny=static function(callable $run,string $label,?int $status=null)use($check):void{try{$run();}catch(DomainException $failure){$check($status===null||$failure->getCode()===$status,$label);return;}throw new RuntimeException('Accepted: '.$label);};
$key=static fn()=>bin2hex(random_bytes(16));
$db->exec("INSERT INTO clubs VALUES('correction-own','Own correction club'),('correction-other','Foreign correction club');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('correction-admin','correction-own','member',1),('correction-reader','correction-own','member',0),('correction-foreign','correction-other','member',1);INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,version) VALUES('correction-own','correction-read','Read finance','','none','read',0,1);INSERT INTO settings_role_assignments VALUES('correction-reader','correction-own','correction-read',1)");
$f=new Finance($db,'correction-admin');$bank=$f->createAccount('Original bank','offline','100','2020-01-01');$cash=$f->createAccount('Original cash','cash','100','2020-01-01');
$entry=static fn($amount='20',$kind='income')=>$f->createEntry(['kind'=>$kind,'description'=>'Correction receipt','counterparty'=>'Original party','amount'=>$amount,'category'=>'general','document_date'=>'2020-06-01','due_date'=>'2020-06-01'],$key());
$tx=static fn($account,$amount='10',$direction='income')=>$f->createTransaction(['account'=>$account,'booked_on'=>'2020-06-01','direction'=>$direction,'amount'=>$amount,'description'=>'Original movement'],$key());
$allocate=static fn($t,$e,$amount='10')=>$f->allocate($t,(int)$f->transactionDetail($t)['version'],$e,(int)$f->entry($e)['version'],$amount,$key());
final class OwnedCorrectionConnection extends PDO{
 public ?Closure $afterBegin=null;public bool $loseCommit=false;
 public function beginTransaction():bool{$ok=parent::beginTransaction();if($this->afterBegin){$run=$this->afterBegin;$this->afterBegin=null;$run();}return $ok;}
 public function commit():bool{$ok=parent::commit();if($this->loseCommit){$this->loseCommit=false;throw new PDOException('Owned lost commit acknowledgement');}return $ok;}
}
$cfg=TestSupport::config();$second=new OwnedCorrectionConnection('mysql:host='.$cfg['host'].';port='.$cfg['port'].';dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$other=new Finance($second,'correction-admin');
$receipt=$entry();$first=$tx($bank);$next=$tx($bank);$link=$allocate($first,$receipt);$tv=(int)$f->transactionDetail($first)['version'];$ev=(int)$f->entry($receipt)['version'];
$second->afterBegin=static function()use($second,$allocate,$next,$receipt):void{
 // Establish an actual older consistent snapshot, then commit a second payment
 // through an independent connection before the correction checks its sources.
 $second->query('SELECT COUNT(*) FROM finance_allocations')->fetchColumn();$allocate($next,$receipt);
};
$deny(fn()=>$other->releaseAllocation($link,$tv,$ev,'Prior receipt state'),'concurrent committed payment cannot be hidden by an older correction snapshot',409);
$check(!$second->inTransaction()&&$f->entry($receipt)['allocated_cents']===2000&&!$f->allocations($receipt)[0]['voided_at'],'rejected stale correction preserves both current payments and releases its transaction');
$proof=static function()use($db):array{return $db->query('SELECT id,account_id,booked_on,counterparty,description,cents,source,reversal_of,request_key,fingerprint FROM finance_transactions ORDER BY club_id,id')->fetchAll();};
$before=$proof();$reason=str_repeat('ä',499).'0';$tv=(int)$f->transactionDetail($first)['version'];$ev=(int)$f->entry($receipt)['version'];
$f->releaseAllocation($link,$tv,$ev,$reason);
$check($f->entry($receipt)['allocated_cents']===1000&&$f->entry($receipt)['payment_state']==='partial'&&$f->transactionDetail($first)['remaining_cents']===1000,'release opens exactly one payment while retaining the other current allocation');
$row=$db->query("SELECT * FROM finance_allocations WHERE id=".$db->quote($link))->fetch();
$check($row['void_reason']===$reason&&$row['voided_at']!==null,'all five hundred Unicode correction characters including zero are preserved');
$versions=[$f->entry($receipt)['version'],$f->transactionDetail($first)['version']];$f->releaseAllocation($link,$tv,$ev,$reason);
$check($versions===[$f->entry($receipt)['version'],$f->transactionDetail($first)['version']]&&(int)$db->query("SELECT COUNT(*) FROM audit_events WHERE action='finance.allocation.released' AND object_id=".$db->quote($link))->fetchColumn()===1,'repeated accepted correction preserves both versions and exactly one original audit event');
$deny(fn()=>$f->releaseAllocation($link,$tv,$ev,'Different intention'),'a repeated correction cannot rewrite its original reason');
$check($proof()===$before,'correction changes no original money movement and adds no transaction');
$fresh=$entry();$movement=$tx($bank);$active=$allocate($movement,$fresh);$tv=(int)$f->transactionDetail($movement)['version'];$ev=(int)$f->entry($fresh)['version'];
foreach(['',str_repeat('ä',501),"\xff"] as $bad)$deny(fn()=>$f->releaseAllocation($active,$tv,$ev,$bad),'empty overlong or invalid Unicode correction rejected');
$deny(fn()=>(new Finance($db,'correction-reader'))->releaseAllocation($active,$tv,$ev,'No write'),'read-only finance actor cannot release an allocation');
$deny(fn()=>(new Finance($db,'correction-foreign'))->releaseAllocation($active,$tv,$ev,'Wrong club'),'foreign actor cannot release an allocation');
$db->beginTransaction();$db->prepare('SELECT id FROM finance_transactions WHERE id=? FOR UPDATE NOWAIT')->execute([$movement]);
try{$deny(fn()=>$other->releaseAllocation($active,$tv,$ev,'Concurrent transaction editor'),'actual independent source row lock yields a safe retryable correction',503);}finally{$db->rollBack();}
$check(!$second->inTransaction()&&$f->entry($fresh)['allocated_cents']===1000,'source lock failure rolls back the allocation lock without changing payment');
$db->beginTransaction();$db->prepare('SELECT id FROM finance_accounts WHERE id=? FOR UPDATE NOWAIT')->execute([$bank]);
try{$deny(fn()=>$other->releaseAllocation($active,$tv,$ev,'Concurrent cash closure'),'actual independent account lock protects the correction boundary',503);}finally{$db->rollBack();}
$check(!$second->inTransaction(),'account conflict releases the correction transaction');
$db->beginTransaction();$db->prepare('SELECT id FROM finance_allocations WHERE id=? FOR UPDATE NOWAIT')->execute([$active]);
try{$deny(fn()=>$other->releaseAllocation($active,$tv,$ev,'Concurrent correction'),'actual independent allocation lock stops a simultaneous correction',503);}finally{$db->rollBack();}
$check(!$second->inTransaction(),'simultaneous correction failure releases every held lock');
$snapshot=static function()use($db,$active,$fresh,$movement):array{$out=[];foreach(['finance_allocations'=>['id',$active],'finance_entries'=>['id',$fresh],'finance_transactions'=>['id',$movement]] as $table=>[$column,$id]){$q=$db->prepare('SELECT * FROM '.$table.' WHERE '.$column.'=?');$q->execute([$id]);$out[$table]=$q->fetch();}return $out;};
$before=$snapshot();$db->exec("CREATE TRIGGER correction_fail BEFORE INSERT ON audit_events FOR EACH ROW BEGIN IF NEW.action='finance.allocation.released' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Owned correction failure'; END IF; END");
try{$f->releaseAllocation($active,$tv,$ev,'SQL failure');throw new RuntimeException('Failed audit accepted');}catch(PDOException){}finally{$db->exec('DROP TRIGGER correction_fail');}
$check($snapshot()===$before,'actual SQL failure rolls back the entire allocation and both source versions');
$second->loseCommit=true;try{$other->releaseAllocation($active,$tv,$ev,'Accepted with lost acknowledgement');throw new RuntimeException('Missing lost acknowledgement');}catch(PDOException){}
$other->releaseAllocation($active,$tv,$ev,'Accepted with lost acknowledgement');
$check($f->entry($fresh)['allocated_cents']===0&&(int)$db->query("SELECT COUNT(*) FROM audit_events WHERE action='finance.allocation.released' AND object_id=".$db->quote($active))->fetchColumn()===1,'actual lost COMMIT acknowledgement can repeat the same correction without a second write');
$cashReceipt=$entry();$cashMovement=$tx($cash);$cashLink=$allocate($cashMovement,$cashReceipt);$db->prepare('INSERT INTO cash_closures VALUES(?,?,?,?,?)')->execute([$key(),'correction-own',$cash,'2020-06-30',11000]);
$deny(fn()=>$f->releaseAllocation($cashLink,(int)$f->transactionDetail($cashMovement)['version'],(int)$f->entry($cashReceipt)['version'],'Closed cash'),'cash closure keeps its actual original allocation intact');
$check($f->entry($cashReceipt)['allocated_cents']===1000,'refused closed cash correction retains its original payment');
require dirname(__DIR__).'/src/Web/View.php';
$render=static function(array $post)use($f,$receipt):string{$_GET=['bank_page'=>'3','bank_q'=>'Original movement'];$_POST=$post;$_SESSION=['actor'=>'correction-admin','csrf'=>str_repeat('a',64)];$finance=$f;$r=$f->entry($receipt);$allocationRows=$f->allocations($receipt);$allocationReturn='entry';ob_start();require dirname(__DIR__).'/views/Finance/Accounting/allocation-history.php';return ob_get_clean();};
$viewBefore=$snapshot();
$remaining=array_values(array_filter($f->allocations($receipt),static fn($l)=>!$l['voided_at']))[0];$post=['action'=>'release-allocation','id'=>$remaining['id'],'reason'=>'Own <em>0</em> ä🙂','transaction_version'=>'0','entry_version'=>'0'];$html=$render($post);
$dom=new DOMDocument();$dom->loadHTML('<meta charset="utf-8">'.$html,LIBXML_NOERROR|LIBXML_NOWARNING);$xp=new DOMXPath($dom);$form=$xp->query('//form[input[@name="id" and @value="'.$remaining['id'].'"]]')->item(0);
$check($form!==null&&$xp->evaluate('string(.//input[@name="reason"]/@value)',$form)===$post['reason']&&$xp->evaluate('string(.//input[@name="entry_version"]/@value)',$form)==='0'&&$xp->evaluate('string(.//input[@name="transaction_version"]/@value)',$form)==='0','failed correction retains the full escaped reason and both original conflict versions');
$check(str_contains($html,'<details class="wide" open>')&&!str_contains($html,'<em>0</em>'),'failed correction stays open with safe original text');
$html=$render($post+['review_allocation'=>'1']);$check(str_contains($html,'Aktueller Stand neu gelesen.')&&str_contains($html,'name="entry_version" value="'.$f->entry($receipt)['version'].'"')&&str_contains($html,'bank_page=3'),'explicit read adopts current versions and retains the complete navigation context');
$check($snapshot()===$viewBefore,'all actual original-context history error and deliberate review renders preserve every original money allocation and audit row');
echo json_encode(['suite'=>'native-allocation-corrections','checks'=>$n,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
