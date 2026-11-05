<?php declare(strict_types=1);
/** Fresh personally authenticated editorial roles; never uses development data. */
function ownEditorialBrowserFixture(PDO $db):array
{
    $root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/editorial-workflow-'.Kulturhus\Native\TestSupport::config()['database'];mkdir($root,0700);foreach(['web','media','manager-sessions']as$dir)mkdir($root.'/'.$dir,0700);
    foreach(['auth','private']as$name){file_put_contents($root.'/'.$name.'.key',random_bytes(32));chmod($root.'/'.$name.'.key',0600);}putenv('KH_NATIVE_AUTH_KEY_FILE='.$root.'/auth.key');putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$root.'/private.key');
    $club='own-editorial-workflow';Kulturhus\Native\Shared\Db::run($db,'INSERT INTO clubs VALUES(?,?)',[$club,'Own editorial website']);
    $actors=[];foreach(['admin','editor','reader','member']as$name){$actors[$name]='editorial-'.$name;Kulturhus\Native\Shared\Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES(?,?,'member',?)",[$actors[$name],$club,(int)($name==='admin')]);}
    $adminBridge=new Kulturhus\Native\Integration\ManagerBridge($db,$actors['admin'],'native',static fn()=>null);$admin=$adminBridge->access();$settings=new Kulturhus\Verein\Settings($db,$actors['admin']);$settings->save('club',array_replace(Kulturhus\Verein\SettingsSchema::defaults('club'),['name'=>'Own editorial website','street'=>'Own fixture street 1','postcode'=>'00000','city'=>'Own fixture city']),0);
    foreach(['editor'=>'write','reader'=>'read']as$name=>$level){$role=$settings->saveRole('',0,['name'=>'Own editorial '.$name,'description'=>'Own isolated complete editorial rights','members_access'=>'none','finance_access'=>'none','settings_access'=>false,'website_custom'=>true,'website_levels'=>array_fill_keys(array_keys(Kulturhus\Verein\EditorialPolicy::AREAS),$level)]);$settings->assignRole($actors[$name],$role,0);}
    $auth=Kulturhus\Native\Auth\Runtime::service($db);$accounts=[];
    foreach($actors as$name=>$actor){$bridge=new Kulturhus\Native\Integration\ManagerBridge($db,$actor,'native',static fn()=>null);$bridge->access();$email='workflow-'.$name.'@example.invalid';$invite=$auth->createInvitation($admin,$bridge->nativeActorId(),$email);$auth->acceptInvitation($invite['token'],'Own isolated workflow password 2026!');$accounts[$name]=['role'=>$name,'actor'=>$actor,'token'=>$auth->passwordLogin($email,'Own isolated workflow password 2026!','own-workflow-'.$name)['session_token']];}
    $reader=new Kulturhus\Native\Integration\ManagerBridge($db,$actors['reader'],'native',static fn()=>null);$reader->access();
    $socket=stream_socket_server('tcp://127.0.0.1:0');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
    return ['root'=>$root,'origin'=>'http://127.0.0.1:'.$port,'club'=>$club,'public_actor'=>$reader->nativeActorId(),'accounts'=>$accounts,'admin'=>$admin];
}

function ownEditorialBrowserStart(array $fixture,array $data):array
{
    $file=$fixture['root'].'/fixture.json';unset($fixture['admin']);$fixture['data']=$data;file_put_contents($file,json_encode($fixture,JSON_THROW_ON_ERROR));chmod($file,0600);putenv('DVM_EDITORIAL_WORKFLOW_FIXTURE='.$file);
    $log=fopen($fixture['root'].'/http.log','ab');$process=proc_open([...Kulturhus\Native\TestSupport::phpCommand(),'-S','127.0.0.1:'.parse_url($fixture['origin'],PHP_URL_PORT),'-t',$fixture['root'].'/web',__DIR__.'/editorial-browser-router.php'],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);if(!is_resource($process))throw new RuntimeException('Own editorial workflow server unavailable.');fclose($pipes[0]);
    for($i=0;$i<100;$i++){$probe=@stream_socket_client('tcp://127.0.0.1:'.parse_url($fixture['origin'],PHP_URL_PORT),$errno,$error,.1);if($probe){fclose($probe);break;}usleep(50000);}
    return ['process'=>$process,'log'=>$log,'fixture'=>$fixture];
}
