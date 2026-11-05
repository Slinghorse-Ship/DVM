<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use RuntimeException;

/** Operator-only installation; never remove the old guard before its replacement exists. */
final class DocumentPurgeSchema
{
    public static function install(PDO $db):void
    {
        if($db->inTransaction())throw new RuntimeException('Dokumentschutz benötigt eine eigene Wartungsverbindung.');
        $db->exec((string)file_get_contents(dirname(__DIR__, 1).'/../migrations/mysql/105_document_purge.sql'));
        $unused="d.origin='upload' AND d.kind='receipt' AND d.admission_only=0 AND NOT EXISTS(SELECT 1 FROM document_links l WHERE l.club_id=d.club_id AND l.document_id=d.id)";
        $history="NOT EXISTS(SELECT 1 FROM document_history h WHERE h.club_id=d.club_id AND h.document_id=d.id AND (h.action='expense-linked' OR NOT JSON_VALID(h.details) OR COALESCE(JSON_LENGTH(JSON_EXTRACT(IF(JSON_VALID(h.details),h.details,'{}'),'$.links')),0)>0 OR COALESCE(JSON_LENGTH(JSON_EXTRACT(IF(JSON_VALID(h.details),h.details,'{}'),'$.before.links')),0)>0 OR COALESCE(JSON_LENGTH(JSON_EXTRACT(IF(JSON_VALID(h.details),h.details,'{}'),'$.after.links')),0)>0))";
        self::guard($db,'document_purge_request_insert','INSERT','document_purge_requests',"BEGIN IF NEW.connection_id<>CONNECTION_ID() OR NOT EXISTS(SELECT 1 FROM documents d WHERE d.club_id=NEW.club_id AND d.id=NEW.document_id AND d.version=NEW.document_version AND $unused AND $history) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Only unused intake mistakes may be purged'; END IF; SET NEW.created_at=CURRENT_TIMESTAMP; END");
        self::guard($db,'document_purge_request_update','UPDATE','document_purge_requests',"BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Purge permission cannot be changed'; END");
        self::guard($db,'document_purge_mail_insert','INSERT','document_purge_mail_requests',"BEGIN IF NOT EXISTS(SELECT 1 FROM document_purge_requests p JOIN invoice_mail_original_links l ON l.club_id=p.club_id AND l.document_id=p.document_id WHERE p.club_id=NEW.club_id AND p.document_id=NEW.document_id AND p.connection_id=CONNECTION_ID() AND l.mailbox_key=NEW.mailbox_key AND l.message_uid=NEW.message_uid) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Original message is not part of this purge'; END IF; END");
        self::guard($db,'document_purge_mail_update','UPDATE','document_purge_mail_requests',"BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Purge permission cannot be changed'; END");
        $permission="SELECT 1 FROM document_purge_requests p JOIN documents d ON d.club_id=p.club_id AND d.id=p.document_id AND d.version=p.document_version WHERE p.connection_id=CONNECTION_ID() AND p.created_at>=CURRENT_TIMESTAMP-INTERVAL 5 MINUTE AND $unused";
        $legacy=require dirname(__DIR__, 1).'/../migrations/mysql/085_native_guards.php';
        foreach(['einvoice_validations','invoice_mail_original_links','document_files','document_history','invoice_mail_originals'] as $table){
            $match=$table==='invoice_mail_originals'
                ? "p.club_id=OLD.club_id AND EXISTS(SELECT 1 FROM document_purge_mail_requests m WHERE m.club_id=p.club_id AND m.document_id=p.document_id AND m.mailbox_key=OLD.mailbox_key AND m.message_uid=OLD.message_uid) AND NOT EXISTS(SELECT 1 FROM invoice_mail_original_links l WHERE l.club_id=OLD.club_id AND l.mailbox_key=OLD.mailbox_key AND l.message_uid=OLD.message_uid)"
                : 'p.club_id=OLD.club_id AND p.document_id=OLD.document_id';
            $body="BEGIN IF NOT EXISTS($permission AND $match) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Original remains protected without scoped purge permission'; END IF; END";
            self::guard($db,$table.'_purge_guard','DELETE',$table,$body);
            // The new BEFORE DELETE guard already protects every row throughout this DDL.
            $name=$table.'_no_delete';$old=self::existing($db,$name);
            if($old){
                $expected=substr($legacy[$name],strpos($legacy[$name],'BEGIN'));
                if($old!==['ACTION_TIMING'=>'BEFORE','EVENT_MANIPULATION'=>'DELETE','EVENT_OBJECT_TABLE'=>$table,'ACTION_STATEMENT'=>$expected])throw new RuntimeException('Unerwarteten Originalschutz zuerst prüfen: '.$name);
                $db->exec('DROP TRIGGER `'.$name.'`');
            }
        }
    }
    private static function existing(PDO $db,string $name):array|false
    {
        $q=$db->prepare('SELECT ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');$q->execute([$name]);return $q->fetch(PDO::FETCH_ASSOC);
    }
    private static function guard(PDO $db,string $name,string $operation,string $table,string $body):void
    {
        $old=self::existing($db,$name);
        if(!$old)$db->exec('CREATE TRIGGER `'.$name.'` BEFORE '.$operation.' ON '.$table.' FOR EACH ROW '.$body);
        elseif($old!==['ACTION_TIMING'=>'BEFORE','EVENT_MANIPULATION'=>$operation,'EVENT_OBJECT_TABLE'=>$table,'ACTION_STATEMENT'=>$body])throw new RuntimeException('Unerwarteten Löschschutz zuerst prüfen: '.$name);
    }
}
