<?php declare(strict_types=1);
namespace Kulturhus\Native\Files;

use PDO;

/** Explicit installer only. Immutable bytes and revisions are separate from the logical asset. */
final class Schema
{
    public static function install(PDO $db):void
    {
        if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$db->inTransaction())throw new \DomainException('Dateimodell nur im Wartungsweg installieren.');
        foreach([
            'khcms_assets'=>"id CHAR(32) PRIMARY KEY,club_id VARCHAR(64) NOT NULL,kind VARCHAR(8) NOT NULL,version INT UNSIGNED NOT NULL,head_id CHAR(32) NULL,revision INT UNSIGNED NOT NULL,state VARCHAR(16) NOT NULL,created_by VARCHAR(64) NOT NULL,created_at DATETIME(6) NOT NULL,updated_at DATETIME(6) NOT NULL,UNIQUE KEY file_asset_owner(club_id,id),KEY file_asset_list(club_id,state,updated_at,id)",
            'khcms_file_blobs'=>"id CHAR(32) PRIMARY KEY,club_id VARCHAR(64) NOT NULL,original_name VARCHAR(240) NOT NULL,mime VARCHAR(120) NOT NULL,bytes INT UNSIGNED NOT NULL,sha256 CHAR(64) NOT NULL,created_by VARCHAR(64) NOT NULL,created_at DATETIME(6) NOT NULL,KEY file_blob_owner(club_id,id)",
            'khcms_asset_revisions'=>"id CHAR(32) PRIMARY KEY,asset_id CHAR(32) NOT NULL,club_id VARCHAR(64) NOT NULL,revision INT UNSIGNED NOT NULL,source_kind VARCHAR(8) NOT NULL,source_id CHAR(32) NOT NULL,original_name VARCHAR(240) NOT NULL,mime VARCHAR(120) NOT NULL,bytes INT UNSIGNED NOT NULL,sha256 CHAR(64) NOT NULL,metadata_json TEXT NOT NULL,restored_from CHAR(32) NULL,actor_id VARCHAR(64) NOT NULL,created_at DATETIME(6) NOT NULL,UNIQUE KEY file_asset_revision(asset_id,revision),KEY file_revision_owner(club_id,source_kind,source_id),CONSTRAINT file_revision_asset FOREIGN KEY(club_id,asset_id) REFERENCES khcms_assets(club_id,id) ON DELETE RESTRICT",
            'khcms_image_group_history'=>"club_id VARCHAR(64) NOT NULL,asset_id CHAR(32) NOT NULL,version INT UNSIGNED NOT NULL,state VARCHAR(16) NOT NULL,actor_id VARCHAR(64) NOT NULL,changed_at DATETIME(6) NOT NULL,PRIMARY KEY(club_id,asset_id,version),CONSTRAINT image_group_history_asset FOREIGN KEY(club_id,asset_id) REFERENCES khcms_assets(club_id,id) ON DELETE RESTRICT",
            'khcms_file_quota'=>"club_id VARCHAR(64) PRIMARY KEY,version INT UNSIGNED NOT NULL,limit_bytes BIGINT UNSIGNED NOT NULL,updated_by VARCHAR(64) NOT NULL,created_at DATETIME(6) NOT NULL",
            'khcms_file_quota_history'=>"club_id VARCHAR(64) NOT NULL,version INT UNSIGNED NOT NULL,limit_bytes BIGINT UNSIGNED NOT NULL,actor_id VARCHAR(64) NOT NULL,created_at DATETIME(6) NOT NULL,PRIMARY KEY(club_id,version)",
            'khcms_file_recoveries'=>"club_id VARCHAR(64) NOT NULL,source_id CHAR(32) NOT NULL,source_sha256 CHAR(64) NOT NULL,asset_id CHAR(32) NOT NULL,actor_id VARCHAR(64) NOT NULL,created_at DATETIME(6) NOT NULL,PRIMARY KEY(club_id,source_id),CONSTRAINT file_recovery_asset FOREIGN KEY(club_id,asset_id) REFERENCES khcms_assets(club_id,id) ON DELETE RESTRICT",
            'khcms_file_removals'=>"operation CHAR(32) PRIMARY KEY,club_id VARCHAR(64) NOT NULL,asset_id CHAR(32) NOT NULL,asset_version INT UNSIGNED NOT NULL,plan_json MEDIUMTEXT NOT NULL,retained_bytes BIGINT UNSIGNED NOT NULL,retain_until DATETIME(6) NOT NULL,actor_id VARCHAR(64) NOT NULL,created_at DATETIME(6) NOT NULL,KEY file_removal_asset(club_id,asset_id),CONSTRAINT file_removal_asset FOREIGN KEY(club_id,asset_id) REFERENCES khcms_assets(club_id,id) ON DELETE RESTRICT",
            'khcms_file_removal_states'=>"club_id VARCHAR(64) NOT NULL,asset_id CHAR(32) NOT NULL,version INT UNSIGNED NOT NULL,state VARCHAR(16) NOT NULL,operation CHAR(32) NOT NULL,actor_id VARCHAR(64) NOT NULL,changed_at DATETIME(6) NOT NULL,PRIMARY KEY(club_id,asset_id),CONSTRAINT file_removal_state_operation FOREIGN KEY(operation) REFERENCES khcms_file_removals(operation) ON DELETE RESTRICT",
            'khcms_file_removal_history'=>"club_id VARCHAR(64) NOT NULL,asset_id CHAR(32) NOT NULL,version INT UNSIGNED NOT NULL,state VARCHAR(16) NOT NULL,operation CHAR(32) NOT NULL,actor_id VARCHAR(64) NOT NULL,changed_at DATETIME(6) NOT NULL,PRIMARY KEY(club_id,asset_id,version),CONSTRAINT file_removal_history_operation FOREIGN KEY(operation) REFERENCES khcms_file_removals(operation) ON DELETE RESTRICT",
        ]as$table=>$columns)$db->exec('CREATE TABLE IF NOT EXISTS '.$table.' ('.$columns.') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
        \Kulturhus\Native\Shared\RetentionStore::install($db,'files');
    }
}
