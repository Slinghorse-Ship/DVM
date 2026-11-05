<?php declare(strict_types=1);
/** Real event import + private SQLite outbox, with isolated ProcessWire page/hook fixtures.
 * Does not bootstrap a website, open a real account or contact a provider. */
namespace ProcessWire {
    #[\AllowDynamicProperties]
    class Page {
        public const statusHidden=1024, statusUnpublished=2048, statusLocked=4, statusTrash=8192;
        public int $id=0,$status=2048; public object $template; public array $fields=[],$metadata=[];
        public bool $guestVisible=true; private ProcessWire $app;
        public function __construct(ProcessWire $app){$this->app=$app;$this->template=(object)['name'=>'event'];}
        public function __get($key){return $this->fields[$key]??'';}
        public function __set($key,$value){$this->fields[$key]=$value;}
        public function getUnformatted($key){return $this->$key;}
        public function of($value){}
        public function meta($key,$value=null){if(func_num_args()>1){$this->metadata[$key]=$value;if($this->id)$this->app->pages->store[$this->id]->metadata=$this->metadata;}return $this->metadata[$key]??null;}
        public function isUnpublished(){return $this->hasStatus(self::statusUnpublished);}
        public function isTrash(){return $this->hasStatus(self::statusTrash);}
        public function hasStatus($flag){return (bool)($this->status & $flag);}
        public function addStatus($flag){$this->status|=$flag;}
        public function removeStatus($flag){$this->status&=~$flag;}
        public function viewable($user){return $this->guestVisible&&!$this->isUnpublished()&&!$this->isTrash();}
        public function wire(){return $this->app;}
        public function save($field=null){$this->app->pages->save($this,$field);}
    }
    class PageArray extends \ArrayObject {public function add($page){$this->append($page);}}
    class Pages {
        public array $store=[]; private int $next=1;
        public function __construct(private ProcessWire $app){}
        public function wire(){return $this->app;}
        public function newPage($options=[]){return new Page($this->app);}
        public function getFresh($id){return isset($this->store[$id])?clone $this->store[$id]:new Page($this->app);}
        public function get($selector){if(preg_match('/external_id=(\d+)/',$selector,$m))foreach($this->store as $p)if((string)$p->external_id===$m[1])return clone $p;return new Page($this->app);}
        public function find($selector){return new PageArray(array_values(array_filter(array_map(fn($p)=>clone $p,$this->store),fn($p)=>$p->template->name==='event'&&(!str_contains($selector,'external_id!=')||(string)$p->external_id!==''))));}
        public function save(Page $page,$field=null){
            if(!$page->id)$page->id=$this->next++;
            $old=$this->store[$page->id]??null;
            if($field!==null&&$old){$saved=clone $old;$saved->$field=$page->$field;}else $saved=clone $page;
            if(!$saved->isUnpublished()&&(!$old||$old->isUnpublished()))$saved->published=time();
            if(!$saved->published&&$old)$saved->published=$old->published;
            $page->published=$saved->published;
            $this->store[$page->id]=$saved;
            $this->app->fire($field===null?'Pages::saved':'Pages::savedField',$page);
        }
    }
    class ProcessWire {
        public object $config,$users,$user,$cache,$sanitizer,$modules,$database,$log; public Pages $pages; public array $hooks=[];
        public function __construct($path){
            $this->config=(object)['kulturhusVereinRoot'=>dirname(__DIR__),'kulturhusVereinDatabase'=>$path,'kulturhusVereinClub'=>'event-fixture'];
            $this->pages=new Pages($this);
            $this->users=new class {public function getGuestUser(){return (object)['id'=>40];}};
            $this->user=new class {public function isSuperuser(){return true;}};
            $this->cache=new class {private array $v=[];public function get($key){return $this->v[$key]??null;}public function save($key,$value,$ttl){$this->v[$key]=$value;}public function delete($key){unset($this->v[$key]);}};
            $this->sanitizer=new class {public function pageName($v){return strtolower(str_replace(' ','-',$v));}};
            $this->modules=new class {private array $config=[];public function getConfig($key){return $this->config[$key]??[];}public function saveConfig($key,$value){$this->config[$key]=$value;}};
            $this->database=new class {public function query($sql){if(!str_contains($sql,'LOCK('))throw new \RuntimeException('Unexpected website SQL');return new class {public function fetchColumn(){return 1;}};}};
            $this->log=new class {public array $errors=[];public function save($name,$message){$this->errors[]=$message;}};
        }
        public function addHookAfter($hook,$fn){$this->hooks[$hook][]=$fn;}
        public function fire($hook,$page){foreach($this->hooks[$hook]??[] as $fn)$fn(new class($page){public function __construct(private $page){}public function arguments($index){return $this->page;}});}
    }
}
namespace {
require __DIR__.'/../src/bootstrap.php';
require __DIR__.'/../../processwire/site/src/EventPublicationNotifications.php';
require __DIR__.'/../../processwire/site/src/EventSyncService.php';
require __DIR__.'/../../processwire/site/src/MetaEventArchive.php';
use Kulturhus\Verein\{Database,ManagerNotifications};
use Kulturhus\{EventPublicationNotifications,EventSyncService,MetaEventArchive};
$dir=sys_get_temp_dir().'/kulturhus-event-notifications-'.bin2hex(random_bytes(8));mkdir($dir,0700);$path=$dir.'/isolated.sqlite';
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect($path);Database::migrate($db);$db->exec("INSERT INTO clubs VALUES('event-fixture','Fiktiver Testverein');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('reader','event-fixture','member',1),('later','event-fixture','member',1)");
foreach(['reader','later'] as $actor)$db->prepare('INSERT INTO push_subscriptions VALUES(?,?,?,?,?,?)')->execute(['sub-'.$actor,'event-fixture',$actor,'synthetic-'.$actor,'{}',gmdate('c')]);
(new ManagerNotifications($db,'reader'))->save(['events'],0);
$path=\Kulturhus\Verein\Tests\MySqlFixture::dsn($db);putenv('VEREIN_DATABASE_USER=root');putenv('VEREIN_DATABASE_PASSWORD=isolated-regression-only');$wire=new \ProcessWire\ProcessWire($path);$n=0;
$check=static function($value,$label)use(&$n){if(!$value)throw new \RuntimeException($label);++$n;echo "PASS $label\n";};
$count=static fn()=>(int)$db->query("SELECT COUNT(*) FROM manager_notifications WHERE topic='events'")->fetchColumn();
$pending=static fn($id)=>(int)$db->query("SELECT COUNT(*) FROM manager_push_deliveries d JOIN manager_notifications n ON n.id=d.notification_id WHERE n.source_id='website:".(int)$id."' AND d.state='pending'")->fetchColumn();
$make=static function($title,$status=1,$date=null)use($wire){$p=$wire->pages->newPage();$p->title=$title;$p->status=$status;$p->start_at=$date??time()+86400;$p->event_status='live';$p->save();return $p;};
try {
    $old=$make('Existing public future');$oldUnpublished=$make('Previously public');$oldUnpublished->addStatus(2048);$oldUnpublished->save();
    $oldDraft=$make('Never published draft',2048);$oldArchive=$make('Existing past',1,time()-86400);
    EventPublicationNotifications::register($wire);EventPublicationNotifications::register($wire);
    $check($count()===0,'baseline enqueues no existing events');
    $check(count($wire->hooks['Pages::saved'])===1,'repeated registration attaches one observer');
    $old->title='Editorial correction';$old->save();$oldUnpublished->removeStatus(2048);$oldUnpublished->save();
    $check($count()===0,'existing editorial update and historical republish remain silent');
    $oldDraft->removeStatus(2048);$oldDraft->save();
    $check($count()===1&&$pending($oldDraft->id)===1,'old never-published draft qualifies on first publication');
    $p=$make('New native editorial event');$check($count()===2&&$pending($p->id)===1,'new direct editorial event enqueues existing opt-in only');
    $p->summary='Changed';$p->save();$check($count()===2,'repeat save and description update do not duplicate');
    (new ManagerNotifications($db,'later'))->save(['events'],0);$p->save();
    $check($pending($p->id)===1,'later opt-in receives no replay after editing existing event');
    $p->start_at=time()+86400*5;$p->save();
    $stored=$db->query("SELECT starts_at FROM manager_notifications WHERE source_id='website:".$p->id."'")->fetchColumn();
    $check(strtotime($stored)===$p->start_at&&$pending($p->id)===1,'reschedule refreshes pending validity without new recipients');
    $p->addStatus(2048);$p->save();$check($pending($p->id)===0,'unpublishing cancels pending hints');
    $p->removeStatus(2048);$p->save();$check($count()===2&&$pending($p->id)===0,'republish never restores cancelled deliveries');
    $dirty=$make('Unpublished fields',2048);$dirty->status=1;$dirty->summary='Only summary saved';$dirty->save('summary');
    $check($count()===2,'single-field save cannot publish dirty unsaved page status');
    $dirty->save();$check($count()===3&&$pending($dirty->id)===2,'whole-page publication uses persisted state for both opt-ins');
    $hidden=$make('Hidden',1024);$guest=$wire->pages->newPage();$guest->guestVisible=false;$guest->title='Private';$guest->status=1;$guest->start_at=time()+86400;$guest->save();
    $check($count()===3,'hidden and guest-inaccessible pages never notify');
    $cancel=$wire->pages->newPage();$cancel->title='Cancelled';$cancel->status=1;$cancel->start_at=time()+86400;$cancel->event_status='canceled';$cancel->save();$cancel->event_status='live';$cancel->save();
    $check($count()===3,'cancelled publication and later reactivation are not new announcements');
    $source=['id'=>'1234567890','name'=>['text'=>'Synthetic Eventbrite'],'start'=>['utc'=>gmdate('c',time()+86400*2)],'end'=>['utc'=>gmdate('c',time()+86400*2+3600)],'status'=>'live'];
    $sync=new EventSyncService($wire->pages);$sync->sync(['events'=>[$source]]);
    $eb=$wire->pages->get('external_id=1234567890');$check($count()===4&&$pending($eb->id)===2,'actual Eventbrite sync enqueues new future event exactly once across both saves');
    $sync->sync(['events'=>[$source]]);$source['name']['text']='Remote update';$sync->sync(['events'=>[$source]]);
    $check($count()===4,'identical and changed Eventbrite reimports never create another hint');
    $payload=['complete'=>true,'source'=>'Meta Graph API','events'=>[['id'=>'9876543210','name'=>'Synthetic Meta archive','start_time'=>gmdate('c',time()-86400*10),'description'=>'Fictitious historic event']]];
    $meta=new MetaEventArchive($wire);$first=$meta->import($payload);$again=$meta->import($payload);
    $check($first['report']['created']===1&&$again['report']['created']===0&&$count()===4,'actual Meta archive import and repeated import never notify');
    $mid=$first['report']['items'][0]['id'];$mp=$wire->pages->getFresh($mid);$mp->start_at=time()+86400;$mp->event_status='live';$mp->save();
    $check($count()===4,'Meta prefix excludes archive before/after metadata and date corrections');
    $past=$make('New past native event',1,time()-1);$past->start_at=time()+86400;$past->save();
    $check($count()===4,'past import remains historical after date edits');
    $sync->sync(['events'=>[],'_complete'=>true]);$check($pending($eb->id)===0,'Eventbrite complete-feed unlisting cancels pending hint');
    $dirty->start_at=time()-1;$dirty->save();$check($pending($dirty->id)===0,'moving event into past cancels pending hint');
    $gone=$make('Delete before delivery');$wire->fire('Pages::deleted',$gone);unset($wire->pages->store[$gone->id]);
    $check($pending($gone->id)===0,'deletion cancels pending hint');
    $bridge=new EventPublicationNotifications($wire,$db,'event-fixture');$before=$count();$bridge->initialize();
    $check($count()===$before,'baseline is persistent and never reruns fanout');
    $check($wire->log->errors===[],'all hooked saves completed without swallowed bridge failures');
    $check(!(\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db)[0]??false),'isolated outbox and source history retain foreign key integrity');
    echo "$n event publication checks passed; no push provider or mail calls\n";
} finally { $wire=null;$db=null;@unlink($path);@rmdir($dir); }
}
