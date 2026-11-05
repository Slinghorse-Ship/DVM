<?php declare(strict_types=1);
require __DIR__.'/member-profile-environment.php';
$file=realpath($argv[1]??'');$prefix=realpath($memberTestOutput);
if(!$file||!$prefix||!str_starts_with(str_replace('\\','/',$file),rtrim(str_replace('\\','/',$prefix),'/').'/'))throw new RuntimeException('Generated local fixture required.');
$fixture=json_decode(file_get_contents($file),true,16,JSON_THROW_ON_ERROR);
if(!str_starts_with($fixture['club'],'member-profile-'))throw new RuntimeException('Synthetic fixture required.');
$db=\Kulturhus\Verein\Tests\MySqlFixture::existing($memberTestDatabase);
if(($argv[2]??'')==='seed'){
 \Kulturhus\Verein\Database::migrate($db);
 $mailings=new \Kulturhus\Verein\ClubMailings($db,$fixture['actor']);
 for($i=1;$i<=26;$i++)$mailings->save('',($i===26?'Bühnenprobe':'Synthetic Nachricht').' '.sprintf('%02d',$i),'Reiner Testentwurf. Kein Versand.',[$fixture['member']],bin2hex(random_bytes(16)));
 echo "Prepared 26 synthetic drafts; no outbox or delivery.\n";exit;
}
$db->exec('START TRANSACTION READ ONLY');
$settings=new \Kulturhus\Verein\Settings($db,$fixture['actor']);$result=[];
foreach(['club','tax','bank','mail'] as $section)$result[$section]=$settings->section($section);
$result['channels']=(new \Kulturhus\Verein\MailChannelSettings($db,$fixture['actor'],\Kulturhus\Verein\ContactArchive::serverKey()))->state();
$result['mailbox']=(new \Kulturhus\Verein\InvoiceMailbox($db,$fixture['actor'],\Kulturhus\Verein\ContactArchive::serverKey()))->state();
$result['mailings']=(new \Kulturhus\Verein\ClubMailings($db,$fixture['actor']))->listing();
$query=$db->prepare('SELECT count(*) FROM club_outbox WHERE mailing_id IN (SELECT id FROM club_mailings WHERE club_id=?)');$query->execute([$fixture['club']]);$result['outbox']=(int)$query->fetchColumn();
echo json_encode($result,JSON_THROW_ON_ERROR);
