<?php declare(strict_types=1);
namespace Kulturhus\Native\Shared;
use PDO;
final class RuntimeSchema
{
    public static function install(PDO $db): void
    {
        \Kulturhus\Native\Communication\Mail\MailboxSchema::install($db);
        require_once dirname(__DIR__,2).'/src/autoload.php';
        \Kulturhus\Verein\Finance\Invoices\Reminder\ReminderSchema::install($db);
        $db->exec("CREATE TABLE IF NOT EXISTS native_social_moderations (
            club_id VARCHAR(64) NOT NULL,id CHAR(32) NOT NULL,attention_id CHAR(32) NOT NULL,
            actor_id VARCHAR(64) NOT NULL,connection_id VARCHAR(64) NOT NULL,request_hash CHAR(64) NOT NULL,
            ciphertext MEDIUMTEXT NOT NULL,desired_hidden TINYINT NOT NULL,before_hidden TINYINT NULL,
            status VARCHAR(24) NOT NULL,version INT UNSIGNED NOT NULL,checked_by VARCHAR(64) NULL,
            created_at DATETIME(6) NOT NULL,updated_at DATETIME(6) NOT NULL,confirmed_at DATETIME(6) NULL,
            PRIMARY KEY(club_id,id),INDEX(club_id,attention_id,status),INDEX(club_id,attention_id,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        // Human send intentions are independent of editable drafts and inbound email commands.
        foreach (
            [
                "CREATE TABLE IF NOT EXISTS native_social_mail_threads(club_id VARCHAR(64) NOT NULL,id CHAR(32) NOT NULL,attention_id CHAR(32) NOT NULL,kind VARCHAR(16) NOT NULL,channel VARCHAR(16) NOT NULL,parent_id VARCHAR(200) NOT NULL,source_fingerprint CHAR(64) NOT NULL,connection_id VARCHAR(64) NOT NULL,binding CHAR(32) NOT NULL,message_hash CHAR(64) NOT NULL,status VARCHAR(24) NOT NULL,version INT NOT NULL,ciphertext MEDIUMTEXT NOT NULL,created_by VARCHAR(64) NOT NULL,sent_by VARCHAR(64) NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,PRIMARY KEY(club_id,id),UNIQUE(club_id,attention_id,source_fingerprint,binding),UNIQUE(club_id,message_hash),INDEX(club_id,kind,channel,parent_id,updated_at))",
                "CREATE TABLE IF NOT EXISTS native_social_mail_replies(club_id VARCHAR(64) NOT NULL,id CHAR(32) NOT NULL,thread_id CHAR(32) NOT NULL,message_hash CHAR(64) NOT NULL,ciphertext MEDIUMTEXT NOT NULL,created_at DATETIME NOT NULL,PRIMARY KEY(club_id,id),UNIQUE(club_id,message_hash),INDEX(club_id,thread_id,created_at),FOREIGN KEY(club_id,thread_id) REFERENCES native_social_mail_threads(club_id,id))",
                "CREATE TABLE IF NOT EXISTS native_social_mail_cursors(club_id VARCHAR(64) NOT NULL,binding CHAR(32) NOT NULL,uidvalidity BIGINT UNSIGNED NOT NULL,last_uid BIGINT UNSIGNED NOT NULL,updated_at DATETIME NOT NULL,PRIMARY KEY(club_id,binding))",
                "CREATE TABLE IF NOT EXISTS native_social_mail_dispatch(club_id VARCHAR(64) NOT NULL,mail_id CHAR(32) NOT NULL,reply_id CHAR(32) NULL,state VARCHAR(24) NOT NULL,reason VARCHAR(64) NULL,created_at DATETIME(6) NOT NULL,updated_at DATETIME(6) NOT NULL,PRIMARY KEY(club_id,mail_id),UNIQUE(club_id,reply_id),INDEX(club_id,state,created_at),FOREIGN KEY(club_id,mail_id) REFERENCES native_social_mail_replies(club_id,id))",
                "CREATE TABLE IF NOT EXISTS native_social_replies(club_id VARCHAR(64) NOT NULL,id CHAR(32) NOT NULL,attention_id CHAR(32) NOT NULL,channel VARCHAR(16) NOT NULL,parent_id VARCHAR(200) NOT NULL,connection_id VARCHAR(64) NOT NULL,source_fingerprint CHAR(64) NOT NULL,status VARCHAR(24) NOT NULL,version INT NOT NULL,ciphertext MEDIUMTEXT NOT NULL,created_by VARCHAR(64) NOT NULL,updated_by VARCHAR(64) NOT NULL,sent_by VARCHAR(64) NULL,remote_id VARCHAR(200) NULL,reason VARCHAR(64) NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,PRIMARY KEY(club_id,id),UNIQUE(club_id,attention_id,source_fingerprint,connection_id),INDEX(club_id,status,updated_at))",
                "CREATE TABLE IF NOT EXISTS native_social_attention(club_id VARCHAR(64) NOT NULL,id CHAR(32) NOT NULL,channel VARCHAR(16) NOT NULL,kind VARCHAR(16) NOT NULL,parent_id VARCHAR(200) NOT NULL,state VARCHAR(24) NOT NULL,version INT NOT NULL,fingerprint CHAR(64) NOT NULL,ciphertext MEDIUMTEXT NOT NULL,observed_at DATETIME NOT NULL,updated_by VARCHAR(64) NULL,PRIMARY KEY(club_id,id),INDEX(club_id,state,observed_at))",
                "CREATE TABLE IF NOT EXISTS native_social_originals(club_id VARCHAR(64) NOT NULL,id CHAR(32) NOT NULL,source_type VARCHAR(16) NOT NULL,source_id CHAR(32) NOT NULL,channel VARCHAR(16) NOT NULL,kind VARCHAR(16) NOT NULL,parent_id VARCHAR(200) NOT NULL,snapshot_hash CHAR(64) NOT NULL,ciphertext LONGBLOB NOT NULL,reason VARCHAR(16) NOT NULL,archived_at DATETIME(6) NOT NULL,PRIMARY KEY(club_id,id),UNIQUE(club_id,source_type,source_id,snapshot_hash),INDEX(club_id,source_type,source_id,archived_at,id),INDEX(club_id,source_type,kind,archived_at,id))",
                "CREATE TABLE IF NOT EXISTS native_social_check_plans(club_id VARCHAR(64) NOT NULL,channel VARCHAR(16) NOT NULL,actor_id VARCHAR(64) NOT NULL,authority VARCHAR(16) NOT NULL,version INT NOT NULL,enabled TINYINT NOT NULL,config_json JSON NOT NULL,next_at DATETIME NULL,last_checked DATETIME NULL,last_slot VARCHAR(40) NULL,offsets_json JSON NOT NULL,state VARCHAR(24) NOT NULL,PRIMARY KEY(club_id,channel),INDEX(enabled,next_at))",
                "CREATE TABLE IF NOT EXISTS native_social_event_origins(club_id VARCHAR(64) NOT NULL,item_id CHAR(32) NOT NULL,page_id CHAR(32) NOT NULL,revision INT NOT NULL,snapshot_json JSON NOT NULL,created_at DATETIME NOT NULL,PRIMARY KEY(club_id,item_id),INDEX(club_id,page_id))",
                "CREATE TABLE IF NOT EXISTS native_press_event_origins(club_id VARCHAR(64) NOT NULL,item_id CHAR(32) NOT NULL,page_id CHAR(32) NOT NULL,revision INT NOT NULL,snapshot_json JSON NOT NULL,created_at DATETIME NOT NULL,PRIMARY KEY(club_id,item_id),INDEX(club_id,page_id))",
                "CREATE TABLE IF NOT EXISTS native_social_objects(club_id VARCHAR(64) NOT NULL,id CHAR(32) NOT NULL,channel VARCHAR(16) NOT NULL,remote_id VARCHAR(200) NOT NULL,kind VARCHAR(16) NOT NULL,caption MEDIUMTEXT NOT NULL,created_at DATETIME NULL,payload_json JSON NOT NULL,last_seen DATETIME NOT NULL,insights_json JSON NULL,insights_at DATETIME NULL,comments_cipher MEDIUMTEXT NULL,comments_at DATETIME NULL,PRIMARY KEY(club_id,id),UNIQUE(club_id,channel,remote_id),INDEX(club_id,kind,created_at))",
                "CREATE TABLE IF NOT EXISTS native_social_checks(club_id VARCHAR(64) NOT NULL,feature VARCHAR(64) NOT NULL,state VARCHAR(24) NOT NULL,checked_at DATETIME NOT NULL,PRIMARY KEY(club_id,feature))",
                "CREATE TABLE IF NOT EXISTS native_social_inbox(club_id VARCHAR(64) NOT NULL,id CHAR(32) NOT NULL,channel VARCHAR(16) NOT NULL,ciphertext MEDIUMTEXT NOT NULL,last_incoming DATETIME NOT NULL,updated_at DATETIME NOT NULL,PRIMARY KEY(club_id,id),INDEX(club_id,last_incoming))",
                "CREATE TABLE IF NOT EXISTS native_social_images(id CHAR(32) PRIMARY KEY,club_id VARCHAR(64) NOT NULL,item_id CHAR(32) NOT NULL,item_version INT NOT NULL,content_hash CHAR(64) NOT NULL,slide INT NOT NULL,kind VARCHAR(16) NOT NULL,sha256 CHAR(64) NOT NULL,metadata_json JSON NOT NULL,created_at DATETIME NOT NULL,INDEX(club_id,item_id,content_hash))",
                "CREATE TABLE IF NOT EXISTS native_image_capabilities(token_hash CHAR(64) PRIMARY KEY,club_id VARCHAR(64) NOT NULL,image_id CHAR(32) NOT NULL,format VARCHAR(8) NOT NULL DEFAULT 'png',expires_at DATETIME NULL)",
                "CREATE TABLE IF NOT EXISTS native_club_settings (club_id VARCHAR(64) PRIMARY KEY, version INT NOT NULL, payload JSON NOT NULL, updated_by VARCHAR(64) NOT NULL, updated_at DATETIME NOT NULL)",
                "CREATE TABLE IF NOT EXISTS native_private_settings (club_id VARCHAR(64) NOT NULL, purpose VARCHAR(24) NOT NULL, version INT NOT NULL, ciphertext MEDIUMTEXT NOT NULL, updated_by VARCHAR(64) NOT NULL, PRIMARY KEY(club_id,purpose))",
                "CREATE TABLE IF NOT EXISTS native_delivery_jobs (id CHAR(32) PRIMARY KEY, club_id VARCHAR(64) NOT NULL, dedupe CHAR(64) NOT NULL, kind VARCHAR(24) NOT NULL, actor_id VARCHAR(64) NOT NULL, authority VARCHAR(16) NOT NULL, capability VARCHAR(24) NOT NULL, due_at DATETIME NOT NULL, status VARCHAR(24) NOT NULL, ciphertext MEDIUMTEXT NOT NULL, attempts INT NOT NULL DEFAULT 0, external_id VARCHAR(200) NULL, reason VARCHAR(64) NULL, started_at DATETIME NULL, completed_at DATETIME NULL, UNIQUE(club_id,dedupe), INDEX(status,due_at))",
                "CREATE TABLE IF NOT EXISTS native_delivery_sources (job_id CHAR(32) PRIMARY KEY, club_id VARCHAR(64) NOT NULL, module VARCHAR(24) NOT NULL, item_id CHAR(32) NOT NULL, item_version INT NOT NULL, INDEX(club_id,module,item_id), FOREIGN KEY(job_id) REFERENCES native_delivery_jobs(id) ON DELETE CASCADE)",
                "CREATE TABLE IF NOT EXISTS native_newsletter_blocks (club_id VARCHAR(64) NOT NULL, email_hash CHAR(64) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY(club_id,email_hash))",
                "CREATE TABLE IF NOT EXISTS native_newsletter_rates (club_id VARCHAR(64) NOT NULL, fingerprint CHAR(64) NOT NULL, bucket BIGINT NOT NULL, count INT NOT NULL, PRIMARY KEY(club_id,fingerprint,bucket))",
                "CREATE TABLE IF NOT EXISTS native_campaign_views (token_hash CHAR(64) PRIMARY KEY, club_id VARCHAR(64) NOT NULL, item_id CHAR(32) NOT NULL, item_version INT NOT NULL, snapshot_json MEDIUMTEXT NOT NULL, expires_at DATETIME NULL, is_test TINYINT NOT NULL, created_at DATETIME NOT NULL)",
                "CREATE TABLE IF NOT EXISTS native_newsletter_reminder_plans (club_id VARCHAR(64) PRIMARY KEY, actor_id VARCHAR(64) NOT NULL, authority VARCHAR(16) NOT NULL, paused TINYINT NOT NULL, version INT NOT NULL)",
                "CREATE TABLE IF NOT EXISTS native_digest_plans (club_id VARCHAR(64) NOT NULL, period VARCHAR(12) NOT NULL, actor_id VARCHAR(64) NOT NULL, authority VARCHAR(16) NOT NULL, paused TINYINT NOT NULL, PRIMARY KEY(club_id,period))",
                "CREATE TABLE IF NOT EXISTS native_digest_editions (club_id VARCHAR(64) NOT NULL, period VARCHAR(12) NOT NULL, period_key VARCHAR(16) NOT NULL, item_id CHAR(32) NOT NULL, PRIMARY KEY(club_id,period,period_key))",
                "CREATE TABLE IF NOT EXISTS native_digest_periods (club_id VARCHAR(64) NOT NULL, period VARCHAR(12) NOT NULL, period_key VARCHAR(32) NOT NULL, paused TINYINT NOT NULL, version INT NOT NULL, updated_by VARCHAR(64) NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(club_id,period,period_key))",
                "CREATE TABLE IF NOT EXISTS native_digest_announcements (club_id VARCHAR(64) NOT NULL, period_key VARCHAR(32) NOT NULL, event_id CHAR(32) NOT NULL, event_revision INT NOT NULL, starts_at VARCHAR(32) NOT NULL, slug VARCHAR(240) NOT NULL, job_id CHAR(32) NOT NULL, announced_at DATETIME NOT NULL, PRIMARY KEY(club_id,period_key,event_id,starts_at), INDEX(club_id,event_id))",
            ]
            as $sql
        ) {
            $db->exec(
                $sql .
                    " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin",
            );
        }
        \Kulturhus\Native\Events\Schema::install($db);
        $db->exec("CREATE TABLE IF NOT EXISTS native_social_human_sends (
            club_id VARCHAR(64) NOT NULL,request_key CHAR(32) NOT NULL,
            request_hash CHAR(64) NOT NULL,text_hash CHAR(64) NOT NULL,
            reply_id CHAR(32) NOT NULL,actor_id VARCHAR(64) NOT NULL,kind VARCHAR(16) NOT NULL,created_at DATETIME(6) NOT NULL,
            PRIMARY KEY(club_id,request_key),UNIQUE(club_id,reply_id),
            FOREIGN KEY(club_id,reply_id) REFERENCES native_social_replies(club_id,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        foreach (
            [
                "native_social_replies" => ["reply_slot" => "VARCHAR(32) NOT NULL DEFAULT ''"],
                "native_digest_plans" => [
                    "version" => "INT NOT NULL DEFAULT 1",
                    "prepare_days" => "INT NOT NULL DEFAULT 7",
                    "send_hour" => "INT NOT NULL DEFAULT 10",
                ],
                "native_digest_editions" => [
                    "schedule_json" => "JSON NULL",
                    "events_json" => "JSON NULL",
                ],
            ]
            as $table => $columns
        ) {
            foreach ($columns as $column => $type) {
                if (
                    !Db::run(
                        $db,
                        "SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?",
                        [$table, $column],
                    )->fetchColumn()
                ) {
                    $db->exec("ALTER TABLE $table ADD $column $type");
                }
            }
        }
        // One ordinary draft per original remains unchanged. Every genuine mail
        // has its own slot, allowing a conversation to receive several answers.
        // Add the replacement before dropping the old index: interrupted DDL
        // must never leave the table without duplicate protection.
        $indexes=Db::run($db,"SELECT INDEX_NAME,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list FROM information_schema.statistics WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='native_social_replies' AND NON_UNIQUE=0 GROUP BY INDEX_NAME")->fetchAll(PDO::FETCH_KEY_PAIR);
        if(!isset($indexes['original_reply_slot']))$db->exec('ALTER TABLE native_social_replies ADD UNIQUE KEY original_reply_slot(club_id,attention_id,source_fingerprint,connection_id,reply_slot)');
        foreach($indexes as$name=>$columns)if($columns==='club_id,attention_id,source_fingerprint,connection_id'){
            if(!preg_match('/\A[A-Za-z0-9_]+\z/D',$name))throw new \DomainException('Antwortindex prüfen.');
            $db->exec('ALTER TABLE native_social_replies DROP INDEX `'.$name.'`');
        }
        $indexes=Db::run($db,"SELECT INDEX_NAME,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.statistics WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='native_social_mail_threads' AND NON_UNIQUE=0 GROUP BY INDEX_NAME")->fetchAll(PDO::FETCH_KEY_PAIR);
        if(!isset($indexes['original_mail_binding']))$db->exec('ALTER TABLE native_social_mail_threads ADD UNIQUE KEY original_mail_binding(club_id,attention_id,source_fingerprint,binding,connection_id)');
        foreach($indexes as$name=>$columns)if($columns==='club_id,attention_id,source_fingerprint,binding'){
            if(!preg_match('/\A[A-Za-z0-9_]+\z/D',$name))throw new \DomainException('Mailindex prüfen.');
            $db->exec('ALTER TABLE native_social_mail_threads DROP INDEX `'.$name.'`');
        }
        if (
            (int) Db::run(
                $db,
                "SELECT DATETIME_PRECISION FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='native_social_images' AND column_name='created_at'",
            )->fetchColumn() !== 6
        ) {
            $db->exec(
                "ALTER TABLE native_social_images MODIFY created_at DATETIME(6) NOT NULL",
            );
        }
        foreach (
            [
                "thumbnail_blob" => "MEDIUMBLOB NULL",
                "thumbnail_sha256" => "CHAR(64) NULL",
            ]
            as $column => $type
        ) {
            if (
                !Db::run(
                    $db,
                    "SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='native_social_objects' AND column_name=?",
                    [$column],
                )->fetchColumn()
            ) {
                $db->exec(
                    "ALTER TABLE native_social_objects ADD $column $type",
                );
            }
        }
        if (
            !Db::run(
                $db,
                "SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='native_image_capabilities' AND column_name='format'",
            )->fetchColumn()
        ) {
            $db->exec(
                "ALTER TABLE native_image_capabilities ADD format VARCHAR(8) NOT NULL DEFAULT 'png'",
            );
        }
    }
}
