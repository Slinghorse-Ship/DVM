<?php declare(strict_types=1);
use Kulturhus\Native\Shared\Db;
use Kulturhus\Native\System\{Authority,Journal,Tasks,Runner};

/** Own synthetic history exercises the real bounded retention and rollback contracts. */
function ownSystemRetention(PDO $db,string $club,string $other,Authority $admin,Authority $second,Tasks $tasks,Runner $runner,int $now):void
{
    $old=$now-91*86400;$events=[];$eventArgs=[];$runs=[];$runArgs=[];$eventIds=[];$runIds=[];
    for($i=0;$i<1005;$i++){
        $eventIds[]=$event=bin2hex(random_bytes(16));$runIds[]=$run=bin2hex(random_bytes(16));$events[]='(?,?,?,?,?,?,?,?)';$eventArgs=[...$eventArgs,$event,$club,'task_completed','info',0,'{"task":"cache-cleanup","count":0}',hash('sha256','own-system-retention-'.$i),$old-$i];
        $runs[]='(?,?,?,?,?,?,?,?,?)';$runArgs=[...$runArgs,$run,$club,'cache-cleanup','completed',1,$old-$i,$old-$i,$old-$i,'{"count":0}'];
    }
    Db::run($db,'INSERT INTO native_system_events VALUES'.implode(',',$events),$eventArgs);Db::run($db,'INSERT INTO native_system_runs VALUES'.implode(',',$runs),$runArgs);
    $protected=[];foreach(['failed','abandoned','running']as$state){$id=bin2hex(random_bytes(16));$protected[]=$id;Db::run($db,'INSERT INTO native_system_runs VALUES(?,?,?,?,?,?,?,?,?)',[$id,$club,'cache-cleanup',$state,1,$old,$old,$state==='running'?null:$old,'{}']);}
    $foreignEvent=Journal::record($db,$other,'task_completed',['task'=>'cache-cleanup','count'=>0],false,'own-system-foreign-retention',$old);$foreignRun=bin2hex(random_bytes(16));Db::run($db,'INSERT INTO native_system_runs VALUES(?,?,?,?,?,?,?,?,?)',[$foreignRun,$other,'cache-cleanup','completed',1,$old,$old,$old,'{}']);
    $notice=Journal::record($db,$club,'worker_review',['count'=>1],true,'own-system-old-personal-notice',$old);(new Journal($db,$admin))->acknowledge($notice,$now);
    $original=systemRows($db);systemCheck($runner->tick()['runs']===0&&systemRows($db)===$original,'disabled private schedules neither prune eligible history nor change system originals');
    foreach([['native_system_events',$eventIds[1004],fn()=>Journal::prune($db,$club,$now)],['native_system_runs',$runIds[1004],fn()=>Tasks::prune($db,$club,$now)]]as[$table,$late,$operation]){
        $before=systemRows($db);$trigger='own_system_retention_failure';
        try{$db->exec("CREATE TRIGGER $trigger BEFORE DELETE ON $table FOR EACH ROW BEGIN IF OLD.id='$late' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Own synthetic late retention interruption'; END IF; END");systemReject($operation,PDOException::class,'actual late history-retention failure is reported instead of confirming a partial cleanup');}
        finally{$db->exec('DROP TRIGGER IF EXISTS '.$trigger);}
        systemCheck(systemRows($db)===$before,'late retention SQL failure restores every earlier original in its actual batch');
    }
    $plan=$tasks->listing()[0];$tasks->save('cache-cleanup',$plan['version'],true,5,$now);systemCheck($runner->tick()['completed']===1,'a freshly authorized actual local task performs its own bounded maintenance');$plan=$tasks->listing()[0];$tasks->save('cache-cleanup',$plan['version'],false,5,$now);
    systemCheck((int)Db::run($db,'SELECT COUNT(*) FROM native_system_events WHERE club_id=? AND notice=0 AND created_at<?',[$club,$now-90*86400])->fetchColumn()===1000&&(int)Db::run($db,"SELECT COUNT(*) FROM native_system_runs WHERE club_id=? AND status IN ('completed','cancelled') AND finished_at<?",[$club,$now-90*86400])->fetchColumn()===1000,'actual successful private maintenance retains the newest thousand old eligible events and runs');
    $unread=[];$journal=new Journal($db,$second);$pages=$journal->listing(['state'=>'unread'])['pages'];for($page=1;$page<=$pages;$page++)$unread=[...$unread,...array_column($journal->listing(['state'=>'unread','page'=>$page])['rows'],'id')];
    systemCheck(in_array($notice,$unread,true)&&(int)Db::run($db,'SELECT COUNT(*) FROM native_system_acknowledgements WHERE club_id=? AND event_id=?',[$club,$notice])->fetchColumn()===1,'another person keeps an old unread operational notice despite one original personal acknowledgement and automatic retention');
    systemCheck((int)Db::run($db,'SELECT COUNT(*) FROM native_system_runs WHERE id IN (?,?,?)',$protected)->fetchColumn()===3&&Db::run($db,'SELECT id FROM native_system_events WHERE id=?',[$foreignEvent])->fetchColumn()===$foreignEvent&&Db::run($db,'SELECT id FROM native_system_runs WHERE id=?',[$foreignRun])->fetchColumn()===$foreignRun,'failed abandoned active and foreign original records survive local maintenance');
    systemCheck(Journal::prune($db,$club,$now)===0&&Tasks::prune($db,$club,$now)===0,'repeated original retention reaches its fixed threshold without deleting further history');
}
