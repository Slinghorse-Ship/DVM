<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\Web\LoginAliases;
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\Db;

$checks=0;
$check=static function(bool$ok,string$label)use(&$checks):void { if(!$ok)throw new RuntimeException($label);++$checks;echo 'PASS '.$label.PHP_EOL; };
foreach(['GET','HEAD']as$method)foreach(['/login','/login/']as$path) {
    $check(LoginAliases::target($method,$path,['forgot'=>'1'])==='/forgot','exact forgotten-password entry '.$method.' '.$path);
    $check(LoginAliases::target($method,$path,['security'=>'1'])==='/account?section=security','exact personal security entry '.$method.' '.$path);
}
foreach([
    [],['forgot'=>'0'],['forgot'=>1],['forgot'=>true],['forgot'=>null],['forgot'=>['1']],
    ['forgot'=>'01'],['forgot'=>'1 '],['security'=>'true'],['security'=>['value'=>'1']],
    ['forgot'=>'1','security'=>'1'],['forgot'=>'1','security'=>'0'],['forgot'=>[],'security'=>'1'],
]as$query)$check(LoginAliases::target('GET','/login/',$query)===null,'ambiguous or non-exact legacy values keep the ordinary login entry');
foreach(['POST','PUT','DELETE','OPTIONS']as$method)$check(LoginAliases::target($method,'/login',['forgot'=>'1'])===null,'legacy selector never changes '.$method.' dispatch');
foreach(['/account','/forgot','/login/other','//login','/LOGIN']as$path)$check(LoginAliases::target('GET',$path,['security'=>'1'])===null,'selector does not claim another route '.$path);
$check(LoginAliases::target('GET','/login',['forgot'=>'1','next'=>"https://outside.example.invalid/\r\nX-Injected: yes",'section'=>'security','lang'=>'en'])==='/forgot','unrelated query data never becomes a redirect target');
if(getenv('DVM_LOGIN_ALIASES_PURE')==='1') { echo json_encode(['suite'=>'native-login-aliases','pure_checks'=>$checks,'sql'=>false,'external_actions'=>0],JSON_THROW_ON_ERROR).PHP_EOL;exit; }

