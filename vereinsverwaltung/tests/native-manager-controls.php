<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');require dirname(__DIR__).'/native/bootstrap.php';require dirname(__DIR__).'/src/bootstrap.php';require __DIR__.'/support/editorial-browser-fixture.php';require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Native\Integration\ManagerBridge;
$db=TestSupport::db();Kulturhus\Native\Installer\SchemaRegistry::install($db);TestSupport::seed($db);$fixture=ownEditorialBrowserFixture($db);$registry=new Kulturhus\Verein\Registry($db,$fixture['accounts']['admin']['actor']);$contacts=[];
foreach($fixture['accounts']as$role=>$account){$id=$registry->saveContact(null,0,'person','Own complete UI '.$role,'workflow-'.$role.'@example.invalid','Own original profile '.$role);Db::run($db,'UPDATE actors SET contact_id=? WHERE id=?',[$id,$account['actor']]);(new ManagerBridge($db,$account['actor'],'native',static fn()=>null))->access();$contacts[$role]=$id;}
Kulturhus\Verein\Database::ensureContributionRates($db);
$subscribers=new Kulturhus\Native\Communication\Subscribers($db,$fixture['admin']);$subscribers->recordWrittenConsent('workflow-admin@example.invalid','Own complete UI admin',['events'],'Own isolated complete UI written consent');(new Kulturhus\Native\Communication\PressContacts($db,$fixture['admin']))->save(['email'=>'workflow-admin@example.invalid','name'=>'Own original press profile','outlet'=>'Own UI source','active'=>true]);
$tables=array_values(array_diff($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),['native_auth_sessions']));$before=ownOriginalDatabaseHash($db,$tables);$keys=[hash_file('sha256',$fixture['root'].'/auth.key'),hash_file('sha256',$fixture['root'].'/private.key')];$browser=getenv('DVM_MANAGER_CONTROLS_BROWSER')==='1';$server=null;$httpChecks=0;
try{
 $server=ownEditorialBrowserStart($fixture,['contact'=>$contacts['admin'],'expected_club_name'=>(new Kulturhus\Verein\Settings($db,$fixture['accounts']['admin']['actor']))->section('club')['data']['name'],'profile_only'=>getenv('DVM_MANAGER_CONTROLS_PROFILE_ONLY')==='1','transition_only'=>getenv('DVM_MANAGER_CONTROLS_TRANSITION_ONLY')==='1','fast'=>getenv('DVM_MANAGER_CONTROLS_FAST')==='1','result_file'=>$fixture['root'].'/manager-controls-result.json']);
 foreach(['admin'=>['?view=dashboard','?view=members&id='.$contacts['admin']],'member'=>['?view=dashboard','?view=portal&tab=own'],'editor'=>['?view=dashboard','?view=website&tab=news']]as$role=>$queries){
  $jar=$fixture['root'].'/core-'.$role.'.cookies';file_put_contents($jar,'');chmod($jar,0600);$request=curl_init();curl_setopt_array($request,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>30,CURLOPT_COOKIEFILE=>$jar,CURLOPT_COOKIEJAR=>$jar]);
  try{
   curl_setopt($request,CURLOPT_URL,$fixture['origin'].'/own-test-enter?role='.$role);if(curl_exec($request)===false||curl_getinfo($request,CURLINFO_RESPONSE_CODE)!==303)throw new RuntimeException('Owned personal '.$role.' session entry failed');$httpChecks++;
   foreach($queries as$query){curl_setopt($request,CURLOPT_URL,$fixture['origin'].'/vereinsverwaltung/'.$query);$html=curl_exec($request);if(!is_string($html)||curl_getinfo($request,CURLINFO_RESPONSE_CODE)!==200)throw new RuntimeException('Actual owned menu HTTP failed '.$role.' '.$query.' status '.curl_getinfo($request,CURLINFO_RESPONSE_CODE));$doc=new DOMDocument();@$doc->loadHTML($html);$xpath=new DOMXPath($doc);if($xpath->query('//script[@type="module" and contains(@src,"bootstrap")]')->length!==1||preg_match('/Fatal error:|SQLSTATE\[|Stack trace:/',$html))throw new RuntimeException('Actual personal menu bootstrap failed '.$role);$httpChecks++;}
  }finally{curl_close($request);}
 }
 echo 'PASS '.$httpChecks.' actual protected personal menu HTTP requests without Node or business POST'."\n";
 if($browser){
  putenv('DVM_MANAGER_CONTROLS_FIXTURE='.getenv('DVM_EDITORIAL_WORKFLOW_FIXTURE'));
  if(getenv('DVM_MANAGER_CONTROLS_PROFILE_ONLY')!=='1'&&getenv('DVM_MANAGER_CONTROLS_TRANSITION_ONLY')!=='1'){
   $nav=proc_open(['node',__DIR__.'/native-bootstrap-navigation-browser.mjs'],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes);if(!is_resource($nav)||proc_close($nav)!==0)throw new RuntimeException('Actual own complete Bootstrap navigation browser failed.');
  }
  $p=proc_open(['node',__DIR__.'/native-manager-controls-browser.mjs'],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes);if(!is_resource($p)||proc_close($p)!==0)throw new RuntimeException('Actual own complete manager controls browser failed.');
 }

 if(getenv('DVM_ANDROID_APP_BROWSER')==='1'){
  $mobile=proc_open(['node',dirname(__DIR__,2).'/native-app/tests/android-emulator.mjs'],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes);if(!is_resource($mobile)||proc_close($mobile)!==0)throw new RuntimeException('Actual Android emulator app workflow failed.');
 }
}finally{
 if($server){proc_terminate($server['process']);proc_close($server['process']);fclose($server['log']);}
 if(ownOriginalDatabaseHash($db,$tables)!==$before)throw new RuntimeException('Read-only whole menu navigation changed an original business or rights byte.');if($keys!==[hash_file('sha256',$fixture['root'].'/auth.key'),hash_file('sha256',$fixture['root'].'/private.key')])throw new RuntimeException('Whole menu navigation changed original account keys.');echo json_encode(['suite'=>'native-manager-controls','browser'=>$browser,'http_checks'=>$httpChecks,'original_tables'=>count($tables),'original_bytes'=>'exact','external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
}
