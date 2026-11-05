<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';require dirname(__DIR__).'/src/bootstrap.php';
require __DIR__.'/support/original-database-hash.php';require __DIR__.'/support/editorial-browser-fixture.php';
use Kulturhus\Native\{TestSupport,Installer\SchemaRegistry,Language\Catalog,Language\ManagerMessages,Language\Settings,Shared\Db};
$db=TestSupport::db();SchemaRegistry::install($db);TestSupport::seed($db);$fixture=ownEditorialBrowserFixture($db);$n=0;
$check=static function(bool$ok,string$label)use(&$n):void{if(!$ok)throw new RuntimeException('FAIL '.$label);$n++;echo 'PASS '.$label.PHP_EOL;};
$deny=static function(callable$work,string$label)use($check):void{try{$work();}catch(DomainException|InvalidArgumentException){$check(true,$label);return;}throw new RuntimeException('FAIL '.$label);};
$catalog=new Catalog($db,$fixture['admin']);$languages=new Settings($db,$fixture['admin']);
$config=$languages->state()['config'];$override='Find <&> features 🙂';$config['languages'][]=['code'=>'en','label'=>'English','enabled'=>false,'fallback'=>'de','messages'=>[ManagerMessages::key('Funktion finden')=>$override]];$languages->save($config,0);
$catalog->select('en');$messages=$catalog->messages();$check(ManagerMessages::text('Menü schließen',$messages)==='Close menu'&&ManagerMessages::text('Funktion finden',$messages)===$override,'installed English interface honors the reviewed pack without enabling public English contents');
$deny(fn()=>$catalog->text('unregistered-manager-source'),'unregistered pack key cannot become executable or arbitrary output');
$denied=$config;$denied['languages'][1]['messages']['unregistered-manager-source']='Bad';$deny(fn()=>$languages->save($denied,1),'reviewed pack rejects undeclared frame labels');
$editorAccess=(new Kulturhus\Native\Integration\ManagerBridge($db,$fixture['accounts']['editor']['actor'],'native',static fn()=>null))->access();
$check((new Catalog($db,$editorAccess))->locale()==='de','another genuine editorial identity retains its original language');
$clubName='Redaktion <&> Menü 🙂';$settings=new Kulturhus\Verein\Settings($db,$fixture['accounts']['admin']['actor']);$club=$settings->section('club');$settings->save('club',array_replace($club['data'],['name'=>$clubName]),$club['version']);
$page=(new Kulturhus\Native\Cms\Pages($db,$fixture['admin']))->save(['title'=>'Menü & Redaktion <&> 🙂','slug'=>'own-frame-original','body_html'=>'<p>Menü schließen · menschlicher Originaltext.</p>']);$fixture['page_id']=$page['id'];
$manager=new Kulturhus\Native\Web\Manager($db,$fixture['admin'],$fixture['root'].'/media','own-frame-csrf');$main=$manager->main('website');$html=$manager->shell($main,'Menü schließen');
$check(str_contains($html,'<html lang="en">')&&str_contains($html,'>Editorial team<')&&str_contains($html,'>Skip to content<')&&str_contains($html,'>My account &amp; security<'),'actual native frame uses the account locale for title, navigation, skip link and identity');
$check(str_contains($html,'>Menü schließen</div>'),'saved original notice is escaped but is never translated as a frame label');
$check(str_contains($html,'name="manager-interface"')&&str_contains($html,'/assets/manager-interface.js'),'actual frame carries only declared messages and its registered local script');
$assets=Kulturhus\Native\Web\PublicAssets::sources();$check(isset($assets['/assets/manager-interface.js'])&&hash_file('sha256',$assets['/assets/manager-interface.js'])===hash_file('sha256',dirname(__DIR__).'/public/manager-interface.js'),'native asset endpoint and integrated manager use the exact same fixed script');
$catalog->select('de');$fixture['club_name']=$clubName;$fixture['override']=$override;
Kulturhus\Verein\Database::ensureContributionRates($db);
foreach($fixture['accounts']as$account)Kulturhus\Native\Integration\ManagerBridge::fromNativeSession($db,Kulturhus\Native\Auth\Runtime::service($db),$account['token'])->access();
if(getenv('DVM_MANAGER_INTERFACE_BROWSER')==='1'){
 $cfg=TestSupport::config();$runtime=dirname((string)getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$cfg['database'];foreach([$runtime,$runtime.'/sessions']as$dir)mkdir($dir,0700);copy($fixture['root'].'/auth.key',$runtime.'/auth.key');chmod($runtime.'/auth.key',0600);
 $fixture['native_runtime']=$runtime;$fixture['media_root']=$fixture['root'].'/media';$fixture['origins']=[];$fixture['flows']=[];
 foreach(['native','integrated']as$app){$socket=stream_socket_server('tcp://127.0.0.1:0');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);$fixture['origins'][$app]='http://127.0.0.1:'.$port;foreach(['js','basic']as$mode)$fixture['flows'][$app][$mode]=$fixture['accounts']['admin'];}
 $tables=array_values(array_diff($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),['native_auth_sessions']));$nativeAdmin=$fixture['admin']->user()['id'];
 $filter=static fn(string$table):array=>$table==='native_language_preferences'?[' WHERE NOT (club_id=? AND actor_id=?)',[$fixture['admin']->club(),$nativeAdmin]]:['',[]];$before=ownOriginalDatabaseHash($db,$tables,$filter);
 $keys=[];foreach(['auth','private']as$name)$keys[$fixture['root'].'/'.$name.'.key']=hash_file('sha256',$fixture['root'].'/'.$name.'.key');$keys[$runtime.'/auth.key']=hash_file('sha256',$runtime.'/auth.key');
 $fixture['result_file']=$fixture['root'].'/interface-result.json';$publicFixture=$fixture;unset($publicFixture['admin']);$path=$fixture['root'].'/interface-fixture.json';file_put_contents($path,json_encode($publicFixture,JSON_THROW_ON_ERROR));chmod($path,0600);putenv('DVM_MANAGER_INTERFACE_FIXTURE='.$path);$servers=[];
 try{
  foreach($fixture['origins']as$origin){$port=parse_url($origin,PHP_URL_PORT);$log=fopen($fixture['root'].'/interface-'.$port.'.log','wb');$process=proc_open([...TestSupport::phpCommand(),'-S','127.0.0.1:'.$port,'-t',$fixture['root'].'/web',__DIR__.'/support/manager-interface-browser-router.php'],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);if(!is_resource($process))throw new RuntimeException('Own frame server unavailable.');fclose($pipes[0]);$servers[]=[$process,$log];for($i=0;$i<100;$i++){$probe=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1);if($probe){fclose($probe);break;}usleep(50000);}}
  $process=proc_open(['node',__DIR__.'/native-manager-interface-browser.mjs'],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes);if(!is_resource($process)||proc_close($process)!==0)throw new RuntimeException('Actual bilingual manager frame browser failed.');
 }finally{foreach($servers as[$process,$log]){proc_terminate($process);proc_close($process);fclose($log);}$check(ownOriginalDatabaseHash($db,$tables,$filter)===$before,'bilingual browser and account language choices retain all complete business, source, rights, profile, key and prior-history originals');foreach($keys as$path=>$hash)$check(hash_file('sha256',$path)===$hash,'every original private key stays exact after bilingual browser activity');}
 $check($catalog->locale()==='de','actual final account language choice is reflected by the original preference service');
}
echo json_encode(['suite'=>'native-manager-interface','checks'=>$n,'external_actions'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
