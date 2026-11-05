<?php declare(strict_types=1);
/** Own scoped CI server and real native/manager sessions, independent of existing development data. */
function ownEditionCatalogueBrowser(PDO $db,string $key): array
{
    $cfg=\Kulturhus\Native\TestSupport::config();$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/edition-catalogue-'.$cfg['database'];
    mkdir($root,0700);foreach(['media','web','sessions','manager-sessions']as$dir)mkdir($root.'/'.$dir,0700);
    file_put_contents($root.'/private.key',$key);chmod($root.'/private.key',0600);file_put_contents($root.'/auth.key',random_bytes(32));chmod($root.'/auth.key',0600);
    putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$root.'/private.key');putenv('KH_NATIVE_AUTH_KEY_FILE='.$root.'/auth.key');
    \Kulturhus\Native\Shared\Db::run($db,"INSERT INTO clubs VALUES('edition-catalogue-browser','Own edition catalogue management browser')");
    \Kulturhus\Native\Shared\Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES('edition-catalogue-browser-admin','edition-catalogue-browser','member',1)");
    $bridge=new \Kulturhus\Native\Integration\ManagerBridge($db,'edition-catalogue-browser-admin','native',static fn()=>null);$access=$bridge->access();$club=$access->club();
    \Kulturhus\Native\Shared\Db::run($db,"INSERT IGNORE INTO native_grants VALUES('admin','press','edit')");
    foreach(['press','newsletter']as$module)foreach(['read','edit','manage']as$capability)\Kulturhus\Native\Shared\Db::run($db,'INSERT IGNORE INTO native_grants VALUES(?,?,?)',['admin',$module,$capability]);
    $pixel=imagecreatetruecolor(8,6);imagefill($pixel,0,0,imagecolorallocate($pixel,31,116,99));imagepng($pixel,$root.'/source.png');imagedestroy($pixel);
    $fixtures=[];
    foreach(['native'=>new \Kulturhus\Native\Shared\Access($db,'admin'),'integrated'=>$access]as$mode=>$actor){
        $mediaDir=$mode==='native'?dirname((string)getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$cfg['database'].'/media':$root.'/media';if(!is_dir($mediaDir))mkdir($mediaDir,0700,true);
        $store=new \Kulturhus\Native\Cms\Media($db,$actor,$mediaDir);$image=$store->upload($root.'/source.png','own-edition-image.png','Actual original edition image')['id'];$other=$store->upload($root.'/source.png','own-unrelated-image.png','Unrelated actual image')['id'];
        foreach(['press','newsletter']as$module){
            $w=new \Kulturhus\Native\Communication\Workspace($db,$actor,$module,$mediaDir);$rows=[];
            foreach(range(0,18)as$i)$rows[]=$w->save(['title'=>'Browser original edition '.str_pad((string)$i,3,'0',STR_PAD_LEFT),'body_html'=>'<p>Actual <strong>original</strong> body 🎶</p>','blocks'=>[['type'=>'heading','text'=>'Actual original heading'],...($i<=1?[['type'=>'image','media_ref'=>$image,'alt'=>'Actual original edition image']]:[])]]);
            $first=$rows[0];foreach(range(1,16)as$i)$w->save(['id'=>$first['id'],'title'=>'Browser later edition '.$i,'body_html'=>'<p>Human later body '.$i.'</p>','blocks'=>[]],$i);
            $approved=$w->approve($rows[2]['id'],1);
            $fixtures[$mode][$module]=['items'=>array_column($rows,'id'),'original'=>$first['id'],'media'=>$image,'unrelated'=>$other,'approved'=>$approved['id']];
        }
    }
    $auth=\Kulturhus\Native\Auth\Runtime::service($db);$invite=$auth->createInvitation($access,$bridge->nativeActorId(),'edition-catalogue-browser@example.invalid');$auth->acceptInvitation($invite['token'],'Own edition catalogue management browser password 2026!');$token=$auth->passwordLogin('edition-catalogue-browser@example.invalid','Own edition catalogue management browser password 2026!','own-edition-catalogue-browser')['session_token'];
    \Kulturhus\Native\Shared\Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES('edition-catalogue-browser-reader','edition-catalogue-browser','member',0)");
    \Kulturhus\Native\Shared\Db::run($db,"INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,website_access,version) VALUES('edition-catalogue-browser','edition-catalogue-read','Own edition catalogue read role','','none','none',0,?,1)",[json_encode(['press'=>'read','newsletter'=>'read'],JSON_THROW_ON_ERROR)]);
    \Kulturhus\Native\Shared\Db::run($db,"INSERT INTO settings_role_assignments(actor_id,club_id,role_id,version) VALUES('edition-catalogue-browser-reader','edition-catalogue-browser','edition-catalogue-read',1)");
    $readerBridge=new \Kulturhus\Native\Integration\ManagerBridge($db,'edition-catalogue-browser-reader','native',static fn()=>null);$readerBridge->access();$readerInvite=$auth->createInvitation($access,$readerBridge->nativeActorId(),'edition-catalogue-read@example.invalid');$auth->acceptInvitation($readerInvite['token'],'Own history read password 2026!');$readerToken=$auth->passwordLogin('edition-catalogue-read@example.invalid','Own history read password 2026!','own-edition-catalogue-read')['session_token'];
    foreach(['press','newsletter']as$module)\Kulturhus\Native\Shared\Db::run($db,"INSERT IGNORE INTO native_grants VALUES('reader',?,'read')",[$module]);\Kulturhus\Native\Shared\Db::run($db,"DELETE FROM native_grants WHERE actor_id='reader' AND module='website'");
    $socket=stream_socket_server('tcp://127.0.0.1:0');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
    $file=$root.'/browser.json';$data=['root'=>$root,'origin'=>'http://127.0.0.1:'.$port,'token'=>$token,'actor'=>'admin','manager_actor'=>'edition-catalogue-browser-admin','reader_token'=>$readerToken,'reader_actor'=>'reader','reader_manager_actor'=>'edition-catalogue-browser-reader','editions'=>$fixtures];file_put_contents($file,json_encode($data,JSON_THROW_ON_ERROR));chmod($file,0600);putenv('DVM_PRESS_RECIPIENTS_FIXTURE='.$file);
    $log=fopen($root.'/http.log','wb');$process=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),'-S','127.0.0.1:'.$port,'-t',$root.'/web',dirname(__DIR__).'/support/press-recipients-router.php'],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);if(!is_resource($process))throw new RuntimeException('Own history server unavailable.');fclose($pipes[0]);
    for($i=0;$i<100;$i++){$probe=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1);if($probe){fclose($probe);break;}usleep(50000);}
    return ['process'=>$process,'log'=>$log,'root'=>$root,'input'=>$data];
}
