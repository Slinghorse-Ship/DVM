<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db,Identity,SecretBox};
use Kulturhus\Native\Communication\{Schema,NewsletterGateway,ConfirmationReminders,Subscribers};
use Kulturhus\Native\Delivery\Outbox;

if(($argv[1]??'')==='--plan-worker'){
    echo count(ConfirmationReminders::run(TestSupport::db(),new SecretBox(str_repeat('r',32))));
    exit;
}
$db=TestSupport::db();TestSupport::seed($db);Schema::install($db);
$access=new Access($db,'admin');$club=$access->club();$box=new SecretBox(str_repeat('r',32));
(new Identity($db,$access))->save(['name'=>'Synthetischer Erinnerungsverein','origin'=>'https://example.invalid'],0);
$gateway=new NewsletterGateway($db,$club,$box);$outbox=new Outbox($db,$box);$service=new ConfirmationReminders($db,$access,$box);
$count=0;function check(bool $ok,string $label):void{global $count;if(!$ok)throw new RuntimeException($label);++$count;echo "PASS $label\n";}
function denied(callable $action,string $label):void{try{$action();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
function pending(bool $delivered=true,bool $old=true):array{
    global $db,$club,$gateway,$outbox;static $number=0;++$number;$email='reminder'.$number.'@example.invalid';
    $gateway->signup($email,'Synthetische Person',['events'],'192.0.2.'.$number,true);
    if($delivered)$outbox->run(static fn()=>'synthetic-confirmation',200,['confirm']);
    $row=Db::run($db,'SELECT id FROM khn_newsletter_subscribers WHERE club_id=? AND email=?',[$club,$email])->fetch();
    if($old)Db::run($db,"UPDATE khn_newsletter_tokens SET created_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 25 HOUR),expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR) WHERE club_id=? AND subscriber_id=? AND kind='confirm'",[$club,$row['id']]);
    return $row;
}
$first=pending();
check($service->plan()===['version'=>0,'enabled'=>false],'reminders disabled until explicit administrative opt-in');
check(ConfirmationReminders::run($db,$box)===[],'default planner creates no reminder');
denied(fn()=>(new ConfirmationReminders($db,new Access($db,'editor'),$box))->savePlan(true,0),'editor cannot enable reminders');
$plan=$service->savePlan(true,0);check($plan===['version'=>1,'enabled'=>true],'versioned administrative plan enables preparation');
denied(fn()=>$service->savePlan(false,0),'stale form cannot overwrite reminder plan');
$jobs=ConfirmationReminders::run($db,$box);check(count($jobs)===1,'one reminder prepared from actually delivered valid DOI');
check(ConfirmationReminders::run($db,$box)===[],'repeated planner does not duplicate reminder');
check((new Subscribers($db,$access))->get($first['id'])['status']==='pending','planning never confirms consent');
$messages=[];$result=$outbox->run(function($club,$payload)use(&$messages){$messages[]=$payload;return 'synthetic-reminder';},50,['reminder']);
check(count($messages)===1&&$result[0]['status']==='sent'&&str_starts_with($messages[0]['subject'],'Erinnerung:'),'synthetic reminder has explicit subject and private original DOI link');
check($service->queue($first['id'])===$jobs[0],'manual and automatic routes share one deduplicated reminder');

$fresh=pending(true,false);check(ConfirmationReminders::run($db,$box)===[],'no reminder before one full day');
denied(fn()=>$service->queue($fresh['id']),'manual reminder also honors one-day wait');
$expired=pending();Db::run($db,"UPDATE khn_newsletter_tokens SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE subscriber_id=?",[$expired['id']]);
check(ConfirmationReminders::run($db,$box)===[],'expired confirmation cannot be reminded');
$revoked=pending();Db::run($db,"DELETE FROM native_grants WHERE actor_id='admin' AND module='newsletter' AND capability='manage'");
check(ConfirmationReminders::run($db,$box)===[],'planner rechecks current administrative rights');
Db::run($db,"INSERT INTO native_grants(actor_id,module,capability) VALUES('admin','newsletter','manage')");
check(count(ConfirmationReminders::run($db,$box))===1,'restored rights permit still-valid pending request');
Db::run($db,"DELETE FROM native_grants WHERE actor_id='admin' AND module='newsletter' AND capability='manage'");
$calls=0;$result=$outbox->run(function()use(&$calls){++$calls;return 'unexpected';},50,['reminder']);
check($calls===0&&$result[0]['status']==='cancelled','delivery independently rejects revoked reminder authority');
Db::run($db,"INSERT INTO native_grants(actor_id,module,capability) VALUES('admin','newsletter','manage')");

$paused=pending();check(count(ConfirmationReminders::run($db,$box))===1,'eligible request prepared before pause');
$service->savePlan(false,1);$calls=0;$outbox->run(function()use(&$calls){++$calls;return 'unexpected';},50,['reminder']);
check($calls===0&&ConfirmationReminders::run($db,$box)===[],'pause also stops already prepared reminders');
check(Db::run($db,"SELECT COUNT(*) FROM native_delivery_jobs WHERE kind='reminder' AND reason='reminder_plan_paused'")->fetchColumn()==1,'pause retains durable cancellation reason');
$service->savePlan(true,2);check(ConfirmationReminders::run($db,$box)===[],'resuming never retries a cancelled request automatically');

$confirmed=pending();ConfirmationReminders::run($db,$box);
Db::run($db,"UPDATE khn_newsletter_subscribers SET status='confirmed' WHERE id=?",[$confirmed['id']]);
$calls=0;$result=$outbox->run(function()use(&$calls){++$calls;return 'unexpected';},50,['reminder']);
check($calls===0&&$result[0]['status']==='cancelled','confirmation between planning and delivery cancels reminder');
$race=pending();$children=[];
for($i=0;$i<2;$i++){
    $process=proc_open([...TestSupport::phpCommand(),__FILE__,'--plan-worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('Planner race process unavailable');
    fclose($pipes[0]);$children[]=[$process,$pipes];
}
$results=[];
foreach($children as [$process,$pipes]){
    $results[]=(int)trim(stream_get_contents($pipes[1]));$errors=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);check(proc_close($process)===0&&$errors==='','concurrent planner exits without diagnostics');
}
sort($results);check($results===[0,1],'two independent planners create exactly one reminder');
$unsent=pending(false);check(ConfirmationReminders::run($db,$box)===[],'unsent initial confirmation is never reminded');
echo "$count native reminder checks passed; fresh synthetic MySQL and test transport only.\n";
