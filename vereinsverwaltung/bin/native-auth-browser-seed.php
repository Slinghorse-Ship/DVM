<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Auth\{Schema,Runtime};
$config=TestSupport::config();if(($config['label']??'')!=='auth-browser')throw new RuntimeException('Eigene Auth-Browserfixture erforderlich.');
$db=TestSupport::db();TestSupport::seed($db);Schema::install($db);
$runtime=dirname((string)getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$config['database'];
if(!is_dir($runtime))mkdir($runtime,0700,true);
$keyPath=$runtime.'/auth.key';
if(!is_file($keyPath)){$file=fopen($keyPath,'x');fwrite($file,random_bytes(32));fclose($file);}
// Every run gets new synthetic principals. No existing credentials or content are reset.
$run=bin2hex(random_bytes(8));$actor='auth-browser-'.$run;$reader='auth-reader-'.$run;
foreach([$actor=>'Browser Administration',$reader=>'Browser Mitglied'] as $id=>$name)Db::run($db,'INSERT INTO native_users(id,club_id,name) VALUES(?,?,?)',[$id,'synthetic-a',$name.' '.$run]);
foreach(['website','newsletter','social','press','chat','accounts'] as $module)foreach(['read','edit','publish','manage'] as $capability)Db::run($db,'INSERT INTO native_grants(actor_id,module,capability) VALUES(?,?,?)',[$actor,$module,$capability]);
Db::run($db,'INSERT INTO native_grants(actor_id,module,capability) VALUES(?,?,?)',[$reader,'website','read']);
$privateFile=dirname((string)getenv('KH_NATIVE_TEST_CONFIG')).'/auth-browser-input.json';
$service=Runtime::service($db,$keyPath);$admin=new Access($db,$actor);$email='admin-'.$run.'@example.invalid';
$invite=$service->createInvitation($admin,$actor,$email);
file_put_contents($privateFile,json_encode(['invitation'=>$invite['token'],'actor'=>$actor,'email'=>$email,'reader_actor'=>$reader,'reader_email'=>'reader-'.$run.'@example.invalid','password'=>'Test-'.bin2hex(random_bytes(12)),'next_password'=>'Neu-'.bin2hex(random_bytes(12))],JSON_THROW_ON_ERROR),LOCK_EX);
echo "Isolierte Browserkonten vorbereitet; Zugangsdaten nur in privater Fixturedatei.\n";
