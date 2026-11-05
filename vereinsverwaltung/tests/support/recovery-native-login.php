<?php declare(strict_types=1);
namespace ProcessWire;
require_once __DIR__.'/../mysql-bootstrap.php';
if(PHP_SAPI!=='cli'||getenv('VEREIN_TEST_MYSQL')!=='isolated-regression-only'||getenv('KULTURHUS_LOCAL_DEMO')!=='1'||getenv('KULTURHUS_DISABLE_MAIL')!=='1')throw new \RuntimeException('Synthetic isolated CLI only');
$input=json_decode(stream_get_contents(STDIN,16384),true,512,JSON_THROW_ON_ERROR);
if(!preg_match('/^kh_test_[a-f0-9]{24}$/D',$input['database']??'')||!preg_match('/^recovery-[a-f0-9]{16}$/D',$input['name']??''))throw new \RuntimeException('Restore target only');
$db=\Kulturhus\Verein\Tests\MySqlFixture::existing($input['verein_dsn']??'');
$root=realpath($input['root']??'');$allowed=realpath(getenv('KULTURHUS_RECOVERY_TEST_ROOT')?:sys_get_temp_dir());
if(!$root||!$allowed||!str_starts_with($root,$allowed.'/kh-recovery-test-'))throw new \RuntimeException('Private restore directory required');
$web=$root.'/web';mkdir($web.'/site',0700,true);
copy($root.'/restored-config.php',$web.'/site/config.php');chmod($web.'/site/config.php',0600);
$marker=json_decode((string)file_get_contents('/var/www/html/.local-database.json'),true,512,JSON_THROW_ON_ERROR);
if(($marker['kind']??'')!=='synthetic')throw new \RuntimeException('Synthetic source fixture marker required');
file_put_contents($web.'/.local-database.json',json_encode($marker,JSON_THROW_ON_ERROR));
// Code comes from the same isolated tested checkout; restored mutable storage is private.
foreach(['wire'=>'/var/www/html/wire','site/src'=>'/var/www/html/site/src','site/modules'=>'/var/www/html/site/modules','site/templates'=>'/var/www/html/site/templates','site/classes'=>'/var/www/html/site/classes'] as $relative=>$source){if(is_dir($source)&&!symlink($source,$web.'/'.$relative))throw new \RuntimeException('Restore code link failed');}
foreach(['cache','logs','sessions','files','backups'] as $directory)mkdir($web.'/site/assets/'.$directory,0700,true);
require '/var/www/html/wire/core/ProcessWire.php';
// buildConfig loads the unchanged archived site/config.php at its original relative depth.
$config=ProcessWire::buildConfig($web);
if($config->kulturhusLocalDataOrigin!=='synthetic')throw new \RuntimeException('Synthetic archived configuration required');
if($config->dbHost!=='mysql-test'||$config->dbUser!=='root'||$config->dbPass!=='isolated-regression-only')throw new \RuntimeException('Archived configuration must use the isolated MySQL service');
$config->dbName=$input['database'];$config->kulturhusVereinDatabase=\Kulturhus\Verein\Tests\MySqlFixture::dsn($db);
$config->sessionName='KH_RECOVERY_'.bin2hex(random_bytes(6));
require_once '/var/www/html/site/src/NativeSessionStorage.php';
$config->kulturhusNativeSessionPath=$root.'/restored-native-sessions';
\Kulturhus\NativeSessionStorage::configure($config);
putenv('APP_SECRET='.$input['app_secret']);
$wire=new ProcessWire($config);
\Kulturhus\NativeSessionStorage::assertActive($config);
if(!str_ends_with(session_name(),'_v2')||session_save_path()!==$root.'/restored-native-sessions')throw new \RuntimeException('Restore must use its own private current session namespace');
if($wire->user->isLoggedin())throw new \RuntimeException('A restored backup must not restore an authenticated session');
require_once '/var/www/html/site/src/AppSecret.php';
if(!hash_equals($input['app_secret'],\Kulturhus\AppSecret::value($wire)))throw new \RuntimeException('Restored application secret differs');
require __DIR__.'/../../src/bootstrap.php';
$wrong=$wire->session->login($input['name'],bin2hex(random_bytes(32)));
if($wrong&&$wrong->id)throw new \RuntimeException('Incorrect password accepted');
$user=$wire->session->login($input['name'],$input['password']);unset($input['password'],$input['app_secret']);
if(!$user||!$user->isLoggedin()||(int)$user->id!==(int)$input['user_id'])throw new \RuntimeException('Restored native login failed');
$actor=\Kulturhus\Verein\ProcessWireIdentity::resolve($db,(int)$user->id,true);
if($actor!=='audit-admin')throw new \RuntimeException('Restored actor mapping differs');
$file=(new \Kulturhus\Verein\Documents($db,$actor))->file($input['document_id']);
if(($file['bytes']??null)!=='Synthetic document bytes to restore')throw new \RuntimeException('Restored authorized document differs');
$db->prepare('UPDATE processwire_identities SET enabled=0 WHERE pw_user_id=?')->execute([(int)$user->id]);
$blocked=false;try{\Kulturhus\Verein\ProcessWireIdentity::resolve($db,(int)$user->id,true);}catch(\DomainException){$blocked=true;}
if(!$blocked)throw new \RuntimeException('Disabled mapping accepted');
$wire->session->logout();
if($wire->user->isLoggedin())throw new \RuntimeException('Native logout failed');
echo json_encode(['initially_logged_out'=>true,'native_login'=>true,'document_read'=>true,'wrong_password_rejected'=>true,'disabled_mapping_rejected'=>true,'restored_app_secret'=>true,'native_logout'=>true,'synthetic_only'=>true],JSON_THROW_ON_ERROR);
