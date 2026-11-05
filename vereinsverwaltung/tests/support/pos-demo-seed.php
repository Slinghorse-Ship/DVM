<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__,2).'/src/bootstrap.php';require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Db,SecretBox,Identity};
use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Native\Cms\Pages;
use Kulturhus\Native\Pos\{Register,Schema};
use Kulturhus\Verein\{Database,Finance,EventVouchers,WebsiteCalendar};
$config=TestSupport::config();if(($config['label']??'')!=='integrated-manager')throw new RuntimeException('Own integrated synthetic fixture required.');$db=TestSupport::db();Database::migrate($db);Schema::install($db);Kulturhus\Native\Communication\Schema::install($db);
$runtime=dirname((string)getenv('KH_NATIVE_TEST_CONFIG')).'/integrated-manager-runtime';$path=$runtime.'/private.key';if(!is_file($path)){$h=fopen($path,'x');fwrite($h,random_bytes(32));fclose($h);chmod($path,0600);}
$access=(new ManagerBridge($db,'manager-admin','native',static function(){if(PHP_SAPI!=='cli')throw new DomainException('CLI only');}))->access();
require __DIR__.'/native-club-profile.php';$clubVersion=prepareSyntheticClubProfile($db,$access);
$identity=new Identity($db,$access);$settings=$identity->get();if($settings['origin']==='')$identity->save(['club_version'=>$clubVersion,'origin'=>'https://example.invalid'],$settings['version']);
$pages=new Pages($db,$access);$page=null;foreach($pages->list() as $p)if($p['slug']==='cloud-pos-browser')$page=$p;if(!$page){$page=$pages->save(['slug'=>'cloud-pos-browser','title'=>'Synthetische Kassenveranstaltung','template'=>'event','body'=>'Nur Browserprüfung','event_start'=>'2026-10-18T19:00','event_location'=>'Testsaal']);$page=$pages->publish($page['id'],$page['version']);}
$calendar=WebsiteCalendar::fromNative($db,'manager-demo',$access->club(),'https://example.invalid');$benefits=new EventVouchers($db,'manager-admin',$calendar);$event=$benefits->open('website:'.$page['id'],bin2hex(random_bytes(16)));
$finance=new Finance($db,'manager-admin');$account=Db::run($db,"SELECT id FROM finance_accounts WHERE club_id='manager-demo' AND name='Cloud-Test-Handkasse'")->fetchColumn();if(!$account)$account=$finance->createAccount('Cloud-Test-Handkasse','cash','0','2026-01-01');
$register=new Register($db,'manager-admin',null,$calendar);foreach(['Testgetränk'=>'3.00','Wertgutschein 20 Euro'=>'20.00'] as $label=>$price)if(!Db::run($db,"SELECT 1 FROM pos_products WHERE club_id='manager-demo' AND label=?",[$label])->fetchColumn())$register->saveProduct(['label'=>$label,'price'=>$price,'sphere'=>'wirtschaftlich','kind'=>$price==='20.00'?'value-voucher':'goods']);
$shift=Db::run($db,"SELECT id FROM pos_shifts WHERE club_id='manager-demo' AND event_id=? AND state='open'",[$event])->fetchColumn();if(!$shift)$shift=$register->open($event,$account,0);
file_put_contents($runtime.'/pos-browser.json',json_encode(['shift'=>$shift,'event'=>$event],JSON_THROW_ON_ERROR));chmod($runtime.'/pos-browser.json',0600);echo 'Own POS browser fixture ready; no external actions.'.PHP_EOL;
