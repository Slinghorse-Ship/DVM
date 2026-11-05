<?php declare(strict_types=1);
/** Own scoped CI server and real native/manager sessions, independent of existing development data. */
function ownCommunicationTemplatesBrowser(PDO $db,string $key): array
{
    $cfg=\Kulturhus\Native\TestSupport::config();$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/communication-templates-'.$cfg['database'];
    mkdir($root,0700);foreach(['media','web','sessions','manager-sessions']as$dir)mkdir($root.'/'.$dir,0700);
    file_put_contents($root.'/private.key',$key);chmod($root.'/private.key',0600);file_put_contents($root.'/auth.key',random_bytes(32));chmod($root.'/auth.key',0600);
    putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$root.'/private.key');putenv('KH_NATIVE_AUTH_KEY_FILE='.$root.'/auth.key');
    \Kulturhus\Native\Shared\Db::run($db,"INSERT INTO clubs VALUES('communication-templates-browser','Own communication template management browser')");
    \Kulturhus\Native\Shared\Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES('communication-templates-browser-admin','communication-templates-browser','member',1)");
    $bridge=new \Kulturhus\Native\Integration\ManagerBridge($db,'communication-templates-browser-admin','native',static fn()=>null);$access=$bridge->access();$club=$access->club();
    \Kulturhus\Native\Shared\Db::run($db,"INSERT IGNORE INTO native_grants VALUES('admin','press','edit')");
    foreach(['press','newsletter']as$module)foreach(['read','edit','manage']as$capability)\Kulturhus\Native\Shared\Db::run($db,'INSERT IGNORE INTO native_grants VALUES(?,?,?)',['admin',$module,$capability]);
    $fixtures=[];$usability=[];
    foreach(['native'=>new \Kulturhus\Native\Shared\Access($db,'admin'),'integrated'=>$access]as$mode=>$actor)foreach(['press','newsletter']as$module){
        $w=new \Kulturhus\Native\Communication\Workspace($db,$actor,$module,$root.'/media');$rows=[];
        foreach(range(0,14)as$i)$rows[]=$w->saveTemplate(['name'=>'Browser original template '.str_pad((string)$i,3,'0',STR_PAD_LEFT),'title'=>'Actual original template subject','body_html'=>'<p>Actual <strong>original</strong> body 🎶</p>','blocks'=>[['type'=>'text','text'=>'First original human block'],['type'=>'text','text'=>'Second original human block']]]);
        $fixtures[$mode][$module]=array_column($rows,'id');$usability[$mode][$module]=$w->saveTemplate(['name'=>'Visual block example','title'=>'Rich text example','body'=>'Body','blocks'=>[['type'=>'richtext','html'=>'<p>Original <strong>visual</strong> block</p>']]])['id'];
    }
    \Kulturhus\Native\Shared\Db::run($db,"INSERT IGNORE INTO native_grants VALUES('admin','social','read')");
    $auth=\Kulturhus\Native\Auth\Runtime::service($db);$invite=$auth->createInvitation($access,$bridge->nativeActorId(),'communication-templates-browser@example.invalid');$auth->acceptInvitation($invite['token'],'Own communication template management browser password 2026!');$token=$auth->passwordLogin('communication-templates-browser@example.invalid','Own communication template management browser password 2026!','own-communication-templates-browser')['session_token'];
    \Kulturhus\Native\Shared\Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES('communication-templates-browser-reader','communication-templates-browser','member',0)");
    \Kulturhus\Native\Shared\Db::run($db,"INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,website_access,version) VALUES('communication-templates-browser','communication-templates-read','Own communication template read role','','none','none',0,?,1)",[json_encode(['press'=>'read','newsletter'=>'read'],JSON_THROW_ON_ERROR)]);
    \Kulturhus\Native\Shared\Db::run($db,"INSERT INTO settings_role_assignments(actor_id,club_id,role_id,version) VALUES('communication-templates-browser-reader','communication-templates-browser','communication-templates-read',1)");
    $readerBridge=new \Kulturhus\Native\Integration\ManagerBridge($db,'communication-templates-browser-reader','native',static fn()=>null);$readerBridge->access();$readerInvite=$auth->createInvitation($access,$readerBridge->nativeActorId(),'communication-templates-read@example.invalid');$auth->acceptInvitation($readerInvite['token'],'Own history read password 2026!');$readerToken=$auth->passwordLogin('communication-templates-read@example.invalid','Own history read password 2026!','own-communication-templates-read')['session_token'];
    foreach(['press','newsletter']as$module)\Kulturhus\Native\Shared\Db::run($db,"INSERT IGNORE INTO native_grants VALUES('reader',?,'read')",[$module]);
    $socket=stream_socket_server('tcp://127.0.0.1:0');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
    $file=$root.'/browser.json';$data=['root'=>$root,'origin'=>'http://127.0.0.1:'.$port,'token'=>$token,'actor'=>'admin','manager_actor'=>'communication-templates-browser-admin','reader_token'=>$readerToken,'reader_actor'=>'reader','reader_manager_actor'=>'communication-templates-browser-reader','templates'=>$fixtures,'usability'=>$usability];file_put_contents($file,json_encode($data,JSON_THROW_ON_ERROR));chmod($file,0600);putenv('DVM_PRESS_RECIPIENTS_FIXTURE='.$file);
    $log=fopen($root.'/http.log','wb');$process=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),'-S','127.0.0.1:'.$port,'-t',$root.'/web',dirname(__DIR__).'/support/press-recipients-router.php'],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);if(!is_resource($process))throw new RuntimeException('Own history server unavailable.');fclose($pipes[0]);
    for($i=0;$i<100;$i++){$probe=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1);if($probe){fclose($probe);break;}usleep(50000);}
    return ['process'=>$process,'log'=>$log,'root'=>$root,'input'=>$data];
}
