<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';require dirname(__DIR__).'/src/bootstrap.php';require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\Shared\{Db,SecretBox};use Kulturhus\Native\Status\{Environment,Monitor,Http};use Kulturhus\Native\Integration\ManagerBridge;
$db=Kulturhus\Native\TestSupport::db();Kulturhus\Native\Installer\SchemaRegistry::install($db);
Db::run($db,"INSERT INTO clubs VALUES('provider-a','Own provider A'),('provider-b','Own provider B')");foreach([['provider-admin','provider-a'],['provider-other','provider-b']]as[$id,$club])Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES(?,?,'member',1)",[$id,$club]);
$actor=Kulturhus\Verein\Permissions::actor($db,'provider-admin');$box=new SecretBox(random_bytes(32));$club=ManagerBridge::clubKey('provider-a');$storage=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/status-provider-storage';mkdir($storage,0700);file_put_contents($storage.'/original-media.txt','Own retained original ä🙂');
$accounts=[
 'meta'=>['page_id'=>'own-page','instagram_id'=>'own-instagram','token'=>'private-own-meta-token','api_version'=>'v25.0'],
 'sumup'=>['merchant_code'=>'OWN-MERCHANT','token'=>'private-own-sumup-token'],
 'stripe'=>['account_id'=>'acct_own_original','mode'=>'test','secret_key'=>'sk_test_own_private','webhook_secret'=>'whsec_own_private'],
 'paypal'=>['client_id'=>'own-client','client_secret'=>'private-paypal-secret','merchant_id'=>'OWNPAYPAL','mode'=>'sandbox'],
 'eventbrite'=>['organization'=>'own-organization','token'=>'private-own-event-token'],
 'stadtlandfunk'=>['account_id'=>'own-person','home_area_id'=>'own-area','access_token'=>'private-own-stadt-token','expires_at'=>time()+3600],
];
foreach($accounts as$provider=>$data){$cipher=$box->seal('api:'.$club.':'.$provider,$data);Db::run($db,'INSERT INTO native_private_settings VALUES(?,?,1,?,?)',[$club,'api-'.$provider,$cipher,'provider-admin']);}
$originalStadt=Db::run($db,"SELECT ciphertext FROM native_private_settings WHERE club_id=? AND purpose='api-stadtlandfunk'",[$club])->fetchColumn();
$scenario='ok';$calls=[];$requests=static function($provider,$method,$path,$headers,$body)use(&$scenario,&$calls):array{
 if(!Http::allowed($provider,$method,$path))throw new RuntimeException('Unexpected status surface');$calls[]=[$provider,$method,$path];
 if(ctype_digit($scenario))return ['status'=>(int)$scenario,'body'=>[]];
 $data=match($provider){'meta'=>['id'=>str_contains($path,'own-instagram')?'own-instagram':'own-page'],'sumup'=>['merchant_code'=>'OWN-MERCHANT'],'stripe'=>['id'=>'acct_own_original','charges_enabled'=>$scenario!=='charges-disabled'],'paypal-sandbox'=>$method==='POST'?['access_token'=>'private-ephemeral-oauth']:['account_id'=>'OWNPAYPAL','balances'=>[['currency'=>'EUR','total_balance'=>['value'=>'1234567.89']]]],'eventbrite'=>['events'=>[['id'=>'own-event','organization_id'=>$scenario==='wrong-account'?'foreign-account':'own-organization']],'pagination'=>['object_count'=>1,'page_count'=>1]],'stadtlandfunk'=>['ownPerson'=>['id'=>'own-person'],'homeGeoArea'=>['id'=>'own-area'],'homeGeoAreaSelectionStatus'=>'VALID','onboardingRequired'=>false],default=>[]};
 if($scenario==='wrong-account')foreach(['id','merchant_code','account_id']as$key)if(array_key_exists($key,$data))$data[$key]='foreign-account';
 if($scenario==='paypal-reporting-denied'&&$method==='GET')return ['status'=>403,'body'=>[]];
 return ['status'=>200,'body'=>$data];
};
$env=new Environment($db,'provider-admin',$box,$storage,$requests);$checks=0;function providerCheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;echo "PASS $label\n";}
$tables=array_values(array_diff($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),['native_status_checks','native_status_settings']));$original=ownOriginalDatabaseHash($db,$tables);$fileHash=hash_file('sha256',$storage.'/original-media.txt');
foreach(array_keys($accounts)as$provider){$d=$env->describe($provider,$actor);providerCheck($d['configured']&&$d['enabled'],'actual encrypted '.$provider.' account is described without network access');providerCheck(!str_contains(json_encode($d),'private-'),'descriptor excludes raw account credentials '.$provider);providerCheck($env->probe($provider,$actor)['state']==='ok','actual production adapter confirms expected '.$provider.' target using injected read-only provider response');}
providerCheck(count($calls)===8,'Meta checks both original targets and PayPal authenticates plus confirms the merchant');
providerCheck($env->describe('stripe',$actor)['environment']==='test'&&$env->describe('paypal',$actor)['environment']==='test','sandbox and test accounts remain explicitly distinct from live');
$public=json_encode($env->monitor()->snapshot());foreach(['private-own','sk_test_own','OWNPAYPAL','1234567.89','acct_own_original']as$secret)providerCheck(!str_contains($public,$secret),'no private credential/account/balance field enters page DTO: '.substr($secret,0,6));
foreach(['0'=>'down','400'=>'restricted','401'=>'restricted','403'=>'restricted','429'=>'restricted','503'=>'provider_error']as$code=>$state){$scenario=(string)$code;providerCheck($env->probe('meta',$actor)['state']===$state,'provider response '.$code.' maps to '.$state);}
$scenario='wrong-account';foreach(['meta','sumup','stripe','paypal','eventbrite']as$provider)providerCheck($env->probe($provider,$actor)['state']==='restricted','foreign '.$provider.' identity cannot report healthy');
$scenario='charges-disabled';providerCheck($env->probe('stripe',$actor)['reason']==='payments_not_enabled','Stripe target without active charges is restricted');
$scenario='paypal-reporting-denied';providerCheck($env->probe('paypal',$actor)['reason']==='paypal_merchant_unverified','OAuth success alone never claims the configured PayPal merchant verified');
$scenario='ok';$before=count($calls);$real=new Environment($db,'provider-admin',$box,$storage);providerCheck($real->probe('meta',$actor)['reason']==='fixture_transport_disabled'&&count($calls)===$before,'CI cannot silently replace an injected provider with real network transport');
$otherActor=Kulturhus\Verein\Permissions::actor($db,'provider-other');providerCheck(!$env->describe('meta',$otherActor)['configured'],'another club cannot discover the original encrypted connection');
providerCheck($env->probe('storage',$actor)['state']==='ok'&&glob($storage.'/.status-probe-*')===[]&&hash_file('sha256',$storage.'/original-media.txt')===$fileHash,'actual storage roundtrip removes only its own temporary probe and preserves original bytes');
providerCheck($env->probe('mysql',$actor)['state']==='ok'&&$env->probe('application',$actor)['state']==='ok','actual current MySQL and installed application schema are checked');
providerCheck($env->probe('scheduler',$actor)['state']==='not_checked','missing scheduler evidence never becomes a green heartbeat');
$expired=$accounts['stadtlandfunk'];$expired['expires_at']=time()-1;Db::run($db,"UPDATE native_private_settings SET ciphertext=? WHERE club_id=? AND purpose='api-stadtlandfunk'",[$box->seal('api:'.$club.':stadtlandfunk',$expired),$club]);
providerCheck($env->monitor()->snapshot('social')[1]['state']==='restricted','an expired StadtLand token invalidates an older successful connection without refreshing it');$expired['username']='synthetic-user';$expired['password']='synthetic-only';Db::run($db,"UPDATE native_private_settings SET ciphertext=? WHERE club_id=? AND purpose='api-stadtlandfunk'",[$box->seal('api:'.$club.':stadtlandfunk',$expired),$club]);$renewable=$env->monitor()->snapshot('social')[1];providerCheck($renewable['can_check']&&$renewable['state']==='not_checked','saved renewable login exposes an explicit check without performing a refresh on GET');Db::run($db,"UPDATE native_private_settings SET ciphertext=? WHERE club_id=? AND purpose='api-stadtlandfunk'",[$originalStadt,$club]);

