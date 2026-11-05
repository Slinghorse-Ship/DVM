<?php declare(strict_types=1);
ob_start();require __DIR__.'/member-profile-fixture.php';ob_end_clean();
$fixture=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$fixture['board']=[];
foreach(\Kulturhus\Verein\AdmissionVotes::OFFICES as $i=>$office){
 $person=$registry->saveContact(null,0,'person','Synthetic Office '.($i+1),'office'.($i+1).'@example.invalid','Synthetic address',$details);
 $who=$club.'-office-'.$i;$db->prepare("INSERT INTO actors(id,club_id,role,contact_id) VALUES(?,?,'member',?)")->execute([$who,$club,$person]);
 (new \Kulturhus\Verein\AdmissionVotes($db,$actor))->grant($who,0,$office,true,true);$fixture['board'][]=$person;
}
file_put_contents($file,json_encode($fixture,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));echo $file,"\n";
