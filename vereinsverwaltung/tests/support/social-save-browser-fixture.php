<?php declare(strict_types=1);
/** Owned local forms only; no provider connection or publishing endpoint is exposed. */
function ownSocialSaveBrowser(PDO $db):array
{
    $cfg=Kulturhus\Native\TestSupport::config();
    if(($cfg['label']??'')!=='native-social-save-requests'||($cfg['owner']??'')!=='isolated-native-ci')throw new RuntimeException('Own social browser context required.');
    $root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$cfg['database'];mkdir($root,0700);
    foreach(['web','media','sessions','manager-sessions']as$dir)mkdir($root.'/'.$dir,0700);
    foreach(['auth','private']as$name){file_put_contents($root.'/'.$name.'.key',random_bytes(32));chmod($root.'/'.$name.'.key',0600);}
    putenv('KH_NATIVE_AUTH_KEY_FILE='.$root.'/auth.key');putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$root.'/private.key');
    $nativeClub=(new Kulturhus\Native\Shared\Access($db,'admin'))->club();
    Kulturhus\Native\Shared\Db::run($db,'INSERT INTO native_users(id,club_id,name) VALUES(?,?,?)',['own-social-native-en',$nativeClub,'Own native English editor']);
    $db->exec("INSERT INTO native_grants(actor_id,module,capability) SELECT 'own-social-native-en',module,capability FROM native_grants WHERE actor_id='admin'");
    $actors=['de'=>'own-social-browser-de','en'=>'own-social-browser-en'];$tokens=[];$managerIds=[];
    Kulturhus\Native\Shared\Db::run($db,"INSERT INTO clubs(id,name) VALUES('own-social-browser','Own social save browser')");
    $authority=null;
    foreach($actors as$locale=>$actor){
        Kulturhus\Native\Shared\Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES(?,'own-social-browser','member',1)",[$actor]);
        $bridge=new Kulturhus\Native\Integration\ManagerBridge($db,$actor,'native',static fn()=>null);$access=$bridge->access();$authority??=$access;
        $settings=new Kulturhus\Verein\Settings($db,$actor);if($locale==='de')$settings->save('club',array_replace(Kulturhus\Verein\SettingsSchema::defaults('club'),['name'=>'Own social save browser','street'=>'Own fixture street','postcode'=>'00000','city'=>'Own fixture city']),0);
        $contact=(new Kulturhus\Verein\Registry($db,$actor))->saveContact(null,0,'person','Own original editor '.$locale,'own-social-'.$locale.'@example.invalid','Own original fixture address');
        Kulturhus\Native\Shared\Db::run($db,'UPDATE actors SET contact_id=? WHERE id=?',[$contact,$actor]);
        $access=$bridge->access();(new Kulturhus\Native\Language\Catalog($db,$access))->select($locale);$managerIds[$locale]=$access->user()['id'];
        $auth=Kulturhus\Native\Auth\Runtime::service($db);$email='own-social-save-'.$locale.'@example.invalid';$invitation=$auth->createInvitation($authority,$bridge->nativeActorId(),$email);$auth->acceptInvitation($invitation['token'],'Own social save browser password 2026!');$tokens[$locale]=$auth->passwordLogin($email,'Own social save browser password 2026!','own-social-save-'.$locale)['session_token'];
    }
    $nativeActors=['de'=>'admin','en'=>'own-social-native-en'];
    foreach($nativeActors as$locale=>$actor)(new Kulturhus\Native\Language\Catalog($db,new Kulturhus\Native\Shared\Access($db,$actor)))->select($locale);
    Kulturhus\Verein\Database::ensureContributionRates($db);
    $db->exec("CREATE TRIGGER own_social_browser_abort BEFORE INSERT ON khn_social_items FOR EACH ROW BEGIN IF JSON_UNQUOTE(JSON_EXTRACT(NEW.payload,'$.title'))='0 Own deliberate social storage failure' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Own social storage failure'; END IF; END");
    $socket=stream_socket_server('tcp://127.0.0.1:0');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
    $data=['root'=>$root,'origin'=>'http://127.0.0.1:'.$port,'native_actors'=>$nativeActors,'manager_actors'=>$actors,'manager_ids'=>$managerIds,'tokens'=>$tokens];
    $file=$root.'/fixture.json';file_put_contents($file,json_encode($data,JSON_THROW_ON_ERROR));chmod($file,0600);putenv('DVM_SOCIAL_SAVE_FIXTURE='.$file);
    $log=fopen($root.'/http.log','ab');$process=proc_open([...Kulturhus\Native\TestSupport::phpCommand(),'-S','127.0.0.1:'.$port,'-t',$root.'/web',__DIR__.'/social-save-browser-router.php'],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);if(!is_resource($process))throw new RuntimeException('Own social server unavailable.');fclose($pipes[0]);
    for($i=0;$i<100;$i++){$probe=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1);if($probe){fclose($probe);break;}usleep(50000);}
    return ['process'=>$process,'log'=>$log,'root'=>$root,'input'=>$data];
}
