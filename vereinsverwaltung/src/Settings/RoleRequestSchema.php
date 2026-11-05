<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;

use DomainException;
use PDO;

/** Explicit package step only; existing role history is never converted into requests. */
final class RoleRequestSchema
{
    public static function install(PDO $db):void
    {
        if($db->inTransaction())throw new DomainException('Rollenbestätigungen benötigen einen eigenen Paketschritt.');
        $db->exec("CREATE TABLE IF NOT EXISTS native_role_requests (
            club_id VARCHAR(128) NOT NULL,
            request_key CHAR(32) NOT NULL,
            actor_id VARCHAR(128) NOT NULL,
            operation VARCHAR(32) NOT NULL,
            object_id VARCHAR(128) NOT NULL,
            object_label VARCHAR(512) NOT NULL,
            input_sha256 CHAR(64) NOT NULL,
            input_json MEDIUMTEXT NOT NULL,
            original_sha256 CHAR(64) NOT NULL,
            original_json MEDIUMTEXT NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY(club_id,request_key),
            INDEX(club_id,actor_id,created_at,request_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
    }
}
