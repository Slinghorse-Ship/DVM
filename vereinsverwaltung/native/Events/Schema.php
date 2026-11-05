<?php
declare(strict_types=1);
namespace Kulturhus\Native\Events;
use PDO;
use Kulturhus\Native\Shared\Db;

final class Schema
{
    public static function install(PDO $db): void
    {
        Tickets\Schema::install($db);
        $db->exec("CREATE TABLE IF NOT EXISTS native_event_source_aliases (
            club_id VARCHAR(64) NOT NULL, source VARCHAR(16) NOT NULL, external_id VARCHAR(100) NOT NULL,
            page_id CHAR(32) NOT NULL, PRIMARY KEY(club_id,source,external_id), INDEX(club_id,page_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS native_event_workflows (
            club_id VARCHAR(64) PRIMARY KEY, version INT UNSIGNED NOT NULL,
            source VARCHAR(16) NOT NULL, tickets VARCHAR(16) NOT NULL, payment VARCHAR(16) NOT NULL,
            automatic_publication TINYINT UNSIGNED NOT NULL, updated_by VARCHAR(64) NOT NULL, updated_at DATETIME(6) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS native_event_workflow_history (
            club_id VARCHAR(64) NOT NULL, version INT UNSIGNED NOT NULL, payload_json JSON NOT NULL,
            actor_id VARCHAR(64) NOT NULL, created_at DATETIME(6) NOT NULL, PRIMARY KEY(club_id,version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS native_event_sources (
            club_id VARCHAR(64) NOT NULL, source VARCHAR(16) NOT NULL, external_id VARCHAR(100) NOT NULL,
            page_id CHAR(32) NOT NULL, source_hash CHAR(64) NOT NULL, state VARCHAR(24) NOT NULL,
            imported_version INT NOT NULL DEFAULT 0, metadata_json JSON NULL,
            PRIMARY KEY(club_id,source,external_id), UNIQUE(club_id,page_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        foreach (
            [
                "imported_version" => "INT NOT NULL DEFAULT 0",
                "metadata_json" => "JSON NULL",
            ]
            as $column => $type
        ) {
            if (
                !Db::run(
                    $db,
                    "SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='native_event_sources' AND column_name=?",
                    [$column],
                )->fetchColumn()
            ) {
                $db->exec("ALTER TABLE native_event_sources ADD $column $type");
            }
        }
    }
}
