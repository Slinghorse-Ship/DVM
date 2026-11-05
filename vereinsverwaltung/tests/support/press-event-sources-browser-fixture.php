<?php declare(strict_types=1);
/** Own scoped CI server and real native/manager sessions, independent of existing development data. */
function ownPressEventSourcesBrowser(PDO $db,string $key): array
{
    $cfg=\Kulturhus\Native\TestSupport::config();$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/press-event-sources-'.$cfg['database'];
    mkdir($root,0700);foreach(['media','web','sessions','manager-sessions']as$dir)mkdir($root.'/'.$dir,0700);
    file_put_contents($root.'/private.key',$key);chmod($root.'/private.key',0600);file_put_contents($root.'/auth.key',random_bytes(32));chmod($root.'/auth.key',0600);
    putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$root.'/private.key');putenv('KH_NATIVE_AUTH_KEY_FILE='.$root.'/auth.key');
    \Kulturhus\Native\Shared\Db::run($db,"INSERT INTO clubs VALUES('press-event-sources-browser','Own press event sources management browser')");
    \Kulturhus\Native\Shared\Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES('press-event-sources-browser-admin','press-event-sources-browser','member',1)");
    $bridge=new \Kulturhus\Native\Integration\ManagerBridge($db,'press-event-sources-browser-admin','native',static fn()=>null);$access=$bridge->access();$club=$access->club();
    \Kulturhus\Native\Shared\Db::run($db,"INSERT IGNORE INTO native_grants VALUES('admin','press','edit')");
    foreach(['press','newsletter']as$module)foreach(['read','edit','manage']as$capability)\Kulturhus\Native\Shared\Db::run($db,'INSERT IGNORE INTO native_grants VALUES(?,?,?)',['admin',$module,$capability]);
    $pixel=imagecreatetruecolor(8,6);imagefill($pixel,0,0,imagecolorallocate($pixel,31,116,99));imagepng($pixel,$root.'/source.png');imagedestroy($pixel);
    $fixtures=[];
    foreach(['native'=>new \Kulturhus\Native\Shared\Access($db,'admin'),'integrated'=>$access]as$mode=>$actor){
        $mediaDir=$mode==='native'?dirname((string)getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$cfg['database'].'/media':$root.'/media';if(!is_dir($mediaDir))mkdir($mediaDir,0700,true);
        $store=new \Kulturhus\Native\Cms\Media($db,$actor,$mediaDir);$image=$store->upload($root.'/source.png','own-event-image.png','Actual original event image')['id'];$pages=new \Kulturhus\Native\Cms\Pages($db,$actor);
        $rows=[];foreach(range(0,18)as$i){$event=$pages->save(['title'=>'Browser original event '.str_pad((string)$i,3,'0',STR_PAD_LEFT),'slug'=>'events/browser-'.$i,'template'=>'event','body'=>'<p>Actual original event detail 🎶</p>','event_start'=>'2050-11-14T20:00','event_end'=>'2050-11-14T20:30:00Z','event_timezone'=>'Europe/Berlin','event_location'=>'Original own hall','event_address'=>'Example road 2','media_ids'=>[$image]]);$rows[]=$pages->publish($event['id'],$event['version']);}
        $draft=(new \Kulturhus\Native\Communication\EventDrafts($db,$actor,'press'))->create($rows[0]['id'],$rows[0]['published_version']);
        $fixtures[$mode]=['events'=>array_column($rows,'id'),'original'=>$draft['id'],'media'=>$image,'revision'=>$rows[0]['published_version']];
    }
    $registry=new \Kulturhus\Verein\Registry($db,'press-event-sources-browser-admin');$person=$registry->saveContact(null,0,'person','Private original attendee','','');$calendar=[];
    foreach(range(0,18)as$i)$calendar[]=$registry->saveAppointment(null,0,['title'=>'Browser calendar original '.str_pad((string)$i,3,'0',STR_PAD_LEFT),'start'=>'2050-10-10T18:00','end'=>'2050-10-10T19:30','location'=>'Own calendar hall','description'=>'Own original calendar detail','activity_kind'=>'event'],[$person]);
    $fixtures['integrated']['calendar']=$calendar;
    $auth=\Kulturhus\Native\Auth\Runtime::service($db);$invite=$auth->createInvitation($access,$bridge->nativeActorId(),'press-event-sources-browser@example.invalid');$auth->acceptInvitation($invite['token'],'Own press event sources management browser password 2026!');$token=$auth->passwordLogin('press-event-sources-browser@example.invalid','Own press event sources management browser password 2026!','own-press-event-sources-browser')['session_token'];
    \Kulturhus\Native\Shared\Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES('press-event-sources-browser-reader','press-event-sources-browser','member',0)");
    \Kulturhus\Native\Shared\Db::run($db,"INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,website_access,version) VALUES('press-event-sources-browser','press-event-sources-read','Own press event sources read role','','read','none',0,?,1)",[json_encode(['press'=>'read','newsletter'=>'read','website'=>'read'],JSON_THROW_ON_ERROR)]);
    \Kulturhus\Native\Shared\Db::run($db,"INSERT INTO settings_role_assignments(actor_id,club_id,role_id,version) VALUES('press-event-sources-browser-reader','press-event-sources-browser','press-event-sources-read',1)");
    $readerBridge=new \Kulturhus\Native\Integration\ManagerBridge($db,'press-event-sources-browser-reader','native',static fn()=>null);$readerBridge->access();$readerInvite=$auth->createInvitation($access,$readerBridge->nativeActorId(),'press-event-sources-read@example.invalid');$auth->acceptInvitation($readerInvite['token'],'Own history read password 2026!');$readerToken=$auth->passwordLogin('press-event-sources-read@example.invalid','Own history read password 2026!','own-press-event-sources-read')['session_token'];
    foreach(['press','newsletter']as$module)\Kulturhus\Native\Shared\Db::run($db,"INSERT IGNORE INTO native_grants VALUES('reader',?,'read')",[$module]);\Kulturhus\Native\Shared\Db::run($db,"INSERT IGNORE INTO native_grants VALUES('reader','website','read')");
    $socket=stream_socket_server('tcp://127.0.0.1:0');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
    $file=$root.'/browser.json';$data=['root'=>$root,'origin'=>'http://127.0.0.1:'.$port,'token'=>$token,'actor'=>'admin','manager_actor'=>'press-event-sources-browser-admin','reader_token'=>$readerToken,'reader_actor'=>'reader','reader_manager_actor'=>'press-event-sources-browser-reader','events'=>$fixtures];file_put_contents($file,json_encode($data,JSON_THROW_ON_ERROR));chmod($file,0600);putenv('DVM_PRESS_RECIPIENTS_FIXTURE='.$file);
    $log=fopen($root.'/http.log','wb');$process=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),'-S','127.0.0.1:'.$port,'-t',$root.'/web',dirname(__DIR__).'/support/press-recipients-router.php'],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);if(!is_resource($process))throw new RuntimeException('Own history server unavailable.');fclose($pipes[0]);
    for($i=0;$i<100;$i++){$probe=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1);if($probe){fclose($probe);break;}usleep(50000);}
    return ['process'=>$process,'log'=>$log,'root'=>$root,'input'=>$data];
}
