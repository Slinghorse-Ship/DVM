<?php declare(strict_types=1);
namespace Kulturhus\Native\Shared;
use Closure;
use DomainException;
use PDO;
use Kulturhus\Native\Integration\ManagerBridge;
/** Rebuild worker authority from the current account, binding and grants. */
final class Authority
{
    public static function resolve(PDO $db,array $job):Access
    {
        if(!in_array($job['authority'],['native','manager'],true))throw new DomainException('Unbekannte Veröffentlichungsautorität.');
        // Old standalone jobs can legitimately retain authority=native while
        // their actual creator is bound to the manager. Keep the job original;
        // a retained binding, not its historical marker or grant cache, selects
        // the fresh source of authority.
        try{$binding=Db::run($db,'SELECT manager_actor_id,manager_club_id FROM native_manager_identities WHERE native_id=?'.($db->inTransaction()?' FOR SHARE':''),[$job['created_by']])->fetch();}
        catch(\PDOException $failure){if($failure->getCode()!=='42S02'||$job['authority']==='manager')throw$failure;$binding=false;}
        $manager=$job['authority']==='manager'||$binding!==false;
        $hasAccounts=(bool)Db::run($db,"SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='native_auth_accounts'")->fetchColumn();
        $accountGuard=static function()use($db,$job,$hasAccounts,$manager):void{
            if(!$hasAccounts){if($manager)throw new DomainException('Bestätigtes Verwaltungskonto fehlt.');return;}
            $account=Db::run($db,'SELECT active,verified FROM native_auth_accounts WHERE actor_id=?'.($db->inTransaction()?' FOR SHARE':''),[$job['created_by']])->fetch();
            if(($manager&&!$account)||($account&&(!(int)$account['active']||!(int)$account['verified'])))throw new DomainException('Das Konto ist gesperrt oder nicht bestätigt.');
        };
        if(!$manager)return new class($db,$job['created_by'],$accountGuard) extends Access {
            public function __construct(PDO $db,string $actor,private Closure $guard){parent::__construct($db,$actor);}
            public function user():array{($this->guard)();return parent::user();}
        };
        if(!$binding)throw new DomainException('Verwaltungszuordnung fehlt.');
        $guard=static function(PDO $connection,string $actor)use($db,$job,$binding,$accountGuard):void{
            $accountGuard();$lock=$db->inTransaction()?' FOR SHARE':'';
            $current=Db::run($db,'SELECT manager_actor_id,manager_club_id FROM native_manager_identities WHERE native_id=?'.$lock,[$job['created_by']])->fetch();
            $user=Db::run($db,'SELECT club_id,active FROM native_users WHERE id=?'.$lock,[$job['created_by']])->fetch();
            if(!$current||$current!==$binding||$actor!==$binding['manager_actor_id']||!$user||!(int)$user['active']||$user['club_id']!==$job['club_id']||$job['club_id']!==ManagerBridge::clubKey($binding['manager_club_id'])||$job['created_by']!==ManagerBridge::actorKey($binding['manager_actor_id'],$binding['manager_club_id']))throw new DomainException('Verwaltungszuordnung nicht mehr gültig.');
        };
        // The interactive manager created this binding/projection before enqueueing.
        // Worker checks must only rebuild authority, including inside a job transaction.
        // access() would attempt a new identity projection and rejects that transaction.
        return new \Kulturhus\Native\Integration\ManagerAccess($db,new ManagerBridge($db,$binding['manager_actor_id'],'native',$guard));
    }

}
