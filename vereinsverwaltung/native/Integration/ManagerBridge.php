<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration;

use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Verein\ProductionRuntime;

require_once dirname(__DIR__,2).'/src/Infrastructure/Runtime/ProductionRuntime.php';
require_once dirname(__DIR__,2).'/src/Communication/EditorialPolicy.php';

/**
 * The caller supplies ONLY the actor resolved by the existing authenticated manager
 * session. This adapter does not authenticate, read request parameters, or choose roles.
 * Both sets of tables must live on this same PDO connection. No passwords are copied.
 */
final class ManagerBridge
{
    private string $club;
    private ?\Closure $identityGuard;
    public function __construct(private PDO $db, private string $actorId, private string $provider='legacy', ?callable $identityGuard=null)
    {
        if ($actorId === '' || strlen($actorId)>128 || preg_match('/[\x00-\x1f]/',$actorId)) throw new DomainException('Gültiger Vereinszugang erforderlich.');
        if (!in_array($provider,['legacy','native'],true)||($provider==='native'&&$identityGuard===null))throw new DomainException('Geprüfte serverseitige Anmeldequelle erforderlich.');
        if (ProductionRuntime::enabled()&&$provider!=='native')throw new DomainException('Der Produktivbetrieb benötigt den nativen Vereinszugang.');
        $this->identityGuard=$identityGuard===null?null:\Closure::fromCallable($identityGuard);
        $this->assertIdentity();
        $actor=$this->source($actorId);$this->actorId=$actor['id'];$this->club=$actor['club_id'];
    }

    public function managerClub(): string { $this->current(); return $this->club; }
    public function managerActor(): string { $this->current(); return $this->actorId; }
    public function nativeActorId(): string { return self::actorKey($this->actorId,$this->club); }
    public static function clubKey(string $club): string { return hash('sha256',"manager-club\0".$club); }
    public static function actorKey(string $actor,string $club): string { return hash('sha256',"manager-actor\0".$club."\0".$actor); }

