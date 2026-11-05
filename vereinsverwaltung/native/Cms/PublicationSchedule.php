<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Integration\{ManagerAccess,ManagerBridge};

/** Durable local publication jobs. A job approves exactly one unchanged draft version. */
final class PublicationSchedule
{
    public function __construct(private PDO $db,private Access $access,private ?Closure $clock=null){}
    private function now():int{return $this->clock?($this->clock)():time();}

    public static function install(PDO $db):void
    {
        if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')throw new DomainException('Veröffentlichungsplanung benötigt MySQL.');
        $db->exec("CREATE TABLE IF NOT EXISTS khcms_publication_schedule (
            id CHAR(32) NOT NULL PRIMARY KEY, sequence_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
            club_id VARCHAR(64) NOT NULL,page_id CHAR(32) NOT NULL,page_version INT UNSIGNED NOT NULL,
            snapshot_json MEDIUMTEXT NOT NULL,snapshot_hash CHAR(64) NOT NULL,due_at BIGINT NOT NULL,
            status VARCHAR(16) NOT NULL,version INT UNSIGNED NOT NULL DEFAULT 1,
            created_by VARCHAR(64) NOT NULL,authority VARCHAR(16) NOT NULL,created_at BIGINT NOT NULL,
            started_at BIGINT NULL,completed_at BIGINT NULL,reason VARCHAR(40) NULL,published_revision INT UNSIGNED NULL,
            INDEX cms_schedule_due(status,due_at),INDEX cms_schedule_page(club_id,page_id,sequence_id),
            FOREIGN KEY(page_id) REFERENCES khcms_pages(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /** Strict wall-clock parsing: reject invalid dates and both DST gaps and ambiguous hours. */
    public static function parseBerlin(string $local):int
    {
        if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D',$local))throw new DomainException('Datum und Uhrzeit vollständig angeben.');
        $utc=new DateTimeZone('UTC');$berlin=new DateTimeZone('Europe/Berlin');
        $wall=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$local,$utc);
        if(!$wall||$wall->format('Y-m-d\TH:i')!==$local)throw new DomainException('Ungültiges Datum oder Uhrzeit.');
        $nominal=$wall->getTimestamp();$candidates=[];
        foreach($berlin->getTransitions($nominal-172800,$nominal+172800) as $transition){
            $candidate=$nominal-$transition['offset'];
            if((new DateTimeImmutable('@'.$candidate))->setTimezone($berlin)->format('Y-m-d\TH:i')===$local)$candidates[$candidate]=true;
        }
        if(count($candidates)!==1)throw new DomainException('Diese Berliner Uhrzeit ist wegen der Zeitumstellung nicht eindeutig. Eine andere Uhrzeit wählen.');
        return (int)array_key_first($candidates);
    }

    public function forPage(string $pageId):?array
    {
        $actor=$this->access->require('website','read');$this->page($pageId,$actor['club_id']);TemplatePolicy::assertCapability($this->db,$this->access,$pageId,'read');
        $row=$this->latest($pageId,$actor['club_id']);return $row?self::present($row):null;
    }

    /** expectedScheduleVersion=0 creates a first plan; a pending replacement requires its version. */
    public function plan(string $pageId,int $expectedPageVersion,int $dueAt,int $expectedScheduleVersion=0):array
    {
        $actor=$this->access->require('website','publish');
        if($dueAt<=$this->now()||$dueAt>$this->now()+5*366*86400)throw new DomainException('Veröffentlichung in der Zukunft und höchstens fünf Jahre im Voraus planen.');
        return $this->locked($pageId,function()use($pageId,$expectedPageVersion,$dueAt,$expectedScheduleVersion):array{
            return Db::atomic($this->db,function()use($pageId,$expectedPageVersion,$dueAt,$expectedScheduleVersion):array{
                $actor=$this->access->require('website','publish');self::lockClub($this->db,$actor['club_id']);
                $page=$this->page($pageId,$actor['club_id'],true);
                TemplatePolicy::assertCapability($this->db,$this->access,$pageId,'publish');
                if((int)$page['version']!==$expectedPageVersion||$expectedPageVersion<1)throw new Conflict('Der Entwurf wurde inzwischen geändert.');
                if (Db::run($this->db,'SELECT page_id FROM khcms_trash WHERE page_id=? AND club_id=?',[$pageId,$actor['club_id']])->fetchColumn()) throw new Conflict('Seite zuerst aus dem Papierkorb zurückholen.');
                if($page['archived_at']!==null)throw new Conflict('Archivierten Inhalt zuerst zurück in die Bearbeitung holen.');
                if($dueAt<=$this->now())throw new DomainException('Veröffentlichungszeit liegt nicht mehr in der Zukunft.');
                // Validate the saved schema snapshot now; Pages repeats this when the job becomes due.
                $payload=(new DataModel($this->db,$this->access))->publication(json_decode($page['draft_json'],true,512,JSON_THROW_ON_ERROR));
                (new NewsPolicy($this->db,$this->access))->assertPublishable($pageId,$payload);
                $previous=$this->latest($pageId,$actor['club_id'],true);
                if($previous&&$previous['status']==='processing')$previous=self::reconcile($this->db,$previous,$this->now());
                $pending=$previous&&in_array($previous['status'],['pending','processing'],true);
                if(($pending?(int)$previous['version']:0)!==$expectedScheduleVersion)throw new Conflict('Die Veröffentlichungsplanung wurde inzwischen geändert.');
                if($pending)self::finish($this->db,$previous,'cancelled','replaced',$this->now());
                $id=bin2hex(random_bytes(16));$authority=$this->access instanceof ManagerAccess?'manager':'native';
                Db::run($this->db,'INSERT INTO khcms_publication_schedule(id,club_id,page_id,page_version,snapshot_json,snapshot_hash,due_at,status,created_by,authority,created_at) VALUES(?,?,?,?,?,?,?,\'pending\',?,?,?)',[$id,$actor['club_id'],$pageId,$expectedPageVersion,$page['draft_json'],self::digest($page['draft_json']),$dueAt,$actor['id'],$authority,$this->now()]);
                return self::present($this->job($id));
            });
        });
    }

    public function cancel(string $id,int $expectedVersion):array
    {
        $actor=$this->access->require('website','publish');$job=$this->job($id,$actor['club_id']);
        return $this->locked($job['page_id'],function()use($id,$expectedVersion):array{
            return Db::atomic($this->db,function()use($id,$expectedVersion):array{
                $actor=$this->access->require('website','publish');self::lockClub($this->db,$actor['club_id']);$job=$this->job($id,$actor['club_id'],true);
                TemplatePolicy::assertCapability($this->db,$this->access,$job['page_id'],'publish');
                if((int)$job['version']!==$expectedVersion)throw new Conflict('Die Veröffentlichungsplanung wurde inzwischen geändert.');
                if($job['status']==='processing')$job=self::reconcile($this->db,$job,$this->now());
                if(!in_array($job['status'],['pending','processing'],true))throw new Conflict('Diese Planung ist bereits abgeschlossen.');
                self::finish($this->db,$job,'cancelled','cancelled_by_user',$this->now());return self::present($this->job($id));
            });
        });
    }

    /** Trusted worker entry point; resolver must return current authority, never a cached grant snapshot. */
    public static function runDue(PDO $db,?callable $accessResolver=null,int $limit=50,?int $now=null,?string $club=null):array
    {
        if($db->inTransaction())throw new \LogicException('Publikationslauf benötigt eine eigene Verbindung ohne offene Transaktion.');
        if($limit<1||$limit>500)throw new DomainException('Lauflimit muss zwischen 1 und 500 liegen.');
        if($club!==null&&($club===''||strlen($club)>64||preg_match('/[\x00-\x1f\x7f]/',$club)))throw new DomainException('Vereinszuordnung für den Publikationslauf prüfen.');
        $now??=time();$results=[];
        $rows=Db::run($db,"SELECT * FROM khcms_publication_schedule WHERE ((status='pending' AND due_at<=?) OR status='processing')".($club!==null?' AND club_id=?':'')." ORDER BY due_at,sequence_id LIMIT ".$limit,$club!==null?[$now,$club]:[$now])->fetchAll();
        foreach($rows as $candidate){
            $lock=self::lockName($db,$candidate['page_id']);
            if((int)Db::run($db,'SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1)continue;
            try{
                $job=Db::run($db,'SELECT * FROM khcms_publication_schedule WHERE id=?'.($club!==null?' AND club_id=?':''),$club!==null?[$candidate['id'],$club]:[$candidate['id']])->fetch();
                if(!$job||!in_array($job['status'],['pending','processing'],true)||(int)$job['due_at']>$now)continue;
                if($job['status']==='processing'){
                    $job=Db::atomic($db,fn()=>self::reconcile($db,$job,$now));
                    if($job['status']!=='processing'){$results[]=self::present($job);continue;}
                }
                try{
                    $access=$accessResolver?$accessResolver($db,self::present($job)):\Kulturhus\Native\Shared\Authority::resolve($db,$job);
                    if(!$access instanceof Access)throw new DomainException('Aktuelle Veröffentlichungsberechtigung fehlt.');
                    $guard=static function()use($db,$job,$access):void{
                        $actor=$access->require('website','publish');
                        if($actor['id']!==$job['created_by']||$actor['club_id']!==$job['club_id'])throw new DomainException('Die Kontozuordnung hat sich geändert.');
                        self::lockClub($db,$job['club_id']);
                        $page=Db::run($db,'SELECT version,draft_json FROM khcms_pages WHERE id=? AND club_id=? FOR UPDATE',[$job['page_id'],$job['club_id']])->fetch();
                        if(!$page||(int)$page['version']!==(int)$job['page_version']||!hash_equals($job['snapshot_hash'],self::digest($page['draft_json'])))throw new Conflict('Der geplante Entwurf wurde inzwischen geändert.');
                    };
                    Db::atomic($db,function()use($db,$job,$guard,$now):void{
                        $guard();Db::run($db,"UPDATE khcms_publication_schedule SET status='processing',version=version+1,started_at=? WHERE id=?",[$now,$job['id']]);
                    });
                    // Recheck the immutable version/hash INSIDE Pages' own publishing transaction.
                    $publicationAccess=new class($db,$access,$guard) extends Access {
                        public function __construct(private PDO $connection,private Access $delegate,private Closure $guard){parent::__construct($connection,'');}
                        public function user():array{return $this->delegate->user();}
                        public function require(string $module,string $capability):array{
                            $actor=$this->delegate->require($module,$capability);
                            if($module==='website'&&$capability==='publish'&&$this->connection->inTransaction())($this->guard)();
                            return $actor;
                        }
                        public function directory(string $module):array{return $this->delegate->directory($module);}
                    };
                    (new Pages($db,$publicationAccess))->publish($job['page_id'],(int)$job['page_version']);
                    Db::atomic($db,fn()=>self::finish($db,$job,'published',null,$now,(int)$job['page_version']+1));
                }catch(\Throwable $failure){
                    // The page commit may have succeeded before an interrupted status update. Reconcile first.
                    $current=Db::run($db,'SELECT * FROM khcms_publication_schedule WHERE id=?',[$job['id']])->fetch();
                    $current=Db::atomic($db,fn()=>self::reconcile($db,$current,$now));
                    if($current['status']!=='published'){
                        $status=$failure instanceof Conflict?'conflicted':($failure instanceof DomainException||$failure instanceof \InvalidArgumentException?'blocked':'failed');
                        $reason=match($status){'conflicted'=>'draft_or_schema_changed','blocked'=>'authority_or_content_unavailable',default=>'execution_failed'};
                        Db::atomic($db,fn()=>self::finish($db,$current,$status,$reason,$now));
                    }
                }
                $results[]=self::present(Db::run($db,'SELECT * FROM khcms_publication_schedule WHERE id=?',[$job['id']])->fetch());
            }finally{Db::run($db,'SELECT RELEASE_LOCK(?)',[$lock]);}
        }
        return $results;
    }

    /** Current account and manager role authority; intentional jobs outlive browser sessions. */

    private static function reconcile(PDO $db,array $job,int $now):array
    {
        if($job['status']!=='processing')return $job;
        $revision=Db::run($db,'SELECT payload_json,actor_id,action FROM khcms_revisions WHERE page_id=? AND club_id=? AND revision=?',[$job['page_id'],$job['club_id'],(int)$job['page_version']+1])->fetch();
        if($revision&&$revision['action']==='publish'&&$revision['actor_id']===$job['created_by']&&hash_equals($job['snapshot_hash'],self::digest($revision['payload_json']))){
            self::finish($db,$job,'published','reconciled_after_interruption',$now,(int)$job['page_version']+1);
            return Db::run($db,'SELECT * FROM khcms_publication_schedule WHERE id=?',[$job['id']])->fetch();
        }
        return $job;
    }
    private static function finish(PDO $db,array $job,string $status,?string $reason,int $now,?int $revision=null):void
    {Db::run($db,'UPDATE khcms_publication_schedule SET status=?,reason=?,completed_at=?,published_revision=?,version=version+1 WHERE id=?',[$status,$reason,$now,$revision,$job['id']]);}
    private static function lockClub(PDO $db,string $club):void
    {Db::run($db,'INSERT INTO khcms_state(club_id,generation) VALUES(?,0) ON DUPLICATE KEY UPDATE generation=generation',[$club]);Db::run($db,'SELECT generation FROM khcms_state WHERE club_id=? FOR UPDATE',[$club])->fetchColumn();}
    private function page(string $id,string $club,bool $lock=false):array
    {
        $row=Db::run($this->db,'SELECT p.*,a.archived_at FROM khcms_pages p LEFT JOIN khcms_archives a ON a.page_id=p.id AND a.club_id=p.club_id WHERE p.id=? AND p.club_id=?'.($lock?' FOR UPDATE':''),[$id,$club])->fetch();
        if(!$row)throw new DomainException('Inhalt nicht verfügbar.');return $row;
    }
    private function latest(string $id,string $club,bool $lock=false):?array
    {return Db::run($this->db,'SELECT * FROM khcms_publication_schedule WHERE page_id=? AND club_id=? ORDER BY sequence_id DESC LIMIT 1'.($lock?' FOR UPDATE':''),[$id,$club])->fetch()?:null;}
    private function job(string $id,?string $club=null,bool $lock=false):array
    {
        $row=Db::run($this->db,'SELECT * FROM khcms_publication_schedule WHERE id=?'.($club!==null?' AND club_id=?':'').($lock?' FOR UPDATE':''),$club!==null?[$id,$club]:[$id])->fetch();
        if(!$row)throw new DomainException('Planung nicht verfügbar.');return $row;
    }
    private static function present(array $row):array
    {
        unset($row['snapshot_json'],$row['snapshot_hash'],$row['sequence_id']);
        foreach(['page_version','due_at','version','created_at','started_at','completed_at','published_revision'] as $key)if($row[$key]!==null)$row[$key]=(int)$row[$key];return $row;
    }
    private static function digest(string $json):string
    {
        $sort=static function(mixed $value)use(&$sort):mixed{if(!is_array($value))return $value;if(!array_is_list($value))ksort($value,SORT_STRING);foreach($value as &$item)$item=$sort($item);return $value;};
        return hash('sha256',json_encode($sort(json_decode($json,true,512,JSON_THROW_ON_ERROR)),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }
    private static function lockName(PDO $db,string $page):string
    {return 'khcms-plan:'.substr(hash('sha256',Db::run($db,'SELECT DATABASE()')->fetchColumn().'|'.$page),0,48);}
    private function locked(string $page,callable $operation):mixed
    {
        if($this->db->inTransaction())throw new \LogicException('Planung benötigt eine eigene Transaktion.');
        $lock=self::lockName($this->db,$page);
        if((int)Db::run($this->db,'SELECT GET_LOCK(?,5)',[$lock])->fetchColumn()!==1)throw new Conflict('Veröffentlichung wird gerade bearbeitet. Bitte erneut versuchen.');
        try{return $operation();}finally{Db::run($this->db,'SELECT RELEASE_LOCK(?)',[$lock]);}
    }
}
