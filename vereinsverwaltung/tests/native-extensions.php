<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';require dirname(__DIR__).'/src/bootstrap.php';require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\Extensions\{Access,Catalog,Context,Definition,Event,Factory,HookScope,Manager,Plugin,Runtime,Schema,UrlScope,View,WebsiteNotice};
use Kulturhus\Native\Shared\Db;
use Kulturhus\Native\TestSupport;
$db=TestSupport::db();Kulturhus\Native\Installer\SchemaRegistry::install($db);Schema::install($db);
Db::run($db,"INSERT INTO clubs VALUES('extensions-a','Own extension A'),('extensions-b','Own extension B')");
foreach([['extensions-admin','extensions-a',1],['extensions-member','extensions-a',0],['extensions-other','extensions-b',1]] as [$id,$club,$super])Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES(?,?,'member',?)",[$id,$club,$super]);
$checks=0;
function extensionCheck(bool $condition,string $label):void{if(!$condition)throw new RuntimeException($label);$GLOBALS['checks']++;echo "PASS $label\n";}
function extensionDenied(callable $action,string $label):void{try{$action();}catch(DomainException){extensionCheck(true,$label);return;}throw new RuntimeException($label);}
function extensionRevision(Manager $manager,string $id):int{foreach($manager->listing() as $row)if($row['id']===$id)return $row['revision'];throw new RuntimeException('missing fixture plugin');}
final class OwnLifecyclePlugin implements Plugin
{
    public bool $failInstall=false,$failUpgrade=false,$failInitialize=false;
    public ?Closure $sideEffect=null;
    public function __construct(private string $id,private string $version='1.0.0',private array $requires=[],private bool $autoload=true){}
    public function definition():Definition{return new Definition($this->id,$this->id,$this->version,$this->requires,['label'=>['type'=>'text','label'=>'Label','max'=>100,'default'=>$this->id]],$this->autoload);}
    public function install(Context $context):void{$context->put('original-value','original-'.$this->id);if($this->sideEffect)($this->sideEffect)();if($this->failInstall)throw new RuntimeException('private-account-never-show');}
    public function upgrade(Context $context,string $previousVersion):void{$context->put('upgraded-from',$previousVersion);if($this->failUpgrade)throw new RuntimeException('private-upgrade-never-show');}
    public function uninstall(Context $context):void{$context->delete('original-value');}
    public function initialize(HookScope $hooks,UrlScope $urls,Context $context):void{
        $label=$context->configuration['label'];
        $hooks->on('site.title','after',static function(Event $event)use($label):void{$event->setResult($event->result().' '.$label);});
        $urls->register('',static fn(array $args):array=>['status'=>200,'headers'=>['Content-Type'=>'application/json; charset=utf-8'],'body'=>json_encode(['label'=>$label],JSON_THROW_ON_ERROR)]);
        if($this->failInitialize)throw new RuntimeException('private-init-never-show');
    }
}
$base=new OwnLifecyclePlugin('base-plugin');$child=new OwnLifecyclePlugin('child-plugin','1.0.0',['base-plugin'=>'1.0.0']);$lazy=new OwnLifecyclePlugin('lazy-plugin','1.0.0',[],false);$bad=new OwnLifecyclePlugin('bad-plugin');$bad->failInitialize=true;
$catalog=new Catalog($child,$base,$lazy,$bad);$access=new Access($db,'extensions-admin');$manager=new Manager($db,$access,$catalog);$other=new Manager($db,new Access($db,'extensions-other'),$catalog);$member=new Manager($db,new Access($db,'extensions-member'),$catalog);
$originalTables=array_values(array_diff($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),['native_extensions','native_extension_values','native_extension_history']));$original=ownOriginalDatabaseHash($db,$originalTables);
extensionCheck(array_keys($catalog->ordered())===['base-plugin','child-plugin','lazy-plugin','bad-plugin'],'local registration resolves dependencies before dependent plugins');
extensionDenied(fn()=>new Catalog(new OwnLifecyclePlugin('missing-plugin','1.0.0',['not-installed'=>'1.0.0'])),'unknown registered dependency is rejected');
extensionDenied(fn()=>new Catalog(new OwnLifecyclePlugin('cycle-first','1.0.0',['cycle-second'=>'1.0.0']),new OwnLifecyclePlugin('cycle-second','1.0.0',['cycle-first'=>'1.0.0'])),'dependency cycles are rejected before lifecycle work');
extensionDenied(fn()=>new Catalog($base,$base),'duplicate plugin identifiers are rejected');
extensionDenied(fn()=>new Catalog(new OwnLifecyclePlugin('too-new','1.0.0',['base-plugin'=>'2.0.0']),$base),'unsatisfied minimum package version is rejected');
extensionDenied(fn()=>$member->listing(),'ordinary members cannot list configuration');extensionDenied(fn()=>$member->change('base-plugin','install',0),'ordinary members cannot execute lifecycle work');
extensionDenied(fn()=>$manager->change('child-plugin','install',0),'dependent plugin cannot install before its required plugin');
$manager->change('base-plugin','install',0);$baseRevision=extensionRevision($manager,'base-plugin');
extensionCheck($baseRevision===1&&!(bool)$manager->listing()[0]['enabled'],'installation creates a versioned inactive state rather than enabling code automatically');
extensionCheck(Db::run($db,"SELECT value_json FROM native_extension_values WHERE club_id='extensions-a' AND plugin_id='base-plugin'")->fetchColumn()==='"original-base-plugin"','installation writes only its own namespaced plugin state');
$readOnly=new Context($db,'extensions-a','base-plugin',[],static fn():bool=>true);
extensionDenied(fn()=>$readOnly->put('forbidden-write','no'),'initialization context cannot write namespaced values');extensionDenied(fn()=>$readOnly->delete('original-value'),'initialization context cannot delete original namespaced values');
extensionCheck(extensionRevision($other,'base-plugin')===0,'another tenant cannot observe original installation or configuration');
extensionDenied(fn()=>$manager->change('base-plugin','configure',0,['label'=>'stale']),'stale configuration cannot overwrite original plugin state');
$manager->change('child-plugin','install',0);
extensionDenied(fn()=>$manager->change('child-plugin','enable',1),'dependent plugin cannot enable before its required plugin');
$manager->change('base-plugin','enable',1);$manager->change('child-plugin','enable',1);
$runtime=new Runtime($db,'extensions-a',$catalog);extensionCheck($runtime->property('site.title','Original')==='Original base-plugin child-plugin','real property runtime initializes enabled plugins in dependency order');
extensionCheck($runtime->url('GET','/extensions/base-plugin')['body']==='{"label":"base-plugin"}','enabled plugin publishes its read-only namespaced URL');
extensionCheck((new Runtime($db,'extensions-b',$catalog))->property('site.title','Original')==='Original','runtime never loads another tenant plugin');
extensionDenied(fn()=>$manager->change('base-plugin','disable',2),'enabled dependents prevent disabling their required plugin');
$manager->change('base-plugin','configure',2,['label'=>'Saved original']);
extensionCheck((new Runtime($db,'extensions-a',$catalog))->property('site.title','Title')==='Title Saved original child-plugin','saved configuration appears in actual subsequent property dispatch');
extensionDenied(fn()=>$manager->change('base-plugin','configure',3,['label'=>str_repeat('x',101)]),'configuration length limits are enforced before persistence');
extensionDenied(fn()=>$manager->change('base-plugin','configure',3,['label'=>'ok','unregistered'=>'bad']),'unknown configuration fields cannot be persisted');
extensionCheck($runtime->property('site.title','Original')==='Original','previous request runtime cannot continue using a changed plugin revision or dependent stale context');
$manager->change('lazy-plugin','install',0);$manager->change('lazy-plugin','enable',1);
extensionCheck(!str_contains((new Runtime($db,'extensions-a',$catalog))->property('site.title','Title'),'lazy-plugin'),'a nonautoload plugin remains unloaded until explicitly requested');
extensionCheck(str_contains((new Runtime($db,'extensions-a',$catalog,['lazy-plugin']))->property('site.title','Title'),'lazy-plugin'),'host can explicitly load a registered nonautoload plugin');
$manager->change('bad-plugin','install',0);extensionDenied(fn()=>$manager->change('bad-plugin','enable',1),'failed initialization prevents an activation from committing');$bad->failInitialize=false;$manager->change('bad-plugin','enable',1);$bad->failInitialize=true;$runtime=new Runtime($db,'extensions-a',$catalog);
extensionCheck(!str_contains($runtime->property('site.title','Title'),'bad-plugin')&&$runtime->url('GET','/extensions/bad-plugin')['status']===404,'failed initialization removes its partially registered hooks and URLs');
extensionCheck($runtime->failures()===[['owner'=>'bad-plugin','code'=>'initialization_failed']],'initialization errors expose only a generic identifier and bounded failure code');
Db::run($db,"UPDATE actors SET is_superuser=0 WHERE id='extensions-admin'");
extensionCheck((new Runtime($db,'extensions-a',$catalog))->property('site.title','Title')==='Title','revoked original owner immediately stops public plugin execution');
extensionDenied(fn()=>$manager->listing(),'revoked administrator cannot read original configuration');
Db::run($db,"UPDATE actors SET is_superuser=1 WHERE id='extensions-admin'");

