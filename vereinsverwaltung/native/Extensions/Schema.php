<?php declare(strict_types=1);
namespace Kulturhus\Native\Extensions;
final class Schema
{
    public static function install(\PDO $db): void
    {
        if($db->getAttribute(\PDO::ATTR_DRIVER_NAME)!=='mysql'||$db->inTransaction())throw new \DomainException('Erweiterungsschema nur im Wartungsweg.');
        $db->exec("CREATE TABLE IF NOT EXISTS native_extensions (club_id VARCHAR(128) NOT NULL, plugin_id VARCHAR(64) NOT NULL, version VARCHAR(20) NOT NULL, source_hash CHAR(64) NOT NULL, revision INT UNSIGNED NOT NULL, enabled TINYINT NOT NULL, configuration JSON NOT NULL, updated_by VARCHAR(128) NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(club_id,plugin_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS native_extension_values (club_id VARCHAR(128) NOT NULL, plugin_id VARCHAR(64) NOT NULL, name VARCHAR(64) NOT NULL, value_json JSON NOT NULL, PRIMARY KEY(club_id,plugin_id,name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS native_extension_history (id CHAR(32) PRIMARY KEY, club_id VARCHAR(128) NOT NULL, plugin_id VARCHAR(64) NOT NULL, actor_id VARCHAR(128) NOT NULL, action VARCHAR(16) NOT NULL, version VARCHAR(20) NOT NULL, occurred_at DATETIME NOT NULL, INDEX extension_history(club_id,occurred_at,id), INDEX extension_revisions(club_id,plugin_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
    }
}
