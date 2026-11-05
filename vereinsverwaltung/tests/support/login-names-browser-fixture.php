<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Db,Access};
use Kulturhus\Native\Auth\{Schema,Runtime};
use Kulturhus\Native\Integration\{MemberAccounts,ManagerBridge};
use Kulturhus\Native\Shared\SecretBox;
$c=TestSupport::config();if(PHP_SAPI!=='cli'||($c['owner']??'')!=='cloud-onboarding-synthetic'||!in_array($c['label']??'',['demo','integrated-manager'],true))throw new RuntimeException('Owned synthetic login-name fixture required.');
$mode=$c['label']==='demo'?'standalone':'integrated';$db=TestSupport::db();Schema::install($db);
$runtime=$mode==='integrated'?dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/integrated-manager-runtime':dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$c['database'];
$dir=dirname(__DIR__,3).'/.local/browser-tests';if(!is_dir($dir))mkdir($dir,0700,true);$file=$dir.'/login-names-'.$mode.'.json';
if(($argv[1]??'')==='check'){
 $f=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$current=Db::run($db,'SELECT * FROM native_auth_accounts WHERE id=?',[$f['account']['id']])->fetch();$original=$f['account'];unset($current['login_name'],$current['version'],$original['login_name'],$original['version']);
 if($current!==$original||Db::run($db,'SELECT * FROM native_users WHERE id=?',[$f['account']['actor_id']])->fetch()!==$f['person'])throw new RuntimeException('Account/person original changed beyond personal alias.');
 if(hash_file('sha256',$f['credentials_file'])!==$f['credentials_hash'])throw new RuntimeException('Private fixture credentials changed.');
 if($mode==='integrated'&&(Db::run($db,'SELECT * FROM actors WHERE id=?',[$f['manager']['id']])->fetch()!==$f['manager']||Db::run($db,'SELECT * FROM contacts WHERE id=?',[$f['contact']['id']])->fetch()!==$f['contact']))throw new RuntimeException('Original manager identity/profile changed.');
 echo "Original mailbox/password/factor/person bytes and private fixture credentials retained: $mode.\n";exit();
}
$key=$runtime.'/auth.key';if(!is_file($key)){if($mode!=='standalone')throw new RuntimeException('Original synthetic auth key missing.');file_put_contents($key,random_bytes(32));chmod($key,0600);}
$auth=Runtime::service($db,$key);
// A fresh owned person keeps this suite independent of unrelated fixture users,
// their enabled factors and their real account-wide attempt budgets.
$run=bin2hex(random_bytes(8));$credentialsFile=$dir.'/login-names-'.$mode.'-credentials.json';
$credentials=['email'=>'own-login-name-'.$run.'@example.invalid','password'=>bin2hex(random_bytes(16)).'Aa7!'];$manager=null;$contact=null;
if($mode==='integrated'){
 require dirname(__DIR__,2).'/src/autoload.php';putenv('KH_NATIVE_MANAGER=1');putenv('VEREIN_AUTH=native');
 $registry=new \Kulturhus\Verein\Registry($db,'manager-admin');
 $person=$registry->saveContact(null,0,'person','Own personal login name '.$run,$credentials['email'],'Synthetic test address');$registry->addMembership($person,'','aktiv','2020-01-01',false);
 $account=(new MemberAccounts($db,$auth,SecretBox::fromFile($runtime.'/private.key')))->prepare('manager-demo',$person);$auth->acceptInvitation($account['setup_token'],$credentials['password']);
 $manager=Db::run($db,'SELECT a.* FROM actors a JOIN native_manager_identities i ON i.manager_actor_id=a.id AND i.manager_club_id=a.club_id JOIN native_auth_accounts au ON au.actor_id=i.native_id WHERE au.id=?',[$account['account_id']])->fetch();$contact=Db::run($db,'SELECT * FROM contacts WHERE id=?',[$person])->fetch();
}else{
  $actor='own-login-name-'.$run;Db::run($db,"INSERT INTO native_users(id,club_id,name) VALUES(?,'synthetic-a','Own personal login name')",[$actor]);
  Db::run($db,"INSERT IGNORE INTO native_grants(actor_id,module,capability) VALUES('admin','accounts','manage')");
  $invite=$auth->createInvitation(new Access($db,'admin'),$actor,$credentials['email']);$auth->acceptInvitation($invite['token'],$credentials['password']);
}
file_put_contents($credentialsFile,json_encode($credentials,JSON_THROW_ON_ERROR));chmod($credentialsFile,0600);
$account=Db::run($db,'SELECT * FROM native_auth_accounts WHERE email=?',[$credentials['email']])->fetch();if(!$account||!$account['active']||!$account['verified']||$account['email_factor']||$account['totp_secret']!==null)throw new RuntimeException('Owned password-only browser account required; existing factors are preserved.');
$f=['account'=>$account,'person'=>Db::run($db,'SELECT * FROM native_users WHERE id=?',[$account['actor_id']])->fetch(),'manager'=>$manager,'contact'=>$contact,'credentials_file'=>$credentialsFile,'credentials_hash'=>hash_file('sha256',$credentialsFile)];
file_put_contents($file,json_encode($f,JSON_THROW_ON_ERROR));chmod($file,0600);echo "Owned personal login-name browser fixture prepared: $mode.\n";
