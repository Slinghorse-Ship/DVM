<?php declare(strict_types=1);
namespace Kulturhus\Native\Appearance;

/** Explicit additive maintenance only. Bound accounts keep their original Settings row. */
final class Schema
{
    public static function install(\PDO $db):void
    {
        if($db->getAttribute(\PDO::ATTR_DRIVER_NAME)!=='mysql'||$db->inTransaction())throw new \DomainException('Anzeigeeinstellungen nur im Wartungsweg installieren.');
        $db->exec('CREATE TABLE IF NOT EXISTS native_appearance_preferences (club_id VARCHAR(64) NOT NULL,actor_id VARCHAR(64) NOT NULL,version INT UNSIGNED NOT NULL,payload_json TEXT NOT NULL,changed_at DATETIME(6) NOT NULL,PRIMARY KEY(club_id,actor_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
        $db->exec('CREATE TABLE IF NOT EXISTS native_appearance_revisions (club_id VARCHAR(64) NOT NULL,actor_id VARCHAR(64) NOT NULL,version INT UNSIGNED NOT NULL,payload_json TEXT NOT NULL,changed_at DATETIME(6) NOT NULL,PRIMARY KEY(club_id,actor_id,version)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
    }
}
