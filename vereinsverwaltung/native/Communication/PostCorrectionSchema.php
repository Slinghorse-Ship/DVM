<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use PDO;
final class PostCorrectionSchema
{
    public static function install(PDO $db):void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS native_social_post_corrections (
            club_id VARCHAR(64) NOT NULL,id CHAR(32) NOT NULL,object_id CHAR(32) NOT NULL,
            actor_id VARCHAR(64) NOT NULL,connection_id VARCHAR(64) NOT NULL,request_hash CHAR(64) NOT NULL,
            ciphertext MEDIUMTEXT NOT NULL,status VARCHAR(24) NOT NULL,version INT UNSIGNED NOT NULL,
            created_at DATETIME(6) NOT NULL,updated_at DATETIME(6) NOT NULL,confirmed_at DATETIME(6) NULL,
            PRIMARY KEY(club_id,id),INDEX(club_id,object_id,status),INDEX(club_id,object_id,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
    }
}
