<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\Extensions\{Contract,Event,Hooks,Urls,Runtime,Definition,Values};
$checks=0;
function extHookCheck(bool $condition,string $label):void{if(!$condition)throw new RuntimeException($label);$GLOBALS['checks']++;echo "PASS $label\n";}
function extHookDenied(callable $action,string $label):void{try{$action();}catch(DomainException){extHookCheck(true,$label);return;}throw new RuntimeException($label);}
$guard=true;$calls=0;$order=[];$hooks=new Hooks();
$permission=static function()use(&$guard):bool{return $guard;};
$args=static fn(array $values):bool=>array_keys($values)===['number']&&is_int($values['number'])&&$values['number']>=0;
$result=static fn(mixed $value):bool=>is_int($value)&&$value>=0;
$hooks->define(new Contract('test.method','method',$permission,$args,$result,true));
$hooks->on('first-plugin','test.method','before',static function(Event $event)use(&$order):void{$order[]='before-first';$values=$event->arguments();$values['number']++;$event->setArguments($values);},-1);
$hooks->on('late-plugin','test.method','before',static function(Event $event)use(&$order):void{$order[]='before-last';});
$hooks->on('bad-plugin','test.method','before',static function(Event $event):void{$event->setArguments(['number'=>-1]);},5);
$hooks->on('error-plugin','test.method','before',static function(Event $event):void{throw new RuntimeException('private-account-password-never-log');},6);
$hooks->on('after-plugin','test.method','after',static function(Event $event)use(&$order):void{$order[]='after';$event->setResult($event->result()+5);});
$value=$hooks->dispatch('test.method',['number'=>3],static function(array $values)use(&$calls,&$order):int{$calls++;$order[]='original';return $values['number']*2;});
extHookCheck($value===13&&$calls===1&&$order===['before-first','before-last','original','after'],'prioritized before and after callbacks wrap the actual original once');
extHookCheck(count($hooks->failures())===2&&!str_contains(json_encode($hooks->failures()),'password'),'invalid mutation and thrown error are isolated with closed redacted diagnostics');
extHookDenied(fn()=>$hooks->dispatch('test.method',['number'=>'3'],static fn()=>0),'wrong argument type rejected before any callback');
extHookDenied(fn()=>$hooks->define(new Contract('test.method','method',$permission,$args,$result)),'host contracts cannot be replaced by a second registration');
extHookDenied(fn()=>$hooks->on('bad-plugin','missing.method','after',static fn()=>null),'unknown contracts cannot be registered');
extHookDenied(fn()=>$hooks->on('bad-plugin','test.method','during',static fn()=>null),'unknown phases are rejected');
extHookDenied(fn()=>$hooks->on('bad-plugin','test.method','after',static fn()=>null,1001),'priority range is bounded');
$hooks->remove('first-plugin');$order=[];extHookCheck($hooks->dispatch('test.method',['number'=>3],static fn(array $v):int=>$v['number'])===8,'removing one plugin removes only its callbacks');
$guard=false;extHookDenied(fn()=>$hooks->dispatch('test.method',['number'=>3],static fn()=>0),'current host permission is required before dispatch');$guard=true;

$replace=new Hooks();$replace->define(new Contract('test.replace','method',$permission,$args,$result,true));$writes=0;
$replace->on('wrapper-plugin','test.replace','replace',static function(Event $event,Closure $next):int{$a=$next();$b=$next();if($a!==$b)throw new RuntimeException('inconsistent continuation');throw new RuntimeException('wrapper failed after original');});
extHookCheck($replace->dispatch('test.replace',['number'=>1],static function()use(&$writes):int{$writes++;return 7;})===7&&$writes===1,'repeated continuation and failure after a write never repeat the original');
$replace->remove('wrapper-plugin');
$replace->on('replacement-plugin','test.replace','replace',static fn(Event $event,Closure $next):int=>42);
extHookCheck($replace->dispatch('test.replace',['number'=>1],static function()use(&$writes):int{$writes++;return 7;})===42&&$writes===1,'an expressly replaceable contract may replace its original');
$replace->remove('replacement-plugin');
$replace->on('swallow-plugin','test.replace','replace',static function(Event $event,Closure $next):int{try{$next();}catch(Throwable){}return 0;});
try{$replace->dispatch('test.replace',['number'=>1],static function():int{throw new LogicException('original-business-failure');});throw new RuntimeException('not thrown');}catch(LogicException $error){extHookCheck($error->getMessage()==='original-business-failure','a plugin cannot turn a failed original business operation into success');}

$locked=new Hooks();$locked->define(new Contract('test.property','property',$permission,static fn(array $a):bool=>true,static fn(mixed $v):bool=>is_string($v)));
extHookDenied(fn()=>$locked->on('bad-plugin','test.property','replace',static fn()=>''),'nonreplaceable property cannot bypass its host original');
$locked->on('property-plugin','test.property','after',static function(Event $e):void{$e->setResult($e->result().' suffix');});
extHookCheck($locked->dispatch('test.property',[],static fn():string=>'Original')==='Original suffix','property contracts support typed after mutation');
$locked->on('bad-plugin','test.property','after',static function(Event $e):void{$e->setArguments([]);});
extHookCheck($locked->dispatch('test.property',[],static fn():string=>'Original')==='Original suffix','phase restrictions preserve the last valid property value');