    public static function fromNativeSession(PDO $db,\Kulturhus\Native\Auth\Service $auth,#[\SensitiveParameter] string $token): self
    {
        $session=$auth->resolve($token);$nativeId=$session['actor_id']??'';
        if(!is_string($nativeId)||$nativeId==='')throw new DomainException('Geprüfte Kontozuordnung fehlt.');
        $binding=Db::run($db,'SELECT manager_actor_id,manager_club_id FROM native_manager_identities WHERE native_id=?'.($db->inTransaction()?' FOR SHARE':''),[$nativeId])->fetch();
        if(!$binding||!hash_equals(self::actorKey($binding['manager_actor_id'],$binding['manager_club_id']),$nativeId))throw new DomainException('Dieses Konto besitzt noch keine bestätigte Vereinszuordnung.');
        $actor=$binding['manager_actor_id'];$club=$binding['manager_club_id'];
        $guard=static function(PDO $connection,string $expectedActor)use($auth,$token,$nativeId,$actor,$club):void {
            $current=$auth->resolve($token);
            if($expectedActor!==$actor||($current['actor_id']??null)!==$nativeId)throw new DomainException('Sitzungszuordnung geändert.');
            $row=Db::run($connection,'SELECT manager_actor_id,manager_club_id FROM native_manager_identities WHERE native_id=?'.($connection->inTransaction()?' FOR SHARE':''),[$nativeId])->fetch();
            if(!$row||$row['manager_actor_id']!==$actor||$row['manager_club_id']!==$club)throw new DomainException('Vereinszuordnung geändert.');
        };
        $bridge=new self($db,$actor,'native',$guard);
        if($bridge->managerClub()!==$club)throw new DomainException('Vereinszuordnung geändert.');
        return $bridge;
    }

    /** Call once after existing login resolution and explicit schema installation. */
    public function access(): Access
    {
        if ($this->db->inTransaction()) throw new \LogicException('Manager-Anbindung vor dem fachlichen Speichervorgang herstellen.');
        Db::atomic($this->db,function(): void { $this->current(); $this->projectClub(); });
        return new ManagerAccess($this->db,$this);
    }

    public function user(): array { return $this->nativeUser($this->current()); }

    public function requireCapability(string $module,string $capability): array
    {
        $actor=$this->current();
        if (!in_array($capability,$this->capabilities($actor)[$module]??[],true)) throw new DomainException('Für diese Aktion fehlt die aktuelle Vereinsberechtigung.');
        // Chat successor selection and notification enqueue use native joins directly.
        // Refresh their complete club projection under the service's own transaction.
        if ($module==='chat' && $this->db->inTransaction()) $this->projectClub();
        return $this->nativeUser($actor);
    }

    public function directory(string $module): array
    {
        $this->requireCapability($module,'read');
        $read=function()use($module):array {
            $rows=$this->projectClub();
            $rows=array_filter($rows,fn(array $actor):bool=>in_array('read',$this->capabilities($actor)[$module]??[],true)
                && ($module!=='chat'||$actor['_directory_visible']));
            $result=array_map($this->nativeUser(...),array_values($rows));
            usort($result,static fn(array $a,array $b):int=>[$a['name'],$a['id']]<=>[$b['name'],$b['id']]);
            return $result;
        };
        return $this->db->inTransaction()?$read():Db::atomic($this->db,$read);
    }

    private function current(): array
    {
        $this->assertIdentity();
        $actor=$this->source($this->actorId);
        if ($actor['club_id']!==$this->club) throw new DomainException('Vereinszuordnung geändert. Bitte erneut anmelden.');
        return $actor;
    }

    private function assertIdentity(): void
    {
        // Native Auth supplies this guard. It must throw on expired, revoked or
        // incomplete sessions and use current locking reads inside transactions.
        if ($this->identityGuard!==null)($this->identityGuard)($this->db,$this->actorId);
    }

    /** Current locking reads also see revocations after an earlier repeatable-read snapshot. */
    private function source(string $id): array
    {
        $lock=$this->db->inTransaction()?' FOR SHARE':'';
        $a=Db::run($this->db,'SELECT * FROM actors WHERE id=?'.$lock,[$id])->fetch();
        if (!$a||!(int)$a['active']) throw new DomainException('Vereinszugang nicht aktiv.');
        $binding=$this->provider==='legacy'?Db::run($this->db,'SELECT pw_user_id,enabled FROM processwire_identities WHERE actor_id=?'.$lock,[$a['id']])->fetch():false;
        if ($this->provider==='legacy' && $binding && !(int)$binding['enabled']) throw new DomainException('Anmeldezuordnung gesperrt.');
        $assignment=Db::run($this->db,'SELECT role_id FROM settings_role_assignments WHERE actor_id=? AND club_id=?'.$lock,[$a['id'],$a['club_id']])->fetchColumn();
        $role=$assignment?Db::run($this->db,'SELECT website_access,settings_access,press_access FROM settings_roles WHERE club_id=? AND id=?'.$lock,[$a['club_id'],$assignment])->fetch():false;
        // Same editorial inputs and explicit overrides as Permissions::actor; other
        // manager permissions (finance, members, documents) never enter this adapter.
        $a['website_access']=$role['website_access']??null;
        $a['settings_access']=$role['settings_access']??($a['role']==='members'?1:0);
        $a['press_access']=$role['press_access']??$a['press_access'];
        if ($a['role']==='editor') $a['settings_access']=0;
        elseif (!empty($a['is_superuser'])) {$a['settings_access']=1;$a['press_access']='write';}
        $contact=$a['contact_id']?Db::run($this->db,'SELECT name,archived_at FROM contacts WHERE club_id=? AND id=?'.$lock,[$a['club_id'],$a['contact_id']])->fetch():false;
        $a['_name']=\Kulturhus\Verein\ActorNames::name($this->db,$a['club_id'],$a['id'],(string)($contact['name']??$a['id']));
        $a['_directory_visible']=!$a['contact_id']||($contact&&$contact['archived_at']===null);
        $a['_pw_id']=(int)($binding['pw_user_id']??0);
        return $a;
    }

    private function capabilities(array $actor): array
    {
        $levels=[];
        foreach (['texts','events','gallery','newsletter','social','press'] as $area) $levels[$area]=$this->level($actor,$area);
        $website=in_array('none',array_intersect_key($levels,array_flip(['texts','events','gallery'])),true)?'none'
            :(in_array('read',array_intersect_key($levels,array_flip(['texts','events','gallery'])),true)?'read':'write');
        $result=[];
        foreach (['website'=>$website,'newsletter'=>$levels['newsletter'],'social'=>$levels['social'],'press'=>$levels['press']] as $module=>$level) {
            $result[$module]=match($level){'write'=>['read','edit','publish'],'read'=>['read'],default=>[]};
            // Content/schema management is technical; communication templates,
            // recipients and scheduling use the existing module write permission.
            if ($level==='write'&&($module!=='website'||$this->technical($actor)))$result[$module][]='manage';
        }
        $result['chat']=$actor['role']==='editor'?[]:['read','edit'];
        $result['accounts']=$this->technical($actor)?['read','manage']:[];
        return $result;
    }

    private function level(array $actor,string $area): string
    {
        $level=\Kulturhus\Verein\EditorialPolicy::level($actor,$area);
        if ($level==='none'||$this->provider==='native'||!ProductionRuntime::enabled())return $level;
        return 'none';
    }

    private function technical(array $actor): bool
    {
        if ($actor['role']==='editor'||empty($actor['is_superuser']))return false;
        return $this->provider==='native'||!ProductionRuntime::enabled();
    }

    private function nativeUser(array $a): array
    {
        return ['id'=>self::actorKey($a['id'],$a['club_id']),'club_id'=>self::clubKey($a['club_id']),
            'name'=>mb_substr((string)$a['_name'],0,160,'UTF-8'),'active'=>1];
    }

    /** Update only bridge-owned reference rows. Legacy tables and native demo users stay unchanged. */
    private function projectClub(): array
    {
        if (!$this->db->inTransaction())throw new \LogicException('Identitätsprojektion benötigt eine Transaktion.');
        $ids=Db::run($this->db,'SELECT id FROM actors WHERE club_id=? ORDER BY id FOR SHARE',[$this->club])->fetchAll(PDO::FETCH_COLUMN);
        $actors=[];$active=[];
        foreach ($ids as $id) {
            try {$a=$this->source($id);}catch(DomainException){continue;}
            $u=$this->nativeUser($a);$active[]=$u['id'];$actors[]=$a;
            $binding=Db::run($this->db,'SELECT manager_actor_id,manager_club_id FROM native_manager_identities WHERE native_id=? FOR UPDATE',[$u['id']])->fetch();
            if (!$binding&&Db::run($this->db,'SELECT 1 FROM native_users WHERE id=? FOR UPDATE',[$u['id']])->fetchColumn())throw new DomainException('Native Benutzerkennung bereits anderweitig vergeben.');
            if ($binding&&($binding['manager_actor_id']!==$a['id']||$binding['manager_club_id']!==$a['club_id']))throw new DomainException('Ungültige native Identitätszuordnung.');
            Db::run($this->db,'INSERT INTO native_users(id,club_id,name,active) VALUES(?,?,?,1) ON DUPLICATE KEY UPDATE name=VALUES(name),active=1',[$u['id'],$u['club_id'],$u['name']]);
            if (!$binding)Db::run($this->db,'INSERT INTO native_manager_identities(native_id,manager_actor_id,manager_club_id) VALUES(?,?,?)',[$u['id'],$a['id'],$a['club_id']]);
            Db::run($this->db,'DELETE FROM native_grants WHERE actor_id=?',[$u['id']]);
            foreach ($this->capabilities($a) as $module=>$caps)foreach ($caps as $cap)Db::run($this->db,'INSERT INTO native_grants(actor_id,module,capability) VALUES(?,?,?)',[$u['id'],$module,$cap]);
        }
        $old=Db::run($this->db,'SELECT native_id FROM native_manager_identities WHERE manager_club_id=? ORDER BY native_id FOR UPDATE',[$this->club])->fetchAll(PDO::FETCH_COLUMN);
        foreach (array_diff($old,$active) as $id) {
            Db::run($this->db,'UPDATE native_users SET active=0 WHERE id=?',[$id]);
            Db::run($this->db,'DELETE FROM native_grants WHERE actor_id=?',[$id]);
        }
        return $actors;
    }
}
