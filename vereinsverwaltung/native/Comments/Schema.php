<?php declare(strict_types=1);
namespace Kulturhus\Native\Comments;

use PDO;

/** Additive maintenance only; public requests never create tables. */
final class Schema
{
    public static function install(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS native_comment_settings (
            club_id VARCHAR(64) NOT NULL, page_id CHAR(32) NOT NULL, stream VARCHAR(64) NOT NULL,
            version INT UNSIGNED NOT NULL, enabled TINYINT UNSIGNED NOT NULL DEFAULT 0,
            moderation TINYINT UNSIGNED NOT NULL DEFAULT 1, anonymous TINYINT UNSIGNED NOT NULL DEFAULT 0,
            max_length INT UNSIGNED NOT NULL DEFAULT 5000, updated_by VARCHAR(64) NOT NULL,
            updated_at DATETIME(6) NOT NULL, PRIMARY KEY(club_id,page_id,stream)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS native_comments (
            id CHAR(32) NOT NULL PRIMARY KEY, club_id VARCHAR(64) NOT NULL, page_id CHAR(32) NOT NULL,
            stream VARCHAR(64) NOT NULL, parent_id CHAR(32) NULL, depth TINYINT UNSIGNED NOT NULL,
            version INT UNSIGNED NOT NULL, state VARCHAR(16) NOT NULL, author_name VARCHAR(100) NOT NULL,
            author_cipher MEDIUMTEXT NOT NULL, author_key CHAR(64) NOT NULL, body TEXT NOT NULL,
            format VARCHAR(16) NOT NULL, original_revision INT UNSIGNED NOT NULL,
            request_hash CHAR(64) NOT NULL, payload_hash CHAR(64) NOT NULL, rate_key CHAR(64) NOT NULL,
            created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, updated_by VARCHAR(64) NOT NULL,
            UNIQUE KEY own_comment_request(club_id,request_hash),
            KEY comment_stream(club_id,page_id,stream,state,created_at,id),
            KEY comment_rate(club_id,rate_key,created_at), KEY comment_parent(club_id,parent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS native_comment_history (
            sequence BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            club_id VARCHAR(64) NOT NULL, comment_id CHAR(32) NOT NULL, version INT UNSIGNED NOT NULL,
            action VARCHAR(16) NOT NULL, actor_id VARCHAR(64) NOT NULL,
            snapshot_json JSON NOT NULL, created_at DATETIME(6) NOT NULL,
            UNIQUE KEY comment_original(club_id,comment_id,version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS native_comment_setting_history (
            club_id VARCHAR(64) NOT NULL, page_id CHAR(32) NOT NULL, stream VARCHAR(64) NOT NULL,
            version INT UNSIGNED NOT NULL, settings_json JSON NOT NULL,
            actor_id VARCHAR(64) NOT NULL, created_at DATETIME(6) NOT NULL,
            PRIMARY KEY(club_id,page_id,stream,version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
    }
}
