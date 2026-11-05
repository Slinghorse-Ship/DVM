<?php declare(strict_types=1);
require __DIR__.'/invoice-reminder-source.php';
require_once __DIR__.'/../src/Finance/Invoices/Reminder/ReminderSchema.php';
use Kulturhus\Verein\Finance\Invoices\Reminder\ReminderSchema;

$ownedDsn=\Kulturhus\Verein\Tests\MySqlFixture::dsn($db);
if(!str_contains($ownedDsn,'port=13372;')||!preg_match('/dbname=kh_test_[a-f0-9]{24}(?:;|$)/D',$ownedDsn))throw new RuntimeException('Owned reminder schema fixture required.');
$beforeSchema=ownOriginalDatabaseHash($db,$tables,null,true);
ReminderSchema::install($db);
$reminderTables=['native_settings_requests','native_invoice_reminder_deliveries','native_invoice_reminder_attempts'];
foreach($reminderTables as$table)$check((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'explicit reminder schema creates empty '.$table.' without a message or historical approval');
$actualTables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$expectedTables=array_values(array_unique([...$tables,...$reminderTables]));sort($actualTables);sort($expectedTables);$check($actualTables===$expectedTables,'actual additive reminder schema introduces only its three declared owned tables');
$check(ownOriginalDatabaseHash($db,$tables,null,true)===$beforeSchema,'actual additive reminder installation preserves every complete earlier business row');
$allReminderTables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$installed=ownOriginalDatabaseHash($db,$allReminderTables);ReminderSchema::install($db);$check(ownOriginalDatabaseHash($db,$allReminderTables)===$installed,'actual reminder installation repeats without changing any complete earlier row or output');
$db->exec('START TRANSACTION');try{$deny(fn()=>ReminderSchema::install($db),0,'request transaction cannot install reminder tables');$check($db->inTransaction(),'refused reminder DDL preserves caller transaction');}finally{if($db->inTransaction())$db->rollBack();}
echo json_encode(['suite'=>'invoice-reminder-schema','checks'=>$n,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
