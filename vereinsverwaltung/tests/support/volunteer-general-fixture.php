<?php declare(strict_types=1);
ob_start();require __DIR__.'/member-profile-fixture.php';ob_end_clean();
(new \Kulturhus\Verein\Settings($db,$actor))->save('club',array_replace(\Kulturhus\Verein\SettingsSchema::defaults('club'),['name'=>'Synthetic Volunteer Club','street'=>'Vereinsweg 7','postcode'=>'26349','city'=>'Musterort']),0);
$db->prepare("UPDATE memberships SET status='passiv',version=version+1 WHERE club_id=? AND contact_id=?")->execute([$club,$relative]);
$firstChairName='Erika Beispielvorstand';
$firstChairContact=$registry->saveContact(null,0,'person',$firstChairName,'chair@example.invalid','',array_replace($details,['notes'=>'Fiktiver erster Vorstand für PDF-Prüfungen.']));
$firstChairActor=$club.'-first-chair';
$db->prepare("INSERT INTO actors(id,club_id,role,contact_id,is_superuser) VALUES(?,?,'member',?,0)")->execute([$firstChairActor,$club,$firstChairContact]);
$db->prepare("INSERT INTO admission_board VALUES(?,?,'1. Vorstand',1,1)")->execute([$club,$firstChairActor]);
// Deliberately no membership: the person selector must retain its existing size.
$fixture=json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
$fixture['first_chair']=['actor'=>$firstChairActor,'contact'=>$firstChairContact,'name'=>$firstChairName];
file_put_contents($file,json_encode($fixture,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
echo $file,"\n";
