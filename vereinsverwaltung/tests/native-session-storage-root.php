<?php declare(strict_types=1);
/** No website/accounts: an isolated installation copy exercises real __DIR__ resolution. */
if(PHP_SAPI!=='cli')exit(1);
ob_start();$n=0;$cwd=getcwd();
$base=str_replace('\\','/',sys_get_temp_dir()).'/kh-native-root-'.bin2hex(random_bytes(12));
$web=$base.'/subscription/example.invalid/httpdocs';$store=$base.'/subscription/tmp/kulturhus-native-sessions';
mkdir($web.'/site/src',0700,true);mkdir($web.'/vereinsverwaltung',0700);mkdir($base.'/subscription/tmp',0700);
copy(__DIR__.'/../../processwire/site/src/NativeSessionStorage.php',$web.'/site/src/NativeSessionStorage.php');
require $web.'/site/src/NativeSessionStorage.php';
$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException($label);++$n;echo "PASS $label\n";};
$configuration=static fn(string $root):object=>(object)['paths'=>(object)['root'=>$root],'sessionName'=>'KH_ROOT_TEST','sessionNameSecure'=>''];
try{
 foreach([$web,$web.'/vereinsverwaltung'] as $working){
  chdir($working);$config=$configuration('.');\Kulturhus\NativeSessionStorage::configure($config);
  $check($config->kulturhusNativeSessionStoragePath===realpath($store),'relative bootstrap root uses canonical private store from '.basename($working));
  $check($config->sessionName==='KH_ROOT_TEST_v2'&&(int)ini_get('session.gc_probability')===0,'same namespace and disabled file GC in both entry points');
 }
 $check(!is_dir($base.'/subscription/example.invalid/tmp'),'external manager bootstrap never creates a second, too-deep store');
 $config=$configuration($web.'/vereinsverwaltung');$config->kulturhusNativeSessionPath=$web.'/unsafe-sessions';
 try{\Kulturhus\NativeSessionStorage::configure($config);throw new LogicException('public storage accepted');}
 catch(RuntimeException){$check(!is_dir($web.'/unsafe-sessions'),'canonical webroot rejected even when configured root is only its subdirectory');}
 $config=$configuration($web.'/vereinsverwaltung');$config->kulturhusNativeSessionPath=$base.'/explicit-private';\Kulturhus\NativeSessionStorage::configure($config);
 $check($config->kulturhusNativeSessionStoragePath===realpath($base.'/explicit-private'),'explicit safe private override remains supported');
 echo "$n native session root checks passed on PHP ".PHP_VERSION."\n";
}finally{
 chdir($cwd);
 $remove=function(string $path)use(&$remove,$base):void{if($path!==$base&&!str_starts_with($path,$base.'/'))throw new RuntimeException('Unsafe fixture path');if(is_file($path)||is_link($path)){unlink($path);return;}foreach(new DirectoryIterator($path)as$f)if(!$f->isDot())$remove(str_replace('\\','/',$f->getPathname()));rmdir($path);};
 $base=str_replace('\\','/',$base);$remove($base);ob_end_flush();
}
