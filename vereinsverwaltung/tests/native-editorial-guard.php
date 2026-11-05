<?php declare(strict_types=1);
// Isolated hook-contract tests. These doubles do not replace a native DB/UI test.
namespace ProcessWire {
 class WirePermissionException extends \RuntimeException{}
 class Page {const statusLocked=4;public object $template;public ?Page $parent=null;public bool $changed=false;public function __construct(public int $id,string $template){$this->template=(object)['name'=>$template];}public function isChanged(string $key):bool{return $this->changed;}}
 class ProcessWire {public array $before=[],$after=[];public object $pages,$user,$input,$config;public Page $page;public function addHookBefore($n,$f){$this->before[$n]=$f;}public function addHookAfter($n,$f){$this->after[$n]=$f;}}
 class HookEvent {public mixed $return=true;public function __construct(private ProcessWire $w,public object $object,private array $args=[]){ }public function wire(){return $this->w;}public function arguments($n){return $this->args[$n]??null;}}
}
namespace {
 require_once __DIR__.'/mysql-bootstrap.php';
 require __DIR__.'/../src/bootstrap.php';
 require __DIR__.'/../../processwire/site/src/NativeEditorialGuard.php';
 use ProcessWire\{Page,ProcessWire,HookEvent};use Kulturhus\NativeEditorialGuard as Guard;
 $n=0;function check(bool $ok,string $m){global $n;if(!$ok)throw new RuntimeException($m);$n++;echo "PASS $m\n";}function denied(callable $f,string $m){try{$f();}catch(\ProcessWire\WirePermissionException){check(true,$m);return;}throw new RuntimeException($m);}
 $db=\Kulturhus\Verein\Tests\MySqlFixture::connect();\Kulturhus\Verein\Database::migrate($db);
 $db->exec("INSERT INTO clubs VALUES('c','Synthetic editorial guard');INSERT INTO actors(id,club_id,role,is_superuser,press_access,active) VALUES('a','c','member',0,'none',1);INSERT INTO processwire_identities(pw_user_id,actor_id,enabled) VALUES(41,'a',1);INSERT INTO settings_roles(id,club_id,name,description,members_access,finance_access,website_access,settings_access,press_access,version) VALUES('r','c','Synthetic role','','none','none','{}',0,'none',1);INSERT INTO settings_role_assignments(actor_id,club_id,role_id,version) VALUES('a','c','r',1);");
 $previous=[];foreach(['VEREIN_MODE'=>'production','VEREIN_DATABASE'=>\Kulturhus\Verein\Tests\MySqlFixture::dsn($db),'VEREIN_DATABASE_USER'=>'root','VEREIN_DATABASE_PASSWORD'=>'isolated-regression-only'] as $key=>$value){$previous[$key]=getenv($key);putenv($key.'='.$value);}
 try{
 $matrix=static function(array $m)use($db){$db->prepare('UPDATE settings_roles SET website_access=?')->execute([json_encode($m)]);};$matrix(['texts'=>'write','events'=>'read','gallery'=>'write','newsletter'=>'read']);
 $w=new ProcessWire();$w->page=new Page(2,'admin');$w->user=new class{public int $id=41;public function isLoggedin(){return true;}public function isUnpublished(){return false;}public function isTrash(){return false;}public function hasStatus($s){return false;}};
 $w->config=(object)['kulturhusVereinDatabase'=>\Kulturhus\Verein\Tests\MySqlFixture::dsn($db),'kulturhusVereinRoot'=>dirname(__DIR__)];
 $home=new Page(1,'home');$text=new Page(10,'basic-page');$text->parent=$home;$event=new Page(11,'event');$event->parent=$home;$gallery=new Page(12,'gallery');$gallery->parent=$home;$a=new Page(13,'gallery-album');$a->parent=$gallery;$b=new Page(14,'gallery-album');$b->parent=$gallery;$recipient=new Page(15,'newsletter-recipient');$recipient->parent=$home;
 $w->pages=new class([$home,$text,$event,$gallery,$a,$b,$recipient]){public array $rows=[];public function __construct($rows){foreach($rows as $r)$this->rows[$r->id]=$r;}public function getFresh(int $id){return $this->rows[$id]??new Page(0,'null');}};
 Guard::register($w);check(isset($w->before['ProcessPageSort::execute']),'Native direct SQL sort guarded before execute');
 denied(fn()=>Guard::assertSort($w,'10,11,12',1,10),'Allowed text cannot reorder denied event sibling');
 Guard::assertSort($w,'14,13',12,14);check(true,'Gallery writer can reorder gallery albums');
 denied(fn()=>Guard::assertSort($w,'13,10',12,13),'Unrelated sibling rejected');
 denied(fn()=>Guard::assertSort($w,'13,999',12,13),'Missing stale sibling rejected');
 denied(fn()=>Guard::assertSort($w,['13'],12,13),'Malformed sort rejected before core');
 $w->input=new class {public function post($k){return ['sort'=>'10,11','parent_id'=>'1','id'=>'10'][$k]??null;}};
 denied(fn()=>($w->before['ProcessPageSort::execute'])(new HookEvent($w,$w)),'Registered before hook blocks actual posted sibling list');
 foreach(['Pages::save','Pages::saveField','Pages::saveFields','Pages::saveReady','Pages::saveFieldReady'] as $hook)denied(fn()=>($w->before[$hook])(new HookEvent($w,$w,[$event,'title'])),'Read event denied at '.$hook);
 denied(fn()=>($w->before['Pages::clone'])(new HookEvent($w,$w,[$a,$event])),'Clone into denied target blocked');
 $changed=new Page(11,'basic-page');$changed->parent=$home;denied(fn()=>Guard::assertSave($changed,$w),'Template change cannot conceal previous denied area');
 $move=clone $a;$move->parent=$event;$move->changed=true;denied(fn()=>Guard::assertSave($move,$w),'Changed parent checked before save');
 denied(fn()=>Guard::assertSave($recipient,$w),'Native recipient administration needs newsletter write');
 foreach(['newsletter','newsletter-confirm','newsletter-unsubscribe'] as $route){$w->page=new Page(40,$route);Guard::assertSave($recipient,$w);check(true,'Public recipient persistence preserved on '.$route);}
 denied(fn()=>Guard::assertSave(new Page(0,'newsletter'),$w),'Newsletter landing inline write not exempt');
 check(Guard::area(new Page(0,'news-index'))==='texts'&&Guard::area(new Page(0,'events-index'))==='events','Installed index templates mapped');
 $core=dirname(__DIR__,2).'/processwire/wire';
 if(!is_dir($core)&&is_dir('/var/www/html/wire'))$core='/var/www/html/wire';
 if(is_dir($core)){$pages=file_get_contents($core.'/core/Pages.php');foreach(['save','saveField','saveFields','saveReady','saveFieldReady','clone','moveReady','sort'] as $name)check(str_contains($pages,'function ___'.$name.'('),'Installed core hook exists: '.$name);$sort=file_get_contents($core.'/modules/Process/ProcessPageSort.module');check(str_contains($sort,'function ___execute()')&&str_contains($sort,'UPDATE pages SET sort='),'Installed native sort entry and direct SQL verified');}else echo "SKIP installed core unavailable; no native integration claimed\n";
 echo "$n isolated editorial guard checks passed on MySQL; native ProcessWire integration remains separate\n";
 }finally{foreach($previous as $key=>$value)putenv($value===false?$key:$key.'='.$value);unset($matrix);$db=null;}
}
