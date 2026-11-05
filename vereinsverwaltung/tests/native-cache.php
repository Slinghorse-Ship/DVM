<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
require_once dirname(__DIR__).'/src/Infrastructure/Runtime/ProductionRuntime.php';
require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\Cache\{Store,Administration,Controller,View};
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\TestSupport;

$db=TestSupport::db();if(getenv('DVM_CACHE_BROWSER')==='1'){require dirname(__DIR__).'/src/bootstrap.php';Kulturhus\Native\Installer\SchemaRegistry::install($db);}TestSupport::seed($db);$root=sys_get_temp_dir().'/dvm-cache-'.bin2hex(random_bytes(10));mkdir($root,0700);$now=1900000000;$clock=static function()use(&$now):int{return $now;};$a=new Store($root,'synthetic-a',clock:$clock);$b=new Store($root,'synthetic-b',clock:$clock);$checks=0;
function cacheCheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);++$GLOBALS['checks'];echo "PASS $label\n";}
function cacheReject(callable $call,string $label):void{try{$call();}catch(DomainException|InvalidArgumentException|JsonException){cacheCheck(true,$label);return;}throw new RuntimeException($label);}
function cacheTreeRemove(string $path):void{if(is_link($path)||!is_dir($path)){unlink($path);return;}foreach(new DirectoryIterator($path)as$f){if(!$f->isDot())cacheTreeRemove($f->getPathname());}rmdir($path);}
try{
    $original=ownOriginalDatabaseHash($db,['native_users','native_grants']);
    $value=['original'=>'ä🙂 <b>Original</b>','zero'=>0,'false'=>false,'null'=>null,'float'=>1.0,'list'=>[1,2,3]];
    cacheCheck($a->set('modules','first',$value,60,['page:own','navigation'],['generation'=>12]),'cache stores original JSON data and explicit dependencies');
    cacheCheck((new Store($root,'synthetic-a',clock:$clock))->get('modules','first',['generation'=>12])===$value,'fresh service instance reads the exact persistent value');
    cacheCheck(!$a->has('modules','first',['generation'=>13])&&$a->has('modules','first',['generation'=>12]),'only the currently supplied dependency version can hit');
    cacheCheck(!$b->has('modules','first',['generation'=>12])&&$b->listing()['entries']===0,'tenant-bound paths isolate identical keys and metadata');
    $a->set('modules','null',null);cacheCheck($a->has('modules','null')&&$a->get('modules','null')===null,'null values remain distinct from cache misses');
    $a->set('modules','forever','owned',0);$now+=60;cacheCheck(!$a->has('modules','first',['generation'=>12])&&$a->has('modules','forever'),'TTL expires exactly at its boundary and explicit non-expiring entries remain valid');
    cacheCheck($a->cleanup()===1&&!$a->has('modules','first',['generation'=>12]),'cleanup removes expired owned data and reports the actual count');
    $a->set('modules','tag-one','one',60,['page:own']);$a->set('fragments','tag-two','two',60,['page:own']);$a->set('modules','other-tag','keep',60,['page:other']);$b->set('modules','tag-one','foreign',60,['page:own']);
    cacheCheck($a->invalidate('page:own')===2&&$a->has('modules','other-tag')&&$b->get('modules','tag-one')==='foreign','one tag invalidates only its own tenant across namespaces');
    cacheCheck($a->clear('fragments')===0&&$a->has('modules','other-tag'),'selective namespace cleanup preserves unrelated values');
    $a->clear();$calls=0;$produce=static function()use(&$calls):array{++$calls;return ['result'=>'exact'];};
    cacheCheck($a->remember('api','remember',$produce)===['result'=>'exact']&&$a->remember('api','remember',$produce)===['result'=>'exact']&&$calls===1,'remember produces once and later reads persistent data');
    $a->remember('api','race',function()use($a){$a->invalidate('withdrawn');return 'old generation';},60,['withdrawn']);
    cacheCheck(!$a->has('api','race'),'invalidation during rendering prevents a stale cache write');
    $a->remember('api','newer',function()use($a){$a->set('api','newer','newer value');return 'older work';});
    cacheCheck($a->get('api','newer')==='newer value','older concurrent work cannot overwrite a newer explicit cache value');
    try{$a->remember('api','failed',static fn()=>throw new RuntimeException('Own failure'));}catch(RuntimeException){}
    cacheCheck(!$a->has('api','failed'),'failed rendering never creates a partial cache entry');
    foreach([['bad namespace','key','value',60],['ok',"bad\nkey",'value',60],['ok','ttl','value',-1],['ok','ttl','value',2592001],['ok','object',new stdClass(),60],['ok','nan',NAN,60]]as$i=>$args)cacheReject(fn()=>$a->set(...$args),'invalid cache input fails closed '.$i);
    cacheReject(fn()=>$a->set('api','bad-tags','value',60,[[]]),'tag arrays cannot become paths or unbounded metadata');
    cacheReject(fn()=>$a->get('api','remember',['generation'=>[]]),'dependency arrays fail before reading a cached value');
    cacheReject(fn()=>$a->deleteId('../outside'),'entry identifiers cannot address filesystem paths');
    $small=new Store($root,'bounded',3,4096,1024,$clock);for($i=0;$i<12;++$i){++$now;$small->set('bounded','entry-'.$i,str_repeat('x',300),0);}
    cacheCheck($small->listing()['entries']===3&&$small->get('bounded','entry-11')===str_repeat('x',300)&&!$small->has('bounded','entry-0'),'oldest entries are evicted under the configured hard entry limit');
    cacheCheck(!$small->set('bounded','too-large',str_repeat('x',2000))&&$small->listing()['entries']===3,'oversized values bypass persistence without evicting useful cached entries');
    $bytes=new Store($root,'bytes',30,2048,1024,$clock);for($i=0;$i<8;++$i){++$now;$bytes->set('bytes','entry-'.$i,str_repeat('b',600),0);}
    cacheCheck($bytes->listing()['bytes']<=2048&&$bytes->listing()['entries']<8,'byte limits bound persistent storage independently of entry limits');
    $directory=$root.'/scope-'.hash('sha256','synthetic-a');$outside=$root.'/owned-original.txt';file_put_contents($outside,'Own source original');$outsideHash=hash_file('sha256',$outside);$foreign=$directory.'/'.str_repeat('f',64).'.cache.json';symlink($outside,$foreign);file_put_contents($directory.'/unknown-original.txt','Own unknown file');
    cacheCheck($a->clear()>=1&&is_link($foreign)&&hash_file('sha256',$outside)===$outsideHash&&is_file($directory.'/unknown-original.txt'),'cache clearing never follows symlinks or deletes non-cache originals');unlink($foreign);
    file_put_contents($directory.'/'.str_repeat('e',64).'.cache.json','{broken');cacheCheck($a->cleanup()===1,'cleanup removes only owned malformed cache records');
    file_put_contents($directory.'/write-OLD123','Own abandoned atomic write');touch($directory.'/write-OLD123',$now-3601);file_put_contents($directory.'/write-NEW123','Own recent atomic write');touch($directory.'/write-NEW123',$now);cacheCheck($a->cleanup()===1&&!is_file($directory.'/write-OLD123')&&is_file($directory.'/write-NEW123'),'maintenance recovers old interrupted atomic writes while retaining recent files');
    $a->set('persist','process',['original'=>'Separate process ä🙂'],0);
    $code='require $argv[1]; $s=new Kulturhus\\Native\\Cache\\Store($argv[2],"synthetic-a"); echo json_encode($s->get("persist","process"),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);';
    $process=proc_open([...TestSupport::phpCommand(),'-r',$code,dirname(__DIR__).'/native/bootstrap.php',$root],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Own cache child unavailable');fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($process);
    cacheCheck($exit===0&&$err===''&&json_decode($out,true)===['original'=>'Separate process ä🙂'],'a separate PHP process reads the persistent cache without PHP serialization');
    $code='require $argv[1]; $s=new Kulturhus\\Native\\Cache\\Store($argv[2],"synthetic-a"); echo $s->remember("concurrent","old",function()use($argv){file_put_contents($argv[3]."/ready","ready");for($i=0;$i<500&&!is_file($argv[3]."/resume");++$i)usleep(10000);if(!is_file($argv[3]."/resume"))throw new RuntimeException("Own concurrent test timed out");return "old render";},3600,["original-withdrawn"]);';$gate=$root.'/gate';mkdir($gate,0700);
    $process=proc_open([...TestSupport::phpCommand(),'-r',$code,dirname(__DIR__).'/native/bootstrap.php',$root,$gate],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Own concurrent cache child unavailable');fclose($pipes[0]);
    try{for($i=0;$i<500&&!is_file($gate.'/ready');++$i)usleep(10000);if(!is_file($gate.'/ready'))throw new RuntimeException('Own concurrent child did not start');$a->invalidate('original-withdrawn');file_put_contents($gate.'/resume','continue');$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);$process=null;cacheCheck($exit===0&&$err===''&&$out==='old render'&&!$a->has('concurrent','old'),'actual concurrent PHP invalidation prevents an in-flight process from restoring withdrawn cached data');}finally{if(is_resource($process)){proc_terminate($process);proc_close($process);}}
    foreach(glob($directory.'/*.cache.json')as$file)cacheCheck((fileperms($file)&0777)===0600,'persistent cache values use private file permissions');
    $admin=new Administration(new Access($db,'admin'),$root);$reader=new Administration(new Access($db,'reader'),$root);$outsider=new Administration(new Access($db,'outsider'),$root);
    cacheReject(fn()=>$reader->listing(),'read-only website identity cannot inspect cache administration');
    cacheReject(fn()=>Controller::request($admin,'POST',[],['action'=>'cache-clear','csrf'=>'wrong'],'expected'),'wrong CSRF cannot clear the cache');
    cacheReject(fn()=>Controller::request($admin,'POST',[],['action'=>'cache-clear','csrf'=>['wrong']],'expected'),'array CSRF cannot activate an operation');
    cacheReject(fn()=>Controller::request($admin,'DELETE',[],[],'expected'),'HTTP deletion methods are rejected explicitly');
    cacheReject(fn()=>Controller::request($admin,'GET',['cache_page'=>[]],[],'expected'),'array pagination cannot start a listing');
    $a->clear();for($i=0;$i<45;++$i)$a->set('managed','original-'.$i,['PRIVATE-VALUE-DO-NOT-RENDER'=>str_repeat('secret',30)],0,['page:'.$i]);
    $list=Controller::request($admin,'GET',[],[],'expected');cacheCheck(count($list['rows'])===10&&$list['pages']===5&&!str_contains(json_encode($list),'PRIVATE-VALUE'),'authenticated listing is bounded and exposes metadata without cached values');
    cacheCheck(count(Controller::request($admin,'GET',['cache_page'=>'5'],[],'expected')['rows'])===5,'last cache page reaches every remaining original metadata row');
    $view=View::render($admin,'expected',[]);$doc=new DOMDocument();@$doc->loadHTML('<?xml encoding="UTF-8">'.$view);$xpath=new DOMXPath($doc);
    cacheCheck($xpath->query('//article')->length===10&&$xpath->query('//form[@method="post"]//input[@name="csrf"]')->length===13&&!str_contains($view,'PRIVATE-VALUE'),'actual management view contains bounded cards and complete protected forms');
    $entry=$list['rows'][0]['id'];cacheCheck(Controller::request($admin,'POST',[],['action'=>'cache-delete','csrf'=>'expected','entry'=>$entry],'expected')['removed']===1,'protected individual removal uses its current tenant-bound identifier');
    $bOriginal=$b->listing();cacheCheck($outsider->action('cache-clear',[])===$bOriginal['entries']&&$a->listing()['entries']===44,'another administrator can only clear their own tenant');
    Db::run($db,"DELETE FROM native_grants WHERE actor_id='admin' AND module='website' AND capability='manage'");cacheReject(fn()=>$admin->action('cache-clear',[]),'cached administrative service honors immediate permission revocation');Db::run($db,"INSERT INTO native_grants VALUES('admin','website','manage')");
    Db::run($db,"UPDATE native_users SET active=0 WHERE id='admin'");cacheReject(fn()=>$admin->listing(),'inactive identity cannot inspect even existing metadata');Db::run($db,"UPDATE native_users SET active=1 WHERE id='admin'");
    cacheCheck(ownOriginalDatabaseHash($db,['native_users','native_grants'])===$original,'cache operation and permission checks preserve all original accounts and rights');
    $oldMode=getenv('VEREIN_MODE');$oldPrivate=getenv('VEREIN_PRIVATE_ROOT');$productionRoot=$root.'/production-private';$separateMedia=$root.'/separate-volume/media';mkdir($separateMedia,0700,true);$productionStore=new Store($productionRoot.'/public-cache','synthetic-a');$productionStore->set('production-only','source','Own production cache fixture',0);$otherCount=$a->listing()['entries'];
    try{
        putenv('VEREIN_MODE=production');putenv('VEREIN_PRIVATE_ROOT='.$productionRoot);$productionAdmin=Kulturhus\Native\Cache\Factory::manager(new Access($db,'admin'),$separateMedia);
        cacheCheck($productionAdmin->listing()['rows'][0]['namespace']==='production-only','production administration uses the public runtime root even when media live on another volume');
        cacheCheck($productionAdmin->action('cache-clear',[])===1&&$a->listing()['entries']===$otherCount,'production clear affects exactly its configured public cache and preserves other private stores');
        putenv('VEREIN_PRIVATE_ROOT=relative/private');cacheReject(fn()=>Kulturhus\Native\Cache\Factory::manager(new Access($db,'admin'),$separateMedia),'relative production runtime root is rejected before cache access');
        putenv('VEREIN_MODE=synthetic');$syntheticStore=new Store(dirname($separateMedia).'/public-cache','synthetic-a');$syntheticStore->set('synthetic-only','source','Own synthetic cache fixture',0);
        cacheCheck(Kulturhus\Native\Cache\Factory::manager(new Access($db,'admin'),$separateMedia)->listing()['rows'][0]['namespace']==='synthetic-only','synthetic manager and its public router share the media parent runtime');
    }finally{putenv($oldMode===false?'VEREIN_MODE':'VEREIN_MODE='.$oldMode);putenv($oldPrivate===false?'VEREIN_PRIVATE_ROOT':'VEREIN_PRIVATE_ROOT='.$oldPrivate);}
    $htmlCache=new Kulturhus\Native\Web\Cache($root);$renders=0;$render=static function()use(&$renders):string{++$renders;return '<p>Exact public HTML ä🙂</p>';};$htmlCache->remember('public-source',$render,'synthetic-a',['publication'=>1],['website','page:exact'],'own-page');$htmlCache->remember('public-source',$render,'synthetic-a',['publication'=>1],['website','page:exact'],'own-page');
    cacheCheck($renders===1&&$a->listing(1,'website-html')['total']===1,'real HTML adapter uses the same tenant-bound administrative cache');
    $htmlCache->remember('public-source',$render,'synthetic-a',['publication'=>2],['website','page:exact'],'own-page');cacheCheck($renders===2,'changed publication generation invalidates actual HTML hits');$a->invalidate('page:exact');$htmlCache->remember('public-source',$render,'synthetic-a',['publication'=>2],['website','page:exact'],'own-page');cacheCheck($renders===3,'selective administration invalidation regenerates actual HTML without editing its source');
    $benchmark=new Store($root,'benchmark');$source=str_repeat('Own unchanged published HTML ä🙂 ',1024);$renderCalls=0;$produce=static function()use(&$renderCalls,$source):string{++$renderCalls;$proof='';for($i=0;$i<60;++$i)$proof=hash('sha256',$source.$proof);return '<article>'.htmlspecialchars($source,ENT_QUOTES,'UTF-8').'<i>'.$proof.'</i></article>';};
    $begin=hrtime(true);for($i=0;$i<40;++$i){$benchmark->clear('benchmark');$cold=$benchmark->remember('benchmark','same-public-original',$produce);}$coldNs=hrtime(true)-$begin;$coldCalls=$renderCalls;$benchmark->clear('benchmark');$renderCalls=0;$begin=hrtime(true);for($i=0;$i<40;++$i)$warm=$benchmark->remember('benchmark','same-public-original',$produce);$warmNs=hrtime(true)-$begin;
    cacheCheck($cold===$warm&&$coldCalls===40&&$renderCalls===1&&$benchmark->listing()['entries']===1,'bounded 40-request comparison preserves exact HTML while avoiding 39 real render workloads');
    echo json_encode(['suite'=>'native-cache','checks'=>$checks,'comparison'=>['requests'=>40,'cold_render_calls'=>$coldCalls,'warm_render_calls'=>$renderCalls,'cold_ms'=>round($coldNs/1e6,2),'warm_ms'=>round($warmNs/1e6,2),'identical_bytes'=>true],'external_actions'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
    if(getenv('DVM_CACHE_BROWSER')==='1'){require __DIR__.'/support/cache-browser-fixture.php';ownCacheBrowser($db);}
}finally{cacheTreeRemove($root);}
