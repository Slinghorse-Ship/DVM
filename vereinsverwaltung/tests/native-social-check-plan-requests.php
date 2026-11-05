<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/native/bootstrap.php';
require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db,RuntimeSchema};
use Kulturhus\Native\Communication\{CheckPlans,CheckPlanRequests};

$db=TestSupport::db();Kulturhus\Native\Installer\SchemaRegistry::install($db);TestSupport::seed($db);RuntimeSchema::install($db);
$access=new Access($db,'admin');$service=new CheckPlanRequests($db,$access);$plans=new CheckPlans($db);$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;echo 'PASS '.$label."\n";};
$deny=static function(callable $call,string $label)use($check):void{try{$call();}catch(DomainException){$check(true,$label);return;}throw new RuntimeException($label);};
$input=static function(string $channel)use($service,$plans,$access):array{
 $data=$service->context()+['channel'=>$channel,'version'=>(string)$plans->status($access,$channel)['version'],'enabled'=>'1'];
 return CheckPlanRequests::capture($data+($channel==='meta'?['interval_seconds'=>' 300 ']:['day_start'=>'08','day_end'=>'22']));
};
$json=static fn(mixed $value):string=>json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$snapshot=static fn():string=>$json([$db->query('SELECT * FROM native_social_check_plans ORDER BY club_id,channel')->fetchAll(),$db->query('SELECT * FROM native_settings_requests ORDER BY club_id,request_key')->fetchAll()]);
$key=bin2hex(random_bytes(16));$first=$input('meta');$before=$snapshot();
$check($service->check($key,$first)===['found'=>false]&&$snapshot()===$before,'an absent original is a pure observation without a plan, receipt or background request');
$accepted=$service->save($key,$first);
$check($accepted['found']&&$accepted['input']===$first&&$accepted['result']['version']===1&&$accepted['result']['config']===['interval_seconds'=>300],'accepted original binds the exact raw input, person, club and normalized actual plan');
$before=$snapshot();$check($service->save($key,$first)===$accepted&&$snapshot()===$before,'exact retry performs no second save or next-time change');
$deny(fn()=>$service->save($key,array_replace($first,['interval_seconds'=>'301'])),'an accepted key cannot adopt altered input');
$deny(fn()=>$service->check($key,array_replace($first,['interval_seconds'=>'301'])),'pure inspection cannot claim different edited fields were accepted');
$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$keys=[];
foreach($tables as$table){
 $columns=Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);
 $where=$table==='native_social_check_plans'?' WHERE club_id<>?':'';$values=Db::run($db,'SELECT `'.implode('`,`',$columns).'` FROM `'.$table.'`'.$where,$where!==''?[$access->club()]:[])->fetchAll(PDO::FETCH_NUM);$keys[$table]=compact('columns','values');
}
$filter=static function(string $table)use($keys):array{$k=$keys[$table];if(!$k['values'])return[' WHERE 0=1',[]];$args=[];foreach($k['values']as$row)array_push($args,...$row);return [' WHERE (`'.implode('`,`',$k['columns']).'`) IN ('.implode(',',array_fill(0,count($k['values']),'('.implode(',',array_fill(0,count($k['columns']),'?')).')')).')',$args];};
$originals=ownOriginalDatabaseHash($db,$tables,$filter);
$translated=Kulturhus\Native\Integration\Routes::translate('/?'.http_build_query(['view'=>'social','section'=>'sync','check_channel'=>'stadtlandfunk','check_request'=>$key,'check_page'=>'2']));parse_str(parse_url($translated,PHP_URL_QUERY),$translatedQuery);$expectedRoute=['view'=>'social','section'=>'sync','check_channel'=>'stadtlandfunk','check_request'=>$key,'check_page'=>'2'];ksort($translatedQuery);ksort($expectedRoute);
$check(parse_url($translated,PHP_URL_PATH)==='/vereinsverwaltung/'&&$translatedQuery===$expectedRoute,'integrated pure original and page links retain the actual sync context and original key');
$check(Kulturhus\Native\Integration\Routes::result(['view'=>'social','section'=>'sync'])===['view'=>'social','section'=>'sync'],'the new editor has an explicitly allowed existing-manager return route');

