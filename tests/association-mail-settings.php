<?php declare(strict_types=1);
namespace ProcessWire {class ProcessWire {public object $config;}}
namespace {
require __DIR__.'/../processwire/site/src/AssociationMailSettings.php';
require_once __DIR__.'/../vereinsverwaltung/tests/mysql-bootstrap.php';
$n=0;function ok(bool $v,string $m):void{global $n;if(!$v)throw new RuntimeException($m);++$n;echo "PASS $m\n";}
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect();$path=\Kulturhus\Verein\Tests\MySqlFixture::dsn($db);
putenv('VEREIN_DATABASE_USER=root');putenv('VEREIN_DATABASE_PASSWORD=isolated-regression-only');
try{
 $db->exec('CREATE TABLE club_settings(club_id TEXT,section TEXT,payload TEXT)');$wire=new \ProcessWire\ProcessWire();$wire->config=(object)['kulturhusVereinDatabase'=>$path,'kulturhusVereinClub'=>'a','kulturhusVereinRoot'=>dirname(__DIR__).'/vereinsverwaltung'];
 putenv('SMTP_DSN=synthetic://keep-private');putenv('MAIL_FROM=server@example.invalid');
 \Kulturhus\AssociationMailSettings::apply($wire);ok(getenv('MAIL_FROM')==='server@example.invalid','empty settings preserve server fallback');
 $q=$db->prepare('INSERT INTO club_settings VALUES(?,?,?)');$q->execute(['b','mail',json_encode(['sender_email'=>'other@example.invalid'])]);$q->execute(['a','mail',json_encode(['sender_email'=>'club@example.invalid','sender_name'=>'Club','reply_to'=>'reply@example.invalid'])]);
 \Kulturhus\AssociationMailSettings::apply($wire);
 ok(getenv('MAIL_FROM')==='club@example.invalid','membership sender uses saved setting');ok(getenv('NEWSLETTER_FROM_EMAIL')==='club@example.invalid','newsletter sender uses same saved setting');ok(getenv('MAIL_REPLY_TO')==='reply@example.invalid'&&getenv('NEWSLETTER_REPLY_TO')==='reply@example.invalid','both mail paths share reply address');ok(getenv('MAIL_FROM_NAME')==='Club'&&getenv('NEWSLETTER_FROM_NAME')==='Club','both mail paths share display name');ok(getenv('SMTP_DSN')==='synthetic://keep-private','protected transport credentials remain untouched');
 $db->exec("UPDATE club_settings SET payload='{}' WHERE club_id='a'");\Kulturhus\AssociationMailSettings::apply($wire);ok(getenv('MAIL_FROM')==='club@example.invalid','missing optional value does not select another club');
 echo "$n association mail checks passed; no transport calls\n";
}finally{$db=null;}
}
