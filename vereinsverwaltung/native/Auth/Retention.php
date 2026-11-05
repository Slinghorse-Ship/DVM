<?php declare(strict_types=1);
namespace Kulturhus\Native\Auth;

use Kulturhus\Native\Shared\{Access,Db};

/** Explicit private housekeeping of expired technical credentials, never business or API replay history. */
final class Retention
{
    public const GRACE_SECONDS=90*86400;
    public function __construct(private readonly \PDO $db,private readonly Access $access){}

    /** The caller's scheduler heartbeat runs outside this short, all-or-nothing transaction. */
    public function prune(int $now,int $limit=100,?callable $heartbeat=null):array
    {
        if(PHP_SAPI!=='cli'||$this->db->inTransaction()||$limit<1||$limit>100||$now<=self::GRACE_SECONDS)throw new \DomainException('Begrenzte private Kontenbereinigung erforderlich.');
        if($heartbeat)$heartbeat();
        $owner=$this->access->require('accounts','manage');$cutoff=$now-self::GRACE_SECONDS;
        $result=Db::atomic($this->db,function()use($owner,$cutoff,$limit):array{
            $result=['sessions'=>0,'tokens'=>0,'challenges'=>0,'total'=>0];
            foreach(['sessions'=>'revoked_at','tokens'=>'consumed_at','challenges'=>'consumed_at']as$kind=>$closed){
                $this->owner($owner);$remaining=$limit-$result['total'];if($remaining===0)break;
                $table='native_auth_'.$kind;$where='u.club_id=? AND (t.expires_at<=? OR (t.'.$closed.' IS NOT NULL AND t.'.$closed.'<=?))';$args=[$owner['club_id'],$cutoff,$cutoff];
                $ids=Db::run($this->db,'SELECT t.token_hash FROM '.$table.' t JOIN native_auth_accounts a ON a.id=t.account_id JOIN native_users u ON u.id=a.actor_id WHERE '.$where.' ORDER BY t.expires_at,t.token_hash LIMIT '.$remaining.' FOR UPDATE',$args)->fetchAll(\PDO::FETCH_COLUMN);
                if(!$ids)continue;$this->owner($owner);$marks=implode(',',array_fill(0,count($ids),'?'));
                $result[$kind]=Db::run($this->db,'DELETE t FROM '.$table.' t JOIN native_auth_accounts a ON a.id=t.account_id JOIN native_users u ON u.id=a.actor_id WHERE '.$where.' AND t.token_hash IN ('.$marks.')',[...$args,...$ids])->rowCount();$result['total']+=$result[$kind];
            }
            $this->owner($owner);return $result;
        });
        if($heartbeat)$heartbeat();return $result;
    }
    private function owner(array $expected):void
    {
        $actual=$this->access->require('accounts','manage');
        if($actual['id']!==$expected['id']||$actual['club_id']!==$expected['club_id'])throw new \DomainException('Aktuelle ursprüngliche Kontenverwaltung erforderlich.');
    }
}
