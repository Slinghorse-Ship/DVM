<?php declare(strict_types=1);
putenv('KH_NATIVE_MANAGER=1');putenv('VEREIN_AUTH=native');
require dirname(__DIR__,2).'/src/bootstrap.php';require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Native\Communication\{Subscribers,PressContacts};
$c=TestSupport::config();if(PHP_SAPI!=='cli'||($c['owner']??'')!=='cloud-onboarding-synthetic'||($c['label']??'')!=='integrated-manager')throw new RuntimeException('Owned synthetic profile fixture required.');
$db=TestSupport::db();$file=dirname(__DIR__,3).'/.local/browser-tests/contact-status.json';$mode=$argv[1]??'prepare';
$f=is_file($file)?json_decode(file_get_contents($file),true,16,JSON_THROW_ON_ERROR):null;
if($f&&($f['database']!==$c['database']||!preg_match('/^[a-f0-9]{32}$/D',$f['contact'])))throw new RuntimeException('Fixture ownership mismatch.');
$actor='manager-admin';$club='manager-demo';$access=(new ManagerBridge($db,$actor,'native',static fn()=>null))->access();$subscribers=new Subscribers($db,$access);$press=new PressContacts($db,$access);
if($f){$contact=(new \Kulturhus\Verein\Registry($db,$actor))->contact($f['contact']);if($contact['name']!=='Eigener Newsletter-Status Browser' ||$contact['email']!=='contact-status-browser@example.invalid')throw new RuntimeException('Original fixture profile differs.');}
if($mode==='confirm'){if(!$f||!$subscribers->confirm($f['token']))throw new RuntimeException('Owned confirmation failed.');exit;}
if($mode==='unsubscribe'){if(!$f)throw new RuntimeException('Owned fixture missing.');$token='';$subscribers->issueUnsubscribe($f['subscriber'],static function(string $t)use(&$token):void{$token=$t;});if(!$subscribers->unsubscribe($token))throw new RuntimeException('Owned withdrawal failed.');exit;}
$snapshot=static function(?string $id)use($db,$club):string{$rows=Db::run($db,'SELECT * FROM contacts WHERE club_id=? AND id<>? ORDER BY id',[$club,$id??''])->fetchAll();return hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));};
if($mode==='check'){if(!$f||$snapshot($f['contact'])!==$f['originals'])throw new RuntimeException('Other original profiles changed.');echo "Other original member profiles retained.\n";exit;}
if($mode!=='prepare')throw new RuntimeException('Unknown fixture action.');
if(!$f){$before=$snapshot(null);$id=(new \Kulturhus\Verein\Registry($db,$actor))->saveContact(null,0,'person','Eigener Newsletter-Status Browser','contact-status-browser@example.invalid','');$f=['database'=>$c['database'],'contact'=>$id,'originals'=>$before];}
else $f['originals']=$snapshot($f['contact']);
$token='';$row=$subscribers->requestConsent('contact-status-browser@example.invalid','Own browser subscriber',['events'],'Owned synthetic browser consent proof',static function(string $t)use(&$token):void{$token=$t;});
$existing=Db::run($db,'SELECT id,version FROM khn_press_contacts WHERE club_id=? AND email=?',[$access->club(),'contact-status-browser@example.invalid'])->fetch();
$press->save(['id'=>$existing['id']??null,'email'=>'contact-status-browser@example.invalid','name'=>'Own browser press','outlet'=>'Eigener <Browser-Verteiler>','active'=>false],(int)($existing['version']??0));
$f['subscriber']=$row['id'];$f['token']=$token;file_put_contents($file,json_encode($f,JSON_THROW_ON_ERROR),LOCK_EX);chmod($file,0600);echo "Owned synthetic profile status fixture prepared.\n";
