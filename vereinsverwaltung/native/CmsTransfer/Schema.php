<?php declare(strict_types=1);
namespace Kulturhus\Native\CmsTransfer;

use PDO;

/** Installer-only schema. A transfer never changes the runtime schema. */
final class Schema
{
    public static function install(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS native_cms_imports (
            id CHAR(32) PRIMARY KEY,club_id VARCHAR(64) NOT NULL,actor_id VARCHAR(64) NOT NULL,
            version INT UNSIGNED NOT NULL,status VARCHAR(12) NOT NULL,
            package_sha256 CHAR(64) NOT NULL,target_hash CHAR(64) NOT NULL,
            plan_json MEDIUMTEXT NOT NULL,result_json MEDIUMTEXT NULL,
            created_at DATETIME(6) NOT NULL,updated_at DATETIME(6) NOT NULL,
            KEY cms_import_owner(club_id,actor_id,updated_at,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
