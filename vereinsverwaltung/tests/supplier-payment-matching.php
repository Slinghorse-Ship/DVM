<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,Finance,InvoiceSupplierContacts};
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);$db->exec("INSERT INTO clubs VALUES('a','A'),('b','B');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('a','a','member',1),('b','b','member',1);INSERT INTO contacts(id,club_id,kind,name) VALUES('company','a','organisation','Synthetic Parts GmbH'),('other','b','organisation','Synthetic Parts GmbH')");
$f=new Finance($db,'a');$key=fn()=>bin2hex(random_bytes(16));$day=date('Y-m-d');$bank=$f->createAccount('Synthetic bank','offline','1000',$day);$n=0;function ck($v,$m){global $n;if(!$v)throw new RuntimeException($m);++$n;echo "PASS $m\n";}
$invoice=function($ref,$amount='10.00',$linked=true)use($f,$db,$key,$day){return $f->createEntry(['kind'=>'expense','category'=>'general','description'=>'Synthetic purchase','counterparty'=>'Synthetic Parts GmbH','amount'=>$amount,'document_date'=>$day,'due_date'=>$day,'notes'=>'Lieferanten-Rechnungsnummer: '.$ref],$key(),$linked?function($id)use($db){$version=(int)$db->query("SELECT version FROM contacts WHERE id='company'")->fetchColumn();(new InvoiceSupplierContacts($db,'a'))->link($id,'company',$version);}:null);};
$transfer=function($ref,$amount='10.00',$name='Synthetic Parts GmbH',$direction='expense')use($f,$bank,$day,$key){return $f->createTransaction(['account'=>$bank,'booked_on'=>$day,'direction'=>$direction,'amount'=>$amount,'description'=>'Payment '.$ref,'counterparty'=>$name],$key());};
$entry=$invoice('SUP-101');$tx=$transfer('SUP-101');ck($f->entry($entry)['status']==='paid'&&count($f->allocations($tx,true))===1,'exact supplier reference amount direction and linked company auto match');ck(count($f->entries())===1&&count($f->transactions())===1,'no duplicate invoice or transaction');
$entry=$invoice('SUP-102');$tx=$transfer('SUP-102','10.00','Different Company');ck(!$f->allocations($tx,true),'different company not guessed');
$entry=$invoice('SUP-103');$tx=$transfer('SUP-103','5.00');ck(!$f->allocations($tx,true),'partial amount stays manual');
$entry=$invoice('SUP-104');$tx=$transfer('SUP-104','10.00','Synthetic Parts GmbH','income');ck(!$f->allocations($tx,true),'wrong direction stays manual');
$invoice('SUP-105');$invoice('SUP-105');$tx=$transfer('SUP-105');ck(!$f->allocations($tx,true),'duplicate open reference stays manual');
$invoice('SUP-106','10.00',false);$tx=$transfer('SUP-106');ck(!$f->allocations($tx,true),'unlinked free text company not enough');
$invoice('SUP-107');$tx=$transfer('SUP-1079');ck(!$f->allocations($tx,true),'reference substring not accepted');
$invoice('SUP-108');$tx=$transfer('SUP-108','10.00','SYNTHETIC PARTS GMBH');ck(count($f->allocations($tx,true))===1,'case normalization preserves exact company identity');
$e1=$invoice('SUP-109');$e2=$invoice('SUP-110');$tx=$transfer('SUP-109 SUP-110');ck(!$f->allocations($tx,true),'two invoice references ambiguous');
$entry=$invoice('SUP-111');$tx=$transfer('SUP-111');$allocation=$f->allocations($tx,true)[0];$f->releaseAllocation($allocation['id'],$f->transactionDetail($tx)['version'],$f->entry($entry)['version'],'Synthetic manual correction');$f->matchRecordedTransactions();ck($f->entry($entry)['status']==='open','manual release never silently rematched');
ck(\Kulturhus\Verein\Tests\MySqlFixture::integrity($db)==='ok','database integrity');echo "$n supplier matching checks passed; synthetic only\n";
