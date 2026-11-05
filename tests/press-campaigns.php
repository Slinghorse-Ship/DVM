<?php declare(strict_types=1);
require __DIR__.'/../processwire/site/src/PressCampaigns.php';require __DIR__.'/../processwire/site/src/MailDeliveryPolicy.php';
use Kulturhus\{PressCampaigns,MailDeliveryPolicy};
require_once __DIR__.'/../vereinsverwaltung/tests/mysql-bootstrap.php';
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect();$store=new PressCampaigns($db);
$n=0;function ok(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
function denied(callable $f,string $label):void{try{$f();}catch(DomainException|InvalidArgumentException|RuntimeException){ok(true,$label);return;}throw new RuntimeException($label);}
$people=[['id'=>'one','name'=>'Testpresse','email'=>'PRESS@example.invalid','outlet'=>'Medium A'],['id'=>'two','name'=>'Doppelt','email'=>' press@example.invalid ','outlet'=>'Medium B']];
$id=$store->save(0,'',1,0,'Freie Pressemitteilung','Nachricht zum Test',$people);$row=$store->get($id);
ok($row['status']==='draft'&&count($row['recipients'])===1,'free draft persists and deduplicates recipient addresses');
ok((new PressCampaigns($db))->get($id)['body']==='Nachricht zum Test','draft survives new session/service instance');
denied(fn()=>$store->save($id,'old',1,0,'Andere Fassung','Text',$people),'stale editor rejected');
$store->save($id,$row['version'],1,0,'Neue Fassung','Inhalt bleibt erhalten',$people);$row=$store->get($id);
ok($row['revision']===2,'editing preserves revision');
$calls=[];$fake=static function($draft,$to)use(&$calls){$calls[]=['subject'=>$draft['subject'],'to'=>$to];return 1;};
putenv('KULTURHUS_LOCAL_DEMO=1');denied(fn()=>$store->send($id,$row['version'],$people,$fake),'local PC blocks press dispatch');ok(!$calls&&$store->get($id)['status']==='draft'&&!$store->get($id)['deliveries'],'local block leaves no delivery or sent status');
foreach(['APP_ENV','APP_URL','KULTURHUS_LOCAL_DEMO','KULTURHUS_DISABLE_MAIL'] as $env)putenv($env);
denied(fn()=>$store->send($id,$row['version'],[],$fake),'removed recipient invalidates draft send');
$sent=$store->send($id,$row['version'],$people,$fake);ok($sent['status']==='sent'&&count($calls)===1,'fake transport sends once per normalized email');ok($sent['deliveries'][0]['email']==='press@example.invalid'&&$sent['deliveries'][0]['sent_at']>0,'actual email and send timestamp preserved');
denied(fn()=>$store->send($id,$sent['version'],$people,$fake),'double send permanently blocked for sent edition');denied(fn()=>$store->save($id,$sent['version'],1,0,'Changed','Changed',$people),'sent text immutable');ok(count($calls)===1,'no duplicate after repeated request');
$id2=$store->save(0,'',1,1027,'Veranstaltung','Bestehende Vorlage',$people,['/approved/event.jpg']);$r=$store->get($id2);ok($r['event_id']===1027&&$r['attachment_names']===['event.jpg']&&!isset($r['attachments_json']),'event identity and attachment name retained without disclosing filesystem paths');
$result=$store->send($id2,$r['version'],$people,static function(){throw new RuntimeException('SMTP result unknown');});ok($result['status']==='needs_review'&&$result['deliveries'][0]['status']==='uncertain'&&!$result['sent_at'],'ambiguous transport never marked sent');denied(fn()=>$store->send($id2,$result['version'],$people,$fake),'unknown result never automatically resent');
ok(count($store->listing())===2&&$store->listing()[0]['recipient_count']===1,'campaign list shows counts and status');
echo "$n press checks passed; fake transport only\n";
