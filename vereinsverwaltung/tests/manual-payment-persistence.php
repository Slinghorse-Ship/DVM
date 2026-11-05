<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,Finance,InvoicePaymentReports,Documents};
if(PHP_SAPI!=='cli')exit(1);
$db=null;$f=null;$checks=0;
function ok(bool $v,string $label):void{global $checks;if(!$v)throw new RuntimeException($label);++$checks;echo "PASS $label\n";}
function rejected(callable $run,string $label):void{try{$run();}catch(DomainException){ok(true,$label);return;}throw new RuntimeException($label);}
$key=fn()=>bin2hex(random_bytes(16));
try{
 $db=\Kulturhus\Verein\Tests\MySqlFixture::connect();Database::migrate($db);$dsn=\Kulturhus\Verein\Tests\MySqlFixture::dsn($db);
 $db->exec("INSERT INTO clubs VALUES('test-a','Fiktiver Testverein'),('test-b','Zweiter Testverein');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('test-admin','test-a','member',1),('test-reader','test-a','member',0),('test-foreign','test-b','member',1)");
 $f=new Finance($db,'test-admin');$bank=$f->createAccount('Fiktives Vereinskonto','offline','1000','2018-01-01');
 $bill=['kind'=>'expense','category'=>'inventory','description'=>'Historischer Testbeleg','counterparty'=>'Fiktive Beispieltechnik','amount'=>'100','document_date'=>'2019-06-01','due_date'=>'2019-06-30'];$entry=$f->createEntry($bill,$key());
 $reports=new InvoicePaymentReports($db,'test-admin');$reports->save($entry,1,0,'2019-06-05','Fiktive Notiz, noch kein Zahlungsnachweis',$key());
 ok($f->transactions()===[]&&$f->entry($entry)['payment_state']==='open','provisional report creates no bank movement or paid status');
 $f->recordPayment($entry,1,$bank,'2019-06-05','40,00');$paid=$f->entry($entry);$txs=$f->transactions();
 ok($paid['payment_state']==='partial'&&$paid['remaining_cents']===6000&&count($txs)===1,'historical partial payment records only actual amount');
 ok($txs[0]['source']==='manual'&&$txs[0]['booked_on']==='2019-06-05'&&(int)$txs[0]['cents']===-4000,'manual provenance and original payment date retained');
 ok($reports->summary($entry)['state']==='reported','partial payment does not resolve full-invoice report');
 rejected(fn()=>$f->recordPayment($entry,1,$bank,'2019-06-05','40'),'replayed stale form cannot create duplicate movement');
 foreach(['0','60.01','-1','1e2','1.001',''] as $bad)rejected(fn()=>$f->recordPayment($entry,$paid['version'],$bank,'2019-06-06',$bad),'invalid or excessive partial amount rejected: '.($bad===''?'empty':$bad));
 rejected(fn()=>(new Finance($db,'test-reader'))->recordPayment($entry,$paid['version'],$bank,'2019-06-06','1'),'reader cannot record payment');
 rejected(fn()=>(new Finance($db,'test-foreign'))->recordPayment($entry,$paid['version'],$bank,'2019-06-06','1'),'foreign club cannot record payment');
 rejected(fn()=>$f->recordPayment($entry,$paid['version'],$bank,'2017-12-31','1'),'payment before account opening rejected');
 rejected(fn()=>$f->recordPayment($entry,$paid['version'],$bank,'2099-01-01','1'),'future payment rejected');
 ok(count($f->transactions())===1&&$f->entry($entry)['remaining_cents']===6000,'rejected requests leave money and allocations unchanged');
 $f->recordPayment($entry,$paid['version'],$bank,'2019-06-06','60');
 ok($f->entry($entry)['payment_state']==='paid'&&$reports->summary($entry)['state']==='resolved','second partial payment resolves invoice and prior report');
 $allocationIds=array_column($f->allocations($entry),'id');$transactionIds=array_column($f->transactions(),'id');$audit=(int)$db->query("SELECT COUNT(*) FROM audit_events WHERE action='finance.payment.recorded'")->fetchColumn();
 ok($audit===2&&count($allocationIds)===2&&count($transactionIds)===2,'each historical payment has independent audit and persistent allocation');
 $f=null;$reports=null;$db=null;
 $db=\Kulturhus\Verein\Tests\MySqlFixture::existing($dsn);$f=new Finance($db,'test-admin');
 ok($f->entry($entry)['payment_state']==='paid'&&array_column($f->allocations($entry),'id')===$allocationIds,'invoice and allocation references survive database reopen');
 ok(array_column($f->transactions(),'id')===$transactionIds&&(int)$f->accounts()[0]['balance_cents']===90000,'stored transactions survive reopen independently of bank access');
 $open=$f->createEntry(array_replace($bill,['document_date'=>'2022-03-01','due_date'=>'2022-03-31']),$key());
 $manualKey=$key();$data=['account'=>$bank,'booked_on'=>'2022-03-05','direction'=>'expense','amount'=>'100','description'=>'Historische Überweisung mit eigenem Nachweis'];$movement=$f->createTransaction($data,$manualKey);
 ok($f->createTransaction($data,$manualKey)===$movement,'manual transaction replay reuses persistent ID');
 $before=count($f->transactions());$f->allocate($movement,$f->transactionDetail($movement)['version'],$open,1,'100',$key());
 ok(count($f->transactions())===$before&&$f->entry($open)['payment_state']==='paid','existing historical movement allocated without new money flow');
 $third=$f->createEntry($bill,$key());$count=count($f->transactions());$db->exec("CREATE TRIGGER test_payment_audit_failure BEFORE INSERT ON audit_events FOR EACH ROW BEGIN IF NEW.action='finance.payment.recorded' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test'; END IF; END");
 try{$f->recordPayment($third,1,$bank,'2022-06-01','10');throw new RuntimeException('Expected audit rejection');}catch(PDOException){}
 ok(count($f->transactions())===$count&&$f->entry($third)['allocated_cents']===0,'audit failure rolls back both movement and allocation');
 ok(\Kulturhus\Verein\Tests\MySqlFixture::integrity($db)==='ok','persisted database integrity');
 echo "$checks manual payment persistence checks passed; no bank calls, real data or mail\n";
}finally{$f=null;$db=null;}
