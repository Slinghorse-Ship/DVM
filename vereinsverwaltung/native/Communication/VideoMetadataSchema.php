<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use PDO;
/** Installer-only current source metadata and immutable source metadata revisions. */
final class VideoMetadataSchema
{
    public static function install(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS native_video_metadata (
            club_id VARCHAR(64) NOT NULL, source_id CHAR(32) NOT NULL,
            version INT UNSIGNED NOT NULL, payload_json JSON NOT NULL,
            consent_cipher MEDIUMTEXT NULL, updated_by VARCHAR(64) NOT NULL,
            updated_at DATETIME(6) NOT NULL, PRIMARY KEY(club_id,source_id),
            FOREIGN KEY(club_id,source_id) REFERENCES native_social_video_sources(club_id,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS native_video_metadata_revisions (
            club_id VARCHAR(64) NOT NULL, source_id CHAR(32) NOT NULL,
            version INT UNSIGNED NOT NULL, payload_json JSON NOT NULL,
            consent_cipher MEDIUMTEXT NULL, actor_id VARCHAR(64) NOT NULL,
            created_at DATETIME(6) NOT NULL, PRIMARY KEY(club_id,source_id,version),
            FOREIGN KEY(club_id,source_id) REFERENCES native_social_video_sources(club_id,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
    }
}
