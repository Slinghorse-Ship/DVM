<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Auth\{Schema,Service};

if(getenv('DVM_ACCOUNT_BROWSER')==='1'&&getenv('DVM_ACCOUNT_SESSIONS_BROWSER')==='1')throw new RuntimeException('Independent fresh account groups required for factor and session browser checks.');
$db=TestSupport::db();TestSupport::seed($db);Schema::install($db);
Kulturhus\Native\Cms\Schema::install($db);Kulturhus\Native\Communication\Schema::install($db);
Db::run($db,"INSERT INTO native_grants(actor_id,module,capability) VALUES('admin','accounts','manage')");
$config=TestSupport::config();$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$config['database'];
foreach(['sessions','media','web']as$dir)mkdir($root.'/'.$dir,0700,true);
file_put_contents($root.'/auth.key',random_bytes(32));chmod($root.'/auth.key',0600);
$auth=new Service($db,file_get_contents($root.'/auth.key'));$admin=new Access($db,'admin');
$password=bin2hex(random_bytes(16)).'Aa7!';
$prior=$auth->createInvitation($admin,'reader','prior-original@example.invalid');$auth->acceptInvitation($prior['token'],$password);
$tables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);$keys=[];
foreach($tables as$table){$columns=Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);if(!$columns)throw new RuntimeException('Stable original keys required.');$keys[$table]=['columns'=>$columns,'values'=>Db::run($db,'SELECT `'.implode('`,`',$columns).'` FROM `'.$table.'`')->fetchAll(PDO::FETCH_NUM)];}
$filter=static function(string $table)use($keys):array{$entry=$keys[$table];if(!$entry['values'])return[' WHERE 0=1',[]];$args=[];foreach($entry['values']as$values)array_push($args,...$values);return[' WHERE (`'.implode('`,`',$entry['columns']).'`) IN ('.implode(',',array_fill(0,count($entry['values']),'('.implode(',',array_fill(0,count($entry['columns']),'?')).')')).')',$args];};
$originalHash=ownOriginalDatabaseHash($db,$tables,$filter);$accounts=[];$people=[];$grants=[];
foreach(['http','js','basic']as$mode){
    $actor='own-account-'.$mode.'-'.bin2hex(random_bytes(6));$alias=$actor;
    Db::run($db,"INSERT INTO native_users(id,club_id,name) VALUES(?,'synthetic-a',?)",[$actor,'Own account '.$mode]);
    Db::run($db,"INSERT INTO native_grants(actor_id,module,capability) VALUES(?,'website','read')",[$actor]);
    $invitation=$auth->createInvitation($admin,$actor,'own-shared-family@example.invalid',86400,$alias);$auth->acceptInvitation($invitation['token'],$password);
    $accounts[$mode]=Db::run($db,'SELECT * FROM native_auth_accounts WHERE actor_id=?',[$actor])->fetch();
    $people[$mode]=Db::run($db,'SELECT * FROM native_users WHERE id=?',[$actor])->fetch();$grants[$mode]=Db::run($db,'SELECT * FROM native_grants WHERE actor_id=? ORDER BY module,capability',[$actor])->fetchAll();
}
$extraSessions=[];
if(getenv('DVM_ACCOUNT_SESSIONS_BROWSER')==='1')foreach(['js','basic']as$mode)for($i=0;$i<13;$i++){
    $extra=\Kulturhus\Native\Auth\Sessions::create($db,$accounts[$mode]['id'],time()-$i,'standard');$hash=hash('sha256',$extra['session_token']);
    $extraSessions[$mode][$hash]=Db::run($db,'SELECT * FROM native_auth_sessions WHERE token_hash=?',[$hash])->fetch();
}
$fileHashes=['auth.key'=>hash_file('sha256',$root.'/auth.key')];
$image=imagecreatetruecolor(48,32);imagefill($image,0,0,imagecolorallocate($image,41,127,153));imagepng($image,$root.'/media/original.png');unset($image);$fileHashes['media/original.png']=hash_file('sha256',$root.'/media/original.png');
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('Own HTTP port required.');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);$origin='http://127.0.0.1:'.$port;
$fixture=['origin'=>$origin,'password'=>$password,'accounts'=>$accounts];$fixtureFile=$root.'/browser.json';file_put_contents($fixtureFile,json_encode($fixture,JSON_THROW_ON_ERROR));chmod($fixtureFile,0600);
$n=0;$server=null;$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException($label);$n++;echo 'PASS '.$label."\n";};
$xpath=static function(string $html):DOMXPath{$dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);return new DOMXPath($dom);};
$http=static function(string $path,string $method='GET',array $post=[])use($origin,$root):array{$curl=curl_init($origin.$path);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_TIMEOUT=>15,CURLOPT_COOKIEFILE=>$root.'/cookies',CURLOPT_COOKIEJAR=>$root.'/cookies']);if($method==='POST')curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($post));$body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);if(!is_string($body))throw new RuntimeException('Own account HTTP required.');return[$status,$body];};
try{
    putenv('KH_NATIVE_LOCAL_DEMO=synthetic');putenv('KH_NATIVE_DEMO_PORT='.$port);putenv('KH_NATIVE_REQUIRE_LOGIN=1');putenv('DVM_ACCOUNT_PANEL_FIXTURE='.$fixtureFile);
    $server=proc_open([...TestSupport::phpCommand(),'-d','session.cookie_secure=0','-S','127.0.0.1:'.$port,'-t',$root.'/web',dirname(__DIR__).'/native/Web/router.php'],[0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']],$pipes,dirname(__DIR__,2));if(!is_resource($server))throw new RuntimeException('Own account server required.');fclose($pipes[0]);
    for($i=0;$i<100;$i++){$ready=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1);if($ready){fclose($ready);break;}usleep(50000);}
    [$status,$html]=$http('/login');$csrf=$xpath($html)->evaluate('string(//input[@name="csrf"]/@value)');$check($status===200&&strlen($csrf)===64,'actual owned account login has its native session');
    [$status]=$http('/login','POST',['csrf'=>$csrf,'operation'=>'login','email'=>$accounts['http']['login_name'],'password'=>$password]);$check($status===303,'actual personal alias authenticates its selected new family account');
    [$status,$html]=$http('/account');$doc=$xpath($html);$csrf=$doc->evaluate('string(//input[@name="csrf"]/@value)');
    $check($status===200&&$doc->query('//details[@name="account-settings"]')->length===8&&$doc->query('//details[@name="account-settings"][@open]')->length===0,'actual initial account displays eight closed native settings');
    $check($doc->query('//link[contains(@href,"bootstrap-6.0.0-alpha.1.min.css")]')->length===1,'actual account loads the local Bootstrap base once');
    $check($doc->query('//a[contains(@href,"tab=api")]/ancestor::details[summary="Technische Einrichtung" and not(@open)]')->length===1&&strpos($html,'account-sessions')<strpos($html,'tab=api'),'technical API setup is closed and follows everyday account settings');
    foreach(['language','appearance','email','username','password','security','device','sessions']as$section){[$status,$html]=$http('/account?section='.$section);$doc=$xpath($html);$check($status===200&&$doc->query('//details[@name="account-settings"][@open]')->length===1&&$doc->query('//details[@id="account-'.$section.'"][@open]')->length===1,'actual direct account link opens only '.$section);}
    [$status,$html]=$http('/account?section[forged]=password');$check($status===200&&$xpath($html)->query('//details[@name="account-settings"][@open]')->length===0,'forged array cannot select a private setting');
    $proposal=$accounts['http']['login_name'].'-updated';$post=['csrf'=>$csrf,'operation'=>'change-login-name','login_name'=>$proposal,'version'=>(string)$accounts['http']['version'],'current_password'=>$password];
    [$status,$html]=$http('/account','POST',array_replace($post,['csrf'=>'wrong']));$doc=$xpath($html);
    $check($status===422&&$doc->query('//details[@id="account-username"][@open]')->length===1,'actual CSRF rejection keeps its native setting open');
    $check($doc->evaluate('string(//input[@name="login_name"]/@value)')===$proposal&&$doc->evaluate('string(//input[@name="current_password"]/@value)')===''&&!str_contains($html,$password),'actual rejected alias keeps proposal and never echoes password');
    $check($doc->query('//*[@id="account-result"]')->length===1&&$doc->query('//*[@id="account-result"][@role="alert"][@tabindex="-1"]')->length===1,'actual failure has one focusable native result anchor');
    [$status,$html]=$http('/account','POST',$post);$check($status===200&&str_contains($html,'Persönlicher Benutzername gespeichert.'),'actual alias correction saves through the original account service');
    [$status,$html]=$http('/account','POST',$post);$doc=$xpath($html);$check($status===422&&$doc->evaluate('string(//details[@id="account-username"]//input[@name="version"]/@value)')===$post['version']&&$doc->evaluate('string(//input[@name="login_name"]/@value)')===$proposal,'actual stale tab preserves its exact proposal and original version');
    [$status,$html]=$http('/account','POST',['csrf'=>$csrf,'operation'=>'change-password','current_password'=>$password,'password'=>$password.'New','repeat'=>$password.'Other']);
    $doc=$xpath($html);$check($status===422&&$doc->query('//details[@id="account-password"][@open]')->length===1&&!str_contains($html,$password),'actual password correction opens its panel and echoes no credential');
    if(getenv('DVM_ACCOUNT_SESSIONS_BROWSER')==='1'){$process=proc_open(['node',__DIR__.'/account-sessions-browser.mjs'],[0=>STDIN,1=>STDOUT,2=>STDERR],$nodePipes,dirname(__DIR__,2));if(!is_resource($process)||proc_close($process)!==0)throw new RuntimeException('Actual account session browser failed.');}
    if(getenv('DVM_ACCOUNT_BROWSER')==='1'){$process=proc_open(['node',__DIR__.'/account-panels-browser.mjs'],[0=>STDIN,1=>STDOUT,2=>STDERR],$nodePipes,dirname(__DIR__,2));if(!is_resource($process)||proc_close($process)!==0)throw new RuntimeException('Actual account panel browser failed.');}
    $check(ownOriginalDatabaseHash($db,$tables,$filter)===$originalHash,'every prior original account, person, grant, session, page, revision and publication remains byte exact');
    $check($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN)===$tables,'actual account flows change no schema');
    $allowed=['native_users','native_grants','native_auth_accounts','native_auth_tokens','native_auth_sessions','native_auth_limits','native_auth_login_names','native_auth_recovery'];
    foreach($tables as$table)if(!in_array($table,$allowed,true))$check((int)Db::run($db,'SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()===count($keys[$table]['values']),'complete original table count retained: '.$table);
    $ids=array_column($accounts,'id');$actors=array_column($accounts,'actor_id');
    foreach($allowed as$table){[$where,$args]=$filter($table);$newWhere=$where===' WHERE 0=1'?'':' WHERE NOT ('.substr($where,7).')';foreach(Db::run($db,'SELECT * FROM `'.$table.'`'.$newWhere,$args)->fetchAll()as$row){if($table==='native_auth_limits')continue;$owner=$table==='native_users'?$row['id']:($table==='native_auth_accounts'?$row['id']:($row['account_id']??$row['actor_id']));$check(in_array($owner,in_array($table,['native_users','native_grants'],true)?$actors:$ids,true),'every new account row belongs to the exact selected synthetic persons');}}
    foreach($accounts as$mode=>$account){
        $current=Db::run($db,'SELECT * FROM native_auth_accounts WHERE id=?',[$account['id']])->fetch();$browser=$mode!=='http'&&getenv('DVM_ACCOUNT_BROWSER')==='1';
        $check($current['login_name']===$account['login_name'].($browser?'-browser':($mode==='http'?'-updated':'')),'actual chosen alias binds only its original family person');
        $check(($current['totp_secret']!==null)===$browser&&$current['pending_secret']===null&&$current['pending_session']===null,'only explicit own browser confirmation activates the second factor');
        foreach(['login_name','version','totp_secret','totp_counter','pending_secret','pending_until','pending_session']as$field){unset($current[$field],$account[$field]);}
        $check($current===$account&&Db::run($db,'SELECT * FROM native_users WHERE id=?',[$account['actor_id']])->fetch()===$people[$mode]&&Db::run($db,'SELECT * FROM native_grants WHERE actor_id=? ORDER BY module,capability',[$account['actor_id']])->fetchAll()===$grants[$mode],'own mailbox, password, profile, active state and all original rights stay byte exact');
    }
    if($extraSessions){
        $result=json_decode(file_get_contents($root.'/session-result.json'),true,32,JSON_THROW_ON_ERROR);
        foreach($extraSessions as$mode=>$originals){
            $target=$result['targets'][$mode]??'';$check(is_string($target)&&isset($originals[$target]),'actual browser closes exactly one previously selected own login');
            foreach($originals as$id=>$original){$current=Db::run($db,'SELECT * FROM native_auth_sessions WHERE token_hash=?',[$id])->fetch();if($id===$target){$check($current['revoked_at']!==null,'actual selected browser login loses its live authority');unset($current['revoked_at'],$original['revoked_at']);}$check($current===$original,'each other prior own login retains every exact original byte');}
            $check((int)Db::run($db,'SELECT COUNT(*) FROM native_auth_sessions WHERE account_id=? AND revoked_at IS NULL AND expires_at>?',[$accounts[$mode]['id'],time()])->fetchColumn()===13,'only one of fourteen own active logins is closed');
        }
    }
    foreach($fileHashes as$file=>$hash)$check(hash_file('sha256',$root.'/'.$file)===$hash,'private key and every original photo byte remain intact');
    echo json_encode(['suite'=>'native-account-panels','checks'=>$n,'browser'=>getenv('DVM_ACCOUNT_BROWSER')==='1'||getenv('DVM_ACCOUNT_SESSIONS_BROWSER')==='1','external_requests'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
}finally{if(is_resource($server)){proc_terminate($server);proc_close($server);}$files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($files as$file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($root);}
