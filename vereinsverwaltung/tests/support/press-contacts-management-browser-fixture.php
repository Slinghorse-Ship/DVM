<?php declare(strict_types=1);
/** Own scoped CI server and real native/manager sessions, independent of existing development data. */
function ownPressContactManagementBrowser(PDO $db,string $key): array
{
    $cfg=\Kulturhus\Native\TestSupport::config();$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/press-management-'.$cfg['database'];
    mkdir($root,0700);foreach(['media','web','sessions','manager-sessions']as$dir)mkdir($root.'/'.$dir,0700);
    file_put_contents($root.'/private.key',$key);chmod($root.'/private.key',0600);file_put_contents($root.'/auth.key',random_bytes(32));chmod($root.'/auth.key',0600);
    putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$root.'/private.key');putenv('KH_NATIVE_AUTH_KEY_FILE='.$root.'/auth.key');
    \Kulturhus\Native\Shared\Db::run($db,"INSERT INTO clubs VALUES('press-management-browser','Own press contact management browser')");
    \Kulturhus\Native\Shared\Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES('press-management-browser-admin','press-management-browser','member',1)");
    $bridge=new \Kulturhus\Native\Integration\ManagerBridge($db,'press-management-browser-admin','native',static fn()=>null);$access=$bridge->access();$club=$access->club();
    \Kulturhus\Native\Shared\Db::run($db,"INSERT IGNORE INTO native_grants VALUES('admin','press','edit')");
    $nativeContacts=new \Kulturhus\Native\Communication\PressContacts($db,new \Kulturhus\Native\Shared\Access($db,'admin'));
    $managerContacts=new \Kulturhus\Native\Communication\PressContacts($db,$access);$nativeRows=[];$managerRows=[];
    foreach(range(0,14)as$i){$data=['name'=>'Browser original editor '.str_pad((string)$i,3,'0',STR_PAD_LEFT),'email'=>'browser-editor'.$i.'@example.invalid','outlet'=>'Browser actual medium'];$nativeRows[]=$nativeContacts->save($data);$managerRows[]=$managerContacts->save($data);}
    $auth=\Kulturhus\Native\Auth\Runtime::service($db);$invite=$auth->createInvitation($access,$bridge->nativeActorId(),'press-management-browser@example.invalid');$auth->acceptInvitation($invite['token'],'Own press contact management browser password 2026!');$token=$auth->passwordLogin('press-management-browser@example.invalid','Own press contact management browser password 2026!','own-press-management-browser')['session_token'];
    \Kulturhus\Native\Shared\Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES('press-management-browser-reader','press-management-browser','member',0)");
    \Kulturhus\Native\Shared\Db::run($db,"INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,website_access,version) VALUES('press-management-browser','press-management-read','Own press contact read role','','none','none',0,?,1)",[json_encode(['press'=>'read'],JSON_THROW_ON_ERROR)]);
    \Kulturhus\Native\Shared\Db::run($db,"INSERT INTO settings_role_assignments(actor_id,club_id,role_id,version) VALUES('press-management-browser-reader','press-management-browser','press-management-read',1)");
    $readerBridge=new \Kulturhus\Native\Integration\ManagerBridge($db,'press-management-browser-reader','native',static fn()=>null);$readerBridge->access();$readerInvite=$auth->createInvitation($access,$readerBridge->nativeActorId(),'press-management-read@example.invalid');$auth->acceptInvitation($readerInvite['token'],'Own history read password 2026!');$readerToken=$auth->passwordLogin('press-management-read@example.invalid','Own history read password 2026!','own-press-management-read')['session_token'];
    \Kulturhus\Native\Shared\Db::run($db,"INSERT IGNORE INTO native_grants VALUES('reader','press','read')");
    $socket=stream_socket_server('tcp://127.0.0.1:0');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
    $file=$root.'/browser.json';$data=['root'=>$root,'origin'=>'http://127.0.0.1:'.$port,'token'=>$token,'actor'=>'admin','manager_actor'=>'press-management-browser-admin','reader_token'=>$readerToken,'reader_actor'=>'reader','reader_manager_actor'=>'press-management-browser-reader','contacts'=>['native'=>array_column($nativeRows,'id'),'integrated'=>array_column($managerRows,'id')]];file_put_contents($file,json_encode($data,JSON_THROW_ON_ERROR));chmod($file,0600);putenv('DVM_PRESS_RECIPIENTS_FIXTURE='.$file);
    $log=fopen($root.'/http.log','wb');$process=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),'-S','127.0.0.1:'.$port,'-t',$root.'/web',dirname(__DIR__).'/support/press-recipients-router.php'],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);if(!is_resource($process))throw new RuntimeException('Own history server unavailable.');fclose($pipes[0]);
    for($i=0;$i<100;$i++){$probe=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1);if($probe){fclose($probe);break;}usleep(50000);}
    return ['process'=>$process,'log'=>$log,'root'=>$root,'input'=>$data];
}
