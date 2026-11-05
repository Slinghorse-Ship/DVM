<?php declare(strict_types=1);
namespace Kulturhus\Native\System;
use Kulturhus\Native\Shared\Db;

/** Pure local maintenance. Existing communications, payments and publication jobs are only observed. */
final class Runner
{
    private \Closure $clock;
    public function __construct(private readonly \PDO $db,private readonly string $club,private readonly PrivateFiles $private,?callable $clock=null){$this->clock=$clock?\Closure::fromCallable($clock):time(...);}
    public function tick(int $limit=2):array
    {
        if(PHP_SAPI!=='cli'||$limit<1||$limit>2)throw new \DomainException('Begrenzten privaten Systemdienst verwenden.');if($this->private->maintenance())return ['status'=>'maintenance','runs'=>0,'completed'=>0,'failed'=>0];$scheduler=new Scheduler($this->db,$this->club,$this->clock);$out=['status'=>'completed','runs'=>0,'completed'=>0,'failed'=>0];
        for($i=0;$i<$limit;$i++){
            if($this->private->maintenance())break;$lease=$scheduler->claim();if($lease===null)break;$out['runs']++;
            try{$authority=$scheduler->heartbeat($lease);$count=match($lease['task']){'cache-cleanup'=>(new \Kulturhus\Native\Cache\Administration($authority->access,$this->private->root.'/public-cache'))->action('cache-cleanup',[]),'operations-observe'=>$this->observe($authority,$lease),'auth-cleanup'=>$this->authCleanup($authority,$lease,$scheduler)};$scheduler->heartbeat($lease);$scheduler->finish($lease,true,['count'=>$count]);$out['completed']++;}
            catch(\Throwable){try{$scheduler->finish($lease,false);}catch(\Throwable){/* An expired lease is observed and bounded on the next private tick. */}$out['failed']++;}
        }
        if($out['completed']>0){Journal::prune($this->db,$this->club,($this->clock)());Tasks::prune($this->db,$this->club,($this->clock)());}return $out;
    }
    private function authCleanup(Authority $authority,array $lease,Scheduler $scheduler):int
    {
        $result=(new \Kulturhus\Native\Auth\Retention($this->db,$authority->access))->prune(($this->clock)(),100,static fn()=>$scheduler->heartbeat($lease));
        if(!is_int($result['total']??null)||$result['total']<0||$result['total']>100)throw new \LogicException('Begrenzten eigenen Authentifizierungsnachweis prüfen.');return $result['total'];
    }
    private function observe(Authority $authority,array $lease):int
    {
        $now=($this->clock)();$count=0;$snapshot=(new Installation($this->db,$authority,$this->private))->snapshot();
        if(!$snapshot['schema_current']){Journal::record($this->db,$this->club,'schema_review',['count'=>count(array_filter($snapshot['schemas'],static fn(array $row):bool=>!$row['current']))],true,hash('sha256',json_encode($snapshot['schemas'],JSON_THROW_ON_ERROR)),$now);$count++;}
        if($snapshot['maintenance']||isset($snapshot['upgrade'])&&!in_array($snapshot['upgrade']['phase'],['done','rolled-back'],true)){Journal::record($this->db,$this->club,'maintenance_review',['phase'=>'incomplete'],true,$snapshot['upgrade']['intent']??'maintenance',$now);$count++;}
        if($snapshot['installation'])Journal::record($this->db,$this->club,'installation_observed',['phase'=>'active'],false,$snapshot['installation']['receipt_sha256'],$now);
        $manager=$authority->managerClub();if($manager!==null){$rows=Db::run($this->db,"SELECT id FROM native_worker_runs WHERE club_id=? AND (status IN ('failed','needs_review') OR (status='running' AND started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE))) ORDER BY started_at DESC,id DESC LIMIT 10",[$this->club])->fetchAll(\PDO::FETCH_COLUMN);foreach($rows as$id){Journal::record($this->db,$this->club,'worker_review',['count'=>1],true,$id,$now);$count++;}}
        return $count;
    }
}
