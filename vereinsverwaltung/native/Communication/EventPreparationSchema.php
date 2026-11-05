<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use PDO;

/** Opt-in draft preparation has no delivery or publication capability. */
final class EventPreparationSchema
{
    public static function install(PDO $db): void
    {
        foreach ([
            'CREATE TABLE IF NOT EXISTS native_social_event_preparation_settings (
                club_id VARCHAR(64) PRIMARY KEY, version INT UNSIGNED NOT NULL,
                enabled TINYINT UNSIGNED NOT NULL, config_json JSON NOT NULL,
                actor_id VARCHAR(64) NOT NULL, authority VARCHAR(16) NOT NULL,
                updated_at DATETIME(6) NOT NULL)',
            'CREATE TABLE IF NOT EXISTS native_social_event_preparation_history (
                club_id VARCHAR(64) NOT NULL, version INT UNSIGNED NOT NULL,
                enabled TINYINT UNSIGNED NOT NULL, config_json JSON NOT NULL,
                actor_id VARCHAR(64) NOT NULL, authority VARCHAR(16) NOT NULL,
                created_at DATETIME(6) NOT NULL, PRIMARY KEY(club_id,version))',
            'CREATE TABLE IF NOT EXISTS native_social_event_preparations (
                club_id VARCHAR(64) NOT NULL, page_id CHAR(32) NOT NULL,
                slot VARCHAR(16) NOT NULL, kind VARCHAR(16) NOT NULL,
                item_id CHAR(32) NOT NULL, item_version INT UNSIGNED NOT NULL,
                page_revision INT UNSIGNED NOT NULL, settings_version INT UNSIGNED NOT NULL,
                state VARCHAR(24) NOT NULL, reason VARCHAR(32) NOT NULL DEFAULT \'\',
                version INT UNSIGNED NOT NULL, created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                PRIMARY KEY(club_id,page_id,slot,kind), UNIQUE KEY social_event_preparation_item(club_id,item_id),
                KEY social_event_preparation_pending(club_id,state,created_at))',
        ] as $sql) $db->exec($sql.' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
    }
}
