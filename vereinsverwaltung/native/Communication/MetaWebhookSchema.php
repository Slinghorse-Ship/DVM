<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use PDO;

/** Explicit installer-only capability; incoming requests never create tables. */
final class MetaWebhookSchema
{
    public static function installed(PDO $db):bool
    {
        return (int)\Kulturhus\Native\Shared\Db::run($db,"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME IN ('native_meta_webhook_settings','native_meta_webhook_history','native_meta_webhook_receipts')")->fetchColumn()===3;
    }
    public static function install(PDO $db):void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS native_meta_webhook_settings (
            club_id VARCHAR(64) PRIMARY KEY, version INT UNSIGNED NOT NULL,
            callback_id CHAR(32) NOT NULL UNIQUE, actor_id VARCHAR(64) NOT NULL,
            authority VARCHAR(12) NOT NULL, enabled TINYINT UNSIGNED NOT NULL,
            connection_version INT UNSIGNED NOT NULL, connection_id CHAR(64) NOT NULL,
            verify_hash CHAR(64) NOT NULL, ciphertext LONGTEXT NOT NULL,
            updated_at DATETIME(6) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS native_meta_webhook_history (
            club_id VARCHAR(64) NOT NULL, version INT UNSIGNED NOT NULL,
            snapshot JSON NOT NULL, actor_id VARCHAR(64) NOT NULL, created_at DATETIME(6) NOT NULL,
            PRIMARY KEY(club_id,version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS native_meta_webhook_receipts (
            club_id VARCHAR(64) NOT NULL, id CHAR(32) NOT NULL,
            settings_version INT UNSIGNED NOT NULL, channel VARCHAR(12) NOT NULL,
            kind VARCHAR(12) NOT NULL, ciphertext LONGTEXT NOT NULL,
            state VARCHAR(16) NOT NULL, attempts INT UNSIGNED NOT NULL DEFAULT 0,
            received_at DATETIME(6) NOT NULL, due_at DATETIME(6) NOT NULL,
            started_at DATETIME(6) NULL, completed_at DATETIME(6) NULL,
            reason VARCHAR(64) NULL,
            PRIMARY KEY(club_id,id), INDEX(club_id,state,due_at,id), INDEX(club_id,received_at,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
    }
}
