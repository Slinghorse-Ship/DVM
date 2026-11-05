<?php declare(strict_types=1);
namespace Kulturhus\Native\System;
use Kulturhus\Native\Shared\Db;

/** Typed event codes and closed context deliberately exclude free text, secrets and request bodies. */
final class Journal
{
    public function __construct(private readonly \PDO $db,private readonly Authority $authority){}
    public static function record(\PDO $db,string $club,string $code,array $context,bool $notice,string $source,int $now):string
    {
        $context=Catalog::context($code,$context);if($club===''||strlen($club)>128||strlen($source)>500||$now<1)throw new \InvalidArgumentException('Systemereignis prüfen.');$hash=hash('sha256',$code.'\0'.$source);$id=bin2hex(random_bytes(16));
        Db::run($db,'INSERT INTO native_system_events VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id',[$id,$club,$code,Catalog::EVENTS[$code][0],(int)$notice,json_encode($context,JSON_THROW_ON_ERROR),$hash,$now]);return (string)Db::run($db,'SELECT id FROM native_system_events WHERE club_id=? AND source_hash=?',[$club,$hash])->fetchColumn();
    }
    public static function pageNumber(mixed $value):int{if((!is_string($value)&&!is_int($value))||!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$value)||(int)$value>1000000)throw new \DomainException('Gültige Systemseite auswählen.');return (int)$value;}
    public function listing(array $query=[]):array
    {
        $owner=$this->authority->require();$state=$query['state']??'all';$level=$query['level']??'all';if(!is_string($state)||!in_array($state,['all','unread','acknowledged'],true)||!is_string($level)||!in_array($level,['all','info','warning','error'],true))throw new \DomainException('Systemhinweise filtern.');$page=self::pageNumber($query['page']??1);$where='e.club_id=?';$args=[$owner['id'],$owner['club_id']];
        if($state!=='all')$where.=' AND e.notice=1 AND a.event_id IS '.($state==='unread'?'NULL':'NOT NULL');if($level!=='all'){$where.=' AND e.severity=?';$args[]=$level;}
        $from=' FROM native_system_events e LEFT JOIN native_system_acknowledgements a ON a.event_id=e.id AND a.club_id=e.club_id AND a.actor_id=? WHERE '.$where;$total=(int)Db::run($this->db,'SELECT COUNT(*)'.$from,$args)->fetchColumn();$pages=max(1,(int)ceil($total/10));$page=min($page,$pages);$rows=Db::run($this->db,'SELECT e.id,e.code,e.severity,e.notice,e.context_json,e.created_at,(a.event_id IS NOT NULL) AS acknowledged'.$from.' ORDER BY e.created_at DESC,e.id DESC LIMIT 10 OFFSET '.(($page-1)*10),$args)->fetchAll();
        foreach($rows as&$row){$row['context']=Catalog::context($row['code'],json_decode($row['context_json'],true,32,JSON_THROW_ON_ERROR));unset($row['context_json']);$row['notice']=(bool)$row['notice'];$row['acknowledged']=(bool)$row['acknowledged'];$row['created_at']=(int)$row['created_at'];}$this->authority->require();return compact('rows','total','page','pages','state','level');
    }
    public function acknowledge(string $id,int $now):void
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new \DomainException('Eigenen Betriebshinweis auswählen.');Db::atomic($this->db,function()use($id,$now):void{$owner=$this->authority->require();if(!Db::run($this->db,'SELECT 1 FROM native_system_events WHERE club_id=? AND id=? AND notice=1 FOR SHARE',[$owner['club_id'],$id])->fetchColumn())throw new \DomainException('Eigener Betriebshinweis nicht verfügbar.');Db::run($this->db,'INSERT INTO native_system_acknowledgements VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE event_id=event_id',[$owner['club_id'],$id,$owner['id'],$now]);});
    }
    public static function prune(\PDO $db,string $club,int $now):int
    {
        // A personal acknowledgement never resolves somebody else's notice.
        // Only ordinary information is eligible for automatic retention cleanup.
        return Db::atomic($db,static function()use($db,$club,$now):int{
            $ids=Db::run($db,'SELECT id FROM native_system_events WHERE club_id=? AND created_at<? AND notice=0 ORDER BY created_at DESC,id DESC LIMIT 1000,100 FOR UPDATE',[$club,$now-90*86400])->fetchAll(\PDO::FETCH_COLUMN);
            foreach($ids as$id)Db::run($db,'DELETE FROM native_system_events WHERE club_id=? AND id=? AND notice=0',[$club,$id]);return count($ids);
        });
    }
}
