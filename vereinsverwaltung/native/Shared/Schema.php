<?php declare(strict_types=1);
namespace Kulturhus\Native\Shared;
use PDO;
final class Schema
{
    public static function install(PDO $db): void
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') throw new \RuntimeException('MySQL erforderlich.');
        $db->exec("CREATE TABLE IF NOT EXISTS native_users (
            id VARCHAR(64) PRIMARY KEY, club_id VARCHAR(64) NOT NULL, name VARCHAR(160) NOT NULL,
            active TINYINT NOT NULL DEFAULT 1, INDEX(club_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS native_grants (
            actor_id VARCHAR(64) NOT NULL, module VARCHAR(24) NOT NULL, capability VARCHAR(24) NOT NULL,
            PRIMARY KEY(actor_id,module,capability), FOREIGN KEY(actor_id) REFERENCES native_users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
