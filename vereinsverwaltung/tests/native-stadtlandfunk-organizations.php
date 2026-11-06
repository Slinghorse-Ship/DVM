<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
require dirname(__DIR__).'/src/autoload.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db,SecretBox,RuntimeSchema};
use Kulturhus\Native\Integration\StadtLandFunk\{Connection,Login,Http,Sender};
use Kulturhus\Native\Communication\{PersonalActivity,PersonalReplyProvider};
$db=TestSupport::db();Kulturhus\Verein\Database::migrate($db);TestSupport::seed($db);RuntimeSchema::install($db);Kulturhus\Native\Communication\Schema::install($db);
$a=new Access($db,'admin');$club=$a->club();$box=new SecretBox(str_repeat('s',32));$n=0;$calls=[];$mode='ok';$published=[];
function check(bool $ok,string $label):void{global$n;if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
function denied(callable $f,string $label):void{try{$f();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$data=['account_id'=>'person','home_area_id'=>'area','connection_id'=>str_repeat('a',32),'access_token'=>'synthetic-only','expires_at'=>time()+7200,'actor_id'=>'admin','authority'=>'native'];
Db::run($db,"INSERT INTO native_private_settings(club_id,purpose,version,ciphertext,updated_by) VALUES(?,'api-stadtlandfunk',1,?,'admin')",[$club,$box->seal('api:'.$club.':stadtlandfunk',$data)]);
$transport=static function($method,$url,$headers,$body)use(&$calls,&$mode,&$published):array{
 $path=parse_url($url,PHP_URL_PATH);$payload=$body===null||$path==='/media/temp'?null:json_decode($body,true,32,JSON_THROW_ON_ERROR);$calls[]=[$method,$path,$payload];
 if($method==='POST'&&$path==='/grapevine/post-creation/create')$published=['id'=>'new_event','type'=>'HAPPENING','happening'=>['creator'=>['id'=>'person'],'organizationId'=>$payload['organizationId'],'geoAreaIds'=>$payload['geoAreaIds'],'images'=>[['id'=>'uploaded_media']],'customAddress'=>['name'=>$payload['customLocationDefinition']['locationName']]]+array_intersect_key($payload,array_flip(['title','text','startTime','endTime']))];
 $out=match($path){
  '/grapevine/organization/own/home-senders'=>$mode==='revoked'?[]:[['id'=>'org','name'=>'Eigener Testverein','role'=>'ADMIN']],
  '/grapevine/organization/org'=>['id'=>'org','name'=>'Eigener Testverein','role'=>'ADMIN','geoAreaIds'=>[$mode==='foreign-area'?'elsewhere':'area']],
  '/grapevine/post-creation/options'=>['senders'=>[['senderType'=>'ORGANIZATION','organization'=>['id'=>'org'],'channels'=>$mode==='no-events'?[]:[['channelType'=>'HAPPENING','postTypes'=>['HAPPENING']]]]]],
  '/grapevine/organization/org/posts'=>['content'=>[
    ['id'=>'event','type'=>'HAPPENING','happening'=>['creator'=>['id'=>'other-admin'],'organizationId'=>$mode==='wrong-org'?'foreign':'org','geoAreaIds'=>['area'],'title'=>'Vereinsabend','text'=>'Gemeinsam aktiv','startTime'=>1900000000000]],
    ['id'=>'news','type'=>'NEWS_ITEM','newsItem'=>['creator'=>['id'=>'other-admin'],'organizationId'=>'org','geoAreaIds'=>['area'],'title'=>'Vereinsnachricht','text'=>'Neuigkeit']],
   ],'page'=>['number'=>0,'size'=>50,'totalElements'=>2,'totalPages'=>1]],
  '/grapevine/comment'=>[],
  '/media/temp'=>['id'=>'temporary_photo','mediaItem'=>['id'=>'uploaded_media'],'expirationTime'=>(time()+3600)*1000],
  '/grapevine/post/new_event'=>$published,
  '/grapevine/post-creation/create'=>['postId'=>'new_event'],
  '/grapevine/comment/event/commentCreateRequest'=>['commentId'=>'new_reply'],
  default=>throw new RuntimeException('Unexpected synthetic endpoint '.$path),
 };
 return ['status'=>200,'headers'=>[],'body'=>json_encode($out,JSON_THROW_ON_ERROR)];
};
$login=new Login($transport);$connection=new Connection($db,$box,$login);
$connection->loadSenders($a,1);check($connection->status($a)['organizations'][0]['name']==='Eigener Testverein','assigned sender profiles are available without exposing a token');
denied(fn()=>$connection->selectSender(new Access($db,'reader'),'org',2),'readers cannot change the sender');
denied(fn()=>$connection->selectSender($a,'org',1),'stale sender form cannot overwrite a current connection');
denied(fn()=>$connection->selectSender($a,'unknown',2),'unassigned organization cannot be selected');
$connection->selectSender($a,'org',2);$identity=$connection->publicationIdentity($a);$binding=$identity['connection_id'];
check($identity['organization_id']==='org'&&$binding!==$data['connection_id'],'organization selection rotates the approval binding');
denied(fn()=>$connection->publicationIdentity($a,$data['connection_id']),'old personal approvals cannot silently change author');
$connection->selectSender($a,'org',3);check($connection->publicationIdentity($a)===$identity,'saving the same sender retains the original binding');
$activity=new PersonalActivity($db,$box,$connection);$result=$activity->sync($a);
check($result['events']===2,'organization posts include an event and a news item from another authorized administrator');
$objects=Db::run($db,"SELECT remote_id,kind,payload_json FROM native_social_objects WHERE club_id=? AND channel='stadtlandfunk' ORDER BY remote_id",[$club])->fetchAll();
check(array_column($objects,'kind')===['event','post'],'news items retain their post type in the library');
foreach($objects as$object)check(json_decode($object['payload_json'],true)['organization_id']==='org','stored activities pin the organization identity');
$mode='wrong-org';denied(fn()=>$activity->sync($a),'foreign organization posts are rejected before changing local originals');$mode='ok';
denied(fn()=>$connection->read($a,'/grapevine/organization/foreign/posts'),'another organization list cannot be fetched through this binding');
$event=['title'=>'Vereinsabend','text'=>'Text mit ä, ß und 🙂','startTime'=>1900000000000,'temporaryMediaItemIds'=>['temporary_photo'],'customLocationDefinition'=>['locationName'=>'Vereinshaus'],'organizationId'=>'org','geoAreaIds'=>['area']];
$connection->createEvent($a,$binding,$event);$last=end($calls);
check($last[0]==='POST'&&$last[1]==='/grapevine/post-creation/create'&&$last[2]['organizationId']==='org'&&$last[2]['geoAreaIds']===['area']&&$last[2]['creationContext']==='MEIN_ORT','actual publication payload uses the confirmed organization and local scope');
check($last[2]['text']===$event['text'],'organization publication retains Unicode text');
denied(fn()=>$connection->createEvent($a,$binding,array_replace($event,['organizationId'=>'foreign'])),'caller cannot substitute another organization');
denied(fn()=>$connection->createEvent($a,$binding,array_replace($event,['geoAreaIds'=>['elsewhere']])),'caller cannot widen the publication area');
$mode='revoked';$before=count(array_filter($calls,fn($c)=>$c[0]==='POST'));denied(fn()=>$connection->createEvent($a,$binding,$event),'revoked organization membership blocks the provider write');
check(count(array_filter($calls,fn($c)=>$c[0]==='POST'))===$before,'membership rejection performs no publication request');$mode='ok';
$connection->reply($a,$binding,'event','question','Danke!');$last=end($calls);check($last[2]['organizationId']==='org','comment answers carry the same organization sender');
$account=(new PersonalReplyProvider($db,$connection))->account($a,['parent_id'=>'news']);check($account['organization_id']==='org','news item conversations can use the organization reply editor');
$mode='foreign-area';denied(fn()=>$login->organization('synthetic-only','org','area'),'assigned organization outside the home scope is refused');$mode='no-events';denied(fn()=>$login->organization('synthetic-only','org','area'),'missing event capability is refused');$mode='ok';
Kulturhus\Native\Cms\Schema::install($db);
(new Kulturhus\Native\Shared\Identity($db,$a))->save(['name'=>'Eigener Testverein','origin'=>'https://example.invalid','address'=>'Testort'],0);
$pages=new Kulturhus\Native\Cms\Pages($db,$a);$page=$pages->save(['slug'=>'veranstaltungen/testabend','title'=>'Vereinsabend','template'=>'event','body'=>'<p>Originaltext äß</p>','event_start'=>'2030-11-14T20:00','event_end'=>'2030-11-14T20:00:00Z','event_timezone'=>'Europe/Berlin','event_location'=>'Vereinshaus']);$page=$pages->publish($page['id'],$page['version']);
$mediaRoot=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/org-publication-media';$item=(new Kulturhus\Native\Communication\EventDrafts($db,$a))->create($page['id'],$page['published_version']);(new Kulturhus\Native\Communication\Images($db,$a,$mediaRoot))->render($item['id']);$item=(new Kulturhus\Native\Communication\Workspace($db,$a,'social'))->approve($item['id'],$item['version']);
$publisher=new Kulturhus\Native\Communication\PersonalPublication($db,$box,$connection);$jobId=$publisher->queue($a,$item['id'],$item['version'],gmdate('Y-m-d H:i:s'),$mediaRoot,true);
$raw=Db::run($db,'SELECT ciphertext FROM native_delivery_jobs WHERE id=?',[$jobId])->fetchColumn();$frozen=$box->open('job:'.$club.':'.$jobId,$raw);
check($frozen['identity']['organization_id']==='org'&&$frozen['event']['organizationId']==='org'&&$frozen['event']['geoAreaIds']===['area'],'durable approval pins the organization before an upload or publication');
$run=static function()use($db,$box,$publisher,$club,$mediaRoot,$jobId):array{Db::run($db,'UPDATE native_delivery_jobs SET due_at=UTC_TIMESTAMP() WHERE id=?',[$jobId]);return(new Kulturhus\Native\Delivery\Outbox($db,$box))->run(fn($c,$p,$kind,$job)=>$publisher->send($c,$p,$job,$mediaRoot),1,['stadtlandfunk'],$club);};
check($run()[0]['status']==='pending','organization publication stores its image checkpoint before posting');
check($run()[0]['status']==='sent','organization publication is independently confirmed by exact organization, content, image, dates and venue');
check($publisher->reconcile($a,$jobId)['publication_confirmed']===true,'organization publication reconciliation reads the confirmed original');
check($run()===[],'completed organization publication cannot be replayed');
$connection->selectSender($a,'',4);check(!isset($connection->publicationIdentity($a)['organization_id']),'personal sender remains available explicitly');
check(count(Db::run($db,"SELECT id FROM native_social_objects WHERE club_id=?",[$club])->fetchAll())===2,'sender switching preserves previous conversation originals');
check(!Sender::owns(['creator'=>['id'=>'person'],'organizationId'=>'org'],['account_id'=>'person','home_area_id'=>'area']),'personal ownership cannot claim an organization post');
echo "$n organization contracts passed; synthetic transport only.\n";
