<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
require dirname(__DIR__).'/src/autoload.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db,SecretBox,RuntimeSchema,Identity};
use Kulturhus\Native\Cms\{Pages,EventDetails};
use Kulturhus\Native\Communication\{EventDrafts,Workspace,Images,PersonalPublication};
use Kulturhus\Native\Delivery\Outbox;
use Kulturhus\Native\Integration\StadtLandFunk\{Connection,Login,Http};

$db=TestSupport::db();Kulturhus\Verein\Database::migrate($db);TestSupport::seed($db);RuntimeSchema::install($db);
Kulturhus\Native\Cms\Schema::install($db);Kulturhus\Native\Communication\Schema::install($db);
$a=new Access($db,'admin');$club=$a->club();$box=new SecretBox(str_repeat('p',32));
(new Identity($db,$a))->save(['name'=>'Eigener synthetischer Verein','origin'=>'https://example.invalid','address'=>'Beispielweg 2'],0);
$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/personal-publication-runtime';mkdir($root,0700,true);$mediaRoot=$root.'/media';
$n=0;$calls=[];$mode='normal';$photos=[];$posts=[];$next=0;$last=null;
function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException|InvalidArgumentException){check(true,$label);return;}throw new RuntimeException($label);}
$identity=['account_id'=>'own_person','home_area_id'=>'own_area','connection_id'=>str_repeat('b',32)];
$data=$identity+['access_token'=>'synthetic-private-token','expires_at'=>time()+7200,'actor_id'=>'admin','authority'=>'native','username'=>'synthetic@example.test'];
$persist=static function(array $value)use($db,$box,$club):void{Db::run($db,"INSERT INTO native_private_settings(club_id,purpose,version,ciphertext,updated_by) VALUES(?,'api-stadtlandfunk',1,?,'admin') ON DUPLICATE KEY UPDATE ciphertext=VALUES(ciphertext),version=version+1",[$club,$box->seal('api:'.$club.':stadtlandfunk',$value)]);};$persist($data);
$http=static function($method,$url,$headers,$body)use(&$calls,&$mode,&$photos,&$posts,&$next,&$last,$db,$box,$club):array{
    $path=parse_url($url,PHP_URL_PATH);parse_str(parse_url($url,PHP_URL_QUERY)??'',$query);$calls[]=[$method,$path];
    check(str_starts_with($url,Http::API.'/')&&in_array('Authorization: Bearer synthetic-private-token',$headers,true)&&in_array('appVariantIdentifier: '.Login::APP,$headers,true),'fixed app endpoint and private connection token');
    $response=static fn(array $value,int $status=200):array=>['status'=>$status,'headers'=>[],'body'=>json_encode($value,JSON_THROW_ON_ERROR)];
    if($method==='GET'){
        if($mode==='read-timeout')throw new RuntimeException('Synthetic read unavailable');
        if($path==='/grapevine/post/own'){
            check(($query['type']??null)==='HAPPENING'&&($query['count']??null)==='50','read-only reconciliation limits its own event list');
            $rows=array_values($posts);if($mode==='duplicate-list'&&$rows)$rows[]=$rows[0];
            $number=(int)($query['page']??0);$total=count($rows);
            $frame=['content'=>array_slice($rows,$number*50,50),'page'=>['number'=>$number,'size'=>50,'totalElements'=>$total,'totalPages'=>$mode==='incomplete-list'?5:(int)ceil($total/50)]];
            if($mode==='legacy-list')$frame=['content'=>$frame['content'],'totalPages'=>(int)ceil($total/50)];
            if($mode==='wrong-page-number')$frame['page']['number']++;
            if($mode==='wrong-page-size')$frame['page']['size']=20;
            if($mode==='missing-page-total')unset($frame['page']['totalPages']);
            if($mode==='string-page-total')$frame['page']['totalPages']=(string)$frame['page']['totalPages'];
            if($mode==='conflicting-page-total')$frame['totalPages']=$frame['page']['totalPages']+1;
            if($mode==='short-page')array_pop($frame['content']);
            if($mode==='null-page')$frame['page']=null;
            if($number>0&&$mode==='changing-page-total'){$frame['page']['totalElements']++;$frame['content'][]=['id'=>'new_during_read'];}
            if($number>0&&$mode==='changing-page-format')$frame=['content'=>$frame['content'],'totalPages'=>$frame['page']['totalPages']];
            return $response($frame);
        }
        $id=basename($path);return $response($posts[$id]??[],isset($posts[$id])?200:404);
    }
    $second=TestSupport::db();$job=Db::run($second,"SELECT * FROM native_delivery_jobs WHERE kind='stadtlandfunk' AND status='processing'")->fetch();
    check($job!==false,'independent MySQL connection sees the committed worker claim before provider write');
    $payload=$box->open('job:'.$job['club_id'].':'.$job['id'],$job['ciphertext']);
    if($path==='/media/temp'){
        $contentType=array_values(array_filter($headers,static fn($h)=>str_starts_with($h,'Content-Type: multipart/form-data; boundary=')))[0]??'';
        $boundary=substr($contentType,strlen('Content-Type: multipart/form-data; boundary='));
        $prefix='--'.$boundary."\r\nContent-Disposition: form-data; name=\"file\"; filename=\"publication.jpg\"\r\nContent-Type: image/jpeg\r\n\r\n";$suffix="\r\n--".$boundary."--\r\n";
        check($boundary!==''&&str_starts_with($body,$prefix)&&str_ends_with($body,$suffix),'photo upload uses the observed exact multipart field');
        $bytes=substr($body,strlen($prefix),-strlen($suffix));check(hash('sha256',$bytes)===$payload['image_hash'],'uploaded JPEG bytes equal the exact approved original');
        $temp='temp_'.++$next;$media='media_'.$next;$photos[$temp]=$media;
        if($mode==='upload-timeout')throw new RuntimeException('Synthetic upload response lost');
        return $response(['id'=>$mode==='missing-photo-id'?null:$temp,'mediaItem'=>['id'=>$media],'expirationTime'=>$mode==='expired-photo'?(time()-60)*1000:(time()+3600)*1000]);
    }
    check($path==='/grapevine/event/happeningCreateRequest','event creation uses only the observed app endpoint');
    $frame=json_decode($body,true,64,JSON_THROW_ON_ERROR);$temp=$frame['temporaryMediaItemIds'][0]??'';
    check(str_starts_with($job['external_id']??'','sf:'.$temp.':'.$photos[$temp].':'),'photo checkpoint is committed before the single event creation');
    $expected=$payload['event']+['temporaryMediaItemIds'=>[$temp]];check($frame===$expected,'provider receives the frozen approved text, exact times and original address');
    $id='own_event_'.++$next;$location=$frame['customLocationDefinition'];
    $event=['creator'=>['id'=>'own_person'],'geoAreaId'=>'own_area','title'=>$frame['title'],'text'=>$frame['text'],'startTime'=>$frame['startTime'],'images'=>[['id'=>$photos[$temp]]],'customAddress'=>['name'=>$location['locationName'],'street'=>$location['addressStreet']??'','zip'=>$location['addressZip']??'','city'=>$location['addressCity']??'']];
    if(isset($frame['endTime']))$event['endTime']=$frame['endTime'];
    $posts[$id]=['id'=>$id,'type'=>'HAPPENING','happening'=>$event];$last=$id;
    if($mode==='create-timeout')throw new RuntimeException('Synthetic response lost after the event was created');
    foreach(['wrong-text'=>'text','wrong-person'=>'creator','wrong-area'=>'geoAreaId','wrong-image'=>'images','wrong-place'=>'customAddress','wrong-time'=>'startTime','flagged'=>'filterStatus']as$case=>$field)if($mode===$case)$posts[$id]['happening'][$field]=match($field){'creator'=>['id'=>'another_person'],'images'=>[['id'=>'another_image']],'customAddress'=>['name'=>'Another venue'],'startTime'=>$frame['startTime']+60000,default=>'changed'};
    if($mode==='missing-post-id')return $response(['post'=>[]]);
    if($mode==='conflicting-post-id')return $response(['postId'=>$id,'post'=>['id'=>'another_post']]);
    if($mode==='revoke-after-create')$db->exec("DELETE FROM native_grants WHERE actor_id='admin' AND module='social' AND capability='publish'");
    return $response(['postId'=>$id,'post'=>$posts[$id]]);
};
$connection=new Connection($db,$box,new Login($http));$publisher=new PersonalPublication($db,$box,$connection);$outbox=new Outbox($db,$box);$pages=new Pages($db,$a);$workspace=new Workspace($db,$a,'social');$images=new Images($db,$a,$mediaRoot);
$prepare=static function(string $name,bool $approved=true,bool $end=true)use($pages,$a,$db,$workspace,$images):array{
    $page=$pages->save(['slug'=>'veranstaltungen/'.$name,'title'=>'Original ä🙂 '.$name,'template'=>'event','body'=>'<p>Ursprüngliche Beschreibung ä🙂.</p>','event_start'=>'2030-11-14T20:00','event_end'=>$end?'2030-11-14T20:30:00Z':'','event_timezone'=>'Europe/Berlin','event_location'=>'Eigener Vereinssaal','event_address'=>"Beispielweg 2\n12345 Beispielstadt"]);
    $page=$pages->publish($page['id'],$page['version']);$item=(new EventDrafts($db,$a))->create($page['id'],$page['published_version']);$ids=$images->render($item['id']);if($approved)$item=$workspace->approve($item['id'],$item['version']);return [$page,$item,$ids[0]];
};
$queue=static fn(array $case):string=>$publisher->queue($a,$case[1]['id'],$case[1]['version'],gmdate('Y-m-d H:i:s'),$mediaRoot,true);
$run=static function(string $id)use($db,$outbox,$publisher,$club,$mediaRoot):array{
    Db::run($db,'UPDATE native_delivery_jobs SET due_at=UTC_TIMESTAMP() WHERE id=?',[$id]);return $outbox->run(static fn($c,$p,$kind,$job)=>$publisher->send($c,$p,$job,$mediaRoot),1,['stadtlandfunk'],$club);
};
$job=static fn(string $id):array=>Db::run($db,'SELECT * FROM native_delivery_jobs WHERE id=?',[$id])->fetch();
$frame=static fn(string $id):array=>$box->open('job:'.$club.':'.$id,$job($id)['ciphertext']);
$writes=static function()use(&$calls):int{return count(array_filter($calls,static fn($c)=>$c[0]==='POST'));};
$first=$prepare('first');$before=count($calls);
denied(fn()=>$publisher->queue($a,$first[1]['id'],$first[1]['version'],gmdate('Y-m-d H:i:s'),$mediaRoot),'target confirmation is required');
denied(fn()=>$publisher->queue(new Access($db,'reader'),$first[1]['id'],$first[1]['version'],gmdate('Y-m-d H:i:s'),$mediaRoot,true),'readers cannot queue publications');
denied(fn()=>$publisher->queue(new Access($db,'outsider'),$first[1]['id'],$first[1]['version'],gmdate('Y-m-d H:i:s'),$mediaRoot,true),'foreign club cannot queue this original');
denied(fn()=>$publisher->queue($a,$first[1]['id'],$first[1]['version']+1,gmdate('Y-m-d H:i:s'),$mediaRoot,true),'stale approval version cannot queue');
denied(fn()=>$publisher->queue($a,$first[1]['id'],$first[1]['version'],'2030-02-30 12:00:00',$mediaRoot,true),'invalid calendar date cannot schedule');
$id=$queue($first);check($queue($first)===$id&&count($calls)===$before,'queueing is idempotent and performs no provider request');
$payload=$frame($id);check($payload['event']['startTime']===(new DateTimeImmutable('2030-11-14T19:00:00Z'))->getTimestamp()*1000&&$payload['event']['endTime']===(new DateTimeImmutable('2030-11-14T20:30:00Z'))->getTimestamp()*1000,'original Berlin start and end use their actual UTC offsets');
check(!str_contains($job($id)['ciphertext'],$payload['event']['title'])&&!str_contains($job($id)['ciphertext'],'synthetic-private-token'),'frozen frame is encrypted and contains no provider token');
check(Db::run($db,'SELECT module,item_id,item_version FROM native_delivery_sources WHERE job_id=?',[$id])->fetch()===['module'=>'social','item_id'=>$first[1]['id'],'item_version'=>$first[1]['version']],'delivery binds the exact social source and revision');
denied(fn()=>$publisher->send($club,$payload,$job($id),$mediaRoot),'direct call without durable worker claim cannot publish');
denied(fn()=>Db::atomic($db,fn()=>$publisher->send($club,$payload,$job($id),$mediaRoot)),'transaction alone cannot forge a worker claim');
check($outbox->run(static fn()=>throw new RuntimeException('Default transport must not consume social events'))===[],'default mail worker never consumes StadtLand.Funk publications');
check(count($outbox->list($a,'social'))===1&&count($outbox->list($a,'newsletter'))===0,'event queue belongs only to the social module');
$firstResult=$run($id);check($firstResult[0]['status']==='pending'&&(int)$job($id)['attempts']===1&&$writes()===1&&count($posts)===0,'first phase stores the photo checkpoint without creating an event: '.json_encode($firstResult));
$photoState=$job($id)['external_id'];check(str_starts_with($photoState,'sf:temp_'),'temporary and permanent photo identifiers survive the transaction rollback');
check($run($id)[0]['status']==='sent'&&(int)$job($id)['attempts']===2&&$writes()===2,'second phase publishes once and independently confirms the original');
check(PersonalPublication::postId($job($id)['external_id'])===$last,'confirmed event retains its exact post identifier and photo checkpoint');
$before=$writes();check($run($id)===[]&&$queue($first)===$id&&$writes()===$before,'completed queue cannot be accidentally replayed');
$proof=$publisher->reconcile($a,$id);check($proof['publication_confirmed']&&$proof['remote_id']===$last&&$writes()===$before,'confirmed original can be checked through GET only');
$duplicate=(new EventDrafts($db,$a))->create($first[0]['id'],$first[0]['published_version']);$images->render($duplicate['id']);$duplicate=$workspace->approve($duplicate['id'],$duplicate['version']);
denied(fn()=>$publisher->queue($a,$duplicate['id'],$duplicate['version'],gmdate('Y-m-d H:i:s'),$mediaRoot,true),'a second draft cannot duplicate the same original event');
$unapproved=$prepare('unapproved',false);denied(fn()=>$queue($unapproved),'unapproved exact image and text cannot be queued');
$openEnd=$prepare('open-end',true,false);$id=$queue($openEnd);check(!array_key_exists('endTime',$frame($id)['event']),'unknown original end time is never invented');$run($id);check($run($id)[0]['status']==='sent','independent proof accepts only the unchanged original without an invented end');
foreach(['private-edit','republished','withdrawn','cancelled','approval-edit','grant-revoked','owner-revoked','connection-changed','account-changed','area-changed','jpeg-tampered']as$case){
    $mode='normal';$current=$prepare($case);$id=$queue($current);$before=$writes();
    if(in_array($case,['private-edit','republished','cancelled'],true)){
        $edited=$pages->save(array_replace($current[0],['title'=>'Geänderte private Fassung','event_status'=>$case==='cancelled'?'cancelled':'scheduled']),$current[0]['version']);
        if($case!=='private-edit')$pages->publish($edited['id'],$edited['version']);
    }
    if($case==='withdrawn')$pages->unpublish($current[0]['id'],$current[0]['version']);
    if($case==='approval-edit')$workspace->save(['id'=>$current[1]['id'],'body'=>'Andere private Social-Fassung'],$current[1]['version']);
    if($case==='grant-revoked')$db->exec("DELETE FROM native_grants WHERE actor_id='admin' AND module='social' AND capability='publish'");
    if($case==='owner-revoked')$db->exec("DELETE FROM native_grants WHERE actor_id='admin' AND module='website' AND capability='manage'");
    if($case==='connection-changed')$persist(array_replace($data,['connection_id'=>str_repeat('c',32)]));
    if($case==='account-changed')$persist(array_replace($data,['account_id'=>'another_account']));
    if($case==='area-changed')$persist(array_replace($data,['home_area_id'=>'another_area']));
    if($case==='jpeg-tampered'){$file=$images->jpeg($current[2]);file_put_contents($file['path'],'changed');}
    $result=$run($id);
    if($case==='private-edit')check($result[0]['status']==='pending'&&$writes()===$before+1,'private website edit preserves the exact still-published original');
    else check(in_array($result[0]['status'],['cancelled','uncertain'],true)&&$writes()===$before,'current '.$case.' blocks before any provider write');
    if($case==='private-edit')Db::run($db,"UPDATE native_delivery_jobs SET status='cancelled' WHERE id=?",[$id]);
    $db->exec("INSERT IGNORE INTO native_grants VALUES('admin','social','publish'),('admin','website','manage')");$persist($data);
}
foreach(['upload-timeout','missing-photo-id','expired-photo','create-timeout','missing-post-id','conflicting-post-id','read-timeout','wrong-text','wrong-person','wrong-area','wrong-image','wrong-place','wrong-time','flagged','revoke-after-create']as$case){
    $mode='normal';$current=$prepare($case);$id=$queue($current);
    if(in_array($case,['upload-timeout','missing-photo-id','expired-photo'],true))$mode=$case;
    $result=$run($id);
    if(!in_array($case,['upload-timeout','missing-photo-id','expired-photo'],true)){$mode=$case;$result=$run($id);}
    check($result[0]['status']==='uncertain','unknown '.$case.' result remains explicitly uncertain');
    $before=$writes();check($run($id)===[]&&$writes()===$before,'uncertain '.$case.' is never automatically retried');
    $mode='normal';$payload=$frame($id);$state=$job($id)['external_id'];
    if($state===null){check(!$publisher->reconcile($a,$id)['publication_confirmed']&&$writes()===$before,'unknown upload is never guessed or recreated');continue;}
    if($case==='revoke-after-create')$db->exec("INSERT IGNORE INTO native_grants VALUES('admin','social','publish')");
    if(in_array($case,['wrong-text','wrong-person','wrong-area','wrong-image','wrong-place','wrong-time','flagged'],true)){
        check(!$publisher->reconcile($a,$id)['publication_confirmed']&&$writes()===$before,'mismatching '.$case.' original cannot be falsely confirmed');
        $p=$payload['event'];$parts=explode(':',$state);$location=$p['customLocationDefinition'];
        $posts[$last]['happening']=['creator'=>['id'=>'own_person'],'geoAreaId'=>'own_area','title'=>$p['title'],'text'=>$p['text'],'startTime'=>$p['startTime'],'endTime'=>$p['endTime'],'images'=>[['id'=>$parts[2]]],'customAddress'=>['name'=>$location['locationName'],'street'=>$location['addressStreet'],'zip'=>$location['addressZip'],'city'=>$location['addressCity']]];
    }
    if($case==='create-timeout'){
        $mode='duplicate-list';denied(fn()=>$publisher->reconcile($a,$id),'duplicate provider page cannot prove a unique original');
        $mode='incomplete-list';denied(fn()=>$publisher->reconcile($a,$id),'unbounded incomplete provider pages cannot prove a unique original');$mode='normal';
        $other=$posts[$last];$other['id']='duplicate_'.$last;$posts[$other['id']]=$other;
        $mode='legacy-list';check(!$publisher->reconcile($a,$id)['publication_confirmed']&&$writes()===$before,'legacy Spring Page still reads exact matching originals without a second write');unset($posts[$other['id']]);$mode='normal';
        $originalPosts=$posts;
        for($i=0;$i<51;$i++)$posts['unrelated_'.$i]=['id'=>'unrelated_'.$i,'type'=>'GOSSIP'];
        $originalJob=$job($id);
        foreach(['wrong-page-number','wrong-page-size','missing-page-total','string-page-total','conflicting-page-total','short-page','null-page','changing-page-total','changing-page-format']as$badPage){
            $mode=$badPage;denied(fn()=>$publisher->reconcile($a,$id),'current PagedModel rejects '.$badPage);
            check($job($id)===$originalJob&&$writes()===$before,'invalid '.$badPage.' retains exact uncertain job and never writes to provider');
        }
        $mode='normal';$start=count($calls);$proof=$publisher->reconcile($a,$id);
        check($proof['publication_confirmed']&&count($calls)===$start+2&&$writes()===$before,'current nested metadata reconciles two complete pages through GET only');
        $posts=$originalPosts;
    }
    $proof=$publisher->reconcile($a,$id);check($proof['publication_confirmed']&&$proof['remote_id']===$last&&$job($id)['status']==='sent'&&$writes()===$before,'later exact '.$case.' proof confirms by read only');
}
$mode='normal';$current=$prepare('forged-frame');$id=$queue($current);$before=$writes();
$result=$outbox->run(static function($c,$p,$kind,$j)use($publisher,$mediaRoot){$p['event']['title']='Forged frame';return $publisher->send($c,$p,$j,$mediaRoot);},1,['stadtlandfunk'],$club);
check($result[0]['status']==='uncertain'&&$writes()===$before,'worker transport cannot substitute a forged encrypted publication frame');
denied(fn()=>$publisher->reconcile(new Access($db,'outsider'),$id),'foreign club cannot inspect or confirm original publication');
denied(fn()=>(new Login($http))->read('synthetic-private-token','/grapevine/post/another'),'general personal read does not inherit the publication-only individual endpoint');
denied(fn()=>(new Login($http))->read('synthetic-private-token','/grapevine/post/../another',true),'independent post read rejects path traversal');
denied(fn()=>(new Login($http))->createEvent('synthetic-private-token',['title'=>'Bad','text'=>'Bad','startTime'=>1,'temporaryMediaItemIds'=>['temp'],'customLocationDefinition'=>[],'venueLocationDefinition'=>[]]),'create rejects unrelated partner API fields');
$current=$prepare('concurrent-source');$second=TestSupport::db();$db->beginTransaction();
try{
    Db::run($db,'SELECT published_revision FROM khcms_pages WHERE club_id=? AND id=?',[$club,$current[0]['id']])->fetchColumn();
    $otherPages=new Pages($second,new Access($second,'admin'));$changed=$otherPages->save(array_replace($current[0],['title'=>'Nebenläufig veröffentlichte neue Fassung']),$current[0]['version']);$otherPages->publish($changed['id'],$changed['version']);
    $before=$writes();denied(fn()=>$queue($current),'repeatable-read snapshot cannot conceal another committed published revision');check($writes()===$before,'concurrent source change makes no provider request');
}finally{$db->rollBack();}
check(EventDetails::end(['event_end'=>'2030-11-14 21:30:00','event_timezone'=>'Europe/Berlin'])->format('c')==='2030-11-14T21:30:00+01:00','calendar original without offset retains its actual local end time');
$current=$prepare('process-crash');$id=$queue($current);$run($id);$reference=$job($id)['external_id'];Db::run($db,'UPDATE native_delivery_jobs SET due_at=UTC_TIMESTAMP() WHERE id=?',[$id]);
$process=proc_open([...TestSupport::phpCommand(),__DIR__.'/support/personal-publication-crash.php',$id],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
if(!is_resource($process))throw new RuntimeException('Synthetic crash process unavailable');fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
check(proc_close($process)===77&&$output===''&&$errors==='','actual separate worker process exits after its one accepted provider write');
check($job($id)['status']==='processing'&&$job($id)['external_id']===$reference,'interrupted process preserves its committed original photo and claim');
$before=$writes();check($run($id)===[]&&$writes()===$before,'interrupted claim is never automatically replayed');
denied(fn()=>$publisher->reconcile($a,$id),'fresh in-progress attempt cannot be mistaken for an abandoned worker');
$accepted=json_decode(file_get_contents($root.'/crash-proof.json'),true,64,JSON_THROW_ON_ERROR);$p=$accepted['event'];$location=$p['customLocationDefinition'];
$posts['crashed_event']=['id'=>'crashed_event','type'=>'HAPPENING','happening'=>['creator'=>['id'=>'own_person'],'geoAreaId'=>'own_area','title'=>$p['title'],'text'=>$p['text'],'startTime'=>$p['startTime'],'endTime'=>$p['endTime'],'images'=>[['id'=>$accepted['media']]],'customAddress'=>['name'=>$location['locationName'],'street'=>$location['addressStreet'],'zip'=>$location['addressZip'],'city'=>$location['addressCity']]]];
Db::run($db,'UPDATE native_delivery_jobs SET started_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 11 MINUTE) WHERE id=?',[$id]);
$originals=static function()use($db,$root):string{
    $hash=hash_init('sha256');foreach(['khcms_pages','khcms_revisions','khn_social_items','khn_social_revisions','native_social_event_origins','native_social_images']as$table)foreach($db->query('SELECT * FROM '.$table.' ORDER BY 1,2')as$row)hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR));
    $files=glob($root.'/communication-images/*')?:[];sort($files);foreach($files as$file)hash_update($hash,basename($file).':'.hash_file('sha256',$file));return hash_final($hash);
};$originalHash=$originals();$ciphertext=$job($id)['ciphertext'];
$proof=$publisher->reconcile($a,$id);check($proof['publication_confirmed']&&$proof['remote_id']==='crashed_event'&&$job($id)['status']==='sent'&&$writes()===$before,'abandoned process is reconciled from the exact independent original without another write');
check($originals()===$originalHash&&$job($id)['ciphertext']===$ciphertext,'independent crash proof preserves every source, revision, protected image byte and encrypted publication frame');
// Publisher can use a configured target without gaining account or secret administration.
$current=$prepare('publisher-role');$db->exec("INSERT IGNORE INTO native_grants VALUES('editor','social','publish')");$editor=new Access($db,'editor');
denied(fn()=>$connection->replyIdentity($editor),'publisher does not gain private account administration');
check($connection->publicationIdentity($editor)===$identity,'publisher gets only the safe original target binding');
$id=$publisher->queue($editor,$current[1]['id'],$current[1]['version'],gmdate('Y-m-d H:i:s'),$mediaRoot,true);$run($id);check($run($id)[0]['status']==='sent','publisher without manage permission completes the exact original publication');
// A real manager calendar keeps its own current permissions and original version.
Kulturhus\Native\Integration\Schema::install($db);Db::run($db,"INSERT INTO clubs VALUES('personal-calendar','Personal Calendar')");Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES('personal-calendar-admin','personal-calendar','members',1)");
$manager=(new Kulturhus\Native\Integration\ManagerBridge($db,'personal-calendar-admin','native',static fn()=>null))->access();$managerClub=$manager->club();$registry=new Kulturhus\Verein\Registry($db,'personal-calendar-admin');
Kulturhus\Native\Auth\Schema::install($db);Db::run($db,'INSERT INTO native_auth_accounts(id,actor_id,email,active,verified,created_at) VALUES(?,?,?,1,1,?)',[bin2hex(random_bytes(16)),$manager->user()['id'],'calendar-admin@example.invalid',time()]);
(new Kulturhus\Verein\Settings($db,'personal-calendar-admin'))->save('club',array_replace(Kulturhus\Verein\SettingsSchema::defaults('club'),['name'=>'Eigener Kalenderverein','street'=>'Eigener Weg']),0);
(new Identity($db,$manager))->save(['origin'=>'https://calendar.example.invalid','club_version'=>1],Identity::forClub($db,$managerClub)['version']);
$managerData=array_replace($data,['actor_id'=>$manager->user()['id'],'authority'=>'manager']);Db::run($db,"INSERT INTO native_private_settings(club_id,purpose,version,ciphertext,updated_by) VALUES(?,'api-stadtlandfunk',1,?,?)",[$managerClub,$box->seal('api:'.$managerClub.':stadtlandfunk',$managerData),$manager->user()['id']]);
$calendarData=['title'=>'Eigener interner Kalendertermin','start'=>'2030-11-14T20:00','end'=>'2030-11-14T21:30','location'=>'Eigener Vereinssaal','description'=>'Nur ursprüngliche Terminangaben','activity_kind'=>'event'];$calendarId=$registry->saveAppointment(null,0,$calendarData,[]);$original=$registry->appointment($calendarId);
$draft=(new EventDrafts($db,$manager))->create($calendarId,1,'manager',true);$managerImages=new Images($db,$manager,$mediaRoot);$managerImages->render($draft['id']);$draft=(new Workspace($db,$manager,'social'))->approve($draft['id'],$draft['version']);
$id=$publisher->queue($manager,$draft['id'],$draft['version'],gmdate('Y-m-d H:i:s'),$mediaRoot,true);$payload=$box->open('job:'.$managerClub.':'.$id,$job($id)['ciphertext']);
check($payload['source']['kind']==='manager'&&$payload['source']['revision']===1&&$payload['event']['endTime']===(new DateTimeImmutable('2030-11-14T20:30:00Z'))->getTimestamp()*1000,'real manager original binds its own revision and exact local end');
$managerRun=static function()use($db,$outbox,$publisher,$mediaRoot,$managerClub,&$id):array{Db::run($db,'UPDATE native_delivery_jobs SET due_at=UTC_TIMESTAMP() WHERE id=?',[$id]);return $outbox->run(static fn($c,$p,$kind,$j)=>$publisher->send($c,$p,$j,$mediaRoot),1,['stadtlandfunk'],$managerClub);};
check($managerRun()[0]['status']==='pending'&&$managerRun()[0]['status']==='sent','approved manager original uses the same committed two-phase publication');
check($registry->appointment($calendarId)===$original,'publication preserves the complete manager calendar original');
$calendarId=$registry->saveAppointment(null,0,array_replace($calendarData,['title'=>'Später geänderter Kalendertermin']),[]);$draft=(new EventDrafts($db,$manager))->create($calendarId,1,'manager',true);$managerImages->render($draft['id']);$draft=(new Workspace($db,$manager,'social'))->approve($draft['id'],$draft['version']);
$id=$publisher->queue($manager,$draft['id'],$draft['version'],gmdate('Y-m-d H:i:s'),$mediaRoot,true);$registry->saveAppointment($calendarId,1,array_replace($calendarData,['title'=>'Neue private Originalfassung']),[]);$before=$writes();
check($managerRun()[0]['status']==='cancelled'&&$writes()===$before,'changed current manager original blocks publication before any provider request');
echo "$n personal publication checks passed; synthetic app transport only, no live publications.\n";
