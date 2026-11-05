<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use InvalidArgumentException;
use Kulturhus\Native\Shared\{Access,Db};
use PDO;

/** Checkpoint preparation separately; the complete private tree becomes visible in one transaction. */
final class TreeCopyJobs
{
    use ClubPublicationLock;
    public function __construct(private PDO $db,private Access $access) {}

    public static function install(PDO $db):void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS khcms_tree_copy_jobs (
            id CHAR(32) PRIMARY KEY,club_id VARCHAR(64) NOT NULL,root_source_id CHAR(32) NOT NULL,
            root_copy_id CHAR(32) NOT NULL,basis VARCHAR(12) NOT NULL,model_hash CHAR(64) NOT NULL,
            total INT UNSIGNED NOT NULL,next_index INT UNSIGNED NOT NULL DEFAULT 0,
            version INT UNSIGNED NOT NULL DEFAULT 1,status VARCHAR(12) NOT NULL,last_reason VARCHAR(500) NULL,
            created_by VARCHAR(64) NOT NULL,updated_by VARCHAR(64) NOT NULL,
            created_at DATETIME(6) NOT NULL,updated_at DATETIME(6) NOT NULL,
            KEY cms_copy_job_club(club_id,updated_at,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS khcms_tree_copy_items (
            job_id CHAR(32) NOT NULL,club_id VARCHAR(64) NOT NULL,item_index INT UNSIGNED NOT NULL,
            source_id CHAR(32) NOT NULL,source_version INT UNSIGNED NOT NULL,copy_id CHAR(32) NOT NULL,
            language_hash CHAR(64) NOT NULL,
            copy_title VARCHAR(200) NOT NULL,copy_slug VARCHAR(180) NOT NULL,
            source_json MEDIUMTEXT NOT NULL,prepared_json MEDIUMTEXT NULL,
            PRIMARY KEY(job_id,item_index),UNIQUE KEY cms_copy_job_source(job_id,source_id),
            UNIQUE KEY cms_copy_new_id(copy_id),KEY cms_copy_original(club_id,source_id),
            CONSTRAINT cms_copy_item_job FOREIGN KEY(job_id) REFERENCES khcms_tree_copy_jobs(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS khcms_tree_copy_removals (
            id CHAR(32) NOT NULL,club_id VARCHAR(64) NOT NULL,expected_version INT UNSIGNED NOT NULL,
            original_hash CHAR(64) NOT NULL,item_count INT UNSIGNED NOT NULL,
            removed_by VARCHAR(64) NOT NULL,removed_at DATETIME(6) NOT NULL,
            PRIMARY KEY(club_id,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function create(string $sourceId,int $version,array $options):array
    {
        $this->access->require('website','edit');
        $options=CopyInput::validate($sourceId,$version,$options);
        return Db::atomic($this->db,function()use($sourceId,$version,$options):array{
            $actor=$this->access->require('website','edit');$this->lockClub();
            $sources=(new TreeCopySources($this->db,$this->access))->read($sourceId,$version,$options['basis'],TreeCopySources::JOB_LIMIT);
            $root=(new DataModel($this->db,$this->access))->normalizePage(array_replace($sources[$sourceId]['payload'],['title'=>$options['title'],'slug'=>$options['slug']]),$sources[$sourceId]['payload'],true);
            if(strlen($root['slug'])>120)throw new InvalidArgumentException('Wurzeladresse für einen Teilbaum auf höchstens 120 Zeichen begrenzen.');
            $id=bin2hex(random_bytes(16));$rootCopy=bin2hex(random_bytes(16));$index=0;$slugs=[];$addresses=[];
            $translations=$this->translations();
            foreach($sources as $key=>$source){
                $slug=$key===$sourceId?$root['slug']:TreeCopyAddresses::child($root['slug'],$source);
                if(isset($slugs[$slug]))throw new Conflict('Kopieradressen sind nicht eindeutig. Andere Wurzeladresse wählen.');
                $slugs[$slug]=true;$addresses[$key]=$slug;
            }
            $this->assertAddresses(array_values($addresses),$actor['club_id']);
            Db::run($this->db,"INSERT INTO khcms_tree_copy_jobs(id,club_id,root_source_id,root_copy_id,basis,model_hash,total,status,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,'ready',?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",[$id,$actor['club_id'],$sourceId,$rootCopy,$options['basis'],$this->modelHash(),count($sources),$actor['id'],$actor['id']]);
            $batch=[];$batchBytes=0;
            foreach($sources as $key=>$source){
                $json=self::json($source['payload']);
                if($batch&&(count($batch)>=25||$batchBytes+strlen($json)>1024*1024)){$this->insertItems($batch);$batch=[];$batchBytes=0;}
                $languageHash=$translations?->sourceFingerprint($key,$options['basis'])??hash('sha256','[]');
                $batch[]=[$id,$actor['club_id'],$index++,$key,$source['version'],$key===$sourceId?$rootCopy:bin2hex(random_bytes(16)),$languageHash,$key===$sourceId?$root['title']:$source['payload']['title'],$addresses[$key],$json];$batchBytes+=strlen($json);
            }
            if($batch)$this->insertItems($batch);
            return $this->get($id);
        });
    }

    public function get(string $id):array
    {
        $this->access->require('website','read');self::id($id);
        $row=Db::run($this->db,'SELECT * FROM khcms_tree_copy_jobs WHERE id=? AND club_id=?',[$id,$this->access->club()])->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new \RuntimeException('Kopierauftrag nicht gefunden.');
        TemplatePolicy::assertCapability($this->db,$this->access,$row['root_source_id'],'read');
        foreach(['version','total','next_index']as $key)$row[$key]=(int)$row[$key];
        return $row;
    }

    public function advance(string $id,int $expected,int $limit=25):array
    {
        $this->access->require('website','edit');
        if($limit<1||$limit>25)throw new InvalidArgumentException('Je Schritt 1–25 Kopien vorbereiten.');
        return Db::atomic($this->db,function()use($id,$expected,$limit):array{
            $actor=$this->access->require('website','edit');$this->lockClub();$job=$this->current($id,$expected);
            try {
                // A savepoint also removes every new page if final validation fails halfway through.
                Db::atomic($this->db,function()use($job,$limit):void{
                    $this->assertModel($job);
                    $translations=$this->translations();
                    $items=Db::run($this->db,'SELECT * FROM khcms_tree_copy_items WHERE job_id=? AND club_id=? AND item_index>=? ORDER BY item_index LIMIT '.$limit,[$job['id'],$this->access->club(),$job['next_index']])->fetchAll(PDO::FETCH_ASSOC);
                    TemplatePolicy::assertAllReadable($this->db,$this->access,array_column($items,'source_id'));
                    foreach($items as $item){
                        $this->assertSource($item,$job['basis'],$translations);
                        $payload=$this->prepare($item);
                        Db::run($this->db,'UPDATE khcms_tree_copy_items SET prepared_json=? WHERE job_id=? AND item_index=?',[self::json($payload),$job['id'],$item['item_index']]);
                    }
                    if((int)Db::run($this->db,'SELECT COALESCE(SUM(OCTET_LENGTH(prepared_json)),0) FROM khcms_tree_copy_items WHERE job_id=?',[$job['id']])->fetchColumn()>TreeCopySources::BYTE_LIMIT)throw new InvalidArgumentException('Vorbereitete Kopien überschreiten 64 MiB Seiteninhalt. Einen kleineren Teilbaum wählen.');
                    $next=$job['next_index']+count($items);
                    // Completion remains an explicit, separate operation; a normal step never creates pages.
                    $this->checkpoint($job,$next,'ready',null);
                });
            }catch(Conflict|InvalidArgumentException $error){
                $this->checkpoint($job,$job['next_index'],'paused',mb_substr($error->getMessage(),0,500));
            }
            return $this->get($id);
        });
    }

    public function complete(string $id,int $expected,bool $confirmed):array
    {
        $this->access->require('website','edit');
        if(!$confirmed)throw new InvalidArgumentException('Vollständigen privaten Teilbaum ausdrücklich anlegen.');
        return Db::atomic($this->db,function()use($id,$expected):array{
            $this->access->require('website','edit');$this->lockClub();$job=$this->current($id,$expected);
            if($job['next_index']!==$job['total'])throw new Conflict('Zuerst alle Seiten des Kopierauftrags vorbereiten.');
            try{
                Db::atomic($this->db,function()use($job):void{
                    $actor=$this->access->require('website','edit');$this->assertModel($job);
                    $rows=Db::run($this->db,'SELECT source_id,copy_id,language_hash FROM khcms_tree_copy_items WHERE job_id=? AND club_id=? ORDER BY item_index',[$job['id'],$actor['club_id']])->fetchAll(PDO::FETCH_ASSOC);
                    TemplatePolicy::assertAllReadable($this->db,$this->access,array_column($rows,'source_id'));
                    $map=array_column($rows,'copy_id','source_id');
                    $translations=$this->translations();
                    // All inserted rows stay invisible to other sessions until the final checkpoint commits.
                    for($offset=0;$offset<$job['total'];$offset+=25){
                        foreach($this->items($job['id'],$offset)as $item){
                            $this->assertSource($item,$job['basis'],$translations);
                            if(!is_string($item['prepared_json']))throw new Conflict('Kopiervorbereitung ist unvollständig.');
                            $payload=$this->prepare($item);
                            if(self::json($payload)!==$item['prepared_json'])throw new Conflict('Ein verknüpftes Original wurde geändert. Kopierauftrag abbrechen und mit dem aktuellen Stand neu vorbereiten.');
                            $payload=self::remap($payload,$map);
                            $json=self::json($payload);
                            PathHistory::assertAvailable($this->db,$actor['club_id'],$item['copy_id'],$payload['slug']);
                            if(Db::run($this->db,'SELECT 1 FROM khcms_pages WHERE club_id=? AND slug=?',[$actor['club_id'],$payload['slug']])->fetchColumn())throw new Conflict('Eine Zieladresse wurde inzwischen vergeben. Auftrag abbrechen und eine andere Wurzeladresse wählen.');
                            Db::run($this->db,'INSERT INTO khcms_pages(id,club_id,slug,version,draft_json,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,1,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))',[$item['copy_id'],$actor['club_id'],$payload['slug'],$json,$actor['id'],$actor['id']]);
                            Db::run($this->db,"INSERT INTO khcms_revisions(page_id,revision,club_id,payload_json,actor_id,action,created_at) VALUES(?,1,?,?,?,'tree-copy',UTC_TIMESTAMP(6))",[$item['copy_id'],$actor['club_id'],$json,$actor['id']]);
                            TemplatePolicy::noteCopy($this->db,$this->access,$payload);
                        }
                    }
                    // The ordinary page service performs all field, parent, media, document and model checks.
                    $pages=new Pages($this->db,$this->access);
                    foreach($rows as $row){$copy=$pages->get($row['copy_id']);$pages->save($copy,1);if($translations!==null&&$row['language_hash']!==hash('sha256','[]'))$translations->cloneDrafts($row['source_id'],$row['copy_id'],$job['basis']);}
                    $this->checkpoint($job,$job['total'],'completed',null);
                });
            }catch(Conflict|InvalidArgumentException $error){$this->checkpoint($job,$job['next_index'],'paused',mb_substr($error->getMessage(),0,500));}
            return $this->get($id);
        });
    }

    public function cancel(string $id,int $expected):array
    {
        $this->access->require('website','edit');
        return Db::atomic($this->db,function()use($id,$expected):array{$this->access->require('website','edit');$this->lockClub();$job=$this->current($id,$expected);$this->checkpoint($job,$job['next_index'],'cancelled',null);return $this->get($id);});
    }

    /** Explicitly discard only a closed job's own retained preparation, never any source or target page. */
    public function forget(string $id,int $expected,bool $confirmed):array
    {
        $this->access->require('website','manage');
        if(!$confirmed||!preg_match('/\A[a-f0-9]{32}\z/D',$id)||$expected<1)throw new InvalidArgumentException('Auftragsdaten und genauen Versionsstand ausdrücklich zur Entfernung bestätigen.');
        return Db::atomic($this->db,function()use($id,$expected):array{
            $actor=$this->access->require('website','manage');$this->lockClub();
            $removed=Db::run($this->db,'SELECT expected_version,item_count,original_hash FROM khcms_tree_copy_removals WHERE club_id=? AND id=?',[$actor['club_id'],$id])->fetch(PDO::FETCH_ASSOC);
            if($removed){if((int)$removed['expected_version']!==$expected)throw new Conflict('Auftragsdaten wurden mit einem anderen bestätigten Stand entfernt.');return ['id'=>$id,'removed'=>true,'replayed'=>true,'items'=>(int)$removed['item_count'],'original_hash'=>$removed['original_hash']];}
            $job=$this->get($id);
            if($job['version']!==$expected)throw new Conflict('Kopierauftrag wurde inzwischen geändert. Aktuellen Stand prüfen.');
            if(!in_array($job['status'],['completed','cancelled'],true))throw new Conflict('Zuerst den Kopierauftrag vollständig abschließen oder abbrechen.');
            // Unknown future CASCADE relations must never make cleanup erase another module's original.
            $foreign=Db::run($this->db,"SELECT k.TABLE_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE k WHERE k.REFERENCED_TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME IN ('khcms_tree_copy_jobs','khcms_tree_copy_items') AND NOT (k.TABLE_NAME='khcms_tree_copy_items' AND k.COLUMN_NAME='job_id' AND k.REFERENCED_TABLE_NAME='khcms_tree_copy_jobs')")->fetchAll(PDO::FETCH_ASSOC);
            if($foreign)throw new Conflict('Eine zusätzliche Datenbankbeziehung verwendet Kopieraufträge. Auftragsdaten bleiben erhalten.');
            $header=Db::run($this->db,'SELECT * FROM khcms_tree_copy_jobs WHERE id=? AND club_id=?',[$id,$actor['club_id']])->fetch(PDO::FETCH_ASSOC);
            $hash=hash_init('sha256');hash_update($hash,self::json($header));$count=0;
            for($offset=0;$offset<$job['total'];$offset+=25)foreach($this->items($id,$offset)as$item){hash_update($hash,self::json($item));++$count;}
            if($count!==$job['total'])throw new Conflict('Gespeicherte Auftragsdaten sind unvollständig. Nichts wurde entfernt.');
            $fingerprint=hash_final($hash);
            Db::run($this->db,'INSERT INTO khcms_tree_copy_removals(id,club_id,expected_version,original_hash,item_count,removed_by,removed_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$id,$actor['club_id'],$expected,$fingerprint,$count,$actor['id']]);
            if(Db::run($this->db,'DELETE FROM khcms_tree_copy_items WHERE job_id=? AND club_id=?',[$id,$actor['club_id']])->rowCount()!==$count||Db::run($this->db,'DELETE FROM khcms_tree_copy_jobs WHERE id=? AND club_id=? AND version=?',[$id,$actor['club_id'],$expected])->rowCount()!==1)throw new Conflict('Auftragsdaten wurden gleichzeitig geändert. Entfernung erneut prüfen.');
            return ['id'=>$id,'removed'=>true,'replayed'=>false,'items'=>$count,'original_hash'=>$fingerprint];
        });
    }

    public function recent():array
    {
        $this->access->require('website','read');
        [$policy,$parameters]=TemplatePolicy::readFilter($this->db,$this->access);
        return Db::run($this->db,'SELECT j.id,j.status,j.total,j.next_index,j.root_copy_id FROM khcms_tree_copy_jobs j JOIN khcms_pages p ON p.id=j.root_source_id AND p.club_id=j.club_id WHERE ('.$policy.') ORDER BY j.updated_at DESC,j.id LIMIT 25',$parameters)->fetchAll(PDO::FETCH_ASSOC);
    }

    private function current(string $id,int $expected):array
    {
        $job=$this->get($id);
        if($expected<1||$expected!==$job['version'])throw new Conflict('Kopierauftrag wurde inzwischen fortgesetzt. Fortschritt neu laden.');
        if(!in_array($job['status'],['ready','paused'],true))throw new Conflict('Kopierauftrag ist bereits abgeschlossen oder abgebrochen.');
        return $job;
    }
    private function items(string $id,int $offset):array{return Db::run($this->db,'SELECT * FROM khcms_tree_copy_items WHERE job_id=? AND club_id=? AND item_index>=? ORDER BY item_index LIMIT 25',[$id,$this->access->club(),$offset])->fetchAll(PDO::FETCH_ASSOC);}
    private function insertItems(array $items):void{Db::run($this->db,'INSERT INTO khcms_tree_copy_items(job_id,club_id,item_index,source_id,source_version,copy_id,language_hash,copy_title,copy_slug,source_json) VALUES '.implode(',',array_fill(0,count($items),'(?,?,?,?,?,?,?,?,?,?)')),array_merge(...$items));}
    private function assertAddresses(array $addresses,string $club):void
    {
        foreach(array_chunk($addresses,200)as $group){
            $placeholders=implode(',',array_fill(0,count($group),'?'));
            $sql='SELECT 1 FROM khcms_pages WHERE club_id=? AND (slug IN ('.$placeholders.') OR published_slug IN ('.$placeholders.'))';
            $parameters=[$club,...$group,...$group];
            foreach(['khcms_path_history','khcms_events','khcms_purged_paths']as $table){$sql.=' UNION ALL SELECT 1 FROM '.$table.' WHERE club_id=? AND slug IN ('.$placeholders.')';array_push($parameters,$club,...$group);}
            if(Db::run($this->db,$sql.' LIMIT 1',$parameters)->fetchColumn())throw new Conflict('Eine Zieladresse ist bereits vergeben oder für ein Original reserviert. Andere Wurzeladresse wählen.');
        }
    }
    private function assertSource(array $item,string $basis,?\Kulturhus\Native\Language\Translations $translations):void
    {
        $current=Db::run($this->db,'SELECT p.version FROM khcms_pages p WHERE p.id=? AND p.club_id=? AND '.PageTrash::activeSql(),[$item['source_id'],$this->access->club()])->fetchColumn();
        if($current===false||(int)$current!==(int)$item['source_version'])throw new Conflict('Eine Originalseite wurde inzwischen geändert. Kopierauftrag abbrechen und den aktuellen Teilbaum neu vorbereiten.');
        $source=json_decode($item['source_json'],true,512,JSON_THROW_ON_ERROR);if(!empty($source['field_definitions']))FieldPolicy::assertCopy($this->db,$this->access,$source);
        if(!hash_equals($item['language_hash'],$translations?->sourceFingerprint($item['source_id'],$basis)??hash('sha256','[]')))throw new Conflict('Eine Übersetzung wurde inzwischen geändert. Kopierauftrag abbrechen und den aktuellen Teilbaum neu vorbereiten.');
    }
    private function prepare(array $item):array
    {
        $model=new DataModel($this->db,$this->access);
        $payload=$model->publication(json_decode($item['source_json'],true,512,JSON_THROW_ON_ERROR));
        $payload['title']=$item['copy_title'];$payload['slug']=$item['copy_slug'];$payload['nav_visible']=false;$payload['nav_label']='';$payload['nav_order']=0;
        $payload=$model->normalizePage($payload,$payload,true);
        if(array_key_exists('document_links',$payload))$payload['document_links']=WebsiteDocuments::capture($this->db,$this->access->club(),$payload['document_links']);
        (new PageReferences($this->db,$this->access))->assertAvailable($item['copy_id'],$payload);
        MediaAvailability::assertContentSelectable($this->db,$this->access->club(),$payload);
        $metadata=MediaMetadata::capture($this->db,$this->access->club(),$payload);
        if($metadata)$payload['media_metadata']=$metadata;
        return NewsReview::afterEdit($payload);
    }
    private static function remap(array $payload,array $map):array
    {
        if(isset($map[$payload['parent_id']??'']))$payload['parent_id']=$map[$payload['parent_id']];
        return FieldValues::remapPages($payload,$map);
    }
    private function modelHash():string{return hash('sha256',self::json(Db::run($this->db,'SELECT kind,definition_key,version,definition_json FROM khcms_definitions WHERE club_id=? ORDER BY kind,definition_key',[$this->access->club()])->fetchAll(PDO::FETCH_ASSOC)));}
    private function translations():?\Kulturhus\Native\Language\Translations{return(new \Kulturhus\Native\Language\Settings($this->db,$this->access))->state()['version']===0?null:new \Kulturhus\Native\Language\Translations($this->db,$this->access);}
    private function assertModel(array $job):void{if(!hash_equals($job['model_hash'],$this->modelHash()))throw new Conflict('Das Inhaltsmodell wurde inzwischen geändert. Kopierauftrag abbrechen und die Originale neu prüfen.');}
    private function checkpoint(array $job,int $next,string $status,?string $reason):void{$actor=$this->access->require('website','edit');Db::run($this->db,'UPDATE khcms_tree_copy_jobs SET next_index=?,status=?,last_reason=?,version=version+1,updated_by=?,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND club_id=?',[$next,$status,$reason,$actor['id'],$job['id'],$actor['club_id']]);}
    private static function id(string $id):void{if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new InvalidArgumentException('Gültige Kopierauftragskennung angeben.');}
    private static function json(array $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
}
