<?php declare(strict_types=1);
/** Execute the repository's actual cloud initializer in a fresh owned copy. */
require dirname(__DIR__).'/native/bootstrap.php';
require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\TestSupport;

$db=TestSupport::db();$config=TestSupport::config();$n=0;
$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException('FAIL: '.$label);$n++;};
$check(($config['owner']??'')==='isolated-native-ci'&&($config['label']??'')==='native-cloud-setup','runner-owned fresh database required');
$check($db->query('SHOW TABLES')->fetchAll()===[],'cloud installation starts with genuinely empty database');
$root=dirname((string)getenv('KH_NATIVE_TEST_CONFIG')).'/cloud-setup-proof';
if(file_exists($root)||!mkdir($root,0700))throw new RuntimeException('New owned cloud proof required');
$source=dirname(__DIR__);
foreach(['native','src','bin','data','migrations','public','views','resources']as$part){
    $base=$source.'/'.$part;mkdir($root.'/vereinsverwaltung/'.$part,0700,true);
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST)as$file){
        if($file->isLink())throw new RuntimeException('Owned cloud source may not follow links');
        $target=$root.'/vereinsverwaltung/'.$part.'/'.substr($file->getPathname(),strlen($base)+1);
        if($file->isDir())mkdir($target,0700);elseif(!copy($file->getPathname(),$target))throw new RuntimeException('Cloud source copy failed');
    }
}
// Banking may contain ignored operator configuration; copy only shipped sources.
$inventory=json_decode((string)file_get_contents(dirname(__DIR__,2).'/distribution/source-files.json'),true,512,JSON_THROW_ON_ERROR);
foreach($inventory['files']as$entry)if(str_starts_with($entry['path'],'banking/')){
    $name=$entry['path'];$target=$root.'/'.$name;
    if(!is_dir(dirname($target)))mkdir(dirname($target),0700,true);
    if(!copy(dirname(__DIR__,2).'/'.$name,$target))throw new RuntimeException('Owned banking source copy failed');
}
$dir=$root.'/.local/native-cms-integration';mkdir($dir,0700,true);
$config['owner']='cloud-onboarding-synthetic';$config['label']='integrated-manager';
$configPath=$dir.'/integrated-manager.json';file_put_contents($configPath,json_encode($config,JSON_THROW_ON_ERROR));chmod($configPath,0600);
$installer=(string)file_get_contents(dirname(__DIR__,2).'/tools/cloud/install.sh');
if(!preg_match("/<<'INTEGRATED_INSTALL'\n(.*?)\nINTEGRATED_INSTALL/s",$installer,$match))throw new RuntimeException('Actual integrated initializer missing');
$helper=str_replace("\$root='/workspace/Kulturhus';",'$root='.var_export($root,true).';',$match[1]);
$helperPath=$root.'/initialize.php';file_put_contents($helperPath,$helper);
$execute=static function()use($helperPath):void{
    $p=proc_open([...TestSupport::phpCommand(),$helperPath],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($p))throw new RuntimeException('Cloud initializer child missing');fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($p)!==0)throw new RuntimeException('Actual fresh cloud initializer failed: '.$err.$out);
};
$execute();
$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
Kulturhus\Native\Installer\SchemaRegistry::assertCurrent($db);
$check(count($tables)>=429,'complete current schema installed before demo page creation');
$runtime=$dir.'/integrated-manager-runtime';$files=[];
foreach(['auth.key','private.key','browser-credentials.json']as$name){$path=$runtime.'/'.$name;$check(is_file($path)&&!is_link($path)&&(fileperms($path)&0077)===0,'actual initial credentials and keys private');$files[$path]=hash_file('sha256',$path);}
$hash=ownOriginalDatabaseHash($db,$tables);$execute();
$check(ownOriginalDatabaseHash($db,$tables)===$hash,'explicit repeated setup retains complete original database bytes');
foreach($files as$path=>$digest)$check(hash_file('sha256',$path)===$digest,'explicit repeated setup retains original keys and credentials');
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('Own cloud HTTP port unavailable');
$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
$router=(string)file_get_contents(__DIR__.'/support/native-manager-router.php');
$router=str_replace(['$root=dirname(__DIR__,3);','127.0.0.1:8111'],['$root='.var_export($root,true).';','127.0.0.1:'.$port],$router);
$routerPath=$root.'/router.php';file_put_contents($routerPath,$router);
$log=fopen($root.'/http.log','wb');$server=proc_open([...TestSupport::phpCommand(),'-S','127.0.0.1:'.$port,'-t',$root.'/vereinsverwaltung/public',$routerPath],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);
if(!is_resource($server))throw new RuntimeException('Owned cloud HTTP server unavailable');fclose($pipes[0]);
try{
    for($i=0;$i<100;$i++){$probe=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1);if($probe){fclose($probe);break;}usleep(50000);}
    $jar=$root.'/session.cookies';file_put_contents($jar,'');chmod($jar,0600);
    $request=curl_init('http://127.0.0.1:'.$port.'/login/');curl_setopt_array($request,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>30,CURLOPT_COOKIEJAR=>$jar,CURLOPT_COOKIEFILE=>$jar]);
    $body=curl_exec($request);$status=curl_getinfo($request,CURLINFO_RESPONSE_CODE);
    $check($status===200&&is_string($body)&&str_contains($body,'Anmelden und Vereinsmanager öffnen')&&!str_contains($body,'Fatal error'),'actual fresh shared Manager serves native login');
    $check(ownOriginalDatabaseHash($db,$tables)===$hash,'anonymous readiness request preserves complete original database');
    $doc=new DOMDocument();@$doc->loadHTML($body);$xpath=new DOMXPath($doc);$csrf=$xpath->evaluate('string(//input[@name="csrf"]/@value)');
    $credentials=json_decode((string)file_get_contents($runtime.'/browser-credentials.json'),true,32,JSON_THROW_ON_ERROR)['admin'];
    curl_setopt_array($request,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['operation'=>'login','csrf'=>$csrf,'email'=>$credentials['email'],'password'=>$credentials['password']])]);
    curl_exec($request);$check(curl_getinfo($request,CURLINFO_RESPONSE_CODE)===303,'actual initial administrator can sign in with preserved private credentials');
    curl_setopt_array($request,[CURLOPT_HTTPGET=>true,CURLOPT_URL=>'http://127.0.0.1:'.$port.'/vereinsverwaltung/?view=dashboard']);
    $dashboard=curl_exec($request);$dashboardStatus=curl_getinfo($request,CURLINFO_RESPONSE_CODE);
    if($dashboardStatus!==200){$errorPath=$runtime.'/php.errors.log';fwrite(STDERR,'Owned dashboard HTTP '.$dashboardStatus.' '.(is_file($errorPath)?substr((string)file_get_contents($errorPath),-3000):'No PHP error log').PHP_EOL);}
    $check($dashboardStatus===200&&is_string($dashboard)&&str_contains($dashboard,'DeinVereinsManager')&&!preg_match('/Fatal error:|SQLSTATE\[|Stack trace:/',$dashboard),'fresh installed Manager opens authenticated dashboard');
    curl_close($request);
    foreach($files as$path=>$digest)$check(hash_file('sha256',$path)===$digest,'actual initial sign-in retains private keys and original credentials');
}finally{proc_terminate($server);proc_close($server);fclose($log);}
echo json_encode(['suite'=>'native-cloud-setup','checks'=>$n,'tables'=>count($tables),'external_requests'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
