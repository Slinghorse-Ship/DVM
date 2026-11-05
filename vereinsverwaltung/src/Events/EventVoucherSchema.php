<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use RuntimeException;

/** Additive component migration, usable without unrelated accounting upgrades. */
final class EventVoucherSchema
{
    public const TABLES=['event_voucher_events','event_vouchers','event_voucher_moves','event_voucher_helpers','event_voucher_requests','event_voucher_outbox','event_voucher_tickets','event_voucher_receipts'];
    public static function install(PDO $db):void
    {
        if(!Database::isMysql($db)||$db->inTransaction())throw new RuntimeException('Eigene MySQL-Migrationsverbindung erforderlich.');
        $file=dirname(__DIR__, 1).'/../migrations/mysql/095_event_vouchers.sql';$oldHash=hash_file('sha256',$file);
        $tickets=dirname(__DIR__, 1).'/../migrations/mysql/096_event_voucher_tickets.sql';$ticketHash=hash('sha256',$oldHash.hash_file('sha256',$tickets));$receipts=dirname(__DIR__,1).'/../migrations/mysql/116_event_voucher_receipts.sql';$hash=hash('sha256',$ticketHash.hash_file('sha256',$receipts));
        $lock='verein-schema-'.substr(hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn()),0,48);
        $q=$db->prepare('SELECT GET_LOCK(?,10)');$q->execute([$lock]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('Datenbankwartung läuft.');
        $writer='verein-write-'.substr(hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn()),0,48);$writerHeld=false;
        try{
            $q=$db->prepare('SELECT GET_LOCK(?,10)');$q->execute([$writer]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('Ein Speichervorgang läuft.');$writerHeld=true;
            if(Database::tableExists($db,'event_voucher_schema')){
                $row=$db->query('SELECT version,source_sha256 FROM event_voucher_schema ORDER BY version DESC LIMIT 1')->fetch();
                if($row){
                    if((int)$row['version']===3&&hash_equals($row['source_sha256'],$hash)){foreach(self::TABLES as $table)if(!Database::tableExists($db,$table))throw new RuntimeException('Gutscheinschema unvollständig.');return;}
                    if(!(((int)$row['version']===1&&hash_equals($row['source_sha256'],$oldHash))||((int)$row['version']===2&&hash_equals($row['source_sha256'],$ticketHash))))throw new RuntimeException('Gutscheinschema gesondert prüfen.');
                }
            }
            $db->exec((string)file_get_contents($file));
            $db->exec('CREATE TABLE IF NOT EXISTS event_voucher_schema(version INT NOT NULL PRIMARY KEY,source_sha256 CHAR(64) NOT NULL,installed_at VARCHAR(128) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $db->exec((string)file_get_contents($tickets));
            $db->exec((string)file_get_contents($receipts));
            require_once __DIR__.'/EventVoucherTickets.php';
            $db->beginTransaction();
            try{
                foreach($db->query("SELECT v.* FROM event_vouchers v WHERE v.unit='piece' AND NOT EXISTS(SELECT 1 FROM event_voucher_tickets t WHERE t.club_id=v.club_id AND t.voucher_id=v.id) FOR UPDATE")->fetchAll() as $v)EventVoucherTickets::create($db,$v);
                $db->prepare('INSERT INTO event_voucher_schema(version,source_sha256,installed_at) VALUES(3,?,?)')->execute([$hash,gmdate('c')]);$db->commit();
            }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        }finally{if($writerHeld){$q=$db->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$writer]);}$q=$db->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}
    }
}
