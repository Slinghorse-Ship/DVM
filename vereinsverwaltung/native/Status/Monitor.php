<?php declare(strict_types=1);
namespace Kulturhus\Native\Status;
use Kulturhus\Native\Shared\{Db,SecretBox};
use Kulturhus\Verein\{Permissions,Settings};
/** Current tenant/account-bound observations. HTTP reads never invoke a probe. */
final class Monitor
{
    public const FRESH_SECONDS=900;
    private \Closure $describe,$probe,$clock;private ?\Closure $guard;private ?\Closure $beforeCheck;
    public function __construct(private \PDO $db,private string $actor,private SecretBox $box,callable $describe,callable $probe,?callable $clock=null,?callable $guard=null,?callable $beforeCheck=null){$this->beforeCheck=$beforeCheck?\Closure::fromCallable($beforeCheck):null;$this->guard=$guard?\Closure::fromCallable($guard):null;$this->describe=\Closure::fromCallable($describe);$this->probe=\Closure::fromCallable($probe);$this->clock=$clock?\Closure::fromCallable($clock):time(...);}
    private function actor():array{if($this->guard)($this->guard)();$a=Permissions::actor($this->db,$this->actor);if(!(new Settings($this->db,$this->actor))->canManage())throw new \DomainException('Keine Berechtigung für den Systemstatus.');
        $identity=Db::run($this->db,'SELECT a.active,a.verified,a.suspended FROM native_manager_identities i LEFT JOIN native_auth_accounts a ON a.actor_id=i.native_id WHERE i.manager_club_id=? AND i.manager_actor_id=?',[$a['club_id'],$a['id']])->fetch();
        if($identity&&(!(int)$identity['active']||!(int)$identity['verified']||(int)$identity['suspended']))throw new \DomainException('Vereinszugang nicht aktiv.');return $a;}
    private function preferences(string $club):array {
        $r=Db::run($this->db,'SELECT * FROM native_status_settings WHERE club_id=?',[$club])->fetch();
        return $r?['version'=>(int)$r['version'],'public_enabled'=>(bool)$r['public_enabled'],'automatic_enabled'=>(bool)$r['automatic_enabled'],'public_services'=>json_decode($r['public_services'],true,32,JSON_THROW_ON_ERROR),'disabled_services'=>json_decode($r['disabled_services'],true,32,JSON_THROW_ON_ERROR)]:['version'=>0,'public_enabled'=>false,'automatic_enabled'=>false,'public_services'=>[],'disabled_services'=>[]];
    }
    public function settings():array{$a=$this->actor();return $this->preferences($a['club_id']);}
    public function save(int $version,bool $public,bool $automatic,array $services,array $disabled):void {
        $services=Catalog::ids($services);$disabled=Catalog::ids($disabled);if($public&&!$services)throw new \DomainException('Mindestens einen öffentlichen Dienst wählen.');$a=$this->actor();
        Db::atomic($this->db,function()use($a,$version,$public,$automatic,$services,$disabled):void{
            // Lock the existing club so the absent settings row has a stable version-zero lock.
            Db::run($this->db,'SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT',[$a['club_id']]);$again=$this->actor();if($again['club_id']!==$a['club_id'])throw new \DomainException('Vereinszuordnung geändert.');
            if($this->preferences($a['club_id'])['version']!==$version)throw new \DomainException('Status-Einstellungen geändert. Bitte neu laden.',409);
            Db::run($this->db,'INSERT INTO native_status_settings VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE version=VALUES(version),public_enabled=VALUES(public_enabled),automatic_enabled=VALUES(automatic_enabled),public_services=VALUES(public_services),disabled_services=VALUES(disabled_services),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)',[$a['club_id'],$version+1,(int)$public,(int)$automatic,json_encode($services,JSON_THROW_ON_ERROR),json_encode($disabled,JSON_THROW_ON_ERROR),$a['id']]);
        });
    }
    private function descriptor(string $id,array $actor):array {
        $service=Catalog::service($id);$settings=$this->preferences($actor['club_id']);
        if($service[2]&&!in_array($actor['finance_access'],['read','write'],true))return ['state'=>'restricted','context'=>'','configured'=>false,'kind'=>'local'];
        try{$d=($this->describe)($id,$actor);}catch(\Throwable){return ['state'=>'application_error','context'=>'','configured'=>true,'kind'=>'local','reason'=>'configuration_unreadable'];}if(!is_array($d)||!is_bool($d['configured']??null)||!is_string($d['binding']??null)||!in_array($d['kind']??null,['local','provider','passive'],true))throw new \RuntimeException('Dienstkonfiguration konnte nicht gelesen werden.');
        if(($d['allowed']??true)!==true)return ['state'=>'restricted','context'=>'','configured'=>false,'kind'=>$d['kind'],'reason'=>$d['reason']??''];
        $enabled=!in_array($id,$settings['disabled_services'],true)&&($d['enabled']??true);
        return ['state'=>!$enabled?'disabled':(!$d['configured']?'not_configured':'not_checked'),'context'=>$this->box->fingerprint(json_encode([Catalog::PROBE_VERSION,$actor['club_id'],$id,$enabled,$d['binding']],JSON_THROW_ON_ERROR)),'reason'=>!$d['configured']?($d['reason']??''):'','configured'=>$d['configured'],'kind'=>$d['kind'],'environment'=>in_array($d['environment']??null,['test','production'],true)?$d['environment']:null];
    }
    private function row(string $club,string $id,string $context):array|false{return Db::run($this->db,'SELECT * FROM native_status_checks WHERE club_id=? AND service=? AND context_sha256=? ORDER BY checked_at DESC,id DESC LIMIT 1',[$club,$id,$context])->fetch();}
    private function summarize(string $id,array $d,array|false $row,int $now):array {
        $state=$d['state'];$checked=null;$ms=null;$reason=$d['reason']??'';
        if($state==='not_checked'&&$row){$checked=$row['observed_at']??$row['checked_at'];$ms=(int)$row['duration_ms'];$reason=$row['reason'];$age=$now-strtotime($checked.' UTC');$state=$age<0||$age>self::FRESH_SECONDS?'stale':$row['state'];}
        return ['id'=>$id,'label'=>Catalog::service($id)[0],'group'=>Catalog::service($id)[1],'state'=>$state,'status'=>Catalog::STATES[$state],'configured'=>$d['configured'],'kind'=>$d['kind'],'environment'=>$d['environment']??null,'checked_at'=>$checked,'duration_ms'=>$ms,'reason'=>$reason,'can_check'=>$d['state']==='not_checked'];
    }
    public function snapshot(?string $group=null):array {
        $a=$this->actor();if($group!==null)Catalog::group($group);$rows=[];$now=($this->clock)();
        foreach(Catalog::SERVICES as$id=>$service){if($group!==null&&$service[1]!==$group)continue;$d=$this->descriptor($id,$a);$rows[]=$this->summarize($id,$d,$d['state']==='not_checked'?$this->row($a['club_id'],$id,$d['context']):false,$now);}
        $current=$this->actor();if($current['club_id']!==$a['club_id']||($current['finance_access']??'none')!==($a['finance_access']??'none')||($current['is_superuser']??0)!==($a['is_superuser']??0))throw new \DomainException('Berechtigung geändert. Bitte neu laden.');return $rows;
    }
    public function check(string $id):array {
        if($this->db->inTransaction())throw new \DomainException('Dienstprüfung außerhalb einer Geschäftsbuchung starten.');$a=$this->actor();$d=$this->descriptor($id,$a);if($d['state']!=='not_checked')throw new \DomainException('Dieser Dienst kann derzeit nicht geprüft werden.');
        $lock='dvm-status-'.substr(hash('sha256',$a['club_id'].':'.$id),0,45);if((int)Db::run($this->db,'SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1)throw new \DomainException('Diese Prüfung läuft bereits.');
        try{
            if($this->beforeCheck){($this->beforeCheck)($id,$a);$again=$this->actor();if($again['club_id']!==$a['club_id'])throw new \DomainException('Vereinszuordnung geändert.');$d=$this->descriptor($id,$again);if($d['state']!=='not_checked')throw new \DomainException('Dieser Dienst kann derzeit nicht geprüft werden.');}
            $last=$this->row($a['club_id'],$id,$d['context']);$age=$last?($this->clock)()-strtotime($last['checked_at'].' UTC'):PHP_INT_MAX;if($last&&$age>=0&&$age<30)return $this->summarize($id,$d,$last,($this->clock)())+['cached'=>true];
            $start=hrtime(true);
            try{$result=($this->probe)($id,$a);}catch(\Throwable){$result=['state'=>'application_error','reason'=>'probe_failed'];}
            $ms=min(4294967295,max(0,(int)round((hrtime(true)-$start)/1000000)));$state=$result['state']??'';$reason=$result['reason']??'';
            if(!in_array($state,['ok','restricted','down','provider_error','application_error','not_checked'],true)||!is_string($reason)||!preg_match('/^[a-z0-9_]{0,48}$/D',$reason)){$state='application_error';$reason='invalid_probe_result';}
            $again=$this->actor();$fresh=$this->descriptor($id,$again);if($again['club_id']!==$a['club_id']||!hash_equals($d['context'],$fresh['context']))throw new \DomainException('Verbindung geändert. Prüfung verworfen; bitte neu prüfen.');
            $observed=$result['observed_at']??null;if($observed!==null&&(!is_int($observed)||$observed<0||$observed>($this->clock)())){$state='application_error';$reason='invalid_observation_time';$observed=null;}
            $time=gmdate('Y-m-d H:i:s',($this->clock)());$row=['id'=>bin2hex(random_bytes(16)),'club_id'=>$a['club_id'],'service'=>$id,'context_sha256'=>$d['context'],'state'=>$state,'reason'=>$reason,'kind'=>$d['kind'],'checked_at'=>$time,'observed_at'=>$observed!==null?gmdate('Y-m-d H:i:s',$observed):null,'duration_ms'=>$ms];
            Db::run($this->db,'INSERT INTO native_status_checks VALUES(?,?,?,?,?,?,?,?,?,?)',array_values($row));return $this->summarize($id,$fresh,$row,($this->clock)());
        }finally{Db::run($this->db,'SELECT RELEASE_LOCK(?)',[$lock]);}
    }
    public function history(string $id,mixed $page=1):array {
        $a=$this->actor();$d=$this->descriptor($id,$a);if($d['state']==='restricted')throw new \DomainException('Keine Berechtigung für diesen Verlauf.');
        if(!is_scalar($page)||!preg_match('/^[1-9][0-9]{0,5}$/D',(string)$page))throw new \DomainException('Ungültige Verlaufsseite.');
        $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_status_checks WHERE club_id=? AND service=? AND context_sha256=?',[$a['club_id'],$id,$d['context']])->fetchColumn();$pages=max(1,(int)ceil($total/20));$page=min((int)$page,$pages);
        $rows=Db::run($this->db,'SELECT state,reason,kind,checked_at,observed_at,duration_ms FROM native_status_checks WHERE club_id=? AND service=? AND context_sha256=? ORDER BY checked_at DESC,id DESC LIMIT 20 OFFSET '.(($page-1)*20),[$a['club_id'],$id,$d['context']])->fetchAll();
        $counts=Db::run($this->db,"SELECT SUM(state='ok') passed,SUM(state IN ('ok','down','provider_error','application_error','restricted')) measured FROM native_status_checks WHERE club_id=? AND service=? AND context_sha256=? AND checked_at>=? AND checked_at<=?",[$a['club_id'],$id,$d['context'],gmdate('Y-m-d H:i:s',($this->clock)()-86400),gmdate('Y-m-d H:i:s',($this->clock)())])->fetch();
        return ['rows'=>$rows,'page'=>$page,'pages'=>$pages,'total'=>$total,'last_day'=>['passed'=>(int)$counts['passed'],'measured'=>(int)$counts['measured']]];
    }
    /** Public output is opt-in, selected, current-owner-authorized and contains no account/configuration fields. */
    public function publicSnapshot(string $club):array {
        $actor=$this->actor();if($actor['club_id']!==$club)throw new \DomainException('Öffentlicher Status nicht verfügbar.');$settings=$this->preferences($club);if(!$settings['public_enabled']||!$settings['public_services'])throw new \DomainException('Öffentlicher Status nicht freigegeben.');$rows=[];
        foreach($settings['public_services']as$id){Catalog::service($id);$descriptor=$this->descriptor($id,$actor);$row=$this->summarize($id,$descriptor,$descriptor['state']==='not_checked'?$this->row($club,$id,$descriptor['context']):false,($this->clock)());$state=match($row['state']){'ok'=>'available','down','provider_error','application_error'=>'disrupted','restricted'=>'restricted',default=>'unknown'};$rows[]=['id'=>$row['id'],'label'=>$row['label'],'state'=>$state,'checked_at'=>$row['checked_at']];}
        return $rows;
    }
}
