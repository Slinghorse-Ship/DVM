<?php declare(strict_types=1);
/** Independent storage/cleanup regression. All files are synthetic in one private temporary tree. */
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../../processwire/site/src/NativeSessionStorage.php';
use Kulturhus\NativeSessionStorage;
ob_start();$count=0;
function ok(bool $value,string $label):void { global $count; if(!$value)throw new RuntimeException($label); ++$count; echo "PASS $label\n"; }
function rejects(callable $work,string $label):void { try{$work();}catch(RuntimeException){ok(true,$label);return;}throw new RuntimeException($label); }
$base=sys_get_temp_dir().'/kh-native-storage-adversarial-'.bin2hex(random_bytes(10));
if(!mkdir($base,0700)||!mkdir($base.'/web',0700)||!mkdir($base.'/release',0700)||!mkdir($base.'/backups',0700))throw new RuntimeException('Private fixture setup failed');
$base=realpath($base);if($base===false)throw new RuntimeException('Private fixture resolution failed');
$oldBackup=getenv('BACKUP_TARGET_PATH');putenv('BACKUP_TARGET_PATH='.$base.'/backups');
$configFor=static fn(string $path):object=>(object)['paths'=>(object)['root'=>$base.'/web/'],'sessionName'=>'KH_STORAGE_TEST','sessionNameSecure'=>'KH_STORAGE_SECURE','kulturhusVereinRoot'=>$base.'/release','kulturhusNativeSessionPath'=>$path];
$config=$configFor($base.'/private');$locked=null;
try {
 NativeSessionStorage::configure($config);$store=$config->kulturhusNativeSessionStoragePath;
 ok($store===realpath($base.'/private')&&session_save_path()===$store,'private store selected outside webroot and release');
 ok($config->sessionName==='KH_STORAGE_TEST_v2'&&$config->sessionNameSecure==='KH_STORAGE_SECURE_v2','both native cookie names change at cutover');
 NativeSessionStorage::configure($config);ok($config->sessionName==='KH_STORAGE_TEST_v2','repeated configuration does not append another namespace');
 ok((int)ini_get('session.gc_probability')===0&&(int)ini_get('session.use_strict_mode')===1,'native random GC disabled and unknown SIDs rejected');
 if(DIRECTORY_SEPARATOR!=='\\')ok((fileperms($store)&0777)===0700,'private directory denies group and world access');
 foreach([86400,2592000,0] as $idle){$mixed=$configFor($store);$mixed->sessionExpireSeconds=$idle;ini_set('session.gc_probability','1');NativeSessionStorage::configure($mixed);ok((int)ini_get('session.gc_probability')===0,'mixed idle '.$idle.' never enables a short file GC');}
 $now=time()+7200;$old=$now-NativeSessionStorage::RETENTION_SECONDS-1;
 $make=static function(string $name,int $mtime)use($store):string{$file=$store.'/'.$name;file_put_contents($file,'synthetic-session-payload');chmod($file,0600);touch($file,$mtime);return $file;};
 $expired=$make('sess_'.str_repeat('a',32),$old);$fresh=$make('sess_'.str_repeat('b',32),$now);
 $boundary=$make('sess_'.str_repeat('c',32),$now-NativeSessionStorage::RETENTION_SECONDS);
 $margin=$make('sess_'.str_repeat('d',32),$now-400*86400);$held=$make('sess_'.str_repeat('e',32),$old);
 $unrelated=$make('unrelated-old-file',$old);$fakeDirectory=$store.'/sess_'.str_repeat('f',32);mkdir($fakeDirectory,0700);touch($fakeDirectory,$old);
 $locked=fopen($held,'r+b');if(!$locked||!flock($locked,LOCK_EX|LOCK_NB))throw new RuntimeException('Lock fixture failed');
 $external=$base.'/outside-store';file_put_contents($external,'must remain unchanged');touch($external,$old);$linked=$store.'/sess_'.str_repeat('g',32);
 $links=DIRECTORY_SEPARATOR!=='\\'&&function_exists('symlink');if($links&&!symlink($external,$linked))throw new RuntimeException('Symlink fixture failed');
 $r=NativeSessionStorage::cleanup($config,$now);clearstatcache();
 ok(!$r['skipped']&&!is_file($expired),'expired unlocked native file removed');
 ok(is_file($held),'locked native file retained without waiting');
 ok(is_file($fresh)&&is_file($boundary)&&is_file($margin),'fresh, boundary and 400-day-margin files retained');
 ok(is_file($unrelated)&&is_dir($fakeDirectory),'non-session file and similarly named directory untouched');
 if($links)ok(is_link($linked)&&file_get_contents($external)==='must remain unchanged','session symlink never followed or removed');
 ok(NativeSessionStorage::cleanup($config,$now+1)['skipped'],'cleanup throttled within one hour');
 touch($held,$now);flock($locked,LOCK_UN);fclose($locked);$locked=null;
 NativeSessionStorage::cleanup($config,$now+3601);clearstatcache(true,$held);ok(is_file($held),'recently refreshed file survives after its lock is released');
 $marker=$store.'/.kulturhus-native-sessions-v2';$markerBytes=file_get_contents($marker);file_put_contents($marker,'wrong store');
 rejects(fn()=>NativeSessionStorage::cleanup($config,$now+7202),'poisoned marker fails closed');file_put_contents($marker,$markerBytes);
 if($links){$cleanup=$store.'/.cleanup.lock';unlink($cleanup);symlink($external,$cleanup);rejects(fn()=>NativeSessionStorage::cleanup($config,$now+7202),'cleanup lock symlink rejected');ok(file_get_contents($external)==='must remain unchanged','rejected lock did not truncate its target');unlink($cleanup);}
 foreach(['web/sessions','release/sessions','backups/sessions'] as $bad)rejects(fn()=>NativeSessionStorage::configure($configFor($base.'/'.$bad)),'storage rejected inside '.$bad);
 rejects(fn()=>NativeSessionStorage::configure($configFor('relative/session-store')),'relative store rejected');
 rejects(fn()=>NativeSessionStorage::configure($configFor('2;0600;'.$store)),'PHP hierarchical save_path syntax rejected');
 $legacy=$base.'/legacy';mkdir($legacy,0700);file_put_contents($legacy.'/sess_'.str_repeat('h',32),'legacy authenticated fixture');
 rejects(fn()=>NativeSessionStorage::configure($configFor($legacy)),'unmarked existing legacy directory never adopted');
 ok(count(glob($store.'/sess_*'))<2048&&!is_file($store.'/sess_'.str_repeat('h',32)),'legacy file not copied into private store');
 if($links){$alias=$base.'/store-link';symlink($store,$alias);rejects(fn()=>NativeSessionStorage::configure($configFor($alias)),'symlinked store rejected');}

 // Force a valid resumable cursor at an old tail entry, after more than one batch
 // of fresh entries. Physical directory ordering is discovered, never assumed.
 $large=$configFor($base.'/large');NativeSessionStorage::configure($large);$largePath=$large->kulturhusNativeSessionStoragePath;
 for($i=0;$i<2100;$i++){ $file=$largePath.'/sess_'.str_pad(dechex($i),32,'0',STR_PAD_LEFT);file_put_contents($file,'fresh synthetic');touch($file,$now); }
 $ordered=[];foreach(new DirectoryIterator($largePath) as $entry)if(str_starts_with($entry->getFilename(),'sess_'))$ordered[(int)$entry->key()]=$entry->getPathname();
 $tailKey=array_key_last($ordered);$tail=$ordered[$tailKey];touch($tail,$old);
 $cursor=$largePath.'/.cleanup.lock';file_put_contents($cursor,json_encode(['at'=>0,'next'=>0]));
 $first=NativeSessionStorage::cleanup($large,$now);ok($first['limited']&&$first['scanned']<=2048,'first cleanup batch stays bounded');
 $second=NativeSessionStorage::cleanup($large,$now+3601);clearstatcache(true,$tail);
 ok(!is_file($tail),'resumed cleanup reaches expired tail beyond first 2048 fresh entries');
 ok($second['scanned']<=2048&&$second['removed']<=128,'resumed batch preserves scan and deletion bounds');
 echo "$count adversarial native-session storage checks passed on PHP ".PHP_VERSION."\n";
} finally {
 if(is_resource($locked)){flock($locked,LOCK_UN);fclose($locked);}
 putenv($oldBackup===false?'BACKUP_TARGET_PATH':'BACKUP_TARGET_PATH='.$oldBackup);
 // Confined cleanup; links are unlinked rather than followed.
 $remove=function(string $path)use(&$remove,$base):void{if($path!==$base&&!str_starts_with($path,$base.DIRECTORY_SEPARATOR)&&!str_starts_with($path,$base.'/'))throw new RuntimeException('Fixture cleanup escaped its root');if(is_link($path)||is_file($path)){unlink($path);return;}foreach(new DirectoryIterator($path) as $entry)if(!$entry->isDot())$remove($entry->getPathname());rmdir($path);};$remove($base);ob_end_flush();
}