$failure=new OwnLifecyclePlugin('failure-plugin');$failure->failInstall=true;$failureCatalog=new Catalog($failure);$failureManager=new Manager($db,$access,$failureCatalog);
try{$failureManager->change('failure-plugin','install',0);throw new LogicException('not thrown');}catch(DomainException $error){extensionCheck(!str_contains($error->getMessage(),'private-account'),'failing local lifecycle exception is redacted at the service boundary');}
extensionCheck(extensionRevision($failureManager,'failure-plugin')===0&&(int)Db::run($db,"SELECT COUNT(*) FROM native_extension_values WHERE plugin_id='failure-plugin'")->fetchColumn()===0,'failed install rolls back its state and partial namespaced values');
$failure->failInstall=false;$failureManager->change('failure-plugin','install',0);extensionCheck(extensionRevision($failureManager,'failure-plugin')===1,'retry after failed install resumes from the unchanged original revision');

$newBase=new OwnLifecyclePlugin('base-plugin','1.1.0');$newBase->failUpgrade=true;$newCatalog=new Catalog($newBase,$child);$upgradeManager=new Manager($db,$access,$newCatalog);
extensionCheck((new Runtime($db,'extensions-a',$newCatalog))->property('site.title','Title')==='Title','changed local package and dependent code remain inactive until explicit upgrade');
extensionDenied(fn()=>$upgradeManager->change('base-plugin','enable',3),'changed package cannot activate without explicit upgrade');
try{$upgradeManager->change('base-plugin','upgrade',3);throw new LogicException('not thrown');}catch(DomainException $error){extensionCheck(!str_contains($error->getMessage(),'private-upgrade'),'failed package upgrade reports a redacted actual lifecycle failure');}
extensionCheck(extensionRevision($upgradeManager,'base-plugin')===3&&Db::run($db,"SELECT version FROM native_extensions WHERE club_id='extensions-a' AND plugin_id='base-plugin'")->fetchColumn()==='1.0.0','failed upgrade preserves exact original version and revision');
extensionCheck(Db::run($db,"SELECT value_json FROM native_extension_values WHERE club_id='extensions-a' AND plugin_id='base-plugin' AND name='upgraded-from'")->fetchColumn()===false,'failed upgrade rolls back partially written upgrade values');
$newBase->failUpgrade=false;$upgradeManager->change('base-plugin','upgrade',3);
extensionCheck((new Runtime($db,'extensions-a',$newCatalog))->property('site.title','Title')==='Title Saved original child-plugin','successful upgrade retains configuration and restores dependency execution');
extensionCheck(Db::run($db,"SELECT value_json FROM native_extension_values WHERE club_id='extensions-a' AND plugin_id='base-plugin' AND name='upgraded-from'")->fetchColumn()==='"1.0.0"','upgrade receives and records its real previous installed version');
extensionDenied(fn()=>$manager->change('base-plugin','upgrade',4),'plugin downgrade cannot be used as an unreviewed rollback');

