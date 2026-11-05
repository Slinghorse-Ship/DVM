<?php declare(strict_types=1);
require __DIR__.'/inventory-fields-environment.php';
use Kulturhus\Verein\{Database,Finance,Documents};
$db=\Kulturhus\Verein\Tests\MySqlFixture::existing($inventoryTestDatabase);Database::migrate($db);$club='manual-payment-'.bin2hex(random_bytes(5));$actor=$club.'-owner';
$db->prepare('INSERT INTO clubs VALUES(?,?)')->execute([$club,'Fiktiver Verein für Zahlungstest']);$db->prepare("INSERT INTO actors(id,club_id,role,is_superuser) VALUES(?,?,'member',1)")->execute([$actor,$club]);
$f=new Finance($db,$actor);$account=$f->createAccount('Fiktives Vereinskonto','offline','1000','2018-01-01');$entry=$f->createEntry(['kind'=>'expense','category'=>'inventory','description'=>'Fiktive Rechnung von 2019','counterparty'=>'Beispieltechnik','amount'=>'100','document_date'=>'2019-06-01','due_date'=>'2019-06-30'],bin2hex(random_bytes(16)));
$doc=(new Documents($db,$actor))->upload('fiktiv.xml','<Invoice/>',['title'=>'Fiktive Originalrechnung','kind'=>'receipt','scope'=>'finance','links'=>['entry:'.$entry]],bin2hex(random_bytes(16)));
if(!is_dir($inventoryTestSessions))mkdir($inventoryTestSessions,0700,true);session_name('KH_VEREIN_DEMO');session_save_path($inventoryTestSessions);session_id(bin2hex(random_bytes(20)));session_start();$_SESSION=['actor'=>$actor,'csrf'=>bin2hex(random_bytes(32))];$session=session_id();session_write_close();
$run=$inventoryTestRoot.'/.local/manual-payment-fixtures/'.$club;mkdir($run,0700,true);$path=$run.'/fixture.json';file_put_contents($path,json_encode(['base'=>$inventoryTestBase,'club'=>$club,'actor'=>$actor,'session'=>$session,'account'=>$account,'entry'=>$entry,'doc'=>$doc,'project_hash'=>hash('sha256',strtolower(str_replace('\\','/',$inventoryTestRoot)))],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));echo $path,"\n";
