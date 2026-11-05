<?php declare(strict_types=1);
namespace ProcessWire { class Page{const statusLocked=1;}class WirePermissionException extends \RuntimeException{}class ProcessWire{public object $database;public object $user;} }
namespace {
require_once __DIR__.'/../../processwire/site/src/NewsletterCampaignService.php';
require_once __DIR__.'/../../processwire/site/src/MailDeliveryPolicy.php';
if(!isset($newsletterTestDb)) {require __DIR__.'/mysql-bootstrap.php';$newsletterTestDb=\Kulturhus\Verein\Tests\MySqlFixture::connect();}
$name=$newsletterTestDb->query('SELECT DATABASE()')->fetchColumn();if($name!=='k15753_migrate'&&!preg_match('/^kh_test_[a-f0-9]{24}$/D',$name))throw new RuntimeException('Isolated test database required');
$prefix='test_nl_'.bin2hex(random_bytes(6)).'_';
$db=new class($newsletterTestDb,$prefix){
 public function __construct(private PDO $pdo,private string $prefix){}
 private function sql($s){return str_replace('kulturhus_newsletter_',$this->prefix,$s);}
 public function exec($s){return $this->pdo->exec($this->sql($s));}public function query($s){return $this->pdo->query($this->sql($s));}public function prepare($s){return $this->pdo->prepare($this->sql($s));}
 public function __call($m,$a){return $this->pdo->$m(...$a);}
};
$n=0;function ck($v,$label){global $n;if(!$v)throw new RuntimeException($label);++$n;}function rejects($f,$label){try{$f();}catch(DomainException){ck(true,$label);return;}throw new RuntimeException($label);}
$oldMode=getenv('VEREIN_MODE');putenv('VEREIN_MODE=synthetic');
try{
 $db->exec("CREATE TABLE kulturhus_newsletter_campaigns(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,subject VARCHAR(180),body MEDIUMTEXT,audience VARCHAR(20),status VARCHAR(30),created_by INT,automation_key VARCHAR(64) UNIQUE,automation_paused INT DEFAULT 0,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
 $store=new \Kulturhus\NewsletterDrafts($db);$store->ensureSchema();$store->ensureSchema();ck(!$store->paused(),'default unchanged');$store->pause(true,41);ck($store->paused(),'global pause persists');$store->pause(false,41);
 $id=$store->save(0,'Synthetic subject','<p>First synthetic body.</p>','events','',41,'Synthetic editor');$a=$store->current($id);$t=\Kulturhus\NewsletterDrafts::token($a);ck(count($store->history($id))===1,'initial snapshot');
 $store->save($id,'Synthetic subject','<p>Second synthetic body.</p>','events',$t,42,'Another editor');$b=$store->current($id);ck(count($store->history($id))===3,'before and after retained');
 rejects(fn()=>$store->save($id,'Old tab','<p>Old synthetic body.</p>','events',$t,41,'Old editor'),'old editor rejected');ck($store->current($id)['body']===$b['body'],'no stale overwrite');
 rejects(fn()=>$store->save($id,'Missing token','text text','events','',41,'Editor'),'missing version rejected');
 $revision=$store->history($id)[2]['id'];$store->restore($id,(int)$revision,\Kulturhus\NewsletterDrafts::token($b),41,'Editor',fn($x)=>$x);$restored=$store->current($id);ck($restored['body']===$a['body']&&(int)$restored['automation_paused']===1,'restore body and pause');ck(\Kulturhus\NewsletterDrafts::token($restored)!==$t,'ABA revision protected');
 $other=$store->save(0,'Other newsletter','<p>Other synthetic body.</p>','all','',41,'Editor');rejects(fn()=>$store->restore($other,(int)$revision,\Kulturhus\NewsletterDrafts::token($store->current($other)),41,'Editor',fn($x)=>$x),'cross campaign restore rejected');
 $db->exec("UPDATE kulturhus_newsletter_campaigns SET automation_key='quarter-2026-Q4' WHERE id=$id");$r=$store->current($id);$store->remove($id,\Kulturhus\NewsletterDrafts::token($r),41,'Editor');ck($store->current($id)['status']==='deleted','delete is recoverable');ck($store->current($id)['automation_key']==='quarter-2026-Q4','period key retained');ck($store->history($id)[0]['kind']==='deleted','deletion attribution');
 $wire=new \ProcessWire\ProcessWire();$wire->database=$db;$wire->user=new class{public $id=41;public $name='Synthetic';public $allowed=true;function isSuperuser(){return $this->allowed;}function hasPermission($p){return $this->allowed;}function isLoggedin(){return true;}function isUnpublished(){return false;}function isTrash(){return false;}function hasStatus($s){return false;}};
 $ref=new ReflectionClass(\Kulturhus\NewsletterCampaignService::class);$service=$ref->newInstanceWithoutConstructor();$ref->getProperty('wire')->setValue($service,$wire);
 $same=$ref->getMethod('createAutomaticCampaign')->invoke($service,'quarter-2026-Q4','Automatic replacement','<p>Wrong body</p>');ck($same===$id&&$store->current($id)['status']==='deleted','worker cannot recreate deleted period');
 foreach(['0','off','false','no'] as $value){putenv('NEWSLETTER_AUTOMATION_ENABLED='.$value);ck(!$ref->getMethod('automationEnabled')->invoke($service),'disabled env '.$value);}putenv('NEWSLETTER_AUTOMATION_ENABLED=1');$store->pause(true,41);ck(!$ref->getMethod('automationEnabled')->invoke($service),'DB pause enforced');
 $store->restore($id,(int)$revision,\Kulturhus\NewsletterDrafts::token($store->current($id)),41,'Editor',fn($x)=>$x);ck($store->current($id)['status']==='draft','deleted draft restored');
 $db->exec("UPDATE kulturhus_newsletter_campaigns SET status='queued' WHERE id=$id");rejects(fn()=>$store->restore($id,(int)$revision,\Kulturhus\NewsletterDrafts::token($store->current($id)),41,'Editor',fn($x)=>$x),'queued draft frozen');
 $wire->user->allowed=false;foreach([fn()=>$service->restoreDraft($id,(int)$revision,''),fn()=>$service->setAutomationPaused(true),fn()=>$service->saveDraft($id,'xx','xx','all'),fn()=>$service->deleteDraft($id)] as $f){try{$f();throw new RuntimeException('Permission missing');}catch(\ProcessWire\WirePermissionException){ck(true,'permission enforced');}}
 echo "$n newsletter revision checks passed; synthetic MySQL rows only, no transport\n";
}finally{foreach(['periods','revisions','control','campaigns'] as $table)$newsletterTestDb->exec('DROP TABLE IF EXISTS `'.$prefix.$table.'`');putenv($oldMode===false?'VEREIN_MODE':'VEREIN_MODE='.$oldMode);putenv('NEWSLETTER_AUTOMATION_ENABLED');}
}
