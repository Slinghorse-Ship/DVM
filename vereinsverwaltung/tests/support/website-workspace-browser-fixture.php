<?php declare(strict_types=1);
/** Own native and integrated GET-only acceptance; no development account or provider is used. */
function ownWebsiteWorkspaceBrowser(PDO $db):array
{
    require_once dirname(__DIR__,2).'/src/bootstrap.php';
    require_once __DIR__.'/editorial-browser-fixture.php';
    require_once __DIR__.'/original-database-hash.php';
    $cfg=\Kulturhus\Native\TestSupport::config();
    if(PHP_SAPI!=='cli'||getenv('KH_NATIVE_CI')!=='1'||($cfg['owner']??null)!=='isolated-native-ci'||($cfg['label']??null)!=='native-website-workspace')throw new RuntimeException('Own isolated website workspace suite required.');
    \Kulturhus\Native\Installer\SchemaRegistry::install($db);
    $fixture=ownEditorialBrowserFixture($db);$nativeRoot=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$cfg['database'];
    foreach(['','/web','/media','/sessions']as$part)if(!is_dir($nativeRoot.$part)&&!mkdir($nativeRoot.$part,0700))throw new RuntimeException('Own native workspace directory unavailable.');
    copy($fixture['root'].'/auth.key',$nativeRoot.'/auth.key');chmod($nativeRoot.'/auth.key',0600);
    \Kulturhus\Native\Shared\Db::run($db,"INSERT IGNORE INTO native_grants VALUES('admin','accounts','manage')");
    \Kulturhus\Native\Shared\Db::run($db,'INSERT IGNORE INTO clubs(id,name) VALUES(?,?)',['synthetic-a','Own native public membership']);
    $auth=\Kulturhus\Native\Auth\Runtime::service($db);$email='own-workspace-native@example.invalid';
    $invite=$auth->createInvitation(new \Kulturhus\Native\Shared\Access($db,'admin'),'admin',$email);
    $auth->acceptInvitation($invite['token'],'Own workspace isolated password 2026!');
    $nativeToken=$auth->passwordLogin($email,'Own workspace isolated password 2026!','own-workspace-browser')['session_token'];
    $intake=new \Kulturhus\Verein\MembershipApplications($db,$fixture['club'],$fixture['accounts']['admin']['actor']);$configuration=$intake->settings()['config'];$configuration['enabled']=true;$intake->saveSettings($configuration,0);
    $titles=[];
    foreach(['native'=>new \Kulturhus\Native\Shared\Access($db,'admin'),'integrated'=>$fixture['admin']]as$app=>$access){
        $pages=new \Kulturhus\Native\Cms\Pages($db,$access);
        $p=$pages->save(['title'=>'Own public membership '.$app,'slug'=>'mitglied-werden','body'=>'Own unchanged membership introduction.','nav_visible'=>true]);$pages->publish($p['id'],$p['version']);
        $ordinary=$pages->save(['title'=>'Own ordinary public page '.$app,'slug'=>'own-workspace-public','body'=>'Original public content without a session.','nav_visible'=>false]);$pages->publish($ordinary['id'],$ordinary['version']);
        $title='Ursprünglich – Human <&> Abbrechen '.str_repeat('LangesOriginal',6).' '.$app;
        $p=$pages->save(['title'=>$title,'slug'=>'own-workspace-original','body_html'=>'<p>Original human text: Speichern &amp; Abbrechen.</p>','nav_visible'=>false]);$titles[$app]=$title;
    }
    $origins=[];foreach(['native','integrated']as$app){$socket=stream_socket_server('tcp://127.0.0.1:0');if(!$socket)throw new RuntimeException('Own loopback port unavailable.');$origins[$app]='http://'.stream_socket_get_name($socket,false);fclose($socket);}
    $runRoot=$fixture['root'].'/workspace-browser';mkdir($runRoot,0700);$file=$runRoot.'/fixture.json';
    $data=['origins'=>$origins,'native_root'=>$nativeRoot,'integrated_root'=>$fixture['root'],'private_key'=>$fixture['root'].'/private.key','native_token'=>$nativeToken,'integrated_account'=>$fixture['accounts']['admin'],'manager_club'=>$fixture['club'],'public_actor'=>$fixture['public_actor'],'titles'=>$titles,'result_file'=>$runRoot.'/result.json'];
    file_put_contents($file,json_encode($data,JSON_THROW_ON_ERROR));chmod($file,0600);putenv('DVM_WEBSITE_WORKSPACE_FIXTURE='.$file);
    $tables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    // Real personal requests renew technical session clocks. Every other persisted row is exact.
    $originals=array_values(array_diff($tables,['native_auth_sessions']));$before=ownOriginalDatabaseHash($db,$originals);
    $keys=[];foreach([$nativeRoot.'/auth.key',$fixture['root'].'/auth.key',$fixture['root'].'/private.key']as$p)$keys[$p]=hash_file('sha256',$p);
    $servers=[];$logs=[];
    try{
        foreach($origins as$app=>$origin){
            $log=fopen($runRoot.'/'.$app.'.http.log','ab');$logs[]=$log;
            $environment=getenv();$environment['DVM_WEBSITE_WORKSPACE_APP']=$app;
            $process=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),'-S','127.0.0.1:'.parse_url($origin,PHP_URL_PORT),'-t',$app==='native'?$nativeRoot.'/web':$fixture['root'].'/web',__DIR__.'/website-workspace-browser-router.php'],[0=>['pipe','r'],1=>$log,2=>$log],$pipes,null,$environment);
            if(!is_resource($process))throw new RuntimeException('Own workspace browser server unavailable.');fclose($pipes[0]);$servers[]=$process;
            $ready=false;for($i=0;$i<100;$i++){$probe=@stream_socket_client('tcp://127.0.0.1:'.parse_url($origin,PHP_URL_PORT),$errno,$error,.1);if($probe){fclose($probe);$ready=true;break;}usleep(50000);}if(!$ready)throw new RuntimeException('Own workspace server not ready.');
        }
        $httpChecks=0;$assertHttp=static function(bool $ok,string $label)use(&$httpChecks):void{if(!$ok)throw new RuntimeException($label);++$httpChecks;};
        $request=static function(string $method,string $path,array $post=[],string $cookie='')use($origins):array{
            $headers=['Origin: '.$origins['integrated']];if($cookie!=='')$headers[]='Cookie: '.$cookie;
            if($method==='POST')$headers[]='Content-Type: application/x-www-form-urlencoded';
            $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$method==='POST'?http_build_query($post):'','ignore_errors'=>true,'follow_location'=>0,'timeout'=>20]]);
            $body=file_get_contents($origins['integrated'].$path,false,$context);$response=$http_response_header??[];
            if(!is_string($body)||!preg_match('~^HTTP/\S+ ([0-9]{3})~',$response[0]??'',$match))throw new RuntimeException('Own public form HTTP response unavailable.');
            $cookies=[];foreach($response as$header)if(str_starts_with(strtolower($header),'set-cookie:'))$cookies[]=trim(substr($header,11));
            return ['status'=>(int)$match[1],'body'=>$body,'cookies'=>$cookies,'headers'=>$response];
        };
        $sessionFiles=static fn():array=>glob($fixture['root'].'/manager-sessions/sess_*')?:[];
        $beforeSessions=$sessionFiles();$head=$request('HEAD','/mitglied-werden/');
        $assertHttp($head['status']===200&&$head['body']===''&&$head['cookies']===[]&&$sessionFiles()===$beforeSessions,'Anonymous membership HEAD is successful without opening a session or issuing a cookie.');
        $ordinary=$request('GET','/site/own-workspace-public/');
        $assertHttp($ordinary['status']===200&&str_contains($ordinary['body'],'Original public content without a session.')&&$ordinary['cookies']===[]&&$sessionFiles()===$beforeSessions,'Ordinary published GET stays free of membership sessions.');
        $form=$request('GET','/mitglied-werden/');$cookie='';foreach($form['cookies']as$c)if(preg_match('/^KH_NATIVE_MANAGER=([^;]+)/',$c,$m))$cookie='KH_NATIVE_MANAGER='.$m[1];
        $assertHttp($form['status']===200&&$cookie!==''&&count($sessionFiles())===count($beforeSessions)+1,'Anonymous membership GET creates the genuine private PHP form session.');
        $document=new DOMDocument();@$document->loadHTML($form['body'],LIBXML_NONET);$xpath=new DOMXPath($document);$fields=[];foreach(['csrf','request_key','configuration_version']as$key)$fields[$key]=$xpath->query('//form[contains(@class,"kh-membership-form")]//input[@name="'.$key.'"]')->item(0)?->getAttribute('value')??'';
        $assertHttp(strlen($fields['csrf'])===64&&preg_match('/^[a-f0-9]{32}$/D',$fields['request_key'])===1&&$fields['configuration_version']==='1','The actual public form carries a real session CSRF, fresh request identity and original configuration version.');
        $missing=$request('POST','/mitglied-werden/',[],$cookie);$wrong=$request('POST','/mitglied-werden/',['csrf'=>str_repeat('0',64)],$cookie);
        $assertHttp($missing['status']===403&&$wrong['status']===403,'Missing and wrong public form CSRF are both rejected.');
        $invalid=$request('POST','/mitglied-werden/',$fields+['first_name'=>'Unchanged own correction value'],$cookie);
        $assertHttp($invalid['status']===200&&str_contains($invalid['body'],'Unchanged own correction value')&&!str_contains($invalid['body'],'application-received'),'A genuine CSRF reaches required-field validation and preserves input without creating an application.');
        $assertHttp(count($sessionFiles())===count($beforeSessions)+1,'Rejected public POSTs retain the same genuine private form session.');
        $head=$request('HEAD','/mitglied-werden/');$assertHttp($head['status']===200&&$head['cookies']===[]&&count($sessionFiles())===count($beforeSessions)+1,'Later anonymous HEAD still does not create or replace a private form session.');
        $node=proc_open(['node',dirname(__DIR__).'/native-website-workspace-browser.mjs'],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes);
        if(!is_resource($node)||proc_close($node)!==0)throw new RuntimeException('Own actual website workspace browser failed.');
        $result=json_decode(file_get_contents($data['result_file']),true,64,JSON_THROW_ON_ERROR);
        if(($result['views']??0)!==64||($result['catalogue_views']??0)!==12||($result['business_posts']??-1)!==0)throw new RuntimeException('Incomplete own workspace acceptance.');
        if(!hash_equals($before,ownOriginalDatabaseHash($db,$originals)))throw new RuntimeException('Original human or other business rows changed during GET-only workspace review.');
        foreach($keys as$p=>$hash)if(!hash_equals($hash,hash_file('sha256',$p)))throw new RuntimeException('Original own authentication/media key changed.');
        return $result+['public_session_http_checks'=>$httpChecks,'rejected_public_form_posts'=>3,'business_row_changes'=>0,'original_tables_exact'=>count($originals),'technical_clock_table'=>'native_auth_sessions','original_keys_exact'=>count($keys)];
    }finally{foreach($servers as$process){proc_terminate($process);proc_close($process);}foreach($logs as$log)fclose($log);}
}
