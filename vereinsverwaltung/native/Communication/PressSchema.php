<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use PDO;
/** Installer only. One encrypted original message shared by its individual deliveries. */
final class PressSchema
{
    public static function installed(PDO $db): bool
    {
        return (bool)\Kulturhus\Native\Shared\Db::run($db,"SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='native_press_messages'")->fetchColumn();
    }

    public static function install(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS native_press_messages (
            club_id VARCHAR(64) NOT NULL, item_id CHAR(32) NOT NULL, item_version INT UNSIGNED NOT NULL,
            ciphertext MEDIUMTEXT NOT NULL, created_at DATETIME(6) NOT NULL,
            PRIMARY KEY(club_id,item_id,item_version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
    }
}
