<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\Database;use Kulturhus\Verein\MembershipApplications;
$tests=0;function check(bool $ok,string $label):void{global $tests;++$tests;if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException('Not denied: '.$label);}
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);$db->exec("INSERT INTO clubs VALUES('a','A'),('b','B');INSERT INTO actors(id,club_id,role) VALUES('s','a','members'),('t','b','members'),('f','a','treasurer'),('e','a','editor'),('m','a','member')");
$db->exec("UPDATE actors SET is_superuser=1 WHERE id IN ('s','t')");
$s=new MembershipApplications($db,'a','s');$public=new MembershipApplications($db,'a');$other=new MembershipApplications($db,'b','t');$key=fn()=>bin2hex(random_bytes(16));$answers=['first_name'=>'Test','last_name'=>'Person','email'=>'test@example.invalid','birthday'=>'1990-01-02','address'=>'Testweg 1'];
$config=$s->settings()['config'];check(!$config['enabled'],'application disabled by default');
denied(fn()=>$public->submit($answers,$key(),0,true),'disabled intake blocked');
foreach([null,'f','e','m','t'] as $actor){$x=new MembershipApplications($db,'a',$actor);denied(fn()=>$x->applications(),'private list protected '.($actor??'anonymous'));denied(fn()=>$x->saveSettings($config,0),'settings protected '.($actor??'anonymous'));}
$config['enabled']=true;$s->saveSettings($config,0);check($s->settings()['version']===1,'settings version persisted');
denied(fn()=>$s->saveSettings($config,0),'stale settings blocked');
denied(fn()=>$s->saveSettings(array_replace($config,['payment'=>'sepa']),1),'unsupported SEPA blocked');
denied(fn()=>$s->saveSettings(array_replace($config,['fields'=>array_replace($config['fields'],['first_name'=>'hidden'])]),1),'name cannot be hidden');
denied(fn()=>$public->submit($answers,$key(),0,true),'stale notice blocked');
denied(fn()=>$public->submit($answers,$key(),1,false),'required consent checked on server');
foreach([['last_name'=>''],['email'=>'not-email'],['birthday'=>'2026-02-30'],['birthday'=>'2099-01-01'],['marital_status'=>'test'],['unknown'=>'test'],['phone'=>[]]] as $bad)denied(fn()=>$public->submit(array_replace($answers,$bad),$key(),1,true),'invalid or hidden answer blocked');
$token=$key();$id=$public->submit($answers,$token,1,true);check($public->submit($answers,$token,1,true)===$id,'submission idempotent');check(count($s->applications())===1,'one pending application');
check((int)$db->query('SELECT count(*) FROM contacts')->fetchColumn()===0,'submission does not create member');
denied(fn()=>$other->application($id),'application tenant boundary');denied(fn()=>$public->application($id),'anonymous cannot retrieve applications');
$config['notice_text']='Neue Fassung';$s->saveSettings($config,1);check(json_decode($s->application($id)['consent_snapshot'],true)['text']!=='Neue Fassung','consent snapshot immutable across settings change');
denied(fn()=>$s->review($id,2,'accepted','Test','A1','2026-09-01',externalDecision:true,decisionDate:'2026-09-05',familyTarget:'single'),'stale review blocked');
denied(fn()=>$s->review($id,1,'accepted','','A1','2026-09-01',externalDecision:true,decisionDate:'2026-09-05',familyTarget:'single'),'review note required');
$contact=$s->review($id,1,'accepted','Synthetisch geprüft','A1','2026-09-01',externalDecision:true,decisionDate:'2026-09-05',familyTarget:'single');check($contact!==null&&$s->application($id)['contact_id']===$contact,'accepted application creates linked contact');check((int)$db->query('SELECT count(*) FROM memberships')->fetchColumn()===1,'accepted application creates exactly one membership');
denied(fn()=>$s->review($id,2,'accepted','again','A2','2026-09-01',externalDecision:true,decisionDate:'2026-09-05',familyTarget:'single'),'double acceptance blocked');
$dup=$public->submit($answers,$key(),2,true);denied(fn()=>$s->review($dup,1,'accepted','duplicate','A2','2026-09-01',externalDecision:true,decisionDate:'2026-09-05',familyTarget:'single'),'duplicate profile detection');denied(fn()=>$s->review($dup,1,'accepted','overlap','A2','2026-09-01',$contact,externalDecision:true,decisionDate:'2026-09-05',familyTarget:'single'),'overlapping membership blocked');
$s->review($dup,1,'rejected','Doppelter Testantrag',externalDecision:true,decisionDate:'2026-09-05',familyTarget:'single');check($s->application($dup)['status']==='rejected','reject without new profile');
$db->exec("UPDATE memberships SET ends_on='2026-09-02',status='ehemalig';INSERT INTO contacts(id,club_id,kind,name) VALUES('foreign','b','person','Other')");
$rejoin=$public->submit($answers,$key(),2,true);denied(fn()=>$s->review($rejoin,1,'accepted','tenant','A2','2026-09-04','foreign',externalDecision:true,decisionDate:'2026-09-05',familyTarget:'single'),'foreign contact rejected');
$s->review($rejoin,1,'accepted','Wiedereintritt','A2','2026-09-04',$contact,externalDecision:true,decisionDate:'2026-09-05',familyTarget:'single');check((int)$db->query('SELECT count(*) FROM contacts')->fetchColumn()===2,'rejoin keeps existing profile');
$failure=$public->submit(array_replace($answers,['last_name'=>'Rollback','email'=>'rollback@example.invalid']),$key(),2,true);
$db->exec("CREATE TRIGGER fail_application_audit BEFORE INSERT ON audit_events FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test failure'; END");
try{$s->review($failure,1,'accepted','Rollback','A3','2026-09-04',externalDecision:true,decisionDate:'2026-09-05',familyTarget:'single');throw new RuntimeException('failure expected');}catch(PDOException){}
check($s->application($failure)['status']==='pending'&&(int)$db->query('SELECT count(*) FROM contacts')->fetchColumn()===2,'audit failure rolls back review and contact');
$db->exec('DROP TRIGGER fail_application_audit');check(!$s->introCompleted(),'intro initially pending');$s->completeIntro();$s->completeIntro();check($s->introCompleted()&&!$other->introCompleted(),'intro acknowledgement idempotent and tenant scoped');denied(fn()=>$public->completeIntro(),'anonymous cannot complete setup');
echo "$tests application checks passed\n";
