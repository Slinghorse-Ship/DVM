<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use DomainException;
use InvalidArgumentException;
use PDO;
use Throwable;
use Kulturhus\Native\Shared\{Access,Db};

/** Reversible private removal. Original records, rights, proofs and identity stay intact. */
final class MediaRemoval
{
    public function __construct(private PDO $db,private Access $access,private string $mediaRoot){}

    public static function install(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS khcms_media_removals (operation CHAR(32) NOT NULL PRIMARY KEY,club_id VARCHAR(64) NOT NULL,media_id CHAR(32) NOT NULL,plan_json MEDIUMTEXT NOT NULL,retained_bytes BIGINT UNSIGNED NOT NULL,actor_id VARCHAR(64) NOT NULL,removed_at DATETIME(6) NOT NULL,retain_until DATETIME(6) NOT NULL,KEY media_removal_original(club_id,media_id,removed_at),CONSTRAINT cms_removal_original FOREIGN KEY(media_id) REFERENCES khcms_media(id) ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS khcms_media_removal_states (club_id VARCHAR(64) NOT NULL,media_id CHAR(32) NOT NULL,version INT UNSIGNED NOT NULL,state VARCHAR(16) NOT NULL,operation CHAR(32) NOT NULL,actor_id VARCHAR(64) NOT NULL,changed_at DATETIME(6) NOT NULL,PRIMARY KEY(club_id,media_id),KEY media_removed_list(club_id,state,changed_at,media_id),CONSTRAINT cms_removal_state_original FOREIGN KEY(media_id) REFERENCES khcms_media(id) ON DELETE RESTRICT,CONSTRAINT cms_removal_state_operation FOREIGN KEY(operation) REFERENCES khcms_media_removals(operation) ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS khcms_media_removal_history (club_id VARCHAR(64) NOT NULL,media_id CHAR(32) NOT NULL,version INT UNSIGNED NOT NULL,state VARCHAR(16) NOT NULL,operation CHAR(32) NOT NULL,actor_id VARCHAR(64) NOT NULL,changed_at DATETIME(6) NOT NULL,PRIMARY KEY(club_id,media_id,version),CONSTRAINT cms_removal_history_operation FOREIGN KEY(operation) REFERENCES khcms_media_removals(operation) ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function get(string $id): array
    {
        $this->identifier($id);$this->schema();
        return Db::atomic($this->db,function()use($id):array{
            $club=$this->access->require('website','manage')['club_id'];$this->asset($id,$club);
            return $this->current($id,$club);
        });
    }

    public function listing(mixed $page=1): array
    {
        if((!is_int($page)&&!is_string($page))||!preg_match('/\A[1-9][0-9]{0,6}\z/D',(string)$page)||(int)$page>1000000)throw new InvalidArgumentException('Seite der entfernten Bilder prüfen.');
        $this->schema();
        return Db::atomic($this->db,function()use($page):array{
            $club=$this->access->require('website','manage')['club_id'];
            $total=(int)Db::run($this->db,"SELECT COUNT(*) FROM khcms_media_removal_states WHERE club_id=? AND state IN ('removed','purging')",[$club])->fetchColumn();
            $pages=max(1,(int)ceil($total/6));$page=min((int)$page,$pages);
            $rows=Db::run($this->db,"SELECT m.id,m.original_name,m.alt,m.mime,m.width,m.height,s.version,s.state,s.operation,s.changed_at,r.retain_until,r.retained_bytes,(r.retain_until<=UTC_TIMESTAMP(6)) AS purge_eligible FROM khcms_media_removal_states s JOIN khcms_media m ON m.id=s.media_id AND m.club_id=s.club_id JOIN khcms_media_removals r ON r.operation=s.operation AND r.club_id=s.club_id AND r.media_id=s.media_id WHERE s.club_id=? AND s.state IN ('removed','purging') ORDER BY s.changed_at DESC,s.media_id DESC LIMIT 6 OFFSET ".(($page-1)*6),[$club])->fetchAll();
            if($this->access->require('website','manage')['club_id']!==$club)throw new DomainException('Vereinszuordnung geändert.');
            return compact('rows','total','pages','page');
        });
    }

    public function describe(string $id): array
    {
        $this->identifier($id);$this->schema();
        return Db::atomic($this->db,function()use($id):array{
            $club=$this->access->require('website','manage')['club_id'];$asset=$this->asset($id,$club);$state=$this->current($id,$club);
            $retained=$state['operation']===''?[]:Db::run($this->db,'SELECT retain_until,retained_bytes,(retain_until<=UTC_TIMESTAMP(6)) AS purge_eligible FROM khcms_media_removals WHERE club_id=? AND media_id=? AND operation=? FOR SHARE',[$club,$id,$state['operation']])->fetch();
            if($state['operation']!==''&&!$retained)throw new DomainException('Ursprünglichen Bildvorgang prüfen.');
            return array_intersect_key($asset,array_flip(['id','original_name','alt','mime','width','height','created_at']))+$state+$retained;
        });
    }

    /** A crashed uncommitted move has its original private plan but no invented database event. */
    public function pendingOperation(string $id): string
    {
        $this->identifier($id);$this->schema();
        return Db::atomic($this->db,function()use($id):string{
            $club=$this->access->require('website','manage')['club_id'];$asset=$this->asset($id,$club);$state=$this->current($id,$club);
            if(!in_array($state['state'],['active','restored'],true))return '';
            $root=realpath($this->mediaRoot);if(!$root)return '';$owner=$root.'/.removed/'.$id;
            if(!file_exists($owner)&&!is_link($owner))return '';
            if(is_link($owner)||realpath($owner)!==$owner||!is_dir($owner))throw new DomainException('Privaten Bildspeicher prüfen.');
            $count=0;$found='';$store=null;
            foreach(new \FilesystemIterator($owner,\FilesystemIterator::SKIP_DOTS)as$directory){
                if(++$count>1000)throw new DomainException('Bildvorgänge benötigen eine gezielte Speicherprüfung.');
                $operation=$directory->getFilename();if(!preg_match('/\A[a-f0-9]{32}\z/D',$operation))continue;
                if($directory->isLink()||!$directory->isDir())throw new DomainException('Privaten Bildvorgang prüfen.');
                if(!file_exists($directory->getPathname().'/files')&&!is_link($directory->getPathname().'/files'))continue;
                $store??=new MediaQuarantineStore($this->mediaRoot);$this->plan($store,$asset,$operation,false);
                if($found!=='')throw new DomainException('Mehrere Bildvorgänge benötigen eine gezielte Speicherprüfung.');$found=$operation;
            }
            return $found;
        });
    }

    /** Only a current media manager can view a verified private quarantine thumbnail. */
    public function thumbnail(string $id): array
    {
        $this->identifier($id);$this->schema();
        return Db::atomic($this->db,function()use($id):array{
            $club=$this->access->require('website','manage')['club_id'];$asset=$this->asset($id,$club);$state=$this->current($id,$club);
            if($state['state']!=='removed')throw new DomainException('Entferntes Bild nicht verfügbar.');
            $store=new MediaQuarantineStore($this->mediaRoot);$plan=$this->plan($store,$asset,$state['operation'],true);
            $extension=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'][$asset['mime']]??null;
            if(!$extension)throw new DomainException('Gespeichertes Bildformat prüfen.');
            $filename='thumb.'.$extension;$variants=json_decode($asset['variants_json'],true,64,JSON_THROW_ON_ERROR);$variant=$variants['thumb']??null;
            $root=realpath($this->mediaRoot);$path=$root.'/.removed/'.$id.'/'.$state['operation'].'/files/'.$filename;clearstatcache(true,$path);
            if(!$root||!is_array($variant)||($variant['file']??null)!==$filename||!isset($plan['files'][$filename])||is_link($path)||realpath($path)!==$path||!is_file($path)||filesize($path)!==$plan['files'][$filename]['bytes']||!hash_equals($plan['files'][$filename]['sha256'],hash_file('sha256',$path)))throw new DomainException('Ursprüngliches privates Vorschaubild prüfen.');
            if($this->access->require('website','manage')['club_id']!==$club)throw new DomainException('Vereinszuordnung geändert.');
            return ['path'=>$path,'mime'=>$asset['mime'],'width'=>$variant['width'],'height'=>$variant['height'],'bytes'=>$plan['files'][$filename]['bytes'],'sha256'=>$plan['files'][$filename]['sha256'],'name'=>$asset['original_name']];
        });
    }

    public function remove(string $id,int $version,string $operation): array
    {
        $this->arguments($id,$version,$operation);$this->schema();$prepared=false;
        try{
            return (new MediaRemovalGuard($this->db,$this->access))->run($id,function(array $asset)use($id,$version,$operation,&$prepared):array{
                $actor=$this->access->require('website','manage');$club=$actor['club_id'];$current=$this->current($id,$club,true);
                if($current['state']==='removed'&&$current['operation']===$operation&&$current['version']===$version+1){
                    $store=new MediaQuarantineStore($this->mediaRoot);$this->plan($store,$asset,$operation,true);$prepared=true;$store->move($id,$operation);return $current;
                }
                if($current['version']!==$version||!in_array($current['state'],['active','restored'],true))throw new Conflict('Bildzustand inzwischen geändert. Aktuellen Stand öffnen.');
                if(Db::run($this->db,'SELECT 1 FROM khcms_media_removals WHERE operation=? FOR SHARE',[$operation])->fetchColumn())throw new Conflict('Bildvorgang bereits verwendet. Aktuellen Stand öffnen.');
                $days=MediaRetention::current($this->db,$club)['minimum_days'];
                $store=new MediaQuarantineStore($this->mediaRoot);$plan=$store->prepare($asset,$operation);$prepared=true;
                Db::run($this->db,'INSERT INTO khcms_media_removals VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6),DATE_ADD(UTC_TIMESTAMP(6),INTERVAL ? DAY))',[$operation,$club,$id,$this->json($plan),array_sum(array_column($plan['files'],'bytes')),$actor['id'],$days]);
                $store->move($id,$operation);
                $this->change($id,$club,$version+1,'removed',$operation,$actor['id']);
                return $this->current($id,$club);
            });
        }catch(Throwable $error){
            $this->recoverAfterError($prepared,$id,$operation,$error);
        }
    }

    public function restore(string $id,int $version,string $operation): array
    {
        $this->arguments($id,$version,$operation);$this->schema();$prepared=false;
        try{
            return $this->transaction(function()use($id,$version,$operation,&$prepared):array{
                $actor=$this->access->require('website','manage');$club=$actor['club_id'];$asset=$this->asset($id,$club,true);$current=$this->current($id,$club,true);
                if($current['state']==='restored'&&$current['operation']===$operation&&$current['version']===$version+1){
                    $store=new MediaQuarantineStore($this->mediaRoot);$this->plan($store,$asset,$operation,true);$prepared=true;$store->restore($id,$operation);return $current;
                }
                if($current['state']!=='removed'||$current['operation']!==$operation||$current['version']!==$version)throw new Conflict('Bildzustand inzwischen geändert. Aktuellen Stand öffnen.');
                $store=new MediaQuarantineStore($this->mediaRoot);$this->plan($store,$asset,$operation,true);$prepared=true;
                $store->restore($id,$operation);
                $this->change($id,$club,$version+1,'restored',$operation,$actor['id']);
                if($this->access->require('website','manage')['club_id']!==$club)throw new DomainException('Vereinszuordnung geändert.');
                return $this->current($id,$club);
            });
        }catch(Throwable $error){
            $this->recoverAfterError($prepared,$id,$operation,$error);
        }
    }

    /** Commit the explicit irreversible decision before the first physical deletion. */
    public function beginPurge(string $id,int $version,string $operation): array
    {
        $this->arguments($id,$version,$operation);$this->schema();
        if($version>=4294967293)throw new InvalidArgumentException('Bildzustand benötigt eine gesonderte Versionsprüfung.');
        return (new MediaRemovalGuard($this->db,$this->access))->run($id,function(array $asset)use($id,$version,$operation):array{
            $actor=$this->access->require('website','manage');$club=$actor['club_id'];$current=$this->current($id,$club,true);
            if($current['operation']!==$operation)throw new Conflict('Bildzustand inzwischen geändert. Aktuellen Stand öffnen.');
            $store=new MediaQuarantineStore($this->mediaRoot);$this->plan($store,$asset,$operation,true);
            if($current['state']==='purged'&&$current['version']===$version+2){
                if($store->inspectPurge($id,$operation,true)['remaining']!==[])throw new DomainException('Abgeschlossenen Bildspeicher prüfen.');
                return $current;
            }
            $this->expired($id,$club,$operation);
            if($current['state']==='purging'&&$current['version']===$version+1){$store->inspectPurge($id,$operation,true);return $current;}
            if($current['state']!=='removed'||$current['version']!==$version)throw new Conflict('Bildzustand inzwischen geändert. Aktuellen Stand öffnen.');
            $store->inspectPurge($id,$operation);
            $this->change($id,$club,$version+1,'purging',$operation,$actor['id']);
            return $this->current($id,$club);
        });
    }

    /** Every continuation rechecks current rights, the original deadline and all global references. */
    public function finishPurge(string $id,int $version,string $operation): array
    {
        $this->arguments($id,$version,$operation);$this->schema();
        return (new MediaRemovalGuard($this->db,$this->access))->run($id,function(array $asset)use($id,$version,$operation):array{
            $actor=$this->access->require('website','manage');$club=$actor['club_id'];$current=$this->current($id,$club,true);
            if($current['operation']!==$operation)throw new Conflict('Bildzustand inzwischen geändert. Aktuellen Stand öffnen.');
            $store=new MediaQuarantineStore($this->mediaRoot);$this->plan($store,$asset,$operation,true);
            if($current['state']==='purged'&&$current['version']===$version+1){
                if($store->inspectPurge($id,$operation,true)['remaining']!==[])throw new DomainException('Abgeschlossenen Bildspeicher prüfen.');
                return $current;
            }
            if($current['state']!=='purging'||$current['version']!==$version)throw new Conflict('Bildzustand inzwischen geändert. Aktuellen Stand öffnen.');
            $this->expired($id,$club,$operation);
            try{$store->purge($id,$operation);}
            catch(\RuntimeException $error){throw new DomainException('Endgültiges Löschen unterbrochen. Aktuellen Stand öffnen.',0,$error);}
            $this->change($id,$club,$version+1,'purged',$operation,$actor['id']);
            return $this->current($id,$club);
        });
    }

    public function purge(string $id,int $version,string $operation): array
    {
        $state=$this->beginPurge($id,$version,$operation);
        return $state['state']==='purged'?$state:$this->finishPurge($id,$state['version'],$operation);
    }

    /** Explicit recovery reads the committed database truth, never guesses after a lost response. */
    public function recover(string $id,string $operation): array
    {
        $this->identifier($id);$this->identifier($operation);$this->schema();
        return $this->transaction(function()use($id,$operation):array{
            $club=$this->access->require('website','manage')['club_id'];$asset=$this->asset($id,$club,true);$current=$this->current($id,$club,true);
            $store=new MediaQuarantineStore($this->mediaRoot);$this->plan($store,$asset,$operation,false);
            if($current['state']==='removed'){
                if($current['operation']!==$operation)throw new Conflict('Ein späterer Bildvorgang ist aktiv. Diesen Stand öffnen.');
                $store->move($id,$operation);
            }elseif(in_array($current['state'],['active','restored'],true))$store->restore($id,$operation);
            else throw new DomainException('Bildvorgang benötigt eine gezielte Wiederaufnahme.');
            if($this->access->require('website','manage')['club_id']!==$club)throw new DomainException('Vereinszuordnung geändert.');
            return $current;
        });
    }

    private function recoverAfterError(bool $prepared,string $id,string $operation,Throwable $error): never
    {
        if($prepared){
            try{$this->recover($id,$operation);}
            catch(Throwable $recovery){throw new DomainException('Bildvorgang unterbrochen. Privaten Speicher prüfen.',0,$error);}
        }
        throw $error;
    }

    private function plan(MediaQuarantineStore $store,array $asset,string $operation,bool $required): array
    {
        $plan=$store->read($asset['id'],$operation);
        if(!hash_equals($plan['asset_sha256'],hash('sha256',$this->json($asset))))throw new DomainException('Ursprünglicher Bilddatensatz wurde verändert.');
        $json=Db::run($this->db,'SELECT plan_json FROM khcms_media_removals WHERE operation=? AND club_id=? AND media_id=? FOR SHARE',[$operation,$asset['club_id'],$asset['id']])->fetchColumn();
        if(($required&&$json===false)||($json!==false&&!hash_equals($json,$this->json($plan))))throw new DomainException('Ursprünglichen Bildvorgang prüfen.');
        return $plan;
    }

    private function asset(string $id,string $club,bool $write=false): array
    {
        $asset=Db::run($this->db,'SELECT * FROM khcms_media WHERE club_id=? AND id=?'.($write?' FOR UPDATE':' FOR SHARE'),[$club,$id])->fetch();
        if(!$asset)throw new DomainException('Bild nicht verfügbar.');
        return $asset;
    }

    private function current(string $id,string $club,bool $write=false): array
    {
        $row=Db::run($this->db,'SELECT version,state,operation,actor_id,changed_at FROM khcms_media_removal_states WHERE club_id=? AND media_id=?'.($write?' FOR UPDATE':' FOR SHARE'),[$club,$id])->fetch();
        if(!$row)return ['version'=>0,'state'=>'active','operation'=>'','actor_id'=>'','changed_at'=>''];
        if(!in_array($row['state'],['removed','restored','purging','purged'],true)||(int)$row['version']<1)throw new DomainException('Gespeicherten Bildzustand prüfen.');
        $row['version']=(int)$row['version'];return $row;
    }

    private function change(string $id,string $club,int $version,string $state,string $operation,string $actor): void
    {
        Db::run($this->db,'INSERT INTO khcms_media_removal_states VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE version=VALUES(version),state=VALUES(state),operation=VALUES(operation),actor_id=VALUES(actor_id),changed_at=VALUES(changed_at)',[$club,$id,$version,$state,$operation,$actor]);
        Db::run($this->db,'INSERT INTO khcms_media_removal_history SELECT club_id,media_id,version,state,operation,actor_id,changed_at FROM khcms_media_removal_states WHERE club_id=? AND media_id=?',[$club,$id]);
    }
    private function expired(string $id,string $club,string $operation): void
    {
        $eligible=Db::run($this->db,'SELECT retain_until<=UTC_TIMESTAMP(6) FROM khcms_media_removals WHERE club_id=? AND media_id=? AND operation=? FOR SHARE',[$club,$id,$operation])->fetchColumn();
        if($eligible===false)throw new DomainException('Ursprünglichen Bildvorgang prüfen.');
        if((int)$eligible!==1)throw new DomainException('Mindestaufbewahrung läuft noch.');
    }

    private function transaction(callable $change): mixed
    {
        if($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$this->db->inTransaction())throw new DomainException('Bildänderung benötigt einen eigenen gesicherten Vorgang.');
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');$this->db->beginTransaction();
        try{$result=$change();$this->db->commit();return $result;}
        catch(Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}
    }

    private function schema(): void
    {
        if((int)Db::run($this->db,"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('khcms_media_removals','khcms_media_removal_states','khcms_media_removal_history') AND TABLE_TYPE='BASE TABLE'")->fetchColumn()!==3)throw new DomainException('Bildentfernung benötigt das CMS-Update im Installer.');
    }
    private function identifier(string $id): void
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new InvalidArgumentException('Bild- und Vorgangskennung prüfen.');
    }
    private function arguments(string $id,int $version,string $operation): void
    {
        $this->identifier($id);$this->identifier($operation);
        if($version<0||$version>=4294967294)throw new InvalidArgumentException('Gespeicherten Bildzustand prüfen.');
    }
    private function json(array $value): string
    {
        return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
}
