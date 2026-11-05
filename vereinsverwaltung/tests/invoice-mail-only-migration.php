<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\Database;
$current=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($current);
$latestSchema=(int)$current->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn();
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect();Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic A'),('b','Synthetic B')");
$original="Subject: Synthetic old original\r\n\r\nOriginal bytes\r\n";
$db->prepare('INSERT INTO invoice_mail_originals VALUES(?,?,?,?,?,?)')->execute(['a','box','1:1',hash('sha256',$original),$original,'2026-09-07T00:00:00Z']);
$before=$db->query('SELECT * FROM invoice_mail_originals')->fetchAll();
$n=0;function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
function fails(PDO $db,string $sql,string $label):void{try{$db->exec($sql);}catch(PDOException){check(true,$label);return;}throw new RuntimeException($label);}
Database::migrate($db);Database::migrate($db);
check((int)$db->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn()===$latestSchema,'native MySQL migration repeat complete');
check($before===$db->query('SELECT * FROM invoice_mail_originals')->fetchAll(),'existing original bytes and metadata unchanged');
$insert="INSERT INTO invoice_mail_only(club_id,id,mailbox_key,message_uid,sender,subject,received_at,imported_at) VALUES ";
$db->exec($insert."('a','one','box','1:1','synthetic@example.invalid','Synthetic','2026-09-07','2026-09-07T00:00:00Z')");
check((int)$db->query('SELECT COUNT(*) FROM invoice_mail_only')->fetchColumn()===1,'existing original can be associated after migration');
fails($db,$insert."('b','foreign','box','1:1','','','','')",'cross-club original reference rejected');
fails($db,$insert."('a','orphan','box','1:2','','','','')",'missing original reference rejected');
fails($db,$insert."('a','duplicate','box','1:1','','','','')",'duplicate correspondence per original rejected');
fails($db,"UPDATE invoice_mail_only SET state='unknown'",'invalid state rejected');
fails($db,"DELETE FROM invoice_mail_originals WHERE club_id='a'",'linked immutable original cannot disappear');
check(\Kulturhus\Verein\Tests\MySqlFixture::integrity($db)==='ok'&&!(\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db)[0]??false),'all references and database integrity valid');
echo "$n additive native mail preservation checks passed; isolated MySQL\n";
