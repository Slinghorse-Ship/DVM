<?php declare(strict_types=1);
use Kulturhus\Native\Shared\Db;
$awConfig=\Kulturhus\Native\TestSupport::config();
if(getenv('KH_NATIVE_CI')!=='1'||($awConfig['owner']??'')!=='isolated-native-ci'||($awConfig['label']??'')!=='native-assignment-interface')throw new RuntimeException('Owned assignment process fixture required.');
$awCode=<<<'WORKER'
ini_set('zend.exception_ignore_args','1');$f=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);require $f['root'].'/native/bootstrap.php';require $f['root'].'/src/bootstrap.php';$db=\Kulturhus\Native\TestSupport::db();while(microtime(true)<$f['start'])usleep(1000);
try{$read=(new \Kulturhus\Verein\Settings\AssignmentRequests($db,'own-list-admin'))->accept($f['input'],$f['key'],$f['hash'],true);echo json_encode(['accepted'=>true,'key'=>$read['key'],'id'=>$read['result']['id'],'sha256'=>$read['sha256'],'created_at'=>$read['created_at']],JSON_THROW_ON_ERROR);}
catch(DomainException $failure){if(!in_array($failure->getCode(),[409,503],true))throw$failure;echo json_encode(['accepted'=>false,'key'=>$f['key'],'status'=>$failure->getCode()],JSON_THROW_ON_ERROR);}
WORKER;
foreach([true,false]as$awSame){
 $awInput=$input;$awInput['data']['start']=$awSame?'2027-04-01':'2027-04-02';$awInput['data']['end']=$awInput['data']['start'];$awReview=$aq->prepare($awInput);$awKeys=[bin2hex(random_bytes(16)),bin2hex(random_bytes(16))];if($awSame)$awKeys[1]=$awKeys[0];
 $awStart=microtime(true)+.3;$awChildren=[];$awAudit=(int)$db->query('SELECT COALESCE(MAX(sequence),0) FROM audit_events')->fetchColumn();$awCount=(int)$db->query('SELECT COUNT(*) FROM native_settings_requests')->fetchColumn();$awBefore=ownOriginalDatabaseHash($db,$tables);
 foreach($awKeys as$awKey){
  $process=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),'-r',$awCode],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
  if(!is_resource($process))throw new RuntimeException('Independent assignment writer unavailable.');
  fwrite($pipes[0],json_encode(['root'=>dirname(__DIR__,2),'input'=>$awInput,'key'=>$awKey,'hash'=>$awReview['hash'],'start'=>$awStart],JSON_THROW_ON_ERROR));fclose($pipes[0]);$awChildren[]=[$process,$pipes];
 }
 $awResults=[];
 foreach($awChildren as[$process,$pipes]){
  $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException('Independent assignment writer failed: '.substr($error,0,700));$awResults[]=json_decode($out,true,64,JSON_THROW_ON_ERROR);
 }
 $awAccepted=array_values(array_filter($awResults,static fn(array $row):bool=>$row['accepted']));
 $check(count($awAccepted)>=1&&($awSame||count($awAccepted)===1),'two actual independent assignment intention writers accept one same-key original or one explicitly reviewed rival intention only');
 $awOriginal=$aq->receipt($awAccepted[0]['key']);$awExact=true;
 foreach($awAccepted as$row)$awExact=$awExact&&$row['id']===$awOriginal['result']['id']&&$row['sha256']===$awOriginal['sha256']&&$row['created_at']===$awOriginal['created_at'];
 $check($awExact,'independent accepted assignment answers retain the same actual canonical identity first original bytes and timestamp');
 $awId=$awOriginal['result']['id'];$awFilter=static function(string $table)use($awId,$awOriginal,$awAudit):array{return match($table){'catalog_assignments'=>[' WHERE NOT(club_id=? AND id=?)',['own-list-club',$awId]],'settings_revisions'=>[' WHERE NOT(club_id=? AND section=?)',['own-list-club','assignment:'.$awId]],'native_settings_requests'=>[' WHERE NOT(club_id=? AND request_key=?)',['own-list-club',$awOriginal['key']]],'audit_events'=>[' WHERE sequence<=?',[$awAudit]],default=>['',[]]};};
 $awAudits=Db::run($db,'SELECT * FROM audit_events WHERE sequence>?',[$awAudit])->fetchAll();
 $awRows=Db::run($db,'SELECT * FROM catalog_assignments WHERE club_id=? AND catalog_id=? AND contact_id=? AND starts_on=? AND ends_on=?',['own-list-club',$awInput['data']['catalog_id'],$awInput['data']['contact_id'],$awInput['data']['start'],$awInput['data']['end']])->fetchAll();
 $check(count($awRows)===1&&$awRows[0]['id']===$awId&&ownOriginalDatabaseHash($db,$tables,$awFilter)===$awBefore&&(int)$db->query('SELECT COUNT(*) FROM native_settings_requests')->fetchColumn()===$awCount+1&&count($awAudits)===1&&$awAudits[0]['object_id']==='assignment:'.$awId,'actual parallel assignment intentions create one whole assignment revision audit and personal original while retaining every complete earlier row and original');
 if(!$awSame)foreach($awResults as$row)if(!$row['accepted'])$reject(fn()=>$aq->receipt($row['key']),404,'an unaccepted rival assignment key cannot substitute the accepted first original');
}
