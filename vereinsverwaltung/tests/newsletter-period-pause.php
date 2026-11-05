<?php declare(strict_types=1);
namespace ProcessWire { class Page{const statusLocked=1;}class WirePermissionException extends \RuntimeException{}class ProcessWire{public object $database;public object $user;public object $pages;} }
namespace Kulturhus { final class PurposeMailTransport { public static function emailSender(...$args): \Closure { return static function(){throw new \RuntimeException('Transport forbidden in this test');}; } } }
namespace {
require_once __DIR__.'/../../processwire/site/src/NewsletterCampaignService.php';
require_once __DIR__.'/../../processwire/site/src/MailDeliveryPolicy.php';
if(!isset($newsletterTestDb)){require __DIR__.'/mysql-bootstrap.php';$newsletterTestDb=\Kulturhus\Verein\Tests\MySqlFixture::connect();}
$name=$newsletterTestDb->query('SELECT DATABASE()')->fetchColumn();if($name!=='k15753_migrate'&&!preg_match('/^kh_test_[a-f0-9]{24}$/D',$name))throw new RuntimeException('Isolated test database required');
$prefix='test_np_'.bin2hex(random_bytes(6)).'_';
$db=new class($newsletterTestDb,$prefix){
 public function __construct(private PDO $pdo,private string $prefix){}
 private function sql($s){return str_replace('kulturhus_newsletter_',$this->prefix,$s);}
 public function exec($s){return $this->pdo->exec($this->sql($s));}public function query($s){return $this->pdo->query($this->sql($s));}public function prepare($s){return $this->pdo->prepare($this->sql($s));}public function __call($m,$a){return $this->pdo->$m(...$a);}
};
$checks=0;function checkPeriod($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);++$checks;}
function rejectPeriod($run,$label){try{$run();}catch(DomainException){checkPeriod(true,$label);return;}throw new RuntimeException($label);}
function periodToken($period,$revision){return hash('sha256',$period.':'.$revision);}
$env=[];foreach(['VEREIN_MODE','APP_ENV','APP_URL','KULTURHUS_LOCAL_DEMO','KULTURHUS_DISABLE_MAIL','NEWSLETTER_AUTOMATION_ENABLED'] as $key){$env[$key]=getenv($key);putenv($key);}putenv('NEWSLETTER_AUTOMATION_ENABLED=1');putenv('VEREIN_MODE=synthetic');
try{
 $db->exec("CREATE TABLE kulturhus_newsletter_campaigns(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,subject VARCHAR(180),body MEDIUMTEXT,audience VARCHAR(20),status VARCHAR(30),created_by INT,automation_key VARCHAR(64) UNIQUE,automation_paused INT DEFAULT 0,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
 $db->exec("CREATE TABLE kulturhus_newsletter_deliveries(id INT PRIMARY KEY,campaign_id INT,status VARCHAR(30),attempts INT DEFAULT 0,started_at DATETIME,last_error TEXT) ENGINE=InnoDB");
 $store=new \Kulturhus\NewsletterDrafts($db);$store->ensureSchema();$store->ensureSchema();
 $wire=new \ProcessWire\ProcessWire();$wire->database=$db;$wire->user=new class{public $id=41;public $allowed=true;function isSuperuser(){return $this->allowed;}function hasPermission($p){return $this->allowed;}function isLoggedin(){return true;}function isUnpublished(){return false;}function isTrash(){return false;}function hasStatus($s){return false;}};$wire->pages=new class{function find($selector){return [];}};
 $ref=new ReflectionClass(\Kulturhus\NewsletterCampaignService::class);$service=$ref->newInstanceWithoutConstructor();$ref->getProperty('wire')->setValue($service,$wire);
 $period='month-2026-11';checkPeriod(!$store->periodPaused($period),'default unchanged');
 $service->setAutomaticPeriodPaused($period,true,periodToken($period,0));checkPeriod($store->periodPaused($period),'pause without draft');checkPeriod((int)$db->query('SELECT COUNT(*) FROM kulturhus_newsletter_campaigns')->fetchColumn()===0,'no fake draft created');checkPeriod(!$store->paused(),'global automation unchanged');checkPeriod(!$store->periodPaused('month-2026-12'),'following month unaffected');
 $overview=$service->automaticDigestOverview(new DateTimeImmutable('2026-10-10 12:00:00 Europe/Berlin'));checkPeriod($overview['period']===$period&&$overview['period_paused']&&$overview['period_version']===periodToken($period,1)&&$overview['campaign']===null,'pause visible before draft exists');
 foreach(['2026-10-25','2026-11-01','2026-11-03'] as $date){$r=$service->scheduleAutomaticDigest(new DateTimeImmutable($date.' 12:00:00 Europe/Berlin'));checkPeriod($r['status']==='paused'&&$r['campaign_id']===null,'scheduler skips reserved period '.$date);}
 rejectPeriod(fn()=>$service->setAutomaticPeriodPaused($period,false,periodToken($period,0)),'stale page cannot resume');rejectPeriod(fn()=>$service->setAutomaticPeriodPaused($period,false,''),'version required');
 $service->setAutomaticPeriodPaused($period,false,periodToken($period,1));checkPeriod(!$store->periodPaused($period),'resume before draft exists');
 rejectPeriod(fn()=>$service->setAutomaticPeriodPaused($period,true,periodToken('month-2026-12',2)),'version cannot cross periods');
 foreach(['month-2026-10','month-2026-13','quarter-2026-Q5','bogus'] as $bad)rejectPeriod(fn()=>$service->setAutomaticPeriodPaused($bad,true,'0'),'invalid period '.$bad);
 $id=$store->save(0,'Synthetic issue','<p>Editorial synthetic text preserved.</p>','events','',41,'Synthetic editor');$db->exec("UPDATE kulturhus_newsletter_campaigns SET automation_key='$period' WHERE id=$id");$old=$store->current($id);$history=count($store->history($id));
 $service->setAutomaticPeriodPaused($period,true,periodToken($period,2));$paused=$store->current($id);checkPeriod($paused['body']===$old['body']&&$paused['subject']===$old['subject']&&count($store->history($id))===$history,'editorial content and revisions unchanged');checkPeriod((int)$paused['automation_paused']===1&&\Kulturhus\NewsletterDrafts::token($paused)!==\Kulturhus\NewsletterDrafts::token($old),'pause invalidates stale draft token');
 rejectPeriod(fn()=>$service->queueCampaign($id),'manual queue cannot bypass individual pause');
 $late=$service->automaticDigestOverview(new DateTimeImmutable('2026-11-10 12:00:00 Europe/Berlin'));checkPeriod($late['period']==='month-2026-12','expired skipped draft does not hide next issue');
 $db->exec("UPDATE kulturhus_newsletter_campaigns SET status='queued' WHERE id=$id");$db->exec("INSERT INTO kulturhus_newsletter_deliveries(id,campaign_id,status) VALUES(1,$id,'pending')");
 $noTransport=static function(){throw new RuntimeException('Real transport forbidden');};$queue=$ref->getMethod('processQueueLocked')->invoke($service,20,$noTransport);checkPeriod($queue['status']==='idle','queued issue skipped before transport');
 checkPeriod(!$ref->getMethod('claimDelivery')->invoke($service,1),'pause enforced during loaded batch');checkPeriod((int)$db->query('SELECT attempts FROM kulturhus_newsletter_deliveries WHERE id=1')->fetchColumn()===0,'paused delivery has no attempt');
 $db->exec("UPDATE kulturhus_newsletter_campaigns SET automation_paused=0 WHERE id=$id");checkPeriod(!$ref->getMethod('claimDelivery')->invoke($service,1),'period reservation independently blocks delivery');
 $store->pause(true,41);$service->setAutomaticPeriodPaused($period,false,periodToken($period,3));checkPeriod(!$store->periodPaused($period)&&$store->paused(),'individual resume does not resume global automation');checkPeriod(!$ref->getMethod('claimDelivery')->invoke($service,1),'global pause still blocks delivery');
 $store->pause(false,41);checkPeriod($ref->getMethod('claimDelivery')->invoke($service,1),'resume permits pending claim');checkPeriod(!$ref->getMethod('claimDelivery')->invoke($service,1),'same delivery not claimed twice');
 $db->exec("UPDATE kulturhus_newsletter_campaigns SET status='sending' WHERE id=$id");$service->setAutomaticPeriodPaused($period,true,periodToken($period,4));checkPeriod($store->periodPaused($period),'active batch can be stopped');
 $manual=$store->save(0,'Manual issue','<p>Separate synthetic manual issue.</p>','events','',41,'Synthetic editor');$db->exec("INSERT INTO kulturhus_newsletter_deliveries(id,campaign_id,status) VALUES(2,$manual,'pending')");$store->pause(true,41);checkPeriod($ref->getMethod('claimDelivery')->invoke($service,2),'manual newsletter unaffected by automatic controls');
 foreach(['sent','deleted','needs_review'] as $status){$db->exec("UPDATE kulturhus_newsletter_campaigns SET status='$status' WHERE id=$id");rejectPeriod(fn()=>$service->setAutomaticPeriodPaused($period,false,periodToken($period,5)),'terminal status protected '.$status);}
 $wire->user->allowed=false;try{$service->setAutomaticPeriodPaused('month-2026-12',true,'0');throw new RuntimeException('Missing permission');}catch(\ProcessWire\WirePermissionException){checkPeriod(true,'write permission required');}
 echo "$checks newsletter period checks passed; isolated MySQL, no messages\n";
}finally{foreach(['periods','revisions','control','deliveries','campaigns'] as $table)$newsletterTestDb->exec('DROP TABLE IF EXISTS `'.$prefix.$table.'`');foreach($env as $key=>$value)putenv($value===false?$key:$key.'='.$value);}
}
