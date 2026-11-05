<?php declare(strict_types=1);
namespace Kulturhus\Native\Status;
final class Schema
{
    public static function install(\PDO $db):void {
        if($db->getAttribute(\PDO::ATTR_DRIVER_NAME)!=='mysql'||$db->inTransaction())throw new \DomainException('Statusinstallation nur im eigenen Wartungsweg.');
        $db->exec("CREATE TABLE IF NOT EXISTS native_status_settings (club_id VARCHAR(128) PRIMARY KEY, version INT NOT NULL, public_enabled TINYINT NOT NULL, automatic_enabled TINYINT NOT NULL, public_services JSON NOT NULL, disabled_services JSON NOT NULL, updated_by VARCHAR(128) NOT NULL, updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS native_status_checks (id CHAR(32) PRIMARY KEY, club_id VARCHAR(128) NOT NULL, service VARCHAR(32) NOT NULL, context_sha256 CHAR(64) NOT NULL, state VARCHAR(32) NOT NULL, reason VARCHAR(48) NOT NULL, kind VARCHAR(16) NOT NULL, checked_at DATETIME NOT NULL, observed_at DATETIME NULL, duration_ms INT UNSIGNED NOT NULL, INDEX status_current(club_id,service,context_sha256,checked_at,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
    }
}