require dirname(__DIR__).'/src/bootstrap.php';
require __DIR__.'/support/original-database-hash.php';
$config=TestSupport::config();
if(($config['owner']??'')!=='isolated-native-ci'||($config['label']??'')!=='native-login-aliases')throw new RuntimeException('Own disposable alias database required.');
$db=TestSupport::db();\Kulturhus\Native\Installer\SchemaRegistry::install($db);TestSupport::seed($db);
$root=dirname((string)getenv('KH_NATIVE_TEST_CONFIG')).'/login-aliases-'.$config['database'];
$nativeRuntime=dirname((string)getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$config['database'];
foreach([$root,$root.'/web',$root.'/media',$root.'/manager-sessions',$nativeRuntime,$nativeRuntime.'/sessions',$nativeRuntime.'/media']as$path)if(!is_dir($path))mkdir($path,0700,true);
foreach(['auth','private']as$key) { file_put_contents($root.'/'.$key.'.key',random_bytes(32));chmod($root.'/'.$key.'.key',0600); }
copy($root.'/auth.key',$nativeRuntime.'/auth.key');chmod($nativeRuntime.'/auth.key',0600);
putenv('KH_NATIVE_AUTH_KEY_FILE='.$root.'/auth.key');
$club='own-login-aliases';Db::run($db,'INSERT INTO clubs VALUES(?,?)',[$club,'Own original login alias club']);
Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES('own-login-admin',?,'member',1),('own-login-person',?,'member',0),('own-login-other',?,'member',0)",[$club,$club,$club]);
$admin=(new \Kulturhus\Native\Integration\ManagerBridge($db,'own-login-admin','native',static fn()=>null))->access();
$person=(new \Kulturhus\Native\Integration\ManagerBridge($db,'own-login-person','native',static fn()=>null))->access();
$auth=\Kulturhus\Native\Auth\Runtime::service($db);$password=bin2hex(random_bytes(18)).'Aa7!';$email='own-login-person@example.invalid';
$invite=$auth->createInvitation($admin,$person->user()['id'],$email);$auth->acceptInvitation($invite['token'],$password);
$account=Db::run($db,'SELECT * FROM native_auth_accounts WHERE actor_id=?',[$person->user()['id']])->fetch();
$other=(new \Kulturhus\Native\Integration\ManagerBridge($db,'own-login-other','native',static fn()=>null))->access();
$otherInvite=$auth->createInvitation($admin,$other->user()['id'],'own-other-original@example.invalid');$auth->acceptInvitation($otherInvite['token'],$password);
$otherLogin=$auth->passwordLogin('own-other-original@example.invalid',$password,'own-other-original-client');
$fixture=['root'=>$root,'club'=>$club,'origins'=>[]];
foreach(['native','integrated']as$app) { $socket=stream_socket_server('tcp://127.0.0.1:0');if(!$socket)throw new RuntimeException('Own HTTP socket unavailable.');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);$fixture['origins'][$app]='http://127.0.0.1:'.$port; }
$fixtureFile=$root.'/fixture.json';file_put_contents($fixtureFile,json_encode($fixture,JSON_THROW_ON_ERROR));chmod($fixtureFile,0600);putenv('DVM_LOGIN_ALIASES_FIXTURE='.$fixtureFile);
$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$before=ownOriginalDatabaseHash($db,$tables);
$keys=[];foreach([$root.'/auth.key',$root.'/private.key',$nativeRuntime.'/auth.key']as$file)$keys[$file]=hash_file('sha256',$file);
$servers=[];
$http=static function(string$origin,string$path,array&$cookies,string$method='GET',?array$post=null,?string$requestOrigin=null):array {
    if(!preg_match('~^http://127\.0\.0\.1:[0-9]+$~D',$origin)||!str_starts_with($path,'/'))throw new RuntimeException('Own loopback HTTP required.');
    $headers=['Connection: close'];
    if($cookies)$headers[]='Cookie: '.implode('; ',array_map(static fn($key,$value)=>$key.'='.$value,array_keys($cookies),$cookies));
    if($post!==null) { $headers[]='Content-Type: application/x-www-form-urlencoded';$headers[]='Origin: '.($requestOrigin??$origin); }
    $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$post===null?'':http_build_query($post),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>30]]);
    $body=file_get_contents($origin.$path,false,$context);$raw=$http_response_header??[];
    if(!is_string($body)||!preg_match('~^HTTP/\S+ ([0-9]{3})~',$raw[0]??'',$status))throw new RuntimeException('Own application response unavailable.');
    $values=[];foreach($raw as$header) { if(preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i',$header,$cookie))$cookies[$cookie[1]]=$cookie[2];if(str_contains($header,':')) { [$key,$value]=explode(':',$header,2);$values[strtolower($key)]=trim($value); } }
    return ['status'=>(int)$status[1],'headers'=>$values,'raw_headers'=>$raw,'body'=>$body];
};
$doc=static function(string$html):DOMXPath { $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);return new DOMXPath($dom); };
$hasForm=static fn(array$response,string$operation):bool=>$doc($response['body'])->query('//form[input[@name="operation"][@value="'.$operation.'"]]')->length===1;
$alias=static function(array$response,string$target,string$label)use($check):void { $check($response['status']===302&&($response['headers']['location']??null)===$target&&($response['headers']['cache-control']??null)==='no-store'&&$response['body']==='',$label.' is a fixed empty temporary NoStore redirect'); };
try {
    foreach($fixture['origins']as$app=>$origin) {
        $port=parse_url($origin,PHP_URL_PORT);$log=fopen($root.'/'.$app.'.log','wb');$process=proc_open([...TestSupport::phpCommand(),'-S','127.0.0.1:'.$port,'-t',$root.'/web',__DIR__.'/support/login-aliases-http.php'],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);
        if(!is_resource($process))throw new RuntimeException('Own alias HTTP server unavailable.');fclose($pipes[0]);$servers[]=[$process,$log];$ready=false;
        for($i=0;$i<100;$i++) { $socket=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1);if($socket) { fclose($socket);$ready=true;break; }usleep(50000); }
        if(!$ready)throw new RuntimeException('Own alias HTTP server did not start.');
        $cookies=[];
        foreach(['forgot'=>'/forgot','security'=>'/account?section=security']as$mode=>$target)foreach(['GET','HEAD']as$method)foreach(['/login','/login/']as$path)$alias($http($origin,$path.'?'.$mode.'=1',$cookies,$method),$target,$app.' '.$method.' '.$path.' '.$mode);
        $forgot=$http($origin,'/forgot',$cookies);$check($forgot['status']===200&&($forgot['headers']['cache-control']??null)==='no-store'&&$hasForm($forgot,'request-reset'),$app.' forgotten-password alias has an actual canonical NoStore reset-request form');
        $security=$http($origin,'/account?section=security',$cookies);$check($security['status']===200&&$hasForm($security,'login')&&!str_contains($security['body'],'id="account-security"')&&!str_contains($security['body'],$email),$app.' anonymous security destination retains the real personal login gate');
        if($app==='integrated')$check((bool)preg_match('/Set-Cookie:\s*KH_NATIVE_MANAGER=[^\r\n]+;\s*secure(?:;|$)/i',implode("\n",$forgot['raw_headers'])),'actual production account retains Secure cookies before the loopback-only test jar adapter');
        foreach(['forgot=0','forgot=01','forgot[]=1','forgot[key]=1','security[]=1','forgot=1&security=1','forgot=1&security=0','forgot[]=1&security=1']as$query) {
            $response=$http($origin,'/login/?'.$query,$cookies);$check($response['status']===200&&!isset($response['headers']['location'])&&$hasForm($response,'login'),$app.' malformed or ambiguous selector leaves the ordinary login form: '.$query);
        }
        $alias($http($origin,'/login/?forgot=1&next=https%3A%2F%2Foutside.example.invalid%2F&section=security&lang=en',$cookies),'/forgot',$app.' unrelated redirect/query values');
        foreach(['forgot'=>'request-reset','security'=>'begin-totp']as$mode=>$operation) {
            $rejected=$http($origin,'/login/?'.$mode.'=1',$cookies,'POST',['operation'=>$operation,'csrf'=>'forged-own-csrf','email'=>$email,'current_password'=>$password]);
            $check($rejected['status']===422&&!isset($rejected['headers']['location']),$app.' POST is not redirected or executed by the legacy selector; original CSRF guard rejects '.$mode);
        }
        $cross=$http($origin,'/login/?forgot=1',$cookies,'POST',['operation'=>'request-reset','csrf'=>'forged-own-csrf','email'=>$email],'https://outside.example.invalid');
        $check($cross['status']===403&&!isset($cross['headers']['location']),$app.' actual original Origin guard remains in front of aliased POSTs');
        foreach(['OPTIONS','PUT','DELETE']as$method) { $response=$http($origin,'/login/?forgot=1',$cookies,$method);$check($response['status']===405&&!isset($response['headers']['location']),$app.' original method guard rejects '.$method); }
        $check(ownOriginalDatabaseHash($db,$tables)===$before,$app.' all anonymous aliases, refusals and GET/HEAD destinations preserve every original database byte');
    }
    // Only now deliberately create real personal sessions using the original login POST.
    $priorLimits=$db->query('SELECT scope_hash FROM native_auth_limits ORDER BY scope_hash')->fetchAll(PDO::FETCH_COLUMN);
    $protectedFilter=static fn(string$table):array=>$table==='native_auth_sessions'?[' WHERE account_id<>?',[$account['id']]]:($table==='native_auth_limits'?($priorLimits?[' WHERE scope_hash IN ('.implode(',',array_fill(0,count($priorLimits),'?')).')',$priorLimits]:[' WHERE 0=1',[]]):['',[]]);
    $protected=ownOriginalDatabaseHash($db,$tables,$protectedFilter);$priorSessions=Db::run($db,'SELECT * FROM native_auth_sessions WHERE account_id<>? ORDER BY token_hash',[$account['id']])->fetchAll();
    foreach($fixture['origins']as$app=>$origin) {
        $cookies=[];$login=$http($origin,'/login',$cookies);$csrf=$doc($login['body'])->evaluate('string(//input[@name="csrf"]/@value)');
        $signed=$http($origin,'/login/?forgot=1',$cookies,'POST',['operation'=>'login','csrf'=>$csrf,'email'=>$email,'password'=>$password]);
        $check($signed['status']===303&&($signed['headers']['location']??'')===($app==='native'?'/?view=website':'/vereinsverwaltung/?view=portal'),$app.' CSRF-valid ordinary login POST retains its own destination instead of interpreting forgot mode');
        $alias($http($origin,'/login/?security=1',$cookies),'/account?section=security',$app.' authenticated personal security alias');
        $security=$http($origin,'/account?section=security',$cookies);$xpath=$doc($security['body']);
        $check($security['status']===200&&($security['headers']['cache-control']??null)==='no-store'&&$xpath->query('//details[@id="account-security"][@open]')->length===1&&str_contains($security['body'],$email),$app.' security alias opens exactly the real personal original NoStore security panel');
        $csrf=$xpath->evaluate('string(//input[@name="csrf"]/@value)');
        $alias($http($origin,'/login/?forgot=1',$cookies),'/forgot',$app.' authenticated forgotten-password entry');
        $ownForgot=$http($origin,'/forgot',$cookies);
        $check($ownForgot['status']===200&&str_contains($ownForgot['body'],$email)&&!$hasForm($ownForgot,'request-reset'),$app.' canonical forgotten-password entry preserves the already authenticated personal account');
        $ambiguous=$http($origin,'/login/?forgot=1&security=1',$cookies);
        $check($ambiguous['status']===200&&!isset($ambiguous['headers']['location'])&&$doc($ambiguous['body'])->query('//details[@name="account-settings"][@open]')->length===0,$app.' conflicting modes cannot force open a private setting');
        $forged=$http($origin,'/login/?security=1',$cookies,'POST',['operation'=>'logout','csrf'=>'forged-own-csrf']);
        $check($forged['status']===422&&!isset($forged['headers']['location'])&&str_contains($forged['body'],$email),$app.' protected aliased POST retains personal CSRF and original session');
        $active=Db::run($db,'SELECT token_hash FROM native_auth_sessions WHERE account_id=? AND revoked_at IS NULL ORDER BY created_at DESC,token_hash',[$account['id']])->fetchAll(PDO::FETCH_COLUMN);
        $check(count($active)===1,$app.' selected alias flow owns exactly one newly authenticated live session');
        Db::run($db,'UPDATE native_auth_sessions SET revoked_at=UNIX_TIMESTAMP() WHERE token_hash=?',[$active[0]]);
        $alias($http($origin,'/login/?security=1',$cookies),'/account?section=security',$app.' previously revoked session security alias');
        $closed=$http($origin,'/account?section=security',$cookies);
        $check($closed['status']===200&&$hasForm($closed,'login')&&!str_contains($closed['body'],'id="account-security"')&&!str_contains($closed['body'],$email),$app.' canonical destination observes current database revocation and never reopens private security');
        $check(ownOriginalDatabaseHash($db,$tables,$protectedFilter)===$protected,$app.' real personal login and targeted revocation preserve every other original table, credential, factor and grant');
        $check(Db::run($db,'SELECT * FROM native_auth_sessions WHERE account_id<>? ORDER BY token_hash',[$account['id']])->fetchAll()===$priorSessions,$app.' every other original session remains byte exact');
    }
    foreach($keys as$file=>$hash)$check(hash_file('sha256',$file)===$hash,'aliases preserve original private key bytes');
    $check($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)===$tables,'actual legacy entry flows change no schema');
    echo json_encode(['suite'=>'native-login-aliases','checks'=>$checks,'apps'=>['native-router','ProductionSite'],'original_tables'=>count($tables),'original_bytes'=>'exact except two explicitly created own sessions and their targeted revocation plus ordinary login throttle metadata','external_actions'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    foreach($servers as[$process,$log]) { proc_terminate($process);proc_close($process);fclose($log); }
    $artifacts=getenv('DVM_LOGIN_ALIASES_ARTIFACTS');if(is_string($artifacts)&&$artifacts!=='') { if(!is_dir($artifacts))mkdir($artifacts,0700,true);foreach(glob($root.'/*.log')as$log)copy($log,$artifacts.'/'.basename($log)); }
}
