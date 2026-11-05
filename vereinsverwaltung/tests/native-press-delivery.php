<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/native/bootstrap.php';
require dirname(__DIR__,2).'/vendor/autoload.php';
require dirname(__DIR__).'/src/bootstrap.php';
require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db,SecretBox};
use Kulturhus\Native\Communication\{Schema,Workspace,PressContacts,PressCampaigns};
use Kulturhus\Native\Delivery\{Outbox,MailAccount,MailMessage};

$db=TestSupport::db();Kulturhus\Native\Installer\SchemaRegistry::install($db);TestSupport::seed($db);
$access=new Access($db,'admin');$club=$access->club();$privateKey=random_bytes(32);$box=new SecretBox($privateKey);$cfg=TestSupport::config();
$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$cfg['database'];mkdir($root,0700);foreach(['media','web','sessions','manager-sessions']as$dir)mkdir($root.'/'.$dir,0700);
file_put_contents($root.'/private.key',$privateKey);chmod($root.'/private.key',0600);
putenv('VEREIN_AUTH=native');putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$root.'/private.key');
$service=new PressCampaigns($db,$access,$box,$root.'/media');$contacts=new PressContacts($db,$access);$outbox=new Outbox($db,$box);$work=new Workspace($db,$access,'press',$root.'/media');$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);++$checks;echo "PASS $label\n";};
$deny=static function(callable $f,string $label)use($check):void{try{$f();}catch(DomainException){$check(true,$label);return;}throw new RuntimeException($label);};
$account=['host'=>'smtp.example.invalid','port'=>587,'username'=>'own-synthetic-user','password'=>'own-synthetic-password','from'=>'club@example.invalid','reply_to'=>'press@example.invalid'];
$a=$contacts->save(['name'=>'Own original editor A','outlet'=>'Own paper A','email'=>'editor-a@example.invalid']);$b=$contacts->save(['name'=>'Own original editor B','outlet'=>'Own paper B','email'=>'editor-b@example.invalid']);
$foreign=(new PressContacts($db,new Access($db,'outsider')))->save(['name'=>'Foreign original editor','email'=>'foreign@example.invalid']);
$draft=$work->save(['title'=>'Original human press subject','body_html'=>'<p>Eigenständig geschriebene Mitteilung ä🙂</p>','recipient_ids'=>[$a['id'],$b['id']]]);
$deny(fn()=>$service->queue($draft['id'],1,gmdate('Y-m-d H:i:s')),'unapproved original cannot enter SMTP queue');
$approved=$work->approve($draft['id'],1);$scheduled=$work->schedule($draft['id'],gmdate(DATE_ATOM,time()-10),2);
$deny(fn()=>$service->queue($scheduled['id'],3,gmdate('Y-m-d H:i:s')),'missing mailbox is rejected before accepting any private SMTP intent');
(new MailAccount($db,$box))->save($access,$account,0);
$jobs=$service->queue($scheduled['id'],3,gmdate('Y-m-d H:i:s',time()-10));
$check(count($jobs)===2&&count(array_unique($jobs))===2,'each selected own contact gets one distinct private delivery');
$sources=Db::run($db,'SELECT * FROM native_delivery_sources WHERE club_id=? ORDER BY job_id',[$club])->fetchAll();
$check(count($sources)===2&&array_unique(array_column($sources,'module'))===['press']&&array_unique(array_column($sources,'item_version'))===[3],'every actual delivery binds its exact press source and scheduled revision');
$messageRow=Db::run($db,'SELECT * FROM native_press_messages WHERE club_id=?',[$club])->fetch();
$check((int)Db::run($db,'SELECT COUNT(*) FROM native_press_messages WHERE club_id=?',[$club])->fetchColumn()===1&&!str_contains($messageRow['ciphertext'],'Eigenständig'),'one encrypted original message is shared by the entire audience');
$check($service->queue($scheduled['id'],3,gmdate('Y-m-d H:i:s',time()-10))===$jobs&&Db::run($db,'SELECT * FROM native_press_messages WHERE club_id=?',[$club])->fetch()===$messageRow,'repeated acknowledged or lost-response enqueue preserves exact original ciphertext and jobs');
$prepared=$work->runDue();
$check($prepared['prepared']===1&&$work->get($scheduled['id'])===$scheduled,'local calendar preparation preserves the scheduled revision needed by the real SMTP worker');
$contacts->save(array_replace($b,['active'=>false]),1);$delivered=[];
$result=$outbox->run(static function($ownClub,$payload,$kind)use(&$delivered,$account,$check,$club){$check($ownClub===$club&&$kind==='press','worker delivers only the exact own press purpose');$mime=MailMessage::compose($account,$payload);$check($mime->getSubject()==='Original human press subject'&&str_contains($mime->getHtmlBody(),'Eigenständig geschriebene Mitteilung ä🙂'),'SMTP MIME retains the exact approved human original');$delivered[]=$payload['to'];return 'own-synthetic-message-id';},10,['press'],$club);
$check($delivered===[$a['email']]&&count($result)===2&&array_count_values(array_column($result,'status'))===['sent'=>1,'cancelled'=>1]||$delivered===[$a['email']]&&array_count_values(array_column($result,'status'))===['cancelled'=>1,'sent'=>1],'deactivated contact is cancelled before transport while the other original sends exactly once');
$check(count($outbox->list($access,'press'))===2&&$outbox->list($access,'newsletter')===[],'press history is distinct from unrelated newsletter jobs');
$check($outbox->run(static fn()=>throw new RuntimeException('No second SMTP'),10,['press'],$club)===[],'completed press deliveries never automatically repeat');
$check((new MailAccount($db,$box))->configuration($club,'press')===$account,'standalone press resolves its explicitly configured private mailbox');
$contacts->save(array_replace($b,['active'=>true]),2);
$next=$work->save(['title'=>'Own changed-recipient original','body'=>'Human next original','recipient_ids'=>[$a['id']]]);$next=$work->approve($next['id'],1);
$nextJobs=$service->queue($next['id'],2,gmdate('Y-m-d H:i:s',time()-10));$contacts->save(array_replace($a,['email'=>'changed-a@example.invalid']),1);$calls=0;
$cancelled=$outbox->run(static function()use(&$calls){$calls++;return 'unexpected';},10,['press'],$club);
$check($calls===0&&$cancelled[0]['status']==='cancelled','changed recipient address cannot receive a queued original addressed to the prior email');
$a=$contacts->list(true)[0];if($a['id']!==$next['recipient_ids'][0])$a=array_values(array_filter($contacts->list(true),static fn($row)=>$row['id']===$next['recipient_ids'][0]))[0];
$uncertain=$work->save(['title'=>'Own uncertain original','body'=>'Human uncertainty original','recipient_ids'=>[$a['id']]]);$uncertain=$work->approve($uncertain['id'],1);$uncertainJobs=$service->queue($uncertain['id'],2,gmdate('Y-m-d H:i:s',time()-10));
$calls=0;$sent=$outbox->run(static function()use(&$calls){$calls++;throw new RuntimeException('Synthetic lost SMTP acknowledgement');},10,['press'],$club);
$check($calls===1&&$sent[0]['status']==='uncertain','a lost SMTP response retains uncertain delivery without inventing success');
$check($outbox->run(static fn()=>throw new RuntimeException('Never retry uncertain SMTP'),10,['press'],$club)===[],'uncertain mail never becomes an automatic retry');
$deny(fn()=>$service->queue($uncertain['id'],2,gmdate('Y-m-d H:i:s')),'explicit requeue cannot bypass an open earlier delivery');
$changed=$work->save(['id'=>$uncertain['id'],'title'=>'Human later revision','body'=>'Human later original','recipient_ids'=>[$a['id']]],2);$changed=$work->approve($changed['id'],3);
$deny(fn()=>$service->queue($changed['id'],4,gmdate('Y-m-d H:i:s')),'a later revision cannot evade uncertain prior mail on the same item');
$stale=$work->save(['title'=>'Own interrupted original','body'=>'Human interrupted original','recipient_ids'=>[$a['id']]]);$stale=$work->approve($stale['id'],1);$staleJobs=$service->queue($stale['id'],2,gmdate('Y-m-d H:i:s',time()-10));
Db::run($db,"UPDATE native_delivery_jobs SET status='processing',started_at=UTC_TIMESTAMP()-INTERVAL 11 MINUTE,attempts=1 WHERE id=?",[$staleJobs[0]]);
$check($outbox->run(static fn()=>throw new RuntimeException('Never resend stale SMTP'),10,['press'],$club)===[]&&Db::run($db,'SELECT status FROM native_delivery_jobs WHERE id=?',[$staleJobs[0]])->fetchColumn()==='uncertain','interrupted old durable SMTP claim is quarantined for review rather than repeated');
$pixels=imagecreatetruecolor(64,48);imagefill($pixels,0,0,imagecolorallocate($pixels,25,140,80));$photo=$root.'/own-source.png';imagepng($pixels,$photo);unset($pixels);
$media=new Kulturhus\Native\Cms\Media($db,$access,$root.'/media');$image=$media->upload($photo,'Own original source.png','Own original image description');$imageFile=$media->variant($image['id'],'medium');$imageHash=hash_file('sha256',$imageFile['path']);
$picture=$work->save(['title'=>'Own press original with image','body'=>'Human original image mail','blocks'=>[['type'=>'image','media_ref'=>$image['id'],'alt'=>'Own image text']],'recipient_ids'=>[$a['id'],$b['id']]]);$picture=$work->approve($picture['id'],1);$pictureJobs=$service->queue($picture['id'],2,gmdate('Y-m-d H:i:s',time()-10));
$small=(int)Db::run($db,'SELECT MAX(LENGTH(ciphertext)) FROM native_delivery_jobs WHERE id IN (?,?)',$pictureJobs)->fetchColumn();
$check($small<2000&&(int)Db::run($db,'SELECT COUNT(*) FROM native_press_messages WHERE club_id=? AND item_id=?',[$club,$picture['id']])->fetchColumn()===1,'individual recipient jobs contain no duplicate image bytes and share exactly one encrypted image message');
$pictureSent=0;$outbox->run(static function($ownClub,$payload,$kind)use(&$pictureSent,$account,$image,$imageHash,$check){
 $check(count($payload['inline_images'])===1&&$payload['inline_images'][0]['sha256']===$imageHash&&str_contains($payload['html'],'cid:press-'.$image['id'].'@dvm.local'),'original image bytes and exact inline reference reach the same approved MIME');
 $mime=MailMessage::compose($account,$payload)->toString();$check(str_contains($mime,'Content-ID: <press-'.$image['id'].'@dvm.local>'),'actual MIME includes the independently stored image Content-ID');$pictureSent++;return 'own-picture-'.$pictureSent;
},10,['press'],$club);
$check($pictureSent===2&&hash_file('sha256',$imageFile['path'])===$imageHash,'two real MIME compositions send original image bytes once per chosen contact without changing the original file');
Db::run($db,'INSERT INTO native_users(id,club_id,name) VALUES(?,?,?)',['own-press-publisher',$club,'Own limited press publisher']);foreach(['read','publish']as$cap)Db::run($db,'INSERT INTO native_grants VALUES(?,?,?)',['own-press-publisher','press',$cap]);$publisher=new Access($db,'own-press-publisher');
$deny(fn()=>(new Kulturhus\Native\Cms\Media($db,$publisher,$root.'/media'))->variant($image['id'],'medium'),'press publisher does not acquire generic website or media-library rights');
$limited=$work->save(['title'=>'Own limited-role image message','body'=>'Human approved limited-role original','blocks'=>[['type'=>'image','media_ref'=>$image['id'],'alt'=>'Own approved photo']],'recipient_ids'=>[$a['id']]]);$limited=$work->approve($limited['id'],1);
$limitedJobs=(new PressCampaigns($db,$publisher,$box,$root.'/media'))->queue($limited['id'],2,gmdate('Y-m-d H:i:s',time()+86400));$check(count($limitedJobs)===1,'current press publication rights can queue only the exact approved image refs without broader website rights');
$catalogue=Kulturhus\Native\Communication\PressDeliveryCatalogue::page($db,$access,$box);$check(count($catalogue['rows'])===6&&$catalogue['pages']>=2,'private press history loads only six recipient originals');
$exact=Kulturhus\Native\Communication\PressDeliveryCatalogue::page($db,$access,$box,['press_item'=>$scheduled['id']]);$check($exact['total']===2&&array_unique(array_column($exact['rows'],'title'))===['Original human press subject'],'later item and contact edits never replace original mail subjects in delivery history');
$focused=Kulturhus\Native\Communication\PressDeliveryCatalogue::page($db,$access,$box,['press_job'=>$jobs[0]]);$check(in_array($jobs[0],array_column($focused['rows'],'id'),true),'an older exact delivery remains reachable beyond the initial six rows');
$filtered=Kulturhus\Native\Communication\PressDeliveryCatalogue::page($db,$access,$box,['press_state'=>'uncertain']);$check($filtered['total']===2&&array_unique(array_column($filtered['rows'],'status'))===['uncertain'],'delivery state filtering uses actual uncertain originals');
$deny(fn()=>Kulturhus\Native\Communication\PressDeliveryCatalogue::page($db,new Access($db,'outsider'),$box,['press_job'=>$jobs[0]]),'foreign reader cannot focus another club delivery');
$deny(fn()=>Kulturhus\Native\Communication\PressDeliveryCatalogue::page($db,$access,$box,['press_state'=>['sent']]),'ambiguous status cannot change recipient history scope');
$check(Kulturhus\Native\Integration\Routes::translate('/?view=press&section=planning&press_item='.$scheduled['id'].'&press_page=2&press_state=sent')==='/vereinsverwaltung/?view=press&section=planning&press_item='.$scheduled['id'].'&press_state=sent&press_page=2','production translation preserves exact press item, state and page');
$future=$work->save(['title'=>'Own future original','body'=>'Human future original','recipient_ids'=>[$a['id']]]);$future=$work->approve($future['id'],1);$futureJobs=$service->queue($future['id'],2,gmdate('Y-m-d H:i:s',time()+86400));
$check($outbox->run(static fn()=>throw new RuntimeException('Not due'),10,['press'],$club)===[],'future press queue obeys its actual UTC deadline');
Db::run($db,"DELETE FROM native_grants WHERE actor_id='admin' AND module='press' AND capability='publish'");Db::run($db,'UPDATE native_delivery_jobs SET due_at=UTC_TIMESTAMP() WHERE id=?',[$futureJobs[0]]);
$calls=0;$revoked=$outbox->run(static function()use(&$calls){$calls++;return 'unexpected';},10,['press'],$club);
$check($calls===0&&$revoked[0]['status']==='cancelled','a queued original checks current press publication rights at SMTP time');
$check((new PressContacts($db,new Access($db,'outsider')))->list()[0]==$foreign,'foreign contact original remains unchanged');
Kulturhus\Native\Integration\Schema::install($db);Kulturhus\Native\Auth\Schema::install($db);
Db::run($db,"INSERT INTO clubs(id,name) VALUES('press-worker','Own private worker club')");Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES('press-worker-admin','press-worker','member',1)");
$managerAccess=(new Kulturhus\Native\Integration\ManagerBridge($db,'press-worker-admin','native',static fn()=>null))->access();$managerClub=$managerAccess->club();$origin='https://press-worker.example.invalid';
Db::run($db,'INSERT INTO native_auth_accounts(id,actor_id,email,active,verified,created_at) VALUES(?,?,?,1,1,?)',[bin2hex(random_bytes(16)),$managerAccess->user()['id'],'press-worker-admin@example.invalid',time()]);
(new Kulturhus\Verein\Settings($db,'press-worker-admin'))->save('club',array_replace(Kulturhus\Verein\SettingsSchema::defaults('club'),['name'=>'Own worker original club']),0);
(new Kulturhus\Native\Shared\Identity($db,$managerAccess))->save(['origin'=>$origin,'club_version'=>1],0);
$mailKey=Kulturhus\Verein\Infrastructure\Runtime\ServerKey::nativeRuntime($root.'/private.key');$channelService=new Kulturhus\Verein\MailChannelSettings($db,'press-worker-admin',$mailKey);$channels=$channelService->state();$channels['data']['channels']['press']=array_replace($channels['data']['channels']['press'],['override'=>true,'host'=>'smtp.press-worker.example.invalid','port'=>'587','user'=>'own-press-user','password'=>'Own synthetic press password','from'=>'press@press-worker.example.invalid','reply'=>'reply@press-worker.example.invalid']);$channelService->save($channels['data'],0);
$check(hash_equals($privateKey,file_get_contents($root.'/private.key')),'shared mailbox uses its own unchanged persistent native key');
$check((new MailAccount($db,$box,static fn()=>$mailKey))->configuration($managerClub,'press')['host']==='smtp.press-worker.example.invalid','shared press mailbox independently decrypts with its exact original native key');
$managerContact=(new PressContacts($db,$managerAccess))->save(['name'=>'Own CLI editor','email'=>'own-cli-editor@example.invalid']);$managerWork=new Workspace($db,$managerAccess,'press',$root.'/media');$managerItem=$managerWork->save(['title'=>'Own CLI human subject','body'=>'Own CLI human original','recipient_ids'=>[$managerContact['id']]]);$managerItem=$managerWork->approve($managerItem['id'],1);
$managerJob=(new PressCampaigns($db,$managerAccess,$box,$root.'/media'))->queue($managerItem['id'],2,gmdate('Y-m-d H:i:s',time()-10))[0];
$configFile=$root.'/worker.php';file_put_contents($configFile,'<?php return '.var_export(['dsn'=>'mysql:host=127.0.0.1;port=13371;dbname='.$cfg['database'].';charset=utf8mb4','username'=>$cfg['username'],'password'=>$cfg['password'],'key_file'=>$root.'/private.key','manager_key_file'=>$root.'/private.key','auth_key_file'=>$root.'/auth.key','club_id'=>'press-worker','origin'=>$origin],true).';');chmod($configFile,0600);file_put_contents($root.'/auth.key',random_bytes(32));chmod($root.'/auth.key',0600);
$worker=Kulturhus\Native\Integration\Jobs\Worker::class;$options=['config'=>$configFile,'press'=>false,'limit'=>'1'];$workerCalls=0;
$send=static function($ownClub,$payload)use(&$workerCalls,$managerClub,$db,$box,$mailKey,$check):string{
 $check($ownClub===$managerClub&&$payload['to']==='own-cli-editor@example.invalid'&&$payload['subject']==='Own CLI human subject','actual private worker reaches only its own chosen press original');
 $smtp=(new MailAccount($db,$box,static fn()=>$mailKey))->configuration($ownClub,'press');$check($smtp['host']==='smtp.press-worker.example.invalid'&&$smtp['from']==='press@press-worker.example.invalid','actual private press purpose resolves its selected original shared mailbox');
 MailMessage::compose($smtp,$payload)->toString();$workerCalls++;return 'own-cli-synthetic-id';
};
$check($worker::run($options,null,null,null,null,$send)['transport']==='disabled'&&$workerCalls===0,'actual private press worker is read-only without explicit send');
$check($worker::run($options+['send'=>false],null,null,null,null,$send)['transport']==='disabled'&&$workerCalls===0,'synthetic mail policy blocks actual private worker even with send selected');
foreach(['fixture','receive','push','publish','meta','stadtlandfunk','social-check','events','mail-replies']as$flag)$deny(fn()=>$worker::run($options+[$flag=>false],null,null,null,null,$send),'private press mode rejects unrelated '.$flag.' before processing');
$env=[];foreach(['KULTURHUS_DISABLE_MAIL','KULTURHUS_LOCAL_DEMO','VEREIN_MODE','APP_ENV','APP_URL']as$key)$env[$key]=getenv($key);
$outsideTables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME NOT IN ('native_worker_runs') ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
$outsideFilter=static fn($table)=>$table==='native_delivery_jobs'?[' WHERE id<>?',[$managerJob]]:['',[]];$outsideBefore=ownOriginalDatabaseHash($db,$outsideTables,$outsideFilter);
try{
 // Only a passed in-memory MIME sink is reachable in this dedicated press mode.
 putenv('KULTURHUS_DISABLE_MAIL=0');putenv('KULTURHUS_LOCAL_DEMO');putenv('VEREIN_MODE=native');putenv('APP_ENV');putenv('APP_URL='.$origin);
 $cli=$worker::run($options+['send'=>false],null,null,null,null,$send);
 $check($workerCalls===1&&$cli['deliveries'][0]['id']===$managerJob&&$cli['deliveries'][0]['status']==='sent','actual private CLI orchestration performs exactly one MIME sink call');
 $worker::run($options+['send'=>false],null,null,null,null,$send);$check($workerCalls===1,'actual private CLI never repeats the completed original');
}finally{foreach($env as$key=>$value)putenv($value===false?$key:$key.'='.$value);}
$check(ownOriginalDatabaseHash($db,$outsideTables,$outsideFilter)===$outsideBefore,'dedicated private press run preserves every unrelated original queue, role, calendar and message row');
$calendarDay=(new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin')))->setTime(0,0);
$calendar=(new Kulturhus\Verein\CalendarFeed($db,'press-worker-admin',new Kulturhus\Verein\Registry($db,'press-worker-admin'),$managerAccess))->between($calendarDay->modify('-1 day')->format('Y-m-d'),$calendarDay->modify('+1 day')->format('Y-m-d'),'press');
$check(count($calendar)===1&&$calendar[0]['title']==='Presse: Own CLI human subject'&&str_contains($calendar[0]['location'],'Versandt (1)'),'original press delivery is represented once with its actual send state in the shared calendar');
$check($calendar[0]['route']===['view'=>'press','section'=>'planning','press_item'=>$managerItem['id']]&&!str_contains(json_encode($calendar),'own-cli-editor@example.invalid'),'calendar opens exact original history without recipient addresses or private body');
$laterSent=$managerWork->save(['id'=>$managerItem['id'],'title'=>'Human changed title after send','body'=>'Human later press text','recipient_ids'=>[$managerContact['id']]],2);$laterSent=$managerWork->approve($laterSent['id'],3);
$deny(fn()=>(new PressCampaigns($db,$managerAccess,$box,$root.'/media'))->queue($laterSent['id'],4,gmdate('Y-m-d H:i:s')),'later approval cannot accidentally send the already delivered item again');
$earlierCalendar=(new Kulturhus\Verein\CalendarFeed($db,'press-worker-admin',new Kulturhus\Verein\Registry($db,'press-worker-admin'),$managerAccess))->between($calendarDay->modify('-1 day')->format('Y-m-d'),$calendarDay->modify('+1 day')->format('Y-m-d'),'press');$check($earlierCalendar[0]['title']==='Presse: Own CLI human subject'&&str_contains($earlierCalendar[0]['location'],'Frühere Fassung'),'later editing preserves exact earlier sent subject and revision in calendar history');
final class PressAcceptedCommit extends PDO
{
 public bool $armed=false;
 public function commit():bool{$result=parent::commit();if($this->armed){$this->armed=false;throw new PDOException('Own accepted COMMIT acknowledgement lost');}return $result;}
}
$fault=new PressAcceptedCommit('mysql:host=127.0.0.1;port=13371;dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$faultAccess=(new Kulturhus\Native\Integration\ManagerBridge($fault,'press-worker-admin','native',static fn()=>null))->access();
$lostItem=$managerWork->save(['title'=>'Own accepted enqueue original','body'=>'Human accepted enqueue original','recipient_ids'=>[$managerContact['id']]]);$lostItem=$managerWork->approve($lostItem['id'],1);$fault->armed=true;
try{(new PressCampaigns($fault,$faultAccess,$box,$root.'/media'))->queue($lostItem['id'],2,gmdate('Y-m-d H:i:s',time()+86400));throw new LogicException('Expected real accepted commit fault');}catch(PDOException){}
$acceptedSource=Db::run($db,"SELECT s.job_id,j.ciphertext FROM native_delivery_sources s JOIN native_delivery_jobs j ON j.id=s.job_id WHERE s.club_id=? AND s.module='press' AND s.item_id=?",[$managerClub,$lostItem['id']])->fetch();$acceptedBody=Db::run($db,'SELECT * FROM native_press_messages WHERE club_id=? AND item_id=?',[$managerClub,$lostItem['id']])->fetch();
$retry=(new PressCampaigns($db,$managerAccess,$box,$root.'/media'))->queue($lostItem['id'],2,gmdate('Y-m-d H:i:s',time()+86400));
$check($acceptedSource&&$retry===[$acceptedSource['job_id']]&&Db::run($db,'SELECT ciphertext FROM native_delivery_jobs WHERE id=?',[$acceptedSource['job_id']])->fetchColumn()===$acceptedSource['ciphertext']&&Db::run($db,'SELECT * FROM native_press_messages WHERE club_id=? AND item_id=?',[$managerClub,$lostItem['id']])->fetch()===$acceptedBody,'actual accepted lost COMMIT preserves one exact message, recipient intent and idempotent original retry');
$crashItem=$managerWork->save(['title'=>'Own killed worker original','body'=>'Human actual interruption original','recipient_ids'=>[$managerContact['id']]]);$crashItem=$managerWork->approve($crashItem['id'],1);$crashJob=(new PressCampaigns($db,$managerAccess,$box,$root.'/media'))->queue($crashItem['id'],2,gmdate('Y-m-d H:i:s',time()-10))[0];
$child=proc_open([...TestSupport::phpCommand(),__DIR__.'/support/press-delivery-worker-fault.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($child))throw new RuntimeException('Own crash child required.');fwrite($pipes[0],json_encode(['club'=>$managerClub,'key_file'=>$root.'/private.key'],JSON_THROW_ON_ERROR));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($child);
$crashed=Db::run($db,'SELECT * FROM native_delivery_jobs WHERE id=?',[$crashJob])->fetch();$check($exit!==0&&$out===''&&$err===''&&$crashed['status']==='processing'&&(int)$crashed['attempts']===1,'real killed worker leaves its exact committed SMTP claim without reporting success');
Db::run($db,'UPDATE native_delivery_jobs SET started_at=UTC_TIMESTAMP()-INTERVAL 11 MINUTE WHERE id=?',[$crashJob]);$crashCalls=0;(new Outbox($db,$box))->run(static function()use(&$crashCalls){$crashCalls++;return 'unexpected';},1,['press'],$managerClub);
$afterCrash=Db::run($db,'SELECT * FROM native_delivery_jobs WHERE id=?',[$crashJob])->fetch();$check($crashCalls===0&&$afterCrash['status']==='uncertain'&&$afterCrash['ciphertext']===$crashed['ciphertext']&&(int)$afterCrash['attempts']===1,'actual killed SMTP attempt remains the same uncertain original and never repeats automatically');
if(getenv('DVM_PRESS_DELIVERY_BROWSER')==='1'){
 Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES('press-browser-admin','press-worker','member',1)");$browserAccess=(new Kulturhus\Native\Integration\ManagerBridge($db,'press-browser-admin','native',static fn()=>null))->access();
 putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$root.'/private.key');putenv('KH_NATIVE_AUTH_KEY_FILE='.$root.'/auth.key');$auth=Kulturhus\Native\Auth\Runtime::service($db);$invite=$auth->createInvitation($browserAccess,$browserAccess->user()['id'],'press-browser@example.invalid');$auth->acceptInvitation($invite['token'],'Own press browser password 2026!');$token=$auth->passwordLogin('press-browser@example.invalid','Own press browser password 2026!','own-press-browser')['session_token'];
 $browserContacts=[];for($i=0;$i<18;$i++)$browserContacts[]=(new PressContacts($db,$browserAccess))->save(['name'=>sprintf('Browser editor %03d',$i),'outlet'=>sprintf('Browser paper %03d',$i),'email'=>'browser-editor'.$i.'@example.invalid']);
 $browserWork=new Workspace($db,$browserAccess,'press',$root.'/media');$viewOriginal=$browserWork->save(['title'=>'Original browser delivery subject','body'=>'Human original browser message','recipient_ids'=>array_column($browserContacts,'id')]);$viewOriginal=$browserWork->approve($viewOriginal['id'],1);$viewJobs=(new PressCampaigns($db,$browserAccess,$box,$root.'/media'))->queue($viewOriginal['id'],2,gmdate('Y-m-d H:i:s',time()+86400));
 $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);$data=$root.'/browser.json';
 file_put_contents($data,json_encode(['root'=>$root,'origin'=>'http://127.0.0.1:'.$port,'actor'=>$browserAccess->user()['id'],'manager_actor'=>'press-browser-admin','token'=>$token,'item'=>$viewOriginal['id'],'contacts'=>array_column($browserContacts,'id')],JSON_THROW_ON_ERROR));chmod($data,0600);putenv('DVM_PRESS_RECIPIENTS_FIXTURE='.$data);
 $server=proc_open([...TestSupport::phpCommand(),'-S','127.0.0.1:'.$port,'-t',$root.'/web',__DIR__.'/support/press-recipients-router.php'],[0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']],$pipes,dirname(__DIR__,2));if(!is_resource($server))throw new RuntimeException('Owned press controller required.');fclose($pipes[0]);
 try{
  $ready=false;for($i=0;$i<100;$i++){$socket=@fsockopen('127.0.0.1',$port,$errno,$error,.1);if($socket){fclose($socket);$ready=true;break;}usleep(50000);}if(!$ready)throw new RuntimeException('Own press controller unavailable.');
  $oldPressIds=Db::run($db,'SELECT id FROM khn_press_items ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);$oldDeliveryIds=Db::run($db,'SELECT id FROM native_delivery_jobs ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
  $pressTables=['khn_press_contacts','khn_press_items','khn_press_revisions','khn_press_jobs','native_press_messages','native_delivery_jobs','native_delivery_sources'];
  $pressFilter=static function($table)use($oldPressIds,$oldDeliveryIds):array{if($table==='khn_press_contacts')return ['',[]];$delivery=in_array($table,['native_delivery_jobs','native_delivery_sources'],true);$ids=$delivery?$oldDeliveryIds:$oldPressIds;$column=match($table){'khn_press_items','native_delivery_jobs'=>'id','native_delivery_sources'=>'job_id',default=>'item_id'};return [' WHERE '.$column.' IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids];};
  $beforeBrowser=ownOriginalDatabaseHash($db,$pressTables,$pressFilter);$node=proc_open(['node',__DIR__.'/press-delivery-browser.mjs'],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes,dirname(__DIR__,2));if(!is_resource($node)||proc_close($node)!==0)throw new RuntimeException('Actual press delivery browser failed.');
  $newPress=Db::run($db,"SELECT id,version,status,payload FROM khn_press_items WHERE club_id=? AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.title')) LIKE 'Browser human %' ORDER BY id",[$managerClub])->fetchAll();$check(count($newPress)===4,'both actual controllers create and schedule one human press original with and without JavaScript');
  foreach($newPress as$row){$check((int)$row['version']===3&&$row['status']==='scheduled','actual browser binds exactly one scheduled third revision');$check((int)Db::run($db,"SELECT COUNT(*) FROM native_delivery_sources WHERE club_id=? AND module='press' AND item_id=? AND item_version=3",[$managerClub,$row['id']])->fetchColumn()===2,'actual browser queues exactly its two selected recipients');}
  $check(Db::run($db,'SELECT COUNT(*) FROM native_delivery_jobs WHERE club_id=? AND kind=? AND status=?',[$managerClub,'press','sent'])->fetchColumn()===1,'actual browser does not activate SMTP or send a test fixture mail');
  $check(ownOriginalDatabaseHash($db,$pressTables,$pressFilter)===$beforeBrowser,'all earlier complete press originals, contacts, message ciphertexts, proofs and jobs remain byte-identical after both browser flows');
 }finally{proc_terminate($server);proc_close($server);}
}
echo json_encode(['suite'=>'native-press-delivery','checks'=>$checks,'live_transport'=>false],JSON_THROW_ON_ERROR)."\n";
