<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';require dirname(__DIR__).'/src/bootstrap.php';
require __DIR__.'/support/original-database-hash.php';require __DIR__.'/support/editorial-browser-fixture.php';
use Kulturhus\Native\{TestSupport,Installer\SchemaRegistry,Language\AccountMessages,Language\Catalog,Language\Settings,Shared\Db};
$db=TestSupport::db();SchemaRegistry::install($db);TestSupport::seed($db);$fixture=ownEditorialBrowserFixture($db);$n=0;
$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException('FAIL '.$label);$n++;echo 'PASS '.$label.PHP_EOL;};
$catalog=new Catalog($db,$fixture['admin']);$languages=new Settings($db,$fixture['admin']);$config=$languages->state()['config'];
$fixture['reviewed_button']='Store <&> choices 🙂';
$config['languages'][]=['code'=>'en','label'=>'English','enabled'=>true,'fallback'=>'de','messages'=>[AccountMessages::key('Benachrichtigungen speichern')=>$fixture['reviewed_button']]];
$config['languages'][]=['code'=>'fr','label'=>'Français','enabled'=>true,'fallback'=>'en','messages'=>[AccountMessages::key('Anmeldung & Sicherheit')=>'Protection personnelle <&>']];$languages->save($config,0);
$catalog->select('fr');$pack=$catalog->messages();
$check(AccountMessages::text('Benachrichtigungen speichern',$catalog)===$fixture['reviewed_button'],'uninstalled French pack keeps reviewed English account fallback');
$check(AccountMessages::text('Anmeldung & Sicherheit',$catalog)==='Protection personnelle <&>','own reviewed French label overrides its English fallback');
$check($catalog->text(Kulturhus\Native\Language\ManagerMessages::key('Menü schließen'))==='Close menu','uninstalled locale also keeps installed manager-frame fallback');
$check(AccountMessages::text('Menschlicher Originaltext <&>',$catalog)==='Menschlicher Originaltext <&>','unregistered human source remains byte-identical');
try{AccountMessages::browser($pack,['Menschlicher Originaltext']);throw new RuntimeException('Undeclared widget source accepted');}catch(InvalidArgumentException){$check(true,'widget payload rejects arbitrary undeclared human source');}
$check(AccountMessages::browser($pack,['Benachrichtigungen speichern'])===['Benachrichtigungen speichern'=>$fixture['reviewed_button']],'widget payload contains only requested fixed labels and preserves reviewed bytes');
$catalog->select('de');
Kulturhus\Verein\Database::ensureContributionRates($db);
foreach($fixture['accounts']as$account)Kulturhus\Native\Integration\ManagerBridge::fromNativeSession($db,Kulturhus\Native\Auth\Runtime::service($db),$account['token'])->access();
$fixture['human_name']='Benachrichtigungen <&> Eigenes Original 🙂';
$contact=(new Kulturhus\Verein\Registry($db,$fixture['accounts']['admin']['actor']))->saveContact(null,0,'person',$fixture['human_name'],'own-account-person@example.invalid','Own fixture address');
$fixture['contact']=$contact;
Db::run($db,'UPDATE actors SET contact_id=? WHERE id=?',[$contact,$fixture['accounts']['admin']['actor']]);
Kulturhus\Native\Integration\ManagerBridge::fromNativeSession($db,Kulturhus\Native\Auth\Runtime::service($db),$fixture['accounts']['admin']['token'])->access();
$image=imagecreatetruecolor(60,24);imagefill($image,0,0,imagecolorallocate($image,255,255,255));imageline($image,4,18,52,5,imagecolorallocate($image,20,40,90));ob_start();imagepng($image);$png=(string)ob_get_clean();unset($image);
$signature=new Kulturhus\Verein\BoardSignature($db,$fixture['accounts']['admin']['actor']);$signature->save($png,$signature->state()['version'],true);
$fixture['upload']=$fixture['root'].'/own-signature.png';file_put_contents($fixture['upload'],$png);
if(getenv('DVM_ACCOUNT_INTERFACE_BROWSER')==='1'){
 $editorAccess=Kulturhus\Native\Integration\ManagerBridge::fromNativeSession($db,Kulturhus\Native\Auth\Runtime::service($db),$fixture['accounts']['editor']['token'])->access();(new Catalog($db,$editorAccess))->select('en');
 $cfg=TestSupport::config();$runtime=dirname((string)getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$cfg['database'];foreach([$runtime,$runtime.'/sessions']as$dir)if(!is_dir($dir))mkdir($dir,0700);copy($fixture['root'].'/auth.key',$runtime.'/auth.key');chmod($runtime.'/auth.key',0600);
 $fixture['native_runtime']=$runtime;$fixture['media_root']=$fixture['root'].'/media';$fixture['origins']=[];$fixture['flows']=[];
 foreach(['native','integrated']as$app){$socket=stream_socket_server('tcp://127.0.0.1:0');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);$fixture['origins'][$app]='http://127.0.0.1:'.$port;$fixture['flows'][$app]=['js'=>$fixture['accounts']['admin'],'basic'=>$fixture['accounts']['admin'],'editor'=>$fixture['accounts']['editor']];}
 $tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$actor=$fixture['accounts']['admin']['actor'];$nativeActor=$fixture['admin']->user()['id'];$club=$fixture['club'];$nativeClub=$fixture['admin']->club();
 $filter=static fn(string $table):array=>match($table){
  'native_language_preferences'=>[' WHERE NOT (club_id=? AND actor_id=?)',[$nativeClub,$nativeActor]],
  'native_auth_sessions'=>[' WHERE token_hash NOT IN (?,?)',[hash('sha256',$fixture['accounts']['admin']['token']),hash('sha256',$fixture['accounts']['editor']['token'])]],
  'club_settings'=>[" WHERE NOT (club_id=? AND section IN (?, 'paper'))",[$club,'account:'.$actor]],
  'notification_preferences'=>[' WHERE NOT (club_id=? AND actor_id=?)',[$club,$actor]],
  default=>['',[]],
 };
 // New personal revisions are legitimate. Every complete pre-existing row, including
 // immutable histories and assets, is retained except the explicitly edited own settings.
 $retainedKeys=[];foreach($tables as$table){$primary=Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);if(!$primary)throw new RuntimeException('Original primary key required');[$where,$args]=$filter($table);$retainedKeys[$table]=[$primary,Db::run($db,'SELECT `'.implode('`,`',$primary).'` FROM `'.$table.'`'.$where,$args)->fetchAll(PDO::FETCH_NUM)];}
 $retainedFilter=static function(string$table)use($retainedKeys):array{[$primary,$rows]=$retainedKeys[$table];if(!$rows)return[' WHERE 0=1',[]];$args=[];foreach($rows as$row)array_push($args,...$row);return[' WHERE (`'.implode('`,`',$primary).'`) IN ('.implode(',',array_fill(0,count($rows),'('.implode(',',array_fill(0,count($primary),'?')).')')).')',$args];};
 $before=ownOriginalDatabaseHash($db,$tables,$retainedFilter);$keys=[];foreach([$fixture['root'].'/auth.key',$fixture['root'].'/private.key',$runtime.'/auth.key',$fixture['upload']]as$path)$keys[$path]=hash_file('sha256',$path);
 $originalSignature=$signature->asset();$fixture['result_file']=$fixture['root'].'/account-interface-result.json';$public=$fixture;unset($public['admin']);$file=$fixture['root'].'/account-interface.json';file_put_contents($file,json_encode($public,JSON_THROW_ON_ERROR));chmod($file,0600);putenv('DVM_ACCOUNT_INTERFACE_FIXTURE='.$file);$servers=[];
 try{
  foreach($fixture['origins']as$origin){$port=parse_url($origin,PHP_URL_PORT);$log=fopen($fixture['root'].'/account-interface-'.$port.'.log','wb');$process=proc_open([...TestSupport::phpCommand(),'-S','127.0.0.1:'.$port,'-t',$fixture['root'].'/web',__DIR__.'/support/account-interface-browser-router.php'],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);if(!is_resource($process))throw new RuntimeException('Owned account interface server unavailable');fclose($pipes[0]);$servers[]=[$process,$log];for($i=0;$i<100;$i++){$probe=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1);if($probe){fclose($probe);break;}usleep(50000);}}
  $process=proc_open(['node',__DIR__.'/native-account-interface-browser.mjs'],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes);if(!is_resource($process)||proc_close($process)!==0)throw new RuntimeException('Actual account interface browser failed');
 }finally{
  foreach($servers as[$process,$log]){proc_terminate($process);proc_close($process);fclose($log);}
  $check(ownOriginalDatabaseHash($db,$tables,$retainedFilter)===$before,'actual account changes retain every complete unrelated row and every pre-existing revision, audit, signature asset and source original');
  foreach($keys as$path=>$hash)$check(is_file($path)&&hash_file('sha256',$path)===$hash,'original private key or upload bytes remain exact');
  $retained=Db::run($db,'SELECT id,mime,bytes,sha256 FROM settings_assets WHERE id=?',[$originalSignature['id']])->fetch();$check($retained===$originalSignature,'previous complete signature asset remains immutable after real replacement');
 }
}
echo json_encode(['suite'=>'native-account-interface','checks'=>$n,'external_actions'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
