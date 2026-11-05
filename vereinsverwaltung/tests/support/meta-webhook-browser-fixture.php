<?php declare(strict_types=1);
/** Isolated accounts, original signed notifications and both real controllers. */
function ownMetaWebhookBrowser(PDO $db,string $key):array
{
    require_once dirname(__DIR__,2).'/src/bootstrap.php';
    $cfg=\Kulturhus\Native\TestSupport::config();
    $root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/meta-webhook-browser-'.$cfg['database'];
    mkdir($root,0700);foreach(['media','web','sessions','manager-sessions']as$dir)mkdir($root.'/'.$dir,0700);
    file_put_contents($root.'/private.key',$key);chmod($root.'/private.key',0600);
    file_put_contents($root.'/auth.key',random_bytes(32));chmod($root.'/auth.key',0600);
    putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$root.'/private.key');putenv('KH_NATIVE_AUTH_KEY_FILE='.$root.'/auth.key');
    $box=new \Kulturhus\Native\Shared\SecretBox($key);
    \Kulturhus\Native\Shared\Db::run($db,"INSERT INTO clubs VALUES('own-meta-webhook-browser','Own Meta webhook browser')");
    \Kulturhus\Native\Shared\Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES('own-meta-webhook-browser-admin','own-meta-webhook-browser','member',1)");
    $bridge=new \Kulturhus\Native\Integration\ManagerBridge($db,'own-meta-webhook-browser-admin','native',static fn()=>null);
    $manager=$bridge->access();$auth=\Kulturhus\Native\Auth\Runtime::service($db);
    $invite=$auth->createInvitation($manager,$bridge->nativeActorId(),'own-webhook-browser@example.invalid');
    $auth->acceptInvitation($invite['token'],'Own synthetic webhook browser password 2026!');
    $token=$auth->passwordLogin('own-webhook-browser@example.invalid','Own synthetic webhook browser password 2026!','own-webhook-browser')['session_token'];
    $socket=stream_socket_server('tcp://127.0.0.1:0');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
    $origin='http://127.0.0.1:'.$port;$accounts=[];
    foreach(['native'=>new \Kulturhus\Native\Shared\Access($db,'admin'),'integrated'=>$manager]as$mode=>$access){
        $identity=new \Kulturhus\Native\Shared\Identity($db,$access);
        $profile=$identity->source();
        $identity->save(['name'=>'Own webhook browser '.$mode,'origin'=>'https://example.invalid','logo_id'=>'','address'=>'','club_version'=>$profile['version']??0],$identity->get()['version']);
        (new \Kulturhus\Native\Integration\Accounts($db,$box))->save($access,'meta',['page_id'=>'123','instagram_id'=>'456','token'=>'own-browser-token-never-live','api_version'=>'v24.0'],0);
        $secret=str_repeat($mode==='native'?'c':'d',32);$verify='own-browser-verification-token-'.$mode;
        $configuration=new \Kulturhus\Native\Communication\MetaWebhookConfiguration($db,$box);
        $configuration->save($access,['enabled'=>true,'app_secret'=>$secret,'verify_token'=>$verify],0);
        $callback=$configuration->row($access->club())['callback_id'];$receipts=[];
        for($i=0;$i<13;++$i){
            $channel=$i%2?'instagram':'page';$owner=$i%2?'456':'123';
            $body=json_encode(['object'=>$channel,'entry'=>[['id'=>$owner,'messaging'=>[['sender'=>['id'=>'789'],'recipient'=>['id'=>$owner],'timestamp'=>1750000000000+$i,'message'=>['mid'=>'own_browser_'.$i,'text'=>'Never use raw notification as human original']]]]]],JSON_THROW_ON_ERROR);
            (new \Kulturhus\Native\Communication\MetaWebhookInbox($db,$box))->receive($access->club(),$callback,$body,'sha256='.hash_hmac('sha256',$body,$secret));
        }
        $rows=\Kulturhus\Native\Shared\Db::run($db,'SELECT * FROM native_meta_webhook_receipts WHERE club_id=? ORDER BY received_at,id',[$access->club()])->fetchAll();
        $paused=$rows[0]['id'];
        \Kulturhus\Native\Shared\Db::run($db,"UPDATE native_meta_webhook_receipts SET state='paused',reason='receiver_unavailable' WHERE club_id=? AND id=?",[$access->club(),$paused]);
        $accounts[$mode]=['club'=>$access->club(),'actor'=>$access->user()['id'],'manager_actor'=>$mode==='integrated'?$bridge->managerActor():null,'token'=>$mode==='integrated'?$token:null,'callback'=>$callback,'secret'=>$secret,'verify'=>$verify,'paused'=>$paused,'ciphertext'=>$rows[0]['ciphertext']];
    }
    // Suspension must block both incoming notifications and existing worker reads.
    $config=new \Kulturhus\Native\Communication\MetaWebhookConfiguration($db,$box);
    \Kulturhus\Native\Shared\Db::run($db,'UPDATE native_auth_accounts SET suspended=1 WHERE actor_id=?',[$bridge->nativeActorId()]);
    if($config->status($manager)['current'])throw new RuntimeException('Suspended account retained webhook authority.');
    try{(new \Kulturhus\Native\Communication\MetaWebhookInbox($db,$box))->challenge($manager->club(),$accounts['integrated']['callback'],['hub_mode'=>'subscribe','hub_verify_token'=>$accounts['integrated']['verify'],'hub_challenge'=>'1']);throw new RuntimeException('Suspended account still verified incoming notifications.');}catch(DomainException){}
    $reads=0;$states=(new \Kulturhus\Native\Communication\MetaWebhookWorker($db,$box))->run($manager->club(),static function()use(&$reads){$reads++;return true;});
    if($reads!==0||count($states)!==12||array_unique(array_column($states,'state'))!==['paused'])throw new RuntimeException('Suspended account reached a provider read.');
    \Kulturhus\Native\Shared\Db::run($db,'UPDATE native_auth_accounts SET suspended=0 WHERE actor_id=?',[$bridge->nativeActorId()]);
    $data=compact('root','origin','accounts');$data['manager_club']=$bridge->managerClub();
    $public=\Kulturhus\Native\Integration\ProductionSite::publicActor($bridge->managerClub());
    \Kulturhus\Native\Shared\Db::run($db,'INSERT INTO native_users(id,club_id,name) VALUES(?,?,?)',[$public,$manager->club(),'Own public callback actor']);
    \Kulturhus\Native\Shared\Db::run($db,'INSERT INTO native_grants VALUES(?,?,?)',[$public,'website','read']);
    $file=$root.'/browser.json';file_put_contents($file,json_encode($data,JSON_THROW_ON_ERROR));chmod($file,0600);putenv('DVM_META_WEBHOOK_FIXTURE='.$file);
    $log=fopen($root.'/http.log','wb');
    $process=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),'-S','127.0.0.1:'.$port,'-t',$root.'/web',__DIR__.'/meta-webhook-browser-router.php'],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);
    if(!is_resource($process))throw new RuntimeException('Own webhook server unavailable.');fclose($pipes[0]);
    for($i=0;$i<100;$i++){$probe=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1);if($probe){fclose($probe);break;}usleep(50000);}
    return['process'=>$process,'log'=>$log,'root'=>$root,'input'=>$data];
}