$revoking=new OwnLifecyclePlugin('revoke-plugin');$revoking->sideEffect=static fn()=>Db::run($db,"UPDATE actors SET is_superuser=0 WHERE id='extensions-admin'");$revokeManager=new Manager($db,$access,new Catalog($revoking));
extensionDenied(fn()=>$revokeManager->change('revoke-plugin','install',0),'role revoked inside lifecycle prevents the new state from committing');
extensionCheck(extensionRevision($revokeManager,'revoke-plugin')===0&&(int)Db::run($db,"SELECT COUNT(*) FROM native_extension_values WHERE plugin_id='revoke-plugin'")->fetchColumn()===0,'revoked lifecycle rolls back both data and the synthetic revocation mutation');

$upgradeManager->change('child-plugin','disable',2);$upgradeManager->change('base-plugin','disable',4);
extensionDenied(fn()=>$upgradeManager->change('base-plugin','uninstall',5),'installed dependent must be removed before required plugin uninstall');
$upgradeManager->change('child-plugin','uninstall',3);$upgradeManager->change('base-plugin','uninstall',5);
extensionCheck(extensionRevision($upgradeManager,'base-plugin')===6&&(int)Db::run($db,"SELECT COUNT(*) FROM native_extension_values WHERE plugin_id IN('base-plugin','child-plugin')")->fetchColumn()===0,'explicit uninstall removes only its own lifecycle and namespaced state while retaining monotone revision');
extensionDenied(fn()=>$upgradeManager->change('base-plugin','install',0),'old installation form cannot recreate a removed plugin at revision zero');
$upgradeManager->change('base-plugin','install',6);extensionCheck(extensionRevision($upgradeManager,'base-plugin')===7,'reinstallation advances the old installation revision instead of resetting it');
extensionDenied(fn()=>$upgradeManager->change('base-plugin','enable',1),'old enable tab cannot mutate a later reinstallation');
$upgradeManager->change('base-plugin','uninstall',7);

