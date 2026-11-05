<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use PDO;

/** Installer-only immutable source bytes and their content-bound private versions. */
final class VideoSchema
{
    public static function install(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS native_social_video_sources (
            id CHAR(32) PRIMARY KEY, club_id VARCHAR(64) NOT NULL,
            original_name VARCHAR(255) NOT NULL, bytes BIGINT UNSIGNED NOT NULL,
            sha256 CHAR(64) NOT NULL, profile_json JSON NOT NULL,
            actor_id VARCHAR(64) NOT NULL, created_at DATETIME(6) NOT NULL,
            UNIQUE(club_id,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS native_social_videos (
            id CHAR(32) PRIMARY KEY, club_id VARCHAR(64) NOT NULL,
            item_id CHAR(32) NOT NULL, source_id CHAR(32) NOT NULL,
            item_version INT UNSIGNED NOT NULL, content_hash CHAR(64) NOT NULL,
            identity_version INT UNSIGNED NOT NULL, created_at DATETIME(6) NOT NULL,
            INDEX(club_id,item_id,created_at,id), UNIQUE(club_id,id),
            FOREIGN KEY(club_id,source_id) REFERENCES native_social_video_sources(club_id,id),
            FOREIGN KEY(club_id,item_id) REFERENCES khn_social_items(club_id,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS native_video_capabilities (
            token_hash CHAR(64) PRIMARY KEY, club_id VARCHAR(64) NOT NULL,
            video_id CHAR(32) NOT NULL, job_id CHAR(32) NOT NULL,
            connection_version INT UNSIGNED NOT NULL,
            expires_at DATETIME(6) NOT NULL, created_at DATETIME(6) NOT NULL,
            UNIQUE(job_id), INDEX(club_id,video_id),
            FOREIGN KEY(club_id,video_id) REFERENCES native_social_videos(club_id,id),
            FOREIGN KEY(job_id) REFERENCES native_delivery_jobs(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        VideoMetadataSchema::install($db);
        VideoQuota::install($db);
        VideoArchive::install($db);
        VideoRemoval::install($db);
        VideoRecovery::install($db);
    }
}
