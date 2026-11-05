<?php declare(strict_types=1);
namespace ProcessWire {
    interface Module {} interface ConfigModule {}
    class Process { public object $testWire; public function wire(): object { return $this->testWire; } }
    class WirePermissionException extends \RuntimeException {}
    class Page {
        public const statusTrash=8192, statusSystem=16, statusSystemID=8, statusHidden=1024, statusLocked=4;
        public int $id=0, $start_at=0, $event_start=0, $created=0;
        public string $title='Synthetische Veranstaltung';
    }
}
namespace {
require __DIR__.'/../../processwire/site/modules/ProcessKulturhusDashboard/ProcessKulturhusDashboard.module.php';
use Kulturhus\SocialPublicationStatus as Status;
$checks=0;
function check(bool $ok,string $label): void { global $checks; if(!$ok)throw new RuntimeException($label); ++$checks; echo "PASS $label\n"; }
function rejects(callable $fn,string $label): void { try{$fn();}catch(RuntimeException){check(true,$label);return;}throw new RuntimeException($label); }
$urls=Status::imageUrls('https://www.example.org','/site/assets/files/23/source.jpg','feed.jpg','story.jpg');
check($urls['story']==='https://www.example.org/site/assets/files/23/story.jpg','canonical HTTPS URL without request hostname');
$_SERVER['HTTP_HOST']='attacker.invalid';
check(Status::imageUrls('https://www.example.org','/site/assets/files/23/source.jpg','feed.jpg','story.jpg')===$urls,'HTTP host cannot override configured public origin');
check(Status::imageUrls('https://example.org/club','/club/site/assets/x.jpg','feed.jpg','story.jpg')['story']==='https://example.org/club/site/assets/story.jpg','installation path is not duplicated');
foreach(['','http://example.org','https://localhost','https://127.0.0.1','https://10.0.0.1','https://example.local','https://user:secret@example.org','https://example.org?token=secret'] as $url) rejects(fn()=>Status::imageUrls($url,'/site/image.jpg','f.jpg','s.jpg'),'reject unsafe or missing origin '.$url);
foreach(['//attacker.invalid/a.jpg','https://attacker.invalid/a.jpg','/site/../secret','/site/a.jpg?token=x',"/site/\na.jpg"] as $path) rejects(fn()=>Status::imageUrls('https://example.org',$path,'f.jpg','s.jpg'),'reject malformed image path');
check(Status::imageUrls('http://localhost','/site/image.jpg','f.jpg','s.jpg',true)['story']==='/site/s.jpg','explicit local preview uses relative URL');
$config=['untouched'=>['value'=>42]];
$config=Status::record($config,'instagram:23:d14',new RuntimeException('Das Veranstaltungsbild muss über eine öffentliche HTTPS-Adresse erreichbar sein.'),100);
check(Status::entries($config,23)[0]['state']==='failed','failure recorded');
check($config['untouched']['value']===42,'other module configuration preserved');
check(Status::entries($config,24)===[],'no status leakage into another event');
$config=Status::record($config,'facebook:23:d14',new RuntimeException('secret TOKEN https://example.com/?access_token=hidden'),101);
check(!str_contains(json_encode($config),'hidden'),'raw exception secrets not persisted');
$config=Status::record($config,'instagram:23:d7',null,200);
check(count(array_filter(Status::entries($config,23),fn($r)=>$r['state']==='failed'))===2,'different successful reminder does not erase earlier failures');
$config=Status::record($config,'instagram:23:d14',null,201);
check($config['social_publication_status']['instagram:23:d14']['resolved_at']===201,'same job success resolves its error');
$legacy=['story_reminder_attempts'=>['instagram:23:d14'=>100,'facebook:99:d14'=>100]];
check(count(Status::entries($legacy,23))===1 && Status::entries($legacy,23)[0]['state']==='unconfirmed','legacy attempt without stored error is honestly unconfirmed');
$legacy['instagram_publications']['23']['story_reminders']['d14']=['id'=>'fake','published_at'=>150];
check(Status::entries($legacy,23)[0]['state']==='published','legacy success takes precedence over attempt');
$today=Status::record([], 'facebook:23:today', new RuntimeException('Synthetic'), 300);
check(Status::entries($today,23)[0]['label']==='Am Veranstaltungstag','actual today schedule key supported');

// Real scheduling/catch/save paths, with memory-only config and no HTTP/database implementation.
date_default_timezone_set('Europe/Berlin');
$event=new \ProcessWire\Page();$event->id=23;$event->start_at=strtotime('tomorrow 20:00');$event->created=time();
$modules=new class { public array $config=['facebook_auto_publish'=>true,'facebook_initial_story_since'=>1]; public int $writes=0; public function getConfig($module){return $this->config;} public function saveConfig($module,$config){$this->config=$config;++$this->writes;} };
$pages=new class($event) { public function __construct(public $event){} public function find($selector){return [$this->event];} public function get($id){return new \ProcessWire\Page();} };
$module=new \ProcessWire\ProcessKulturhusDashboard();$module->testWire=(object)['modules'=>$modules,'pages'=>$pages];
$result=$module->publishScheduledStoryReminders();
check($result['status']==='partial','real reminder failure reported');
check($modules->config['social_publication_status']['facebook:23:d1']['state']==='failed','real reminder catch persists failure');
$saved=$modules->config;$writes=$modules->writes;
check($module->publishScheduledStoryReminders()['status']==='ok' && $modules->writes===$writes,'retry cooldown still enforced');
check($saved===$modules->config,'idle run keeps persistent error');
$event->start_at=strtotime('+5 days 20:00');
check($module->publishScheduledStoryReminders()['facebook']===0 && $saved===$modules->config,'off-schedule run does not clear failure or publish');
$module->publishPendingFacebookStories();
check($modules->config['social_publication_status']['facebook:23:initial']['state']==='failed','initial story catch persists failure');

function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$nativeId=0;$link=fn($args)=>'?item='.$args['item'];
$native=['rows'=>[['id'=>23,'title'=>'<script>synthetic</script>','story_status'=>Status::entries($modules->config,23)]]];
ob_start();require __DIR__.'/../views/Website/website-social-status.php';$html=ob_get_clean();
check(str_contains($html,'Story-Veröffentlichung prüfen') && str_contains($html,'Nicht veröffentlicht'),'persistent issue visible in native UI');
check(!str_contains($html,'<script>synthetic</script>'),'status UI escapes event title');
$nativeId=24;ob_start();require __DIR__.'/../views/Website/website-social-status.php';$html=ob_get_clean();
check(!str_contains($html,'synthetic'),'detail page shows only its own issues');
echo "$checks checks passed; no network, database, mail or publications.\n";
}
