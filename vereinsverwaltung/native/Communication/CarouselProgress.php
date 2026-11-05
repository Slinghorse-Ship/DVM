<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;

/** A phase is committed with the worker's pending state, after its authority transaction ends. */
final class CarouselProgress extends \RuntimeException
{
    public readonly string $stage;
    public function __construct(public readonly string $reference,public readonly int $slot,public readonly ?string $remote)
    {
        if($slot<0||$slot>11||($remote!==null&&!preg_match('/\A[0-9_]{1,100}\z/D',$remote)))throw new DomainException('Karussell-Fortschritt prüfen.');
        $this->stage=$remote===null?'carousel_phase_prepared':'carousel_phase_saved';
        parent::__construct('Anbieter verarbeitet den Auftrag. Bestätigung steht aus.');
    }

    public static function installed(PDO $db):bool
    {
        return (int)Db::run($db,"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('native_carousel_progress','native_carousel_capabilities') AND TABLE_TYPE='BASE TABLE'")->fetchColumn()===2;
    }

    public static function install(PDO $db):void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS native_carousel_progress (
            job_id CHAR(32) NOT NULL, club_id VARCHAR(64) NOT NULL, slot TINYINT UNSIGNED NOT NULL,
            frame_hash CHAR(64) NOT NULL, state VARCHAR(12) NOT NULL, remote_id VARCHAR(100) NULL,
            created_at DATETIME(6) NOT NULL, completed_at DATETIME(6) NULL,
            PRIMARY KEY(job_id,slot), INDEX(club_id,job_id),
            FOREIGN KEY(job_id) REFERENCES native_delivery_jobs(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS native_carousel_capabilities (
            token_hash CHAR(64) PRIMARY KEY, club_id VARCHAR(64) NOT NULL, job_id CHAR(32) NOT NULL,
            image_index TINYINT UNSIGNED NOT NULL, connection_version INT UNSIGNED NOT NULL,
            FOREIGN KEY(token_hash) REFERENCES native_image_capabilities(token_hash),
            FOREIGN KEY(job_id) REFERENCES native_delivery_jobs(id), INDEX(club_id,job_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
    }

    public function persist(PDO $db,array $job,array $frame):void
    {
        if(!$db->inTransaction()||!self::installed($db)||($frame['kind']??null)!=='carousel')throw new DomainException('Karussell-Fortschritt benötigt einen bestätigten Worker-Vorgang.');
        $current=Db::run($db,"SELECT status,attempts,club_id,kind FROM native_delivery_jobs WHERE id=? FOR UPDATE",[$job['id']])->fetch();
        if(!$current||$current['club_id']!==$job['club_id']||$current['kind']!=='meta'||$current['status']!=='processing'||(int)$current['attempts']!==(int)$job['attempts']+1)throw new DomainException('Karussell-Auftrag wird bereits anderweitig geprüft.');
        $hash=CarouselPublication::fingerprint($frame);
        $old=Db::run($db,'SELECT * FROM native_carousel_progress WHERE job_id=? AND slot=? FOR UPDATE',[$job['id'],$this->slot])->fetch();
        if($old&&($old['club_id']!==$job['club_id']||$old['frame_hash']!==$hash))throw new DomainException('Ursprünglicher Karussell-Fortschritt geändert.');
        if($this->remote===null){
            if($old)throw new DomainException('Karussell-Schritt bereits vorbereitet.');
            Db::run($db,"INSERT INTO native_carousel_progress VALUES(?,?,?,?, 'prepared',NULL,UTC_TIMESTAMP(6),NULL)",[$job['id'],$job['club_id'],$this->slot,$hash]);
            return;
        }
        if(!$old||$old['state']!=='prepared'||$old['remote_id']!==null)throw new DomainException('Genau den ursprünglichen Karussell-Schritt bestätigen.');
        Db::run($db,"UPDATE native_carousel_progress SET state='known',remote_id=?,completed_at=UTC_TIMESTAMP(6) WHERE job_id=? AND slot=? AND state='prepared' AND remote_id IS NULL",[$this->remote,$job['id'],$this->slot]);
    }

    public static function rows(PDO $db,string $club,string $job,array $frame):array
    {
        if(!self::installed($db))throw new DomainException('Karussells über den Installer aktivieren.');
        Content::id($job);$rows=Db::run($db,'SELECT slot,frame_hash,state,remote_id,created_at FROM native_carousel_progress WHERE club_id=? AND job_id=? ORDER BY slot'.($db->inTransaction()?' FOR SHARE':''),[$club,$job])->fetchAll();
        $out=[];$hash=CarouselPublication::fingerprint($frame);
        foreach($rows as$row){$slot=(int)$row['slot'];if($slot<0||$slot>11||$row['frame_hash']!==$hash||!in_array($row['state'],['prepared','known'],true)||($row['state']==='prepared'&&$row['remote_id']!==null)||($row['state']==='known'&&(!is_string($row['remote_id'])||!preg_match('/\A[0-9_]{1,100}\z/D',$row['remote_id']))))throw new DomainException('Ursprünglichen Karussell-Fortschritt prüfen.');$out[$slot]=$row;}
        return $out;
    }
}
