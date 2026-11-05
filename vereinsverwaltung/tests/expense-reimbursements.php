<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\Database;
use Kulturhus\Verein\Registry;
use Kulturhus\Verein\Finance;
use Kulturhus\Verein\ExpenseReimbursements;

$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$ok=0;function ok(bool $value,string $label):void{global $ok;if(!$value)throw new RuntimeException($label);++$ok;echo "PASS $label\n";}
$db->exec("INSERT INTO clubs VALUES('club','Testverein');INSERT INTO actors(id,club_id,role) VALUES('treasurer','club','treasurer');INSERT INTO finance_accounts VALUES('bank','club','Vereinskonto','offline',100000,'2026-01-01')");
$contact=bin2hex(random_bytes(16));$db->prepare("INSERT INTO contacts(id,club_id,kind,name,email,address) VALUES(?,?,?,?,?,?)")->execute([$contact,'club','person','Mitglied Beispiel','member@example.invalid','Vereinsweg 1']);$membership=bin2hex(random_bytes(16));$db->prepare("INSERT INTO memberships(id,club_id,contact_id,member_number,status,starts_on) VALUES(?,?,?,?,?,?)")->execute([$membership,'club',$contact,'M-1','aktiv','2026-01-01']);
$finance=new Finance($db,'treasurer');$entry=$finance->createEntry(['kind'=>'expense','description'=>'Bühnenteil privat gekauft','counterparty'=>'Lieferant GmbH','amount'=>'119.00','document_date'=>'2026-09-10','due_date'=>'2026-09-10','category'=>'inventory','notes'=>''],bin2hex(random_bytes(16)));
$service=new ExpenseReimbursements($db,'treasurer');$claim=$service->createForEntry($entry,$contact,'member',bin2hex(random_bytes(16)));$row=$service->forEntry($entry);ok($row!==null&&$row['state']==='open'&&$row['contact_name']==='Mitglied Beispiel','private advance creates an open reimbursement claim');ok($row['invoice_recipient_label']==='Mitglied','invoice recipient is retained');
$tx=$finance->createTransaction(['account'=>'bank','booked_on'=>'2026-09-10','direction'=>'expense','amount'=>'119.00','counterparty'=>'Mitglied Beispiel','description'=>'Erstattung Bühnenteil'],bin2hex(random_bytes(16)));$finance->allocate($tx,1,$entry,1,'119.00',bin2hex(random_bytes(16)));$db->exec("UPDATE finance_reimbursement_claims SET state='approved' WHERE id='$claim'");$txDetail=$finance->transactionDetail($tx);$service->linkPaid($claim,1,$tx,(int)$txDetail['version'],bin2hex(random_bytes(16)));$row=$service->forEntry($entry);ok($row['state']==='paid'&&$row['paid_transaction_id']===$tx,'assigned reimbursement transaction closes the claim');
try{$service->createForEntry($entry,$contact,'club',bin2hex(random_bytes(16)));throw new RuntimeException('conflicting claim accepted');}catch(DomainException){ok(true,'conflicting reimbursement claim is rejected');}
echo "$ok reimbursement checks passed\n";
