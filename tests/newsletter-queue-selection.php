<?php declare(strict_types=1);
require __DIR__.'/../vereinsverwaltung/src/bootstrap.php';
require __DIR__.'/../processwire/site/src/MembershipNotificationDelivery.php';
require_once __DIR__.'/../vereinsverwaltung/tests/mysql-bootstrap.php';
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect();
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE application_confirmations(id TEXT,club_id TEXT,confirmed_at INTEGER,newsletter INTEGER,newsletter_status TEXT,created_at INTEGER)');
$insert=$db->prepare('INSERT INTO application_confirmations VALUES(?,?,?,?,?,?)');
foreach([
 ['pending','club',1,1,'pending',1],
 ['due','club',1,1,'retry:1800000000:1',2],
 ['later','club',1,1,'retry:1800000001:2',3],
 ['unconfirmed','club',null,1,'pending',4],
 ['optout','club',1,0,'pending',5],
 ['other','other',1,1,'pending',6],
 ['done','club',1,1,'done',7],
 ['uncertain','club',1,1,'uncertain',8],
] as $row)$insert->execute($row);
$rows=\Kulturhus\MembershipNotificationDelivery::dueNewsletterRequests($db,'club',1800000000);
if(array_column($rows,'id')!==['pending','due'])throw new RuntimeException('Queue filtering failed');
$rows=\Kulturhus\MembershipNotificationDelivery::dueNewsletterRequests($db,'club',1800000001);
if(array_column($rows,'id')!==['pending','due','later'])throw new RuntimeException('Retry boundary failed');
for($i=0;$i<25;$i++)$insert->execute(['limit-'.$i,'limit',1,1,'pending',$i]);
if(count(\Kulturhus\MembershipNotificationDelivery::dueNewsletterRequests($db,'limit',1800000000))!==20)throw new RuntimeException('Limit failed');
if((int)$db->query("SELECT COUNT(*) FROM application_confirmations WHERE newsletter_status='sending'")->fetchColumn()!==0)throw new RuntimeException('Selection modified queue');
echo "PASS: queue filters, exact retry boundary, 20-row limit, read-only selection; no transport invoked\n";
