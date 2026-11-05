<?php declare(strict_types=1);
/** Owned, isolated production-mode HTTP fixture. No synthetic production guard bypass. */
function balanceDayBrowser(PDO $db):void{
 $cfg=\Kulturhus\Native\TestSupport::config();
 if(($cfg['label']??'')!=='native-balance-day-allocation'||($cfg['owner']??'')!=='isolated-native-ci')throw new RuntimeException('Owned day fixture required.');
 $root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/day-browser-'.$cfg['database'];mkdir($root,0700);
 foreach(['web','sessions','media']as$dir)mkdir($root.'/'.$dir,0700);
 foreach(['auth','private']as$name){file_put_contents($root.'/'.$name.'.key',random_bytes(32));chmod($root.'/'.$name.'.key',0600);}
 file_put_contents($root.'/features.json','{"periods":true}');chmod($root.'/features.json',0600);
 $db->exec("INSERT INTO clubs VALUES('owned-day-interface','Owned private daily closing UI');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('owned-day-interface-admin','owned-day-interface','treasurer',1)");
 $settings=array_replace(\Kulturhus\Verein\SettingsSchema::defaults('tax'),['accounting_basis'=>'balance','fiscal_start'=>'2025-01-01']);
 $db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES('owned-day-interface','tax',?,1,UTC_TIMESTAMP())")->execute([json_encode($settings,JSON_THROW_ON_ERROR)]);
 putenv('KH_NATIVE_AUTH_KEY_FILE='.$root.'/auth.key');putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$root.'/private.key');
 $actor='owned-day-interface-admin';$access=(new \Kulturhus\Native\Integration\ManagerBridge($db,$actor,'native',static fn()=>null))->access();$auth=\Kulturhus\Native\Auth\Runtime::service($db);
 $email='owned-day-interface@example.invalid';$password='Own isolated internal daily browser password 2026!';$invite=$auth->createInvitation($access,\Kulturhus\Native\Integration\ManagerBridge::actorKey($actor,'owned-day-interface'),$email);$auth->acceptInvitation($invite['token'],$password);
 $fixture=['root'=>$root,'club'=>'owned-day-interface','origins'=>[]];
 foreach(['front','direct']as$app){$socket=stream_socket_server('tcp://127.0.0.1:0');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);$fixture['origins'][$app]='http://localhost:'.$port;}
 $file=$root.'/fixture.json';file_put_contents($file,json_encode($fixture,JSON_THROW_ON_ERROR));chmod($file,0600);putenv('DVM_BALANCE_PRODUCTION_FIXTURE='.$file);$servers=[];
 try{
  foreach($fixture['origins']as$app=>$origin){
   $port=parse_url($origin,PHP_URL_PORT);$log=fopen($root.'/'.$app.'.log','wb');$process=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),'-S','127.0.0.1:'.$port,'-t',$root.'/web',__DIR__.'/balance-period-production-controller.php'],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);
   if(!is_resource($process))throw new RuntimeException('Owned production controller unavailable.');fclose($pipes[0]);$servers[]=[$process,$log];$ready=false;
   for($i=0;$i<100;$i++){$probe=@stream_socket_client('tcp://127.0.0.1:'.$port,$err,$message,.1);if($probe){fclose($probe);$ready=true;break;}usleep(20000);}if(!$ready)throw new RuntimeException('Owned controller did not start.');
  }
  $process=proc_open(['node',dirname(__DIR__).'/balance-day-allocation-browser.mjs'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes);if(!is_resource($process))throw new RuntimeException('Browser unavailable.');fwrite($pipes[0],json_encode($fixture+['email'=>$email,'password'=>$password],JSON_THROW_ON_ERROR));fclose($pipes[0]);if(proc_close($process)!==0)throw new RuntimeException('Actual internal day browser failed.');
 }finally{foreach($servers as[$process,$log]){proc_terminate($process);proc_close($process);fclose($log);}}
}