foreach(['before','original','after'] as $phase){
    $guard=true;$test=new Hooks();$test->define(new Contract('test.revoke','method',$permission,$args,$result));$originalCalls=0;
    if($phase!=='original')$test->on('revoking-plugin','test.revoke',$phase,static function(Event $e)use(&$guard):void{$guard=false;});
    extHookDenied(function()use($test,$phase,&$guard,&$originalCalls):void{$test->dispatch('test.revoke',['number'=>1],static function()use($phase,&$guard,&$originalCalls):int{$originalCalls++;if($phase==='original')$guard=false;return 1;});},'permission revoked during '.$phase.' aborts the entire operation');
    extHookCheck($originalCalls===($phase==='before'?0:1),'revocation during '.$phase.' never replays an original');
}
$guard=true;
$recursive=new Hooks();$recursive->define(new Contract('test.recursion','method',$permission,$args,$result));
$recursive->on('recursive-plugin','test.recursion','before',static function(Event $e)use($recursive):void{$recursive->dispatch('test.recursion',['number'=>1],static fn():int=>1);});
extHookCheck($recursive->dispatch('test.recursion',['number'=>1],static fn():int=>9)===9&&count($recursive->failures())===1,'recursive callback is isolated and original remains available');
$reference='original';$copyEvent=new Event('test.references','before',['nested'=>['reference'=>&$reference]]);$mutated=$copyEvent->arguments();$mutated['nested']['reference']='changed';
extHookCheck($reference==='original'&&$copyEvent->arguments()['nested']['reference']==='original','detached events cannot mutate caller references without contract validation');
extHookDenied(fn()=>$hooks->dispatch('test.method',['number'=>new stdClass()],static fn()=>0),'objects never enter extension event snapshots');
extHookCheck(!Values::plain(INF)&&!Values::plain(fopen('php://memory','r')),'nonfinite numbers and resources are not hook values');

$urlHooks=new Hooks();$urls=new Urls($urlHooks);$urlGuard=true;
$urlContract=new Contract('test.url','url',static function()use(&$urlGuard):bool{return $urlGuard;},static fn(array $a):bool=>array_keys($a)===['slug'],Runtime::validResponse(...),true);
$urls->register('/extensions/local-test/{slug}',$urlContract,static fn(array $a):array=>['status'=>200,'headers'=>['Content-Type'=>'application/json; charset=utf-8'],'body'=>json_encode($a)]);
extHookCheck($urls->dispatch('GET','/extensions/local-test/own-item')['body']==='{"slug":"own-item"}','URL contract resolves a bounded named parameter');
extHookCheck($urls->dispatch('HEAD','/extensions/local-test/own-item')['body']==='','HEAD runs the same authorization while omitting its body');
extHookCheck($urls->dispatch('POST','/extensions/local-test/own-item')['status']===405,'extension URL dispatcher rejects writes');
extHookCheck($urls->dispatch('GET','/login')===null,'extension URL dispatcher leaves existing routes to their host');
foreach(['/extensions/local-test/%2e%2e','/extensions/local-test/../login','/extensions/local-test/one?token=private','/extensions/local-test/a%2fb'] as $path)extHookCheck($urls->dispatch('GET',$path)['status']===404,'ambiguous or encoded URL is not dispatched: '.$path);
extHookDenied(fn()=>$urls->register('/login',new Contract('test.login','url',$permission,$args,$result),static fn()=>0),'extensions cannot register authentication or other protected routes');
extHookDenied(fn()=>$urls->register('/extensions/local-test/fixed',new Contract('test.collision','url',$permission,$args,$result),static fn()=>0),'literal route cannot ambiguously overlap an existing parameter route');
$urlHooks->on('url-plugin','test.url','after',static function(Event $e):void{$v=$e->result();$v['headers']['Set-Cookie']='privilege=admin';$e->setResult($v);});
extHookCheck(!isset($urls->dispatch('GET','/extensions/local-test/own-item')['headers']['Set-Cookie']),'response validator isolates attempts to overwrite session or security headers');
$urlGuard=false;extHookDenied(fn()=>$urls->dispatch('GET','/extensions/local-test/own-item'),'URL hooks never bypass their current authorization');

$definition=new Definition('form-test','Form test','1.0.0',[],['flag'=>['type'=>'boolean','label'=>'Flag','default'=>false],'size'=>['type'=>'integer','label'=>'Size','default'=>1,'min'=>1,'max'=>10],'mode'=>['type'=>'select','label'=>'Mode','default'=>'a','options'=>['a'=>'A','b'=>'B']]]);
extHookCheck($definition->configuration(['flag'=>true,'size'=>5,'mode'=>'b'])===['flag'=>true,'size'=>5,'mode'=>'b'],'typed configuration normalizes valid fields');
foreach([['flag'=>'false'],['flag'=>null],['size'=>11],['size'=>'2'],['mode'=>'unknown'],['not_registered'=>'x']] as $invalid)extHookDenied(fn()=>$definition->configuration($invalid),'typed configuration rejects invalid or unknown values');
foreach(['https://outside.invalid/','//outside.invalid/','/../login','/ok%0aheader','/ok\\bad'] as $path)extHookCheck(!Values::localPath($path),'notice links reject external or ambiguous path '.$path);
extHookCheck(Values::localPath('/events?month=10#list')&&Values::localPath(''),'bounded local paths and empty optional target are accepted');
echo json_encode(['suite'=>'native-extension-hooks','checks'=>$checks,'live_transport'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
