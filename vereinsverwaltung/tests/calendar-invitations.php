<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,Registry,CalendarInvitations,AdmissionVotes,MailPolicy};
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Test'),('b','Fremd');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('s','a','members',1),('staff','a','members',0),('board','a','member',0),('editor','a','editor',1),('foreign','b','members',1);INSERT INTO contacts(id,club_id,kind,name,email) VALUES('one','a','person','Eins','Shared@example.invalid'),('two','a','person','Zwei',' shared@example.invalid '),('three','a','person','Drei','three@example.invalid'),('none','a','person','Ohne',''),('other','b','person','Fremd','foreign@example.invalid');INSERT INTO memberships(id,club_id,contact_id,member_number,status,starts_on) VALUES('m1','a','one','1','aktiv','2026-01-01'),('m2','a','two','2','passiv','2026-01-01')");
$n=0;function ok(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
function denied(callable $f,string $label):void{try{$f();}catch(DomainException){ok(true,$label);return;}throw new RuntimeException($label);}
$r=new Registry($db,'s');$event=$r->saveAppointment(null,0,['title'=>'Testtermin','start'=>'2026-10-09T20:00','end'=>'2026-10-09T22:00','location'=>'Kulturhus','category'=>'verein'],[]);
$inv=new CalendarInvitations($db,'s',$r);$key=fn()=>bin2hex(random_bytes(16));$p=$inv->preview($event,['members','all'],[],'Einladung','Hallo');
ok(count($p['recipients'])===2&&$p['duplicates']===1&&$p['missing']===1,'overlapping groups and shared emails deduplicate');
ok(str_contains($p['body'],'09.10.2026 20:00')&&str_contains($p['body'],'Ort: Kulturhus'),'saved event time and location appended');
ok(count($inv->preview($event,['contacts'],[],'Test','')['recipients'])===1,'contacts excludes current members');
ok(count($inv->preview($event,['selected'],['two','one'],'Test','')['recipients'])===1,'explicit selection deduplicates email');
denied(fn()=>$inv->preview($event,['selected'],['other'],'Test',''),'foreign recipient rejected');
denied(fn()=>$inv->preview($event,['all'],[],"Injected\nHeader",''),'subject header injection rejected');
foreach(['staff','editor','foreign'] as $actor){$x=new CalendarInvitations($db,$actor,new Registry($db,$actor));denied(fn()=>$x->preview($event,['all'],[],'Test',''),'unauthorized invitation '.$actor);}
(new AdmissionVotes($db,'s'))->grant('board',0,'1. Vorstand',true,true);
$b=new CalendarInvitations($db,'board',new Registry($db,'board'));ok(count($b->preview($event,['all'],[],'Test','')['recipients'])===2,'board member can invite without general member administration');
$calls=[];$fake=static function($to,$subject,$body)use(&$calls){$calls[]=$to;};
foreach(['APP_URL','APP_ENV','VEREIN_MODE','KULTURHUS_DISABLE_MAIL','KULTURHUS_LOCAL_DEMO'] as $env)putenv($env);
putenv('VEREIN_PUBLIC_URL=https://verein.example.invalid');putenv('KULTURHUS_LOCAL_DEMO=1');
denied(fn()=>$inv->queue($event,['all'],[],'Einladung','Hallo',$p['preview_hash'],$key()),'local PC cannot queue mail');
ok(CalendarInvitations::dispatch($db,'a','https://verein.example.invalid',$fake)===0&&!$calls,'local dispatch blocked');
putenv('KULTURHUS_LOCAL_DEMO');putenv('APP_URL=http://127.0.0.1:8092');ok(!MailPolicy::allowed('https://verein.example.invalid'),'local APP_URL blocks even public delivery URL');putenv('APP_URL');
$stale=$inv->preview($event,['all'],[],'Einladung','Hallo');$db->exec("UPDATE contacts SET email='updated@example.invalid' WHERE id='three'");denied(fn()=>$inv->queue($event,['all'],[],'Einladung','Hallo',$stale['preview_hash'],$key()),'changed recipients invalidate preview');$db->exec("UPDATE contacts SET email='three@example.invalid' WHERE id='three'");
$req=$key();$id=$inv->queue($event,['all'],[],'Einladung','Hallo',$p['preview_hash'],$req);ok($inv->queue($event,['all'],[],'Einladung','Hallo',$p['preview_hash'],$req)===$id,'double-click reuses invitation');
$inv->queue($event,['all','members'],[],'Einladung','Hallo',$p['preview_hash'],$key());ok((int)$db->query('SELECT count(*) FROM calendar_invitation_deliveries')->fetchColumn()===2,'same event and content across campaigns sends once per email');
ok(CalendarInvitations::dispatch($db,'a','https://verein.example.invalid',$fake)===2&&count(array_unique($calls))===2,'fake transport receives distinct private deliveries');
ok(CalendarInvitations::dispatch($db,'a','https://verein.example.invalid',$fake)===0&&count($calls)===2,'repeat dispatch sends nothing');
$p2=$inv->preview($event,['selected'],['one'],'Änderung','Neue Information');$inv->queue($event,['selected'],['one'],'Änderung','Neue Information',$p2['preview_hash'],$key());CalendarInvitations::dispatch($db,'a','https://verein.example.invalid',static function(){throw new RuntimeException('ambiguous transport');});
ok((int)$db->query("SELECT count(*) FROM calendar_invitation_deliveries WHERE status='uncertain'")->fetchColumn()===1,'uncertain transport is retained');ok(CalendarInvitations::dispatch($db,'a','https://verein.example.invalid',$fake)===0,'uncertain delivery never automatically retries');
$p3=$b->preview($event,['all'],[],'Board','Test');$b->queue($event,['all'],[],'Board','Test',$p3['preview_hash'],$key());$db->exec("UPDATE admission_board SET enabled=0 WHERE actor_id='board'");ok(CalendarInvitations::dispatch($db,'a','https://verein.example.invalid',$fake)===0,'revoked board permission cancels queued delivery');
echo "$n calendar invitation checks passed; fake transport only\n";
