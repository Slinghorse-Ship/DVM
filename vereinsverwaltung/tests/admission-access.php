<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,Registry,MembershipApplications,Documents,DocumentCatalog,AdmissionVotes,BoardAccess};
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Test'),('b','Fremd');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('s','a','members',1),('staff','a','members',0),('board','a','member',0),('editor','a','editor',1),('foreign','b','members',1)");
$n=0;function ok(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
function denied(callable $f,string $label):void{try{$f();}catch(DomainException){ok(true,$label);return;}throw new RuntimeException($label);}
$i=new MembershipApplications($db,'a','s');$cfg=$i->settings()['config'];$cfg['enabled']=true;$i->saveSettings($cfg,0);
$id=(new MembershipApplications($db,'a'))->submit(['first_name'=>'Privater','last_name'=>'Test','email'=>'private@example.invalid','birthday'=>'1990-01-02','address'=>'Testweg'],bin2hex(random_bytes(16)),1,true);
$d=new Documents($db,'s');$source=$d->source('application',$id);$pdf=$d->listing(['source_type'=>'application','source_id'=>$id])[0];$hash=$d->file($pdf['id'])['sha256'];
foreach(['staff','editor','foreign'] as $actor){$intake=new MembershipApplications($db,'a',$actor);$docs=new Documents($db,$actor);$votes=new AdmissionVotes($db,$actor);
 denied(fn()=>$intake->applications(),'private applications hidden '.$actor);denied(fn()=>$intake->familyChoices(),'family choices hidden '.$actor);denied(fn()=>$docs->file($pdf['id']),'generic file endpoint protected '.$actor);denied(fn()=>$votes->documentFile($id,$pdf['id']),'inline PDF endpoint protected '.$actor);denied(fn()=>$docs->folder($source['folder_id']),'application folder hidden '.$actor);denied(fn()=>$docs->source('application',$id),'application source hidden '.$actor);
 ok(!in_array($pdf['id'],array_column($docs->listing(),'id'),true),'document listing excludes application '.$actor);
}
$staff=new Documents($db,'staff');$folder=$d->folder($source['folder_id']);denied(fn()=>$staff->saveFolder($folder['id'],$folder['version'],'Changed','', 'members'),'ordinary staff cannot rename protected folder');
$votes=new AdmissionVotes($db,'s');ok($votes->canAccess()&&!$votes->canVote(),'superuser sees applications without gaining personal board vote');$votes->grant('board',0,'1. Vorstand',true,true);
$boardIntake=new MembershipApplications($db,'a','board');ok($boardIntake->application($id)['id']===$id,'registered board can view application');$bd=new Documents($db,'board');ok($bd->file($pdf['id'])['sha256']===$hash,'board can read PDF without general members access');
$child=$bd->saveFolder('',0,'Nachträge',$folder['id'],'members');ok($bd->folder($child)['admission_only']===1,'new subfolder inherits protection');
$scan=$bd->upload('Ergänzung.txt','Test',['folder'=>$child,'kind'=>'membership','title'=>'Privat'],bin2hex(random_bytes(16)));denied(fn()=>$staff->file($scan),'unlinked attachment in protected folder remains private');
$root=DocumentCatalog::folder($db,'a',['Mitglieder'],'members');$doc=$d->document($scan);$d->update($scan,$doc['version'],['folder'=>$root,'kind'=>'membership','title'=>'Verschoben','reason'=>'Test','links'=>[]]);denied(fn()=>$staff->file($scan),'privacy remains after moving out of application folder');
$original=$d->document($pdf['id']);$d->update($pdf['id'],$original['version'],['folder'=>$root,'kind'=>$original['kind'],'title'=>'Original','reason'=>'Test','links'=>[]]);denied(fn()=>$staff->file($pdf['id']),'original privacy remains after unlink and move');ok($d->file($pdf['id'])['sha256']===$hash,'original bytes unchanged');
$db->exec("UPDATE admission_board SET enabled=0 WHERE actor_id='board'");denied(fn()=>$boardIntake->application($id),'board revocation applies immediately');denied(fn()=>$bd->file($pdf['id']),'revocation removes file access');
$db->exec("UPDATE actors SET active=0 WHERE id='s'");ok(!BoardAccess::allowed($db,'s'),'inactive superuser denied');
echo "$n admission access checks passed\n";