$noticeManager=Factory::manager($db,'extensions-admin',false);$noticeManager->change('website-notice','install',0);$noticeManager->change('website-notice','configure',1,['text'=>'Actual <script>unsafe</script> $1 Hinweis','path'=>'/events','title_suffix'=>'Verein']);$noticeManager->change('website-notice','enable',2);
$notice=new Runtime($db,'extensions-a');$response=$notice->response(['status'=>200,'headers'=>['Content-Type'=>'text/html; charset=utf-8'],'body'=>'<!doctype html><html><body><main>Original content</main></body></html>']);
extensionCheck(str_contains($response['body'],'&lt;script&gt;unsafe&lt;/script&gt; $1 Hinweis')&&!str_contains($response['body'],'<script>')&&str_contains($response['body'],'href="/events"'),'shipped website plugin renders actual escaped text, literal replacement markers and local links');
extensionCheck($notice->property('site.title','Start')==='Start · Verein','shipped plugin changes the actual public title property');
extensionCheck($notice->url('GET','/extensions/website-notice')['headers']['Cache-Control']==='no-store','shipped plugin exposes an actual bounded read-only URL response');
extensionDenied(fn()=>$noticeManager->change('website-notice','configure',3,['text'=>'bad','path'=>'https://outside.invalid/','title_suffix'=>'']),'external target is rejected when saving rather than disabling a later request');
$json=['status'=>200,'headers'=>['Content-Type'=>'application/json; charset=utf-8'],'body'=>'{"original":true}'];extensionCheck($notice->response($json)===$json,'website banner never mutates non-HTML API responses');
$noticeManager->change('website-notice','disable',3);extensionCheck($notice->property('site.title','Start')==='Start','disabling plugin revokes already initialized callbacks in the same request');

for($i=0;$i<22;$i++)$noticeManager->change('website-notice','configure',extensionRevision($noticeManager,'website-notice'),['text'=>'Revision '.$i,'path'=>'','title_suffix'=>'']);
$history=$noticeManager->history();extensionCheck(count($history['rows'])===20&&$history['pages']>1&&count($noticeManager->history(2)['rows'])>0,'actual lifecycle history is bounded and paginated');
$html=View::render($noticeManager,'csrf-value');extensionCheck(str_contains($html,'<details class="extension-configuration">')&&str_contains($html,'name="csrf" value="csrf-value"')&&str_contains($html,'name="revision"'),'actual administration keeps configuration collapsed and includes CSRF and optimistic revision');
extensionCheck(str_contains($html,'name="confirm" value="1" required')&&str_contains($html,'Einstellungen dieser Erweiterung löschen'),'uninstall UI requires an explicit checkbox for its own configuration removal');
$draft=View::render($noticeManager,'csrf-value',[],['plugin'=>'website-notice','revision'=>'1','configuration'=>['text'=>'Own invalid <draft>','path'=>'https://outside.invalid/','title_suffix'=>'']]);extensionCheck(str_contains($draft,'Own invalid &lt;draft&gt;')&&str_contains($draft,'extension-configuration" open')&&str_contains($draft,'name="revision" value="1"'),'failed input remains escaped and open without upgrading a stale revision');
extensionCheck(!str_contains($html,'private-account')&&!str_contains($html,'<script>unsafe'),'protected UI never emits plugin exception text or unescaped original notice');
extensionCheck($other->history()['rows']===[],'another tenant cannot read lifecycle history');
extensionCheck(ownOriginalDatabaseHash($db,$originalTables)===$original,'all original business tables remain exact after complete lifecycle, failure, retry and public execution flows');
if(getenv('DVM_EXTENSIONS_BROWSER')==='1'){require __DIR__.'/support/extensions-browser-fixture.php';ownExtensionsBrowser($db);}
echo json_encode(['suite'=>'native-extensions','checks'=>$checks,'original_tables'=>count($originalTables),'live_transport'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
