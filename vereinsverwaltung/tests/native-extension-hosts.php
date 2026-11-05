<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';require dirname(__DIR__).'/src/bootstrap.php';require __DIR__.'/support/editorial-browser-fixture.php';require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\Extensions\{Catalog,Context,Definition,Event,Factory,HookScope,HostPlugins,Manager,Plugin,Runtime,UrlScope};
use Kulturhus\Native\TestSupport;
$db=TestSupport::db();Kulturhus\Native\Installer\SchemaRegistry::install($db);$fixture=ownEditorialBrowserFixture($db);$root=$fixture['root'];putenv('VEREIN_PRIVATE_ROOT='.$root);$checks=0;
function extensionHostCheck(bool $condition,string $label):void{if(!$condition)throw new RuntimeException($label);$GLOBALS['checks']++;echo "PASS $label\n";}
function ownHostWrite(string $path,string $contents,int $mode=0600):void{file_put_contents($path,$contents);chmod($path,$mode);clearstatcache(true,$path);}
final class OwnUnreviewedHostPlugin implements Plugin{
 public function definition():Definition{return new Definition('outside-host','Outside','1.0.0');}public function install(Context $context):void{}public function upgrade(Context $context,string $previousVersion):void{}public function uninstall(Context $context):void{}public function initialize(HookScope $hooks,UrlScope $urls,Context $context):void{}
}
$entry=$root.'/reviewed-plugins.php';$sourceRoot=dirname(__DIR__).'/native/Extensions';$sourceHashes=[];foreach(glob($sourceRoot.'/*.php') as $path)$sourceHashes[$path]=hash_file('sha256',$path);
extensionHostCheck(array_keys(HostPlugins::catalog()->ordered())===['website-notice','editorial-layouts'],'missing optional private registry loads exactly both supplied modules');
ownHostWrite($entry,'<?php $GLOBALS["ownHostExecuted"]=true; return [];',0644);unset($GLOBALS['ownHostExecuted']);$catalog=HostPlugins::catalog();extensionHostCheck($catalog->diagnostics()===[['owner'=>'reviewed-plugins','code'=>'host_registry_failed']]&&!isset($GLOBALS['ownHostExecuted']),'publicly readable registry is rejected before executing any PHP');
chmod($entry,0600);chmod($root,0755);clearstatcache();extensionHostCheck(HostPlugins::catalog()->diagnostics()!==[],'nonprivate root is rejected');chmod($root,0700);clearstatcache();
ownHostWrite($entry,'<?php this is not valid PHP;');extensionHostCheck(HostPlugins::catalog()->diagnostics()!==[],'a syntax failure is isolated from the supplied module');
ownHostWrite($entry,'<?php echo "private-output-never-leak"; return [];');ob_start();$catalog=HostPlugins::catalog();$leaked=ob_get_clean();extensionHostCheck($catalog->diagnostics()!==[]&&$leaked==='','accidental registry output is discarded and no partial module set loads');
ownHostWrite($entry,'<?php return [new \OwnUnreviewedHostPlugin];');extensionHostCheck(HostPlugins::catalog()->diagnostics()!==[],'plugin class outside the private reviewed root is rejected');
$target=$root.'/own-registry-target.php';ownHostWrite($target,'<?php return [];');unlink($entry);symlink($target,$entry);extensionHostCheck(HostPlugins::catalog()->diagnostics()!==[],'fixed registry cannot be a symlink');unlink($entry);
$classFile=$root.'/reviewed-host-module.php';
ownHostWrite($classFile, <<<'PHP'
<?php declare(strict_types=1);
namespace Own\ReviewedHost;
use Kulturhus\Native\Extensions\{Context,Definition,Event,HookScope,Plugin,UrlScope};
final class Notice implements Plugin{
 public function definition():Definition{return new Definition('host-reviewed','Own reviewed host module','1.0.0',[],['suffix'=>['type'=>'text','label'=>'Title suffix','max'=>80,'default'=>'Local review']]);}
 public function install(Context $context):void{$context->put('installed',true);}public function upgrade(Context $context,string $previousVersion):void{}public function uninstall(Context $context):void{$context->delete('installed');}
 public function initialize(HookScope $hooks,UrlScope $urls,Context $context):void{$suffix=$context->configuration['suffix'];$hooks->on('site.title','after',static function(Event $event)use($suffix):void{$event->setResult($event->result().' · '.$suffix);});$hooks->on('site.response','after',static function(Event $event)use($suffix):void{$r=$event->result();$r['body']=str_replace('</body>','<aside aria-label="Host review">'.htmlspecialchars($suffix,ENT_QUOTES,'UTF-8').'</aside></body>',$r['body']);$event->setResult($r);});$urls->register('',static fn(array $arguments):array=>['status'=>200,'headers'=>['Content-Type'=>'application/json; charset=utf-8'],'body'=>json_encode(['suffix'=>$suffix],JSON_THROW_ON_ERROR)]);}
}
PHP);
$valid='<?php require_once __DIR__."/reviewed-host-module.php"; return [new \Own\ReviewedHost\Notice];';ownHostWrite($entry,$valid);$catalog=Runtime::catalog();extensionHostCheck(array_keys($catalog->ordered())===['website-notice','editorial-layouts','host-reviewed']&&$catalog->diagnostics()===[],'fixed private host file registers a real reviewed class without changing the core');
extensionHostCheck(Runtime::catalog()===$catalog,'repeated initialization uses the reviewed request-local registry rather than redeclaring PHP classes');
chmod($classFile,0644);clearstatcache();extensionHostCheck(HostPlugins::catalog()->diagnostics()!==[],'cached registry still revalidates private module-source permissions');chmod($classFile,0600);clearstatcache();
$alias=$root.'-own-alias';symlink($root,$alias);putenv('VEREIN_PRIVATE_ROOT='.$alias);extensionHostCheck(HostPlugins::catalog()->diagnostics()!==[],'symlinked root or ancestor is rejected');putenv('VEREIN_PRIVATE_ROOT='.$root);unlink($alias);
$manager=Factory::manager($db,$fixture['accounts']['admin']['actor'],false);$manager->change('host-reviewed','install',0);$manager->change('host-reviewed','configure',1,['suffix'=>'Actual customer plugin']);$manager->change('host-reviewed','enable',2);
$pages=new \Kulturhus\Native\Cms\Pages($db,$fixture['admin']);$page=$pages->save(['title'=>'Own reviewed page','slug'=>'own-host-plugin','body_html'=>'<p>Original host content.</p>','nav_visible'=>false]);$pages->publish($page['id'],$page['version']);
$tables=array_values(array_diff($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),['native_extensions','native_extension_values','native_extension_history']));$original=ownOriginalDatabaseHash($db,$tables);$keys=[hash_file('sha256',$root.'/auth.key'),hash_file('sha256',$root.'/private.key')];
$safeFixture=array_intersect_key($fixture,array_flip(['root','origin','club','public_actor']));$fixtureFile=$root.'/host-fixture.json';ownHostWrite($fixtureFile,json_encode($safeFixture,JSON_THROW_ON_ERROR));putenv('DVM_EXTENSION_HOST_FIXTURE='.$fixtureFile);
$log=fopen($root.'/host-http.log','ab');$server=proc_open([...TestSupport::phpCommand(),'-S','127.0.0.1:'.parse_url($fixture['origin'],PHP_URL_PORT),'-t',$root.'/web',__DIR__.'/support/extensions-host-router.php'],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);if(!is_resource($server))throw new RuntimeException('Own extension host server unavailable');fclose($pipes[0]);
$request=static function(string $path)use($fixture):array{$curl=curl_init($fixture['origin'].$path);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>30]);try{$body=curl_exec($curl);if($body===false)throw new RuntimeException('Own host HTTP failed');return ['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>$body];}finally{curl_close($curl);}};
try{
 for($i=0;$i<100;$i++){$socket=@stream_socket_client('tcp://127.0.0.1:'.parse_url($fixture['origin'],PHP_URL_PORT),$errno,$error,.1);if($socket){fclose($socket);break;}usleep(50000);}
 $response=$request('/own-host-plugin');extensionHostCheck($response['status']===200&&str_contains($response['body'],'Actual customer plugin')&&str_contains($response['body'],'aria-label="Host review"'),'actual public host applies reviewed plugin property and final HTML hooks');
 $response=$request('/extensions/host-reviewed');extensionHostCheck($response['status']===200&&json_decode($response['body'],true,8,JSON_THROW_ON_ERROR)===['suffix'=>'Actual customer plugin'],'actual host routes the reviewed plugin URL without any core registration edit');
 ownHostWrite($entry,'<?php throw new \RuntimeException("private-host-path-password");');$response=$request('/own-host-plugin');extensionHostCheck($response['status']===200&&!str_contains($response['body'],'Actual customer plugin')&&!str_contains($response['body'],'private-host'),'broken host entry point leaves the original public site working and never exposes its exception');
 extensionHostCheck($request('/extensions/host-reviewed')['status']===404,'broken host registry withdraws its customer routes');
 $brokenManager=Factory::manager($db,$fixture['accounts']['admin']['actor'],false);$html=\Kulturhus\Native\Extensions\View::render($brokenManager,'own-csrf');extensionHostCheck(str_contains($html,'Zusätzliche Erweiterungen konnten nicht geladen werden')&&!str_contains($html,'private-host-path'),'protected administration shows a closed load failure while retaining original module state');
 ownHostWrite($entry,$valid);extensionHostCheck($request('/extensions/host-reviewed')['status']===200,'repairing the reviewed private registry resumes the same original enabled module and configuration');
 extensionHostCheck(ownOriginalDatabaseHash($db,$tables)===$original&&$keys===[hash_file('sha256',$root.'/auth.key'),hash_file('sha256',$root.'/private.key')],'host registration and failure recovery preserve all original business rows and both keys');
 foreach($sourceHashes as $path=>$hash)if(hash_file('sha256',$path)!==$hash)throw new RuntimeException('Host test changed a core extension file');extensionHostCheck(true,'all actual host plugin registration happened without a core file change');
}finally{proc_terminate($server);proc_close($server);fclose($log);putenv('VEREIN_PRIVATE_ROOT');}
echo json_encode(['suite'=>'native-extension-hosts','checks'=>$checks,'original_tables'=>count($tables),'original_bytes'=>'exact','original_keys'=>'exact','live_transport'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
