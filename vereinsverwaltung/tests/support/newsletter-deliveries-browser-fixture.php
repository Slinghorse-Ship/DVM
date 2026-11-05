<?php declare(strict_types=1);
/** Own scoped CI server and real native/manager sessions, independent of existing development data. */
function ownNewsletterDeliveriesBrowser(PDO $db,\Kulturhus\Native\Shared\SecretBox $box,string $key,string $nativeJob): array
{
    $cfg=\Kulturhus\Native\TestSupport::config();$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/newsletter-history-'.$cfg['database'];
    mkdir($root,0700);foreach(['media','web','sessions','manager-sessions']as$dir)mkdir($root.'/'.$dir,0700);
    file_put_contents($root.'/private.key',$key);chmod($root.'/private.key',0600);file_put_contents($root.'/auth.key',random_bytes(32));chmod($root.'/auth.key',0600);
    putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$root.'/private.key');putenv('KH_NATIVE_AUTH_KEY_FILE='.$root.'/auth.key');
    \Kulturhus\Native\Shared\Db::run($db,"INSERT INTO clubs VALUES('newsletter-history-browser','Own newsletter history browser')");
    \Kulturhus\Native\Shared\Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES('newsletter-history-browser-admin','newsletter-history-browser','member',1)");
    $bridge=new \Kulturhus\Native\Integration\ManagerBridge($db,'newsletter-history-browser-admin','native',static fn()=>null);$access=$bridge->access();$club=$access->club();
    $identity=new \Kulturhus\Native\Shared\Identity($db,$access);$identity->save(['origin'=>'https://newsletter-browser.example.invalid','club_version'=>$identity->source()['version']],$identity->get()['version']);
    $work=new \Kulturhus\Native\Communication\Workspace($db,$access,'newsletter');$draft=$work->save(['title'=>'Actual human browser source','body'=>'Actual complete newsletter original ä🙂']);
    $campaigns=new \Kulturhus\Native\Communication\Campaigns($db,$access,$box);$job=$campaigns->queue($draft['id'],gmdate('Y-m-d H:i:s'),'browser-reader@example.invalid')[0];
    $outbox=new \Kulturhus\Native\Delivery\Outbox($db,$box);
    foreach(range(0,16)as$i)$outbox->enqueue($club,'test','own-browser-history-'.$i,['to'=>'browser'.$i.'@example.invalid','subject'=>'Own actual browser mail '.$i,'text'=>'Human original browser mail '.$i,'html'=>'<p>Human original browser mail '.$i.'</p>'],gmdate('Y-m-d H:i:s',time()+3600+$i),$access,'edit');
    $auth=\Kulturhus\Native\Auth\Runtime::service($db);$invite=$auth->createInvitation($access,$bridge->nativeActorId(),'newsletter-history-browser@example.invalid');$auth->acceptInvitation($invite['token'],'Own newsletter history browser password 2026!');$token=$auth->passwordLogin('newsletter-history-browser@example.invalid','Own newsletter history browser password 2026!','own-newsletter-history-browser')['session_token'];
    \Kulturhus\Native\Shared\Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES('newsletter-history-browser-reader','newsletter-history-browser','member',0)");
    \Kulturhus\Native\Shared\Db::run($db,"INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,website_access,version) VALUES('newsletter-history-browser','newsletter-history-read','Own actual read role','','none','none',0,?,1)",[json_encode(['newsletter'=>'read'],JSON_THROW_ON_ERROR)]);
    \Kulturhus\Native\Shared\Db::run($db,"INSERT INTO settings_role_assignments(actor_id,club_id,role_id,version) VALUES('newsletter-history-browser-reader','newsletter-history-browser','newsletter-history-read',1)");
    $readerBridge=new \Kulturhus\Native\Integration\ManagerBridge($db,'newsletter-history-browser-reader','native',static fn()=>null);$readerBridge->access();$readerInvite=$auth->createInvitation($access,$readerBridge->nativeActorId(),'newsletter-history-read@example.invalid');$auth->acceptInvitation($readerInvite['token'],'Own history read password 2026!');$readerToken=$auth->passwordLogin('newsletter-history-read@example.invalid','Own history read password 2026!','own-newsletter-history-read')['session_token'];
    \Kulturhus\Native\Shared\Db::run($db,"INSERT IGNORE INTO native_grants VALUES('reader','newsletter','read')");
    $socket=stream_socket_server('tcp://127.0.0.1:0');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
    $file=$root.'/browser.json';$data=['root'=>$root,'origin'=>'http://127.0.0.1:'.$port,'token'=>$token,'actor'=>'admin','manager_actor'=>'newsletter-history-browser-admin','reader_token'=>$readerToken,'reader_actor'=>'reader','reader_manager_actor'=>'newsletter-history-browser-reader','jobs'=>['native'=>$nativeJob,'integrated'=>$job]];file_put_contents($file,json_encode($data,JSON_THROW_ON_ERROR));chmod($file,0600);putenv('DVM_PRESS_RECIPIENTS_FIXTURE='.$file);
    $log=fopen($root.'/http.log','wb');$process=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),'-S','127.0.0.1:'.$port,'-t',$root.'/web',dirname(__DIR__).'/support/press-recipients-router.php'],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);if(!is_resource($process))throw new RuntimeException('Own history server unavailable.');fclose($pipes[0]);
    for($i=0;$i<100;$i++){$probe=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1);if($probe){fclose($probe);break;}usleep(50000);}
    return ['process'=>$process,'log'=>$log,'root'=>$root,'input'=>$data];
}
