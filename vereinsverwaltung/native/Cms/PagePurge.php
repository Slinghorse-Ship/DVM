<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use DomainException;
use InvalidArgumentException;
use Kulturhus\Native\Shared\{Access, Db};
use PDO;
use Throwable;

/** Irreversible content removal; retained business originals always take precedence. */
final class PagePurge
{
    use ClubPublicationLock;

    /** These rows belong exclusively to the removed original, rather than another business record. */
    private const OWNED = [
        'khcms_revisions', 'khcms_trash', 'khcms_archives', 'khcms_path_history',
        'khcms_published_media', 'khcms_event_publications',
        'native_language_pages','native_language_revisions','native_language_routes',
    ];
    private const LANGUAGE_OWNED=['native_language_pages'=>'locale','native_language_revisions'=>'locale,revision','native_language_routes'=>'locale,slug'];

    public function __construct(private PDO $db, private Access $access) {}

    /** Explicit installer/maintenance entry only; never called from a web request. */
    public static function install(PDO $db): void
    {
        foreach ([
            "CREATE TABLE IF NOT EXISTS khcms_page_tombstones (
                page_id CHAR(32) NOT NULL,club_id VARCHAR(64) NOT NULL,page_version INT UNSIGNED NOT NULL,
                draft_sha256 CHAR(64) NOT NULL,revisions_sha256 CHAR(64) NOT NULL,revision_count INT UNSIGNED NOT NULL,
                purged_by VARCHAR(64) NOT NULL,purged_at DATETIME(6) NOT NULL,
                PRIMARY KEY(page_id),KEY cms_purge_club(club_id,purged_at,page_id)
            )",
            "CREATE TABLE IF NOT EXISTS khcms_purged_paths (
                club_id VARCHAR(64) NOT NULL,slug VARCHAR(197) NOT NULL,page_id CHAR(32) NOT NULL,
                PRIMARY KEY(club_id,slug),KEY cms_purge_path_original(club_id,page_id),
                CONSTRAINT cms_purge_path_tombstone FOREIGN KEY(page_id) REFERENCES khcms_page_tombstones(page_id) ON DELETE RESTRICT
            )",
        ] as $sql) $db->exec($sql.' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    /** Deleted addresses cannot later identify an unrelated page. Schema is a maintenance prerequisite. */
    public static function assertPathAvailable(PDO $db, string $club, string $slug): void
    {
        if (Db::run($db, 'SELECT 1 FROM khcms_purged_paths WHERE club_id=? AND slug=?', [$club,$slug])->fetchColumn()) {
            throw new Conflict('Diese Adresse gehört zu einer endgültig gelöschten Seite. Eine neue Adresse wählen.');
        }
    }

    /** Same object permission for the real action and its optional irreversible form. No DDL. */
    public function assertRemovalRights(string $id):void
    {
        $actor=$this->access->require('website','publish');
        TemplatePolicy::assertCapability($this->db,$this->access,$id,'publish');
        \Kulturhus\Native\Comments\PageRemoval::assertRights($this->db,$this->access,$id);
        $tables=Db::run($this->db,"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('native_language_pages','native_language_revisions','native_language_routes')")->fetchAll(PDO::FETCH_COLUMN);
        foreach($tables as $table)if(Db::run($this->db,'SELECT 1 FROM `'.$table.'` WHERE club_id=? AND page_id=? LIMIT 1',[$actor['club_id'],$id])->fetchColumn()){$this->access->require('website','manage');return;}
    }

    /** A lost HTTP response can be retried with the same exact version, without a second change. */
    public function purge(string $id, int $expectedVersion, bool $confirmed, bool $commentsConfirmed=false): array
    {
        $this->access->require('website','publish');
        if (!$confirmed || !preg_match('/\A[a-f0-9]{32}\z/D',$id) || $expectedVersion<1 || $expectedVersion>4294967294) {
            throw new InvalidArgumentException('Endgültiges Löschen ausdrücklich für die gespeicherte Seitenfassung bestätigen.');
        }
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql' || $this->db->inTransaction()) {
            throw new DomainException('Endgültiges Löschen benötigt einen eigenen gesicherten Vorgang.');
        }
        // Aggregate reads below protect source rows and empty ranges, including writers in other modules.
        // Only this transaction changes isolation; normal editing and worker transactions keep theirs.
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        $this->db->beginTransaction();
        try {
            $actor=$this->access->require('website','publish');$club=$actor['club_id'];$this->lockClub();
            $old=Db::run($this->db,'SELECT page_version FROM khcms_page_tombstones WHERE page_id=? AND club_id=? FOR UPDATE',[$id,$club])->fetchColumn();
            if ($old!==false) {
                if ((int)$old!==$expectedVersion) throw new Conflict('Löschbestätigung gehört zu einer anderen Seitenfassung.');
                $this->db->commit();
                return ['id'=>$id,'version'=>$expectedVersion,'purged'=>true,'replayed'=>true];
            }
            $page=Db::run($this->db,'SELECT p.*,t.trashed_at FROM khcms_pages p LEFT JOIN khcms_trash t ON t.page_id=p.id AND t.club_id=p.club_id WHERE p.id=? AND p.club_id=? FOR UPDATE',[$id,$club])->fetch();
            if (!$page) throw new DomainException('Seite nicht verfügbar.');
            $this->assertRemovalRights($id);
            if ((int)$page['version']!==$expectedVersion) throw new Conflict('Seite wurde inzwischen geändert. Aktuellen Papierkorbstand neu laden.');
            if ($page['trashed_at']===null || $page['published_revision']!==null || $page['published_slug']!==null || $page['published_at']!==null) {
                throw new Conflict('Nur eine bereits zurückgezogene Seite im Papierkorb kann endgültig gelöscht werden.');
            }
            $available=array_fill_keys(Db::run($this->db,'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_COLUMN),true);
            $languageRows=[];$languageCount=0;
            foreach(self::LANGUAGE_OWNED as $table=>$order)if(isset($available[$table])){$languageRows[$table]=Db::run($this->db,'SELECT * FROM '.$table.' WHERE club_id=? AND page_id=? ORDER BY '.$order,[$club,$id])->fetchAll();$languageCount+=count($languageRows[$table]);}
            $paths=Db::run($this->db,'SELECT slug FROM khcms_path_history WHERE club_id=? AND page_id=? ORDER BY slug',[$club,$id])->fetchAll(PDO::FETCH_COLUMN);
            $translations=null;
            if($languageCount>0){$translations=new \Kulturhus\Native\Language\Translations($this->db,$this->access);$paths=[...$paths,...$translations->retainedPaths($id)];}
            $paths[]=$page['slug'];$paths=array_values(array_unique($paths));
            \Kulturhus\Native\Comments\PageRemoval::clear($this->db,$this->access,$id,$commentsConfirmed);
            $this->assertNoForeignBindings($id,$club);
            $this->assertUnreferenced($id,$club,$paths);
            $digest=hash_init('sha256');$count=0;
            $revisions=Db::run($this->db,'SELECT revision,payload_json,actor_id,action,created_at FROM khcms_revisions WHERE page_id=? AND club_id=? ORDER BY revision',[$id,$club]);
            while ($revision=$revisions->fetch()) {
                hash_update($digest,json_encode($revision,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n");++$count;
            }
            foreach($languageRows as $table=>$rows)foreach($rows as $row){hash_update($digest,$table."\0".json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n");if($table==='native_language_revisions')++$count;}
            Db::run($this->db,'INSERT INTO khcms_page_tombstones(page_id,club_id,page_version,draft_sha256,revisions_sha256,revision_count,purged_by,purged_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$id,$club,$expectedVersion,hash('sha256',$page['draft_json']),hash_final($digest),$count,$actor['id']]);
            foreach ($paths as $slug) Db::run($this->db,'INSERT INTO khcms_purged_paths(club_id,slug,page_id) VALUES(?,?,?)',[$club,$slug,$id]);
            // Original media, communication, schedules, tickets, payments and other pages are never deleted.
            // The latter sources must all have passed the current serializable dependency check above.
            if($translations!==null&&$translations->purgeForOriginal($id,$expectedVersion,true)!==$languageCount)throw new Conflict('Sprachoriginale sind nicht vollständig prüfbar. Vor dem Löschen die Spracheinrichtung prüfen.');
            foreach (self::OWNED as $table)if(isset($available[$table])&&!isset(self::LANGUAGE_OWNED[$table]))Db::run($this->db,'DELETE FROM `'.$table.'` WHERE page_id=? AND club_id=?',[$id,$club]);
            Db::run($this->db,'DELETE FROM khcms_pages WHERE id=? AND club_id=?',[$id,$club]);
            Db::run($this->db,'UPDATE khcms_state SET generation=generation+1 WHERE club_id=?',[$club]);
            if ($this->access->require('website','publish')['club_id']!==$club) throw new DomainException('Seitenzugang wurde geändert.');
            $this->db->commit();
            return ['id'=>$id,'version'=>$expectedVersion,'purged'=>true,'replayed'=>false];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    /** A future module's CASCADE is never permission to remove its original, even without club_id. */
    private function assertNoForeignBindings(string $id, string $club): void
    {
        $owned=['khcms_pages',...self::OWNED];
        $rows=Db::run($this->db,"SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IN (".implode(',',array_fill(0,count($owned),'?')).") ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION",$owned)->fetchAll();
        $edges=[];
        foreach ($rows as $row) {
            if (in_array($row['TABLE_NAME'],$owned,true)) continue;
            foreach (['TABLE_NAME','COLUMN_NAME','REFERENCED_TABLE_NAME','REFERENCED_COLUMN_NAME'] as $key) {
                if (!preg_match('/\A[a-z][a-z0-9_]*\z/D',$row[$key])) throw new DomainException('Geschäftsverknüpfungen konnten nicht vollständig geprüft werden.');
            }
            $key=$row['TABLE_NAME'].':'.$row['CONSTRAINT_NAME'];
            $edges[$key]['table']=$row['TABLE_NAME'];$edges[$key]['parent']=$row['REFERENCED_TABLE_NAME'];
            $edges[$key]['joins'][]='c.`'.$row['COLUMN_NAME'].'`=p.`'.$row['REFERENCED_COLUMN_NAME'].'`';
        }
        foreach ($edges as $edge) {
            $key=$edge['parent']==='khcms_pages'?'id':'page_id';
            if (Db::run($this->db,'SELECT 1 FROM `'.$edge['table'].'` c JOIN `'.$edge['parent'].'` p ON '.implode(' AND ',$edge['joins']).' WHERE p.`'.$key.'`=? AND p.club_id=? LIMIT 1',[$id,$club])->fetchColumn()) {
                throw new Conflict('Ein verknüpfter Geschäftsvorgang oder ein Originalnachweis benötigt diese Seite noch.');
            }
        }
    }

    /** Includes old revisions, trashed sources, JSON keys, jobs and module-specific business bindings. */
    private function assertUnreferenced(string $id, string $club, array $paths): void
    {
        $columns=Db::run($this->db,"SELECT c.TABLE_NAME,c.COLUMN_NAME,c.DATA_TYPE FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA=DATABASE() AND c.DATA_TYPE IN ('json','text','mediumtext','longtext','char','varchar') AND EXISTS(SELECT 1 FROM information_schema.COLUMNS o WHERE o.TABLE_SCHEMA=c.TABLE_SCHEMA AND o.TABLE_NAME=c.TABLE_NAME AND o.COLUMN_NAME='club_id') ORDER BY c.TABLE_NAME,c.ORDINAL_POSITION")->fetchAll();
        $tables=[];
        foreach ($columns as $column) {
            $table=$column['TABLE_NAME'];$name=$column['COLUMN_NAME'];
            if (!preg_match('/\A[a-z][a-z0-9_]*\z/D',$table) || !preg_match('/\A[a-z][a-z0-9_]*\z/D',$name)) throw new DomainException('Seitenverwendungen konnten nicht vollständig geprüft werden.');
            if (in_array($table,['khcms_page_tombstones','khcms_purged_paths'],true) || $name==='club_id') continue;
            $json=$column['DATA_TYPE']==='json' || str_ends_with($name,'_json') || in_array($name,['payload','snapshot','metadata','configuration','settings'],true);
            $tables[$table][]=[$name,$json];
        }
        // A link to /newsletters does not reference the different page /news.
        // Canonical JSON escapes HTML attribute quotes; the following backslash is a boundary too.
        $needles=[['needle'=>$id,'pattern'=>null]];
        foreach ($paths as $slug) $needles[]=['needle'=>'/'.$slug,'pattern'=>'/'.preg_quote($slug,'~').'(?:$|[?#"\'<>[:space:]]|'.preg_quote('\\','~').')'];
        $needles=json_encode($needles,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        foreach ($tables as $table=>$fields) {
            $matches=[];$invalid=[];$parameters=[];
            foreach ($fields as [$name,$json]) {
                $field='`'.$name.'`';
                $value=$json?'CASE WHEN '.$field.' IS NULL THEN NULL WHEN JSON_VALID('.$field.') THEN CAST(CAST('.$field.' AS JSON) AS CHAR CHARACTER SET utf8mb4) ELSE NULL END':$field;
                $matches[]="EXISTS(SELECT 1 FROM JSON_TABLE(?, '\$[*]' COLUMNS(needle VARCHAR(198) PATH '\$.needle',pattern VARCHAR(400) PATH '\$.pattern' NULL ON EMPTY)) n WHERE LOCATE(CAST(n.needle AS BINARY),CAST(($value) AS BINARY))>0 AND (n.pattern IS NULL OR REGEXP_LIKE(CONVERT(($value) USING utf8mb4) COLLATE utf8mb4_bin,CONVERT(n.pattern USING utf8mb4) COLLATE utf8mb4_bin,'c')))";
                $parameters[]=$needles;
                if ($json) $invalid[]='('.$field.' IS NOT NULL AND NOT JSON_VALID('.$field.'))';
            }
            $where='club_id=?';$parameters[]=$club;
            if ($table==='khcms_pages') {$where.=' AND id<>?';$parameters[]=$id;}
            elseif (in_array($table,self::OWNED,true)) {$where.=' AND page_id<>?';$parameters[]=$id;}
            $state=Db::run($this->db,'SELECT COALESCE(SUM('.implode(' OR ',$matches).'),0) AS referenced,COALESCE(SUM('.($invalid?implode(' OR ',$invalid):'0').'),0) AS unreadable FROM `'.$table.'` WHERE '.$where,$parameters)->fetch();
            if ((int)$state['unreadable']>0) throw new Conflict('Gespeicherte Verwendungen sind nicht vollständig lesbar. Originale zuerst prüfen.');
            if ((int)$state['referenced']>0) throw new Conflict('Andere Inhalte, Originalfassungen, Verknüpfungen oder Geschäftsvorgänge benötigen diese Seite noch.');
        }
    }
}