$plans->save($access,'meta',['interval_seconds'=>600],false,1);
$check($service->review('meta',$key)===$accepted&&$service->save($key,$first)===$accepted&&$plans->status($access,'meta')['version']===2,'an older accepted original stays version one after the current plan becomes version two');
$staleKey=bin2hex(random_bytes(16));$before=$snapshot();$deny(fn()=>$service->save($staleKey,$first),'a stale unaccepted form cannot overwrite a newer schedule');
$check($snapshot()===$before&&$service->check($staleKey,$first)===['found'=>false],'stale rejection leaves no invented original receipt');
$proposal=$service->prepare($staleKey,$first);
$check($proposal['prepared']['input']['version']==='2'&&$proposal['prepared']['input']['interval_seconds']===$first['interval_seconds']&&$proposal['prepared']['key']!==$staleKey&&$snapshot()===$before,'explicit current preparation preserves raw values and changes only the new intention and expected version without writes');
$prepared=$service->save($proposal['prepared']['key'],$proposal['prepared']['input']);
$check($prepared['result']['version']===3&&$service->check($staleKey,$first)===['found'=>false],'accepting the new preparation never confirms the refused old form');
$check($service->prepare($key,$first)===['original'=>$accepted,'prepared'=>null],'a saved original is returned instead of silently preparing a different intention');
$personal=$input('stadtlandfunk');$personalKey=bin2hex(random_bytes(16));$personalOriginal=$service->save($personalKey,$personal);
$check(count($personalOriginal['result']['config'])===3&&$personalOriginal['result']['config']['mode']==='hourly'&&$personalOriginal['result']['config']['day_start']===8&&$personalOriginal['result']['config']['day_end']===22,'personal plan records an hourly randomized daytime range');
$check($personalOriginal['input']['day_start']==='08','hourly request retains the exact entered hour notation in its original');
$invalid=array_replace($personal,['version'=>'1','day_start'=>'23','day_end'=>'8']);$before=$snapshot();$deny(fn()=>$service->save(bin2hex(random_bytes(16)),$invalid),'an inverted daytime range is rejected by the existing scheduler');
$check($snapshot()===$before,'invalid original windows cannot change either shared store');
$deny(fn()=>$service->save(bin2hex(random_bytes(16)),array_replace($first,['club_id'=>'synthetic-b'])),'a copied form cannot switch its pinned club');
$deny(fn()=>$service->save(bin2hex(random_bytes(16)),array_replace($first,['actor_id'=>'outsider'])),'a copied form cannot switch its pinned person');
$deny(fn()=>$service->save(bin2hex(random_bytes(16)),array_replace($first,['authority'=>'manager'])),'a copied form cannot switch its authority');
$deny(fn()=>(new CheckPlanRequests($db,new Access($db,'reader')))->review('meta',$key),'ordinary reading rights expose no private plan confirmation');
$check((new CheckPlanRequests($db,new Access($db,'outsider')))->review('meta',$key)===['found'=>false],'another club cannot adopt or read an original key');
Db::run($db,"DELETE FROM native_grants WHERE actor_id='admin' AND module='social' AND capability='manage'");
$deny(fn()=>$service->review('meta',$key),'current manage permission is required even for an old original');
Db::run($db,"INSERT INTO native_grants VALUES('admin','social','manage')");
$check($service->review('meta',$key)===$accepted,'a fresh pure lookup observes restored current authority');
$db->beginTransaction();try{$deny(fn()=>$service->save(bin2hex(random_bytes(16)),$input('meta')),'a schedule change refuses an unrelated outer transaction');$check($db->inTransaction(),'refusing an outer transaction does not roll back the caller');}finally{$db->rollBack();}

