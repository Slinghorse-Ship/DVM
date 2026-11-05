<?php declare(strict_types=1);
/** Current owned manager account, original tariffs and a real isolated controller. */
function ownCatalogPricingBrowser(PDO $db,array $data):array
{
    $cfg=\Kulturhus\Native\TestSupport::config();$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/catalog-pricing-browser-'.$cfg['database'];mkdir($root,0700);foreach(['web','media','manager-sessions']as$dir)mkdir($root.'/'.$dir,0700);
    foreach(['auth','private']as$name){file_put_contents($root.'/'.$name.'.key',random_bytes(32));chmod($root.'/'.$name.'.key',0600);}putenv('KH_NATIVE_AUTH_KEY_FILE='.$root.'/auth.key');putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$root.'/private.key');
    $auth=\Kulturhus\Native\Auth\Runtime::service($db);$accounts=[];
    \Kulturhus\Native\Shared\Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES('own-price-reader','own-catalog-a','members',0),('own-price-finance-only','own-catalog-a','member',0)");
    foreach(['reader'=>['read','read'],'finance-only'=>['none','write']]as$name=>$grants){
        $role='own-price-'.$name;\Kulturhus\Native\Shared\Db::run($db,'INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,version) VALUES(?,?,?,?,?,?,0,1)',['own-catalog-a',$role,'Own '.$name,'',$grants[0],$grants[1]]);\Kulturhus\Native\Shared\Db::run($db,'INSERT INTO settings_role_assignments VALUES(?,?,?,1)',[$role,'own-catalog-a',$role]);
    }
    foreach(['admin'=>'own-price-admin','reader'=>'own-price-reader','finance-only'=>'own-price-finance-only']as$name=>$actor){$bridge=new \Kulturhus\Native\Integration\ManagerBridge($db,$actor,'native',static fn()=>null);$access=$bridge->access();$invite=$auth->createInvitation((new \Kulturhus\Native\Integration\ManagerBridge($db,'own-price-admin','native',static fn()=>null))->access(),$bridge->nativeActorId(),$actor.'@example.invalid');$auth->acceptInvitation($invite['token'],'Own catalog browser password 2026!');$token=$auth->passwordLogin($actor.'@example.invalid','Own catalog browser password 2026!',$actor)['session_token'];$accounts[$name]=['actor'=>$actor,'token'=>$token];}
    $socket=stream_socket_server('tcp://127.0.0.1:0');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
    $input=['root'=>$root,'origin'=>'http://127.0.0.1:'.$port,'accounts'=>$accounts,'data'=>$data];$file=$root.'/browser.json';file_put_contents($file,json_encode($input,JSON_THROW_ON_ERROR));chmod($file,0600);putenv('DVM_CATALOG_PRICING_FIXTURE='.$file);
    $log=fopen($root.'/http.log','wb');$process=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),...(in_array($cfg['label']??'',['native-admission-choices','native-invoice-intake-review','native-invoice-intake-catalogue','native-documents-detail','native-document-library','native-contact-choices','native-calendar-people','native-calendar-recipients'],true)?['-d','memory_limit=64M']:[]),'-S','127.0.0.1:'.$port,'-t',$root.'/web',__DIR__.'/catalog-pricing-browser-router.php'],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);if(!is_resource($process))throw new RuntimeException('Own pricing server unavailable.');fclose($pipes[0]);for($i=0;$i<100;$i++){$probe=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1);if($probe){fclose($probe);break;}usleep(50000);}
    return['process'=>$process,'log'=>$log,'root'=>$root,'input'=>$input];
}
