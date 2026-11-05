<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use DomainException;
use PDO;

/** New, isolated storage. Never reads or migrates historical records. */
final class Schema
{
    public const MODULES = ['newsletter', 'social', 'press'];

    public static function table(string $module, string $suffix): string
    {
        if (!in_array($module, self::MODULES, true) || !in_array($suffix, ['items', 'revisions', 'templates', 'template_revisions', 'jobs', 'automations'], true)) {
            throw new DomainException('Unbekanntes Kommunikationsmodul.');
        }
        return 'khn_'.$module.'_'.$suffix;
    }

    public static function install(PDO $db): void
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') throw new DomainException('Die Kommunikationsmodule benötigen MySQL.');
        PostCorrectionSchema::install($db);
        EditionSaveRequests::install($db);
        DocumentAttachments::install($db);
        EventPreparationSchema::install($db);
        foreach (self::MODULES as $module) {
            $items = self::table($module, 'items');
            $db->exec("CREATE TABLE IF NOT EXISTS $items (
                club_id VARCHAR(64) NOT NULL, id CHAR(32) NOT NULL, payload JSON NOT NULL,
                status VARCHAR(24) NOT NULL, version INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
                PRIMARY KEY(club_id,id), INDEX(status,updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
            foreach (['revisions', 'template_revisions'] as $suffix) {
                $table = self::table($module, $suffix);
                $db->exec("CREATE TABLE IF NOT EXISTS $table (
                    club_id VARCHAR(64) NOT NULL, item_id CHAR(32) NOT NULL, version INT UNSIGNED NOT NULL,
                    payload JSON NOT NULL, actor_id VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL,
                    PRIMARY KEY(club_id,item_id,version)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
            }
            $table = self::table($module, 'templates');
            $db->exec("CREATE TABLE IF NOT EXISTS $table (
                club_id VARCHAR(64) NOT NULL, id CHAR(32) NOT NULL, version INT UNSIGNED NOT NULL,
                payload JSON NOT NULL, updated_at DATETIME NOT NULL,
                PRIMARY KEY(club_id,id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
            $table = self::table($module, 'jobs');
            $db->exec("CREATE TABLE IF NOT EXISTS $table (
                club_id VARCHAR(64) NOT NULL, id CHAR(32) NOT NULL, item_id CHAR(32) NOT NULL,
                item_version INT UNSIGNED NOT NULL, due_at DATETIME NOT NULL, status VARCHAR(24) NOT NULL,
                payload JSON NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
                PRIMARY KEY(club_id,id), UNIQUE(club_id,item_id,item_version), INDEX(club_id,status,due_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
            $table = self::table($module, 'automations');
            $db->exec("CREATE TABLE IF NOT EXISTS $table (
                club_id VARCHAR(64) NOT NULL, id CHAR(32) NOT NULL, item_id CHAR(32) NOT NULL,
                source_version INT UNSIGNED NOT NULL, version INT UNSIGNED NOT NULL,
                due_at DATETIME NOT NULL, status VARCHAR(24) NOT NULL, created_at DATETIME NOT NULL,
                actor_id VARCHAR(64) NOT NULL DEFAULT '', authority VARCHAR(16) NOT NULL DEFAULT 'native',
                PRIMARY KEY(club_id,id), UNIQUE(club_id,item_id,source_version), INDEX(club_id,status,due_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
            foreach(['actor_id'=>"VARCHAR(64) NOT NULL DEFAULT ''",'authority'=>"VARCHAR(16) NOT NULL DEFAULT 'native'"] as $column=>$type) {
                if(!\Kulturhus\Native\Shared\Db::run($db,'SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',[$table,$column])->fetchColumn())$db->exec("ALTER TABLE $table ADD $column $type");
            }
        }
        $db->exec("CREATE TABLE IF NOT EXISTS khn_newsletter_subscribers (
            club_id VARCHAR(64) NOT NULL, id CHAR(32) NOT NULL, email VARCHAR(254) NOT NULL,
            name VARCHAR(200) NOT NULL, status VARCHAR(20) NOT NULL, purposes JSON NOT NULL,
            consent_evidence VARCHAR(1000) NOT NULL, version INT UNSIGNED NOT NULL,
            confirmed_at DATETIME NULL, unsubscribed_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
            PRIMARY KEY(club_id,id), UNIQUE(club_id,email), INDEX(club_id,status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS khn_newsletter_consent_events (
            id CHAR(32) PRIMARY KEY, club_id VARCHAR(64) NOT NULL, subscriber_id CHAR(32) NOT NULL,
            action VARCHAR(24) NOT NULL, evidence VARCHAR(1000) NOT NULL, actor_id VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL, INDEX(club_id,subscriber_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS khn_newsletter_tokens (
            token_hash CHAR(64) PRIMARY KEY, club_id VARCHAR(64) NOT NULL, subscriber_id CHAR(32) NOT NULL,
            kind VARCHAR(20) NOT NULL, expires_at DATETIME NULL, consumed_at DATETIME NULL,
            created_at DATETIME NOT NULL, INDEX(club_id,subscriber_id,kind)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        \Kulturhus\Native\Shared\RuntimeSchema::install($db);
        VideoSchema::install($db);
        CarouselProgress::install($db);
        PressSchema::install($db);
        MetaWebhookSchema::install($db);
        $db->exec("CREATE TABLE IF NOT EXISTS native_newsletter_renewals (
            club_id VARCHAR(64) NOT NULL, subscriber_id CHAR(32) NOT NULL,
            job_id CHAR(32) NOT NULL, attempted_at DATETIME NULL,
            PRIMARY KEY(club_id,subscriber_id), UNIQUE(job_id),
            FOREIGN KEY(job_id) REFERENCES native_delivery_jobs(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS native_newsletter_confirmation_batches (
            club_id VARCHAR(64) NOT NULL, id CHAR(32) NOT NULL, actor_id VARCHAR(64) NOT NULL,
            authority VARCHAR(16) NOT NULL, subscriber_id CHAR(32) NOT NULL DEFAULT '', preview_hash CHAR(64) NOT NULL, job_ids JSON NOT NULL,
            created_at DATETIME NOT NULL, PRIMARY KEY(club_id,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS native_newsletter_renewal_events (
            job_id CHAR(32) PRIMARY KEY, club_id VARCHAR(64) NOT NULL, subscriber_id CHAR(32) NOT NULL,
            queued_at DATETIME NOT NULL, INDEX(club_id,subscriber_id,queued_at),
            FOREIGN KEY(job_id) REFERENCES native_delivery_jobs(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS native_social_handoffs (
            id CHAR(32) PRIMARY KEY, club_id VARCHAR(64) NOT NULL, item_id CHAR(32) NOT NULL,
            item_version INT UNSIGNED NOT NULL, channel VARCHAR(16) NOT NULL,
            snapshot_json JSON NOT NULL, status VARCHAR(24) NOT NULL, version INT UNSIGNED NOT NULL,
            reference_url VARCHAR(2000) NOT NULL DEFAULT '', confirmed_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
            UNIQUE(club_id,item_id,item_version,channel), INDEX(club_id,item_id,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS native_social_handoff_events (
            id CHAR(32) PRIMARY KEY, club_id VARCHAR(64) NOT NULL, handoff_id CHAR(32) NOT NULL,
            action VARCHAR(32) NOT NULL, actor_id VARCHAR(64) NOT NULL, authority VARCHAR(16) NOT NULL,
            details_json JSON NOT NULL, created_at DATETIME(6) NOT NULL,
            INDEX(club_id,handoff_id,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS khn_press_contacts (
            club_id VARCHAR(64) NOT NULL, id CHAR(32) NOT NULL, email VARCHAR(254) NOT NULL,
            name VARCHAR(200) NOT NULL, outlet VARCHAR(200) NOT NULL, active TINYINT NOT NULL,
            version INT UNSIGNED NOT NULL, updated_at DATETIME NOT NULL,
            PRIMARY KEY(club_id,id), UNIQUE(club_id,email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
    }
}
