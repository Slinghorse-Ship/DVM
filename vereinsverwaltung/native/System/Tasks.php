<?php declare(strict_types=1);
namespace Kulturhus\Native\System;
use Kulturhus\Native\Shared\Db;

final class Tasks
{
    public function __construct(private readonly \PDO $db,private readonly Authority $authority){}
    public function listing():array
    {
        $club=$this->authority->club();$actual=Db::run($this->db,'SELECT task,version,enabled,interval_minutes,next_due,failures,lease_until,run_id FROM native_system_tasks WHERE club_id=?',[$club])->fetchAll(\PDO::FETCH_UNIQUE|\PDO::FETCH_ASSOC);$out=[];
        foreach(Catalog::TASKS as$id=>$label){$row=$actual[$id]??['version'=>0,'enabled'=>0,'interval_minutes'=>60,'next_due'=>null,'failures'=>0,'lease_until'=>null,'run_id'=>null];$out[]=['id'=>$id,'label'=>$label,'version'=>(int)$row['version'],'enabled'=>(bool)$row['enabled'],'interval_minutes'=>(int)$row['interval_minutes'],'next_due'=>$row['next_due']===null?null:(int)$row['next_due'],'failures'=>(int)$row['failures'],'lease_until'=>$row['lease_until']===null?null:(int)$row['lease_until'],'running'=>$row['run_id']!==null];}
        $this->authority->require();return $out;
    }
    public function save(string $task,int $version,bool $enabled,int $interval,int $now):void
    {
        Catalog::task($task);if($version<0||$version>2147483646||!isset(Catalog::INTERVALS[$interval])||$now<1)throw new \DomainException('Zeitplan und ursprüngliche Version prüfen.');
        Db::atomic($this->db,function()use($task,$version,$enabled,$interval,$now):void{
            $owner=$this->authority->owner();Db::run($this->db,'INSERT INTO native_system_scopes VALUES(?) ON DUPLICATE KEY UPDATE club_id=VALUES(club_id)',[$owner['club']]);$current=Db::run($this->db,'SELECT version,run_id FROM native_system_tasks WHERE club_id=? AND task=? FOR UPDATE',[$owner['club'],$task])->fetch();if((int)($current['version']??0)!==$version)throw new Conflict();
            $again=$this->authority->owner();if($again!==$owner)throw new Conflict();
            // Pausing can cancel a lease immediately; reconfiguration requires its current run to finish.
            if($enabled&&($current['run_id']??null)!==null)throw new \DomainException('Lokalen Lauf zuerst abschließen oder den Zeitplan pausieren.');
            Db::run($this->db,'INSERT INTO native_system_tasks(club_id,task,version,enabled,interval_minutes,owner_id,manager_actor,account_version,next_due,failures,updated_at) VALUES(?,?,?,?,?,?,?,?,?,0,?) ON DUPLICATE KEY UPDATE version=VALUES(version),enabled=VALUES(enabled),interval_minutes=VALUES(interval_minutes),owner_id=VALUES(owner_id),manager_actor=VALUES(manager_actor),account_version=VALUES(account_version),next_due=VALUES(next_due),failures=0,updated_at=VALUES(updated_at)',[$owner['club'],$task,$version+1,(int)$enabled,$interval,$owner['id'],$owner['manager_actor'],$owner['account_version'],$enabled?$now:null,$now]);
            Journal::record($this->db,$owner['club'],'task_saved',['task'=>$task,'version'=>$version+1],false,$task.':'.($version+1),$now);
        });
    }
    public function request(string $task,int $version,int $now):void
    {
        Catalog::task($task);Db::atomic($this->db,function()use($task,$version,$now):void{$owner=$this->authority->owner();$row=Db::run($this->db,'SELECT * FROM native_system_tasks WHERE club_id=? AND task=? FOR UPDATE',[$owner['club'],$task])->fetch();if(!$row||(int)$row['version']!==$version)throw new Conflict();if(!$row['enabled']||$row['run_id']!==null||$row['failures']>=Scheduler::MAX_FAILURES)throw new \DomainException('Aktuellen Zeitplan freigeben, bevor eine Aufgabe angefordert wird.');
            Db::run($this->db,'UPDATE native_system_tasks SET version=version+1,next_due=?,owner_id=?,manager_actor=?,account_version=?,updated_at=? WHERE club_id=? AND task=?',[$now,$owner['id'],$owner['manager_actor'],$owner['account_version'],$now,$owner['club'],$task]);Journal::record($this->db,$owner['club'],'task_requested',['task'=>$task,'version'=>$version+1],false,$task.':'.($version+1),$now);
        });
    }
    public function history(array $query=[]):array
    {
        $club=$this->authority->club();$task=$query['task']??'';$state=$query['state']??'all';if(!is_string($task)||($task!==''&&!isset(Catalog::TASKS[$task]))||!is_string($state)||!in_array($state,['all','running','completed','failed','abandoned','cancelled'],true))throw new \DomainException('Lokalen Laufverlauf filtern.');$page=Journal::pageNumber($query['page']??1);$where='club_id=?';$args=[$club];if($task!==''){$where.=' AND task=?';$args[]=$task;}if($state!=='all'){$where.=' AND status=?';$args[]=$state;}$total=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_system_runs WHERE '.$where,$args)->fetchColumn();$pages=max(1,(int)ceil($total/10));$page=min($page,$pages);$rows=Db::run($this->db,'SELECT id,task,status,attempt,started_at,heartbeat_at,finished_at,summary_json FROM native_system_runs WHERE '.$where.' ORDER BY started_at DESC,id DESC LIMIT 10 OFFSET '.(($page-1)*10),$args)->fetchAll();
        foreach($rows as&$row){foreach(['attempt','started_at','heartbeat_at']as$key)$row[$key]=(int)$row[$key];$row['finished_at']=$row['finished_at']===null?null:(int)$row['finished_at'];$row['summary']=$row['summary_json']===null?[]:json_decode($row['summary_json'],true,16,JSON_THROW_ON_ERROR);unset($row['summary_json']);}$this->authority->require();return compact('rows','total','page','pages','task','state');
    }
    public static function prune(\PDO $db,string $club,int $now):int
    {
        return Db::atomic($db,static function()use($db,$club,$now):int{
            $ids=Db::run($db,"SELECT id FROM native_system_runs WHERE club_id=? AND finished_at<? AND status IN ('completed','cancelled') ORDER BY finished_at DESC,id DESC LIMIT 1000,100 FOR UPDATE",[$club,$now-90*86400])->fetchAll(\PDO::FETCH_COLUMN);foreach($ids as$id)Db::run($db,'DELETE FROM native_system_runs WHERE club_id=? AND id=? AND status IN (\'completed\',\'cancelled\')',[$club,$id]);return count($ids);
        });
    }
}