final class OwnPlanCommitPdo extends PDO
{
 public string $fault='';
 public function commit():bool{
  $fault=$this->fault;$this->fault='';
  if($fault==='declined'){parent::rollBack();throw new RuntimeException('Own declined COMMIT acknowledgement');}
  if($fault==='false-active')return false;
  $result=parent::commit();if($fault==='accepted')throw new RuntimeException('Own accepted COMMIT acknowledgement lost');return $result;
 }
}
$cfg=TestSupport::config();$faultDb=new OwnPlanCommitPdo('mysql:host=127.0.0.1;port=13371;dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$faultDb->exec("SET time_zone='+00:00'");
$faultService=new CheckPlanRequests($faultDb,new Access($faultDb,'admin'));
$faultKey=bin2hex(random_bytes(16));$faultInput=$input('meta');$faultDb->fault='accepted';$lost=$faultService->save($faultKey,$faultInput);
$check($lost['found']&&$service->check($faultKey,$faultInput)===$lost&&!$faultDb->inTransaction(),'a genuinely committed but lost COMMIT response is recovered from the immutable committed receipt');
foreach(['declined','false-active']as$mode){$failedKey=bin2hex(random_bytes(16));$values=$input('meta');$before=$snapshot();$faultDb->fault=$mode;$failed=false;try{$faultService->save($failedKey,$values);}catch(Throwable){$failed=true;}$check($failed&&!$faultDb->inTransaction()&&$snapshot()===$before&&$service->check($failedKey,$values)===['found'=>false],'actual '.$mode.' COMMIT cannot be reported as accepted or leak an open transaction');}
$sqlKey=bin2hex(random_bytes(16));$sqlInput=$input('meta');$before=$snapshot();$db->exec("CREATE TRIGGER own_plan_receipt_refusal BEFORE INSERT ON native_settings_requests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Own receipt insert refused'");
try{$failed=false;try{$service->save($sqlKey,$sqlInput);}catch(Throwable){$failed=true;}$check($failed&&$snapshot()===$before,'an actual SQL receipt failure rolls the scheduler mutation back atomically');}finally{$db->exec('DROP TRIGGER own_plan_receipt_refusal');}
$check($service->check($sqlKey,$sqlInput)===['found'=>false],'failed SQL never manufactures an accepted schedule receipt');
$parallelInput=$input('meta');$parallelKey=bin2hex(random_bytes(16));$gate=bin2hex(random_bytes(16));$gatePath=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/plan-commit-'.$gate;
$start=static function(string $mode)use($parallelInput,$parallelKey,$gate):array{$p=proc_open([...TestSupport::phpCommand(),__DIR__.'/support/check-plan-process.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($p))throw new RuntimeException('Own check-plan subprocess missing');fwrite($pipes[0],json_encode(['mode'=>$mode,'input'=>$parallelInput,'key'=>$parallelKey,'gate'=>$gate],JSON_THROW_ON_ERROR));fclose($pipes[0]);return[$p,$pipes];};
$finish=static function(array $worker):array{[$p,$pipes]=$worker;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0||$err!=='')throw new RuntimeException('Own check-plan worker failed: '.$err);return json_decode($out,true,32,JSON_THROW_ON_ERROR);};
$writer=$start('hold');$waiting=false;for($i=0;$i<1000;$i++){clearstatcache(true,$gatePath.'.waiting');if(is_file($gatePath.'.waiting')){$waiting=true;break;}usleep(10000);}if(!$waiting)throw new RuntimeException('Own held original writer did not become observable.');
try{$other=$finish($start('normal'));$check(isset($other['busy'])&&$service->check($parallelKey,$parallelInput)===['found'=>false],'a second actual PHP process observes an unconfirmed running writer without inventing success or replaying its change');}
finally{file_put_contents($gatePath.'.release','release owned writer');chmod($gatePath.'.release',0600);}
$completed=$finish($writer);$parallelOriginal=$service->save($parallelKey,$parallelInput);
$check($completed['saved']===$parallelOriginal&&$parallelOriginal['result']['version']===(int)$parallelInput['version']+1&&(int)Db::run($db,'SELECT COUNT(*) FROM native_settings_requests WHERE club_id=? AND request_key=?',[$access->club(),$parallelKey])->fetchColumn()===1,'the completed independent writer and exact later retry share one original and one actual version increment');
foreach(['.waiting','.release']as$suffix)unlink($gatePath.$suffix);
$changedOriginal=array_replace($first,['interval_seconds'=>' 777 ']);$before=$snapshot();$currentVersion=$plans->status($access,'meta')['version'];$next=$service->prepare($key,$changedOriginal);
$check($next['original']===$accepted&&$next['prepared']['key']!==$key&&$next['prepared']['input']['interval_seconds']===' 777 '&&$next['prepared']['input']['version']===(string)$currentVersion&&$snapshot()===$before,'a conscious further edit prepares a new intention while retaining the actual earlier original without writes');
$nextOriginal=$service->save($next['prepared']['key'],$next['prepared']['input']);$check($nextOriginal['result']['version']===$currentVersion+1&&$service->review('meta',$key)===$accepted,'a consecutive accepted edit preserves the first immutable original and its own new exact version');
$deny(fn()=>$service->check($key,$changedOriginal),'the newer prepared values never become accepted under the earlier original key');

$before=$snapshot();$deny(fn()=>$service->save($key,$input('stadtlandfunk')),'a key from a different settings section cannot be adopted for another scheduler');$check($snapshot()===$before,'cross-section key collision leaves both schedules and every original receipt unchanged');
Db::run($db,"INSERT INTO native_users(id,club_id,name) VALUES('own-plan-mover','synthetic-a','Own movable schedule actor')");Db::run($db,"INSERT INTO native_grants VALUES('own-plan-mover','social','read'),('own-plan-mover','social','manage')");
$mover=new CheckPlanRequests($db,new Access($db,'own-plan-mover'));$moveInput=array_replace($input('meta'),$mover->context());Db::run($db,"UPDATE native_users SET club_id='synthetic-b' WHERE id='own-plan-mover'");$before=$snapshot();$deny(fn()=>$mover->save(bin2hex(random_bytes(16)),$moveInput),'a real changed current actor club cannot redirect the original form to the new club');$check($snapshot()===$before,'changing current club never moves the original form or creates another club schedule');
while($service->history('meta')['total']<8){$values=$input('meta');$values['enabled']='0';$service->save(bin2hex(random_bytes(16)),$values);}
$before=$snapshot();$one=$service->history('meta',1);$two=$service->history('meta',2);$seen=array_column(array_merge($one['rows'],$two['rows']),'request_key');
$check(count($one['rows'])===6&&count($two['rows'])===2&&count(array_unique($seen))===8&&$one['total']===8&&$one['pages']===2,'pure own history reaches all eight actual original requests across six-item pages without omissions or duplicates');$check($snapshot()===$before,'history pagination never writes a schedule or confirmation');
$check(ownOriginalDatabaseHash($db,$tables,$filter)===$originals,'all earlier complete business rows and their column/primary-key metadata remain exact, including the first immutable original');

if(getenv('DVM_SOCIAL_CHECK_PLAN_BROWSER')==='1'){
 require __DIR__.'/support/social-human-browser-fixture.php';$browser=ownSocialHumanBrowser($db);
 $browserTables=array_values(array_diff($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),['native_auth_sessions']));$browserKeys=[];
 foreach($browserTables as$table){$columns=Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);$values=Db::run($db,'SELECT `'.implode('`,`',$columns).'` FROM `'.$table.'`')->fetchAll(PDO::FETCH_NUM);$browserKeys[$table]=compact('columns','values');}
 $browserFilter=static function(string$table)use($browserKeys):array{$k=$browserKeys[$table];if(!$k['values'])return[' WHERE 0=1',[]];$args=[];foreach($k['values']as$row)array_push($args,...$row);return[' WHERE (`'.implode('`,`',$k['columns']).'`) IN ('.implode(',',array_fill(0,count($k['values']),'('.implode(',',array_fill(0,count($k['columns']),'?')).')')).')',$args];};
 $browserOriginals=ownOriginalDatabaseHash($db,$browserTables,$browserFilter);

 try{$p=proc_open(['node',__DIR__.'/social-check-plan-browser.mjs'],[0=>['pipe','r'],1=>['pipe','w']],$pipes);fwrite($pipes[0],json_encode($browser['input'],JSON_THROW_ON_ERROR));fclose($pipes[0]);while(($line=fgets($pipes[1]))!==false)echo $line;fclose($pipes[1]);if(proc_close($p)!==0)throw new RuntimeException('Actual check-plan browser failed');$check((int)$db->query("SELECT COUNT(*) FROM own_human_browser_calls WHERE method='POST'")->fetchColumn()===0,'all real check-plan browser profiles make zero provider writes');$check(ownOriginalDatabaseHash($db,$browserTables,$browserFilter)===$browserOriginals,'all earlier whole business rows and column/primary-key metadata remain exact after every real plan browser profile');}
 finally{proc_terminate($browser['process']);proc_close($browser['process']);fclose($browser['log']);foreach($browser['original_files']as$file=>$old){clearstatcache(true,$file);if(!is_file($file)||hash_file('sha256',$file)!==$old['sha256']||(fileperms($file)&0777)!==$old['mode'])throw new RuntimeException('Original browser key changed');}}
}
echo json_encode(['suite'=>'native-social-check-plan-requests','checks'=>$checks,'external_actions'=>0,'schema_change'=>false,'real_parallel_workers'=>2],JSON_THROW_ON_ERROR)."\n";
