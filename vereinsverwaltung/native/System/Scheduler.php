<?php declare(strict_types=1);
namespace Kulturhus\Native\System;
use Kulturhus\Native\Shared\Db;

/** Only this private worker claims leases. Public requests can edit an explicit disabled-by-default plan. */
final class Scheduler
{
    public const LEASE_SECONDS=120;
    public const MAX_FAILURES=3;
    public function __construct(private readonly \PDO $db,private readonly string $club,private readonly \Closure $clock){}
    private function now():int{$value=($this->clock)();if(!is_int($value)||$value<1)throw new \LogicException('Gültige Systemzeit erforderlich.');return $value;}
    public function claim():?array
    {
        if(PHP_SAPI!=='cli')throw new \DomainException('Lokale Aufgaben nur über den privaten CLI-Dienst starten.');$now=$this->now();return Db::atomic($this->db,function()use($now):?array{
            $rows=Db::run($this->db,'SELECT * FROM native_system_tasks WHERE club_id=? ORDER BY task FOR UPDATE',[$this->club])->fetchAll();
            foreach($rows as$row){Catalog::task($row['task']);
                if($row['run_id']!==null){if((int)$row['lease_until']>$now)continue;$this->abandon($row,$now);$row['failures']++;$row['run_id']=null;$row['lease_hash']=null;$row['lease_until']=null;$row['next_due']=$row['failures']>=self::MAX_FAILURES?null:$now+min(3600,60*(2**($row['failures']-1)));}
                if(!$row['enabled']||$row['failures']>=self::MAX_FAILURES||$row['next_due']===null||(int)$row['next_due']>$now)continue;
                $run=bin2hex(random_bytes(16));$token=bin2hex(random_bytes(32));Db::run($this->db,'INSERT INTO native_system_runs VALUES(?,?,?,\'running\',?,?,?,NULL,NULL)',[$run,$this->club,$row['task'],$row['failures']+1,$now,$now]);Db::run($this->db,'UPDATE native_system_tasks SET run_id=?,lease_hash=?,lease_until=? WHERE club_id=? AND task=?',[$run,hash('sha256',$token),$now+self::LEASE_SECONDS,$this->club,$row['task']]);return ['run'=>$run,'lease'=>$token,'task'=>$row['task'],'owner'=>$row];
            }return null;
        });
    }
    private function abandon(array $row,int $now):void
    {
        Db::run($this->db,"UPDATE native_system_runs SET status='abandoned',finished_at=? WHERE club_id=? AND id=? AND status='running'",[$now,$this->club,$row['run_id']]);$failed=(int)$row['failures']+1;Db::run($this->db,'UPDATE native_system_tasks SET run_id=NULL,lease_hash=NULL,lease_until=NULL,failures=?,next_due=? WHERE club_id=? AND task=?',[$failed,$failed>=self::MAX_FAILURES?null:$now+min(3600,60*(2**($failed-1))),$this->club,$row['task']]);Journal::record($this->db,$this->club,'task_abandoned',['task'=>$row['task'],'attempt'=>$failed],true,$row['run_id'],$now);if($failed>=self::MAX_FAILURES)Journal::record($this->db,$this->club,'task_paused',['task'=>$row['task'],'attempt'=>$failed],true,$row['run_id'],$now);
    }
    private function locked(array $lease,int $now):array
    {
        if(!is_string($lease['task']??null)||!is_string($lease['run']??null)||!is_string($lease['lease']??null))throw new \DomainException('Eigene aktive Systemlease erforderlich.');$row=Db::run($this->db,'SELECT * FROM native_system_tasks WHERE club_id=? AND task=? FOR UPDATE',[$this->club,$lease['task']])->fetch();if(!$row||$row['run_id']!==$lease['run']||!is_string($row['lease_hash'])||!hash_equals($row['lease_hash'],hash('sha256',$lease['lease']))||(int)$row['lease_until']<=$now)throw new \DomainException('Lokaler Lauf hat seine aktive Lease verloren.');return $row;
    }
    public function heartbeat(array $lease):Authority
    {
        $now=$this->now();return Db::atomic($this->db,function()use($lease,$now):Authority{$row=$this->locked($lease,$now);if(!$row['enabled'])throw new \DomainException('Lokaler Zeitplan ist pausiert.');$authority=Authority::scheduled($this->db,$row);Db::run($this->db,'UPDATE native_system_tasks SET lease_until=? WHERE club_id=? AND task=?',[$now+self::LEASE_SECONDS,$this->club,$row['task']]);Db::run($this->db,'UPDATE native_system_runs SET heartbeat_at=? WHERE club_id=? AND id=? AND status=\'running\'',[$now,$this->club,$lease['run']]);return $authority;});
    }
    public function finish(array $lease,bool $success,array $summary=[]):void
    {
        if(array_diff(array_keys($summary),['count'])||isset($summary['count'])&&(!is_int($summary['count'])||$summary['count']<0||$summary['count']>2147483646))throw new \InvalidArgumentException('Begrenzte Systemzählung erforderlich.');$now=$this->now();Db::atomic($this->db,function()use($lease,$success,$summary,$now):void{
            $row=$this->locked($lease,$now);$cancelled=!$row['enabled'];if($success&&!$cancelled)Authority::scheduled($this->db,$row)->require();$failures=$success||$cancelled?0:(int)$row['failures']+1;$status=$cancelled?'cancelled':($success?'completed':'failed');$due=$cancelled||$failures>=self::MAX_FAILURES?null:$now+($success?$row['interval_minutes']*60:min(3600,60*(2**($failures-1))));
            Db::run($this->db,'UPDATE native_system_runs SET status=?,finished_at=?,summary_json=? WHERE club_id=? AND id=? AND status=\'running\'',[$status,$now,json_encode($summary,JSON_THROW_ON_ERROR),$this->club,$lease['run']]);Db::run($this->db,'UPDATE native_system_tasks SET run_id=NULL,lease_hash=NULL,lease_until=NULL,next_due=?,failures=? WHERE club_id=? AND task=?',[$due,$failures,$this->club,$row['task']]);
            if(!$cancelled)Journal::record($this->db,$this->club,$success?'task_completed':'task_failed',['task'=>$row['task'],'attempt'=>(int)$row['failures']+1]+$summary,!$success,$lease['run'],$now);if($failures>=self::MAX_FAILURES)Journal::record($this->db,$this->club,'task_paused',['task'=>$row['task'],'attempt'=>$failures],true,$lease['run'],$now);
        });
    }
}