// Actual read-only FinTS observation: own private files, current scope and original run timestamps.
$bankRoot=dirname($storage).'/status-own-banking';mkdir($bankRoot,0700);
$bankConfig=['club_id'=>'provider-a','url'=>'https://bank.example.invalid/fints','bank_code'=>'28262673','product_id'=>'OWN_SYNTHETIC'];
foreach(['fints.json'=>json_encode($bankConfig),'fints-credentials.enc'=>'Own encrypted fixture bytes','fints-state.key'=>random_bytes(32)]as$name=>$bytes){file_put_contents($bankRoot.'/'.$name,$bytes);chmod($bankRoot.'/'.$name,0600);touch($bankRoot.'/'.$name,time()-120);}
$previousMode=getenv('VEREIN_MODE');$previousRoot=getenv('VEREIN_BANKING_ROOT');putenv('VEREIN_MODE=production');putenv('VEREIN_BANKING_ROOT='.$bankRoot);
$db->beginTransaction();
try{
 Db::run($db,'INSERT INTO finance_accounts VALUES(?,?,?,?,?,?)',['own-status-bank-account','provider-a','Own status bank','offline',0,'2026-01-01']);
 Db::run($db,'INSERT INTO bank_account_mappings VALUES(?,?,?,?,?,?,?,?,?)',['provider-a','own-status-bank-map','fints','PRODUCTION',hash('sha256','own-bank-identity'),'own-status-bank-account','Own status bank','provider-admin',gmdate('c',time()-3600)]);
 providerCheck($env->describe('fints',$actor)['configured'],'actual complete private FinTS configuration and club mapping are present');
 providerCheck($env->probe('fints',$actor)['state']==='not_checked','configured bank without an observed completed read never becomes healthy');
 $observed=time()-60;
 Db::run($db,'INSERT INTO fints_sync_runs(club_id,id,mapping_id,window_key,state,started_at) VALUES(?,?,?,?,?,?)',['provider-a','own-status-bank-run','own-status-bank-map','own-window','complete',gmdate('c',$observed)]);
 $proof=$env->probe('fints',$actor);providerCheck($proof['state']==='ok'&&$proof['observed_at']===$observed,'FinTS current completed read retains its actual original UTC observation');
 touch($bankRoot.'/fints-credentials.enc',time());clearstatcache();providerCheck($env->probe('fints',$actor)['reason']==='bank_configuration_changed','changed credentials invalidate a prior completed bank read');touch($bankRoot.'/fints-credentials.enc',time()-120);clearstatcache();
 Db::run($db,'UPDATE fints_sync_runs SET state=? WHERE club_id=? AND id=?',['needs_auth','provider-a','own-status-bank-run']);providerCheck($env->probe('fints',$actor)['reason']==='bank_authentication_required','latest TAN/authentication requirement cannot reuse an older bank success');
 Db::run($db,'UPDATE fints_sync_runs SET state=?,started_at=? WHERE club_id=? AND id=?',['complete',gmdate('c',time()-1800),'provider-a','own-status-bank-run']);foreach(glob($bankRoot.'/*')as$file)touch($file,time()-3600);clearstatcache();providerCheck($env->probe('fints',$actor)['reason']==='bank_proof_old','old actual bank read is explicitly unconfirmed');
 unlink($bankRoot.'/fints-credentials.enc');providerCheck(!$env->describe('fints',$actor)['configured'],'removed bank credential cannot remain configured or produce green proof');
 file_put_contents($bankRoot.'/fints-credentials.enc','Own encrypted fixture bytes');chmod($bankRoot.'/fints-credentials.enc',0600);$wrong=$bankConfig;$wrong['club_id']='provider-b';file_put_contents($bankRoot.'/fints.json',json_encode($wrong));
 $refused=false;try{$env->describe('fints',$actor);}catch(DomainException){$refused=true;}providerCheck($refused,'FinTS private configuration belonging to another club is refused');
}finally{$db->rollBack();putenv($previousMode===false?'VEREIN_MODE':'VEREIN_MODE='.$previousMode);putenv($previousRoot===false?'VEREIN_BANKING_ROOT':'VEREIN_BANKING_ROOT='.$previousRoot);foreach(glob($bankRoot.'/*')as$file)unlink($file);rmdir($bankRoot);}
foreach(['[AUTHENTICATIONFAILED] own private credential rejected','LOGIN FAILED own-private-password','Can not authenticate to IMAP server']as$error)providerCheck(Environment::imapFailure([$error])===['state'=>'restricted','reason'=>'imap_authentication_required'],'IMAP credential failure is restricted and contains no raw server secret');
foreach(['Connection refused','Connection timed out','TLS certificate invalid','']as$error)providerCheck(Environment::imapFailure([$error])===['state'=>'down','reason'=>'imap_not_confirmed'],'temporary IMAP reachability failure remains eligible for automatic recovery');
providerCheck(ownOriginalDatabaseHash($db,$tables)===$original,'all original encrypted accounts and business tables remain byte-exact after adapter checks');
echo json_encode(['suite'=>'native-service-status-providers','checks'=>$checks,'injected_http_requests'=>count($calls),'real_provider_requests'=>0,'original_bytes'=>'exact','live_transport'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
