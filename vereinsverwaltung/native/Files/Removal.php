<?php declare(strict_types=1);
namespace Kulturhus\Native\Files;

use DomainException;
use InvalidArgumentException;
use PDO;
use Throwable;
use Kulturhus\Native\Cms\{ClubPublicationLock,Conflict};
use Kulturhus\Native\Shared\{Access,Db};

/** Removed local files stay private in place. A committed original plan authorizes each purge step. */
final class Removal
{
    use ClubPublicationLock;
    private BlobStore $store;
    public function __construct(private PDO $db,private Access $access,string $root){$this->store=new BlobStore($root);}
    public function describe(string $id):array
    {
        AssetVersions::identifier($id);$club=$this->access->require('website','manage')['club_id'];
        $asset=$this->asset($id,$club);$state=$this->state($id,$club);
        if($state['operation']==='')return $asset+$state+['retain_until'=>'','retained_bytes'=>0,'purge_eligible'=>false];
        $row=Db::run($this->db,'SELECT retain_until,retained_bytes,(retain_until<=UTC_TIMESTAMP(6)) AS purge_eligible FROM khcms_file_removals WHERE operation=? AND asset_id=? AND club_id=?',[$state['operation'],$id,$club])->fetch();
        if(!$row)throw new DomainException('Ursprünglichen Dateivorgang prüfen.');$row['retained_bytes']=(int)$row['retained_bytes'];$row['purge_eligible']=(bool)$row['purge_eligible'];return $asset+$state+$row;
    }
    public function remove(string $id,int $version,string $operation):array
    {
        $this->arguments($id,$version,$operation);
        return $this->transaction(function()use($id,$version,$operation):array{
            $actor=$this->access->require('website','manage');$club=$actor['club_id'];$this->lockClub();$asset=$this->asset($id,$club,true);$state=$this->state($id,$club,true);
            if($state['state']==='removed'&&$state['operation']===$operation&&$state['version']===$version+1)return $this->describe($id);
            if($asset['version']!==$version||$asset['state']!=='active')throw new Conflict('Dateizustand inzwischen geändert. Aktuellen Stand öffnen.');
            if($asset['kind']!=='file')throw new DomainException('Bilddateien über die bestehende Bildverwaltung entfernen.');
            if(Db::run($this->db,'SELECT 1 FROM khcms_file_removals WHERE operation=? FOR SHARE',[$operation])->fetchColumn())throw new Conflict('Dateivorgang bereits verwendet. Aktuellen Stand öffnen.');
            (new References($this->db,$this->access))->assertUnused($id);$plan=$this->plan($asset,$club);$json=self::json($plan);$days=Retention::current($this->db,$club)['minimum_days'];
            Db::run($this->db,'INSERT INTO khcms_file_removals VALUES(?,?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL ? DAY),?,UTC_TIMESTAMP(6))',[$operation,$club,$id,$version,$json,array_sum(array_column($plan['blobs'],'bytes')),$days,$actor['id']]);
            $this->change($asset,$club,'removed',$operation,$actor['id']);return $this->describe($id);
        });
    }
    public function restore(string $id,int $version,string $operation):array
    {
        $this->arguments($id,$version,$operation);
        return $this->transaction(function()use($id,$version,$operation):array{
            $actor=$this->access->require('website','manage');$club=$actor['club_id'];$this->lockClub();$asset=$this->asset($id,$club,true);$state=$this->state($id,$club,true);
            if($state['state']==='restored'&&$state['operation']===$operation&&$state['version']===$version+1)return $this->describe($id);
            if($asset['version']!==$version||$asset['state']!=='removed'||$state['operation']!==$operation)throw new Conflict('Dateizustand inzwischen geändert. Aktuellen Stand öffnen.');
            $plan=$this->originalPlan($asset,$club,$operation);foreach($plan['blobs']as$blob)$this->store->file($club,$blob['id'],$blob);
            $this->change($asset,$club,'restored',$operation,$actor['id']);return $this->describe($id);
        });
    }
    public function beginPurge(string $id,int $version,string $operation):array
    {
        $this->arguments($id,$version,$operation);
        return $this->transaction(function()use($id,$version,$operation):array{
            $actor=$this->access->require('website','manage');$club=$actor['club_id'];$this->lockClub();$asset=$this->asset($id,$club,true);$state=$this->state($id,$club,true);
            if($state['operation']!==$operation)throw new Conflict('Ein anderer Dateivorgang ist aktiv. Aktuellen Stand öffnen.');
            if($asset['state']==='purged'&&$asset['version']===$version+2)return $this->describe($id);
            $this->originalPlan($asset,$club,$operation);$this->expired($id,$club,$operation);(new References($this->db,$this->access))->assertUnused($id);
            if($asset['state']==='purging'&&$asset['version']===$version+1)return $this->describe($id);
            if($asset['state']!=='removed'||$asset['version']!==$version)throw new Conflict('Dateizustand inzwischen geändert. Aktuellen Stand öffnen.');
            $this->change($asset,$club,'purging',$operation,$actor['id']);return $this->describe($id);
        });
    }
    public function finishPurge(string $id,int $version,string $operation):array
    {
        $this->arguments($id,$version,$operation);
        return $this->transaction(function()use($id,$version,$operation):array{
            $actor=$this->access->require('website','manage');$club=$actor['club_id'];$this->lockClub();$asset=$this->asset($id,$club,true);$state=$this->state($id,$club,true);
            if($state['operation']!==$operation)throw new Conflict('Ein anderer Dateivorgang ist aktiv. Aktuellen Stand öffnen.');
            if($asset['state']==='purged'&&$asset['version']===$version+1)return $this->describe($id);
            if($asset['state']!=='purging'||$asset['version']!==$version)throw new Conflict('Dateizustand inzwischen geändert. Aktuellen Stand öffnen.');
            $plan=$this->originalPlan($asset,$club,$operation);$this->expired($id,$club,$operation);(new References($this->db,$this->access))->assertUnused($id);
            // Validate every shared-blob blocker before the first physical deletion.
            foreach($plan['blobs']as$blob)if(Db::run($this->db,"SELECT 1 FROM khcms_asset_revisions r JOIN khcms_assets a ON a.id=r.asset_id AND a.club_id=r.club_id WHERE r.club_id=? AND r.source_kind='file' AND r.source_id=? AND r.asset_id<>? AND a.state<>'purged' LIMIT 1 FOR SHARE",[$club,$blob['id'],$id])->fetchColumn())throw new DomainException('Dateifassung gehört noch zu einer weiteren Versionsfolge.');
            foreach($plan['blobs']as$blob)$this->store->purge($club,$blob['id'],$blob);
            $this->change($asset,$club,'purged',$operation,$actor['id']);return $this->describe($id);
        });
    }
    public function purge(string $id,int $version,string $operation):array
    {$state=$this->beginPurge($id,$version,$operation);return $state['state']==='purged'?$state:$this->finishPurge($id,$state['version'],$operation);}
    private function originalPlan(array $asset,string $club,string $operation):array
    {
        $json=Db::run($this->db,'SELECT plan_json FROM khcms_file_removals WHERE operation=? AND club_id=? AND asset_id=? FOR SHARE',[$operation,$club,$asset['id']])->fetchColumn();if(!is_string($json))throw new DomainException('Ursprünglicher Dateivorgang nicht verfügbar.');$plan=json_decode($json,true,32,JSON_THROW_ON_ERROR);
        if(($plan['asset_id']??null)!==$asset['id']||!hash_equals($plan['revisions_sha256']??'',hash('sha256',self::json($this->revisions($asset['id'],$club)))))throw new DomainException('Ursprüngliche Versionsfolge wurde verändert.');
        foreach($plan['blobs']as$blob){$current=Db::run($this->db,'SELECT id,original_name,mime,bytes,sha256 FROM khcms_file_blobs WHERE id=? AND club_id=? FOR SHARE',[$blob['id'],$club])->fetch();if(!$current)throw new DomainException('Ursprüngliche Dateifassung nicht verfügbar.');$current['bytes']=(int)$current['bytes'];if($current!==$blob)throw new DomainException('Ursprüngliche Dateiangaben wurden verändert.');}
        return $plan;
    }
    private function plan(array $asset,string $club):array
    {
        $revisions=$this->revisions($asset['id'],$club);$ids=[];foreach($revisions as$revision){if($revision['source_kind']!=='file')throw new DomainException('Dateiquelle prüfen.');$ids[$revision['source_id']]=true;}$blobs=[];
        foreach(array_keys($ids)as$id){$row=Db::run($this->db,'SELECT id,original_name,mime,bytes,sha256 FROM khcms_file_blobs WHERE id=? AND club_id=? FOR SHARE',[$id,$club])->fetch();if(!$row)throw new DomainException('Dateifassung nicht verfügbar.');$row['bytes']=(int)$row['bytes'];$this->store->file($club,$id,$row);$blobs[]=$row;}
        return ['asset_id'=>$asset['id'],'revisions_sha256'=>hash('sha256',self::json($revisions)),'blobs'=>$blobs];
    }
    private function revisions(string $id,string $club):array
    {return Db::run($this->db,'SELECT * FROM khcms_asset_revisions WHERE asset_id=? AND club_id=? ORDER BY revision FOR SHARE',[$id,$club])->fetchAll();}
    private function expired(string $id,string $club,string $operation):void
    {if(!(bool)Db::run($this->db,'SELECT(retain_until<=UTC_TIMESTAMP(6)) FROM khcms_file_removals WHERE operation=? AND club_id=? AND asset_id=? FOR SHARE',[$operation,$club,$id])->fetchColumn())throw new DomainException('Mindestaufbewahrung läuft noch.');}
    private function asset(string $id,string $club,bool $write=false):array
    {$row=Db::run($this->db,'SELECT id,kind,version,state FROM khcms_assets WHERE id=? AND club_id=?'.($write?' FOR UPDATE':''),[$id,$club])->fetch();if(!$row)throw new DomainException('Datei nicht verfügbar.');$row['version']=(int)$row['version'];return $row;}
    private function state(string $id,string $club,bool $write=false):array
    {$row=Db::run($this->db,'SELECT version,state,operation,changed_at FROM khcms_file_removal_states WHERE asset_id=? AND club_id=?'.($write?' FOR UPDATE':''),[$id,$club])->fetch();if(!$row)return ['version'=>0,'state'=>'active','operation'=>'','changed_at'=>''];$row['version']=(int)$row['version'];return $row;}
    private function change(array $asset,string $club,string $state,string $operation,string $actor):void
    {
        $version=$asset['version']+1;$assetState=$state==='restored'?'active':$state;
        Db::run($this->db,'UPDATE khcms_assets SET version=?,state=?,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND club_id=?',[$version,$assetState,$asset['id'],$club]);$args=[$club,$asset['id'],$version,$state,$operation,$actor];Db::run($this->db,'INSERT INTO khcms_file_removal_states VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE version=VALUES(version),state=VALUES(state),operation=VALUES(operation),actor_id=VALUES(actor_id),changed_at=VALUES(changed_at)',$args);Db::run($this->db,'INSERT INTO khcms_file_removal_history VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6))',$args);Db::run($this->db,'UPDATE khcms_state SET generation=generation+1 WHERE club_id=?',[$club]);
    }
    private function transaction(callable $run):array
    {
        if($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$this->db->inTransaction())throw new DomainException('Dateientfernung benötigt einen eigenen gesicherten Vorgang.');$this->db->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');$this->db->beginTransaction();try{$result=$run();$this->db->commit();return $result;}catch(Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}
    }
    private function arguments(string $id,int $version,string $operation):void
    {AssetVersions::identifier($id);AssetVersions::identifier($operation);if($version<1||$version>=4294967293)throw new InvalidArgumentException('Gespeicherten Dateizustand prüfen.');}
    private static function json(array $value):string{return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
}
