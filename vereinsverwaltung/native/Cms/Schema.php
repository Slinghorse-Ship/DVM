<?php
declare(strict_types=1);

namespace Kulturhus\Native\Cms;

use PDO;
use RuntimeException;

final class Schema
{
    public static function install(PDO $db): void
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('Das native CMS benötigt MySQL.');
        }
        $tables = [
            "CREATE TABLE IF NOT EXISTS khcms_trash (
                page_id CHAR(32) NOT NULL PRIMARY KEY, club_id VARCHAR(64) NOT NULL,
                trashed_by VARCHAR(64) NOT NULL, trashed_at DATETIME(6) NOT NULL,
                KEY cms_trash_club (club_id,trashed_at,page_id),
                CONSTRAINT cms_trash_original FOREIGN KEY (page_id) REFERENCES khcms_pages(id) ON DELETE RESTRICT
            )",
            "CREATE TABLE IF NOT EXISTS khcms_definitions (
                club_id VARCHAR(64) NOT NULL, kind VARCHAR(12) NOT NULL, definition_key VARCHAR(64) NOT NULL,
                version INT UNSIGNED NOT NULL, definition_json MEDIUMTEXT NOT NULL,
                updated_by VARCHAR(64) NOT NULL, updated_at DATETIME(6) NOT NULL,
                PRIMARY KEY (club_id,kind,definition_key)
            )",
            "CREATE TABLE IF NOT EXISTS khcms_definition_revisions (
                club_id VARCHAR(64) NOT NULL, kind VARCHAR(12) NOT NULL, definition_key VARCHAR(64) NOT NULL,
                version INT UNSIGNED NOT NULL, definition_json MEDIUMTEXT NOT NULL,
                actor_id VARCHAR(64) NOT NULL, created_at DATETIME(6) NOT NULL,
                PRIMARY KEY (club_id,kind,definition_key,version)
            )",
            "CREATE TABLE IF NOT EXISTS khcms_state (
                club_id VARCHAR(64) NOT NULL PRIMARY KEY,
                generation BIGINT UNSIGNED NOT NULL DEFAULT 0
            )",
            "CREATE TABLE IF NOT EXISTS khcms_archives (
                page_id CHAR(32) NOT NULL PRIMARY KEY, club_id VARCHAR(64) NOT NULL,
                archived_by VARCHAR(64) NOT NULL, archived_at DATETIME(6) NOT NULL,
                KEY cms_archive_club (club_id,page_id)
            )",
            "CREATE TABLE IF NOT EXISTS khcms_album_order (
                club_id VARCHAR(64) NOT NULL PRIMARY KEY, version INT UNSIGNED NOT NULL,
                ids_json MEDIUMTEXT NOT NULL, updated_by VARCHAR(64) NOT NULL, updated_at DATETIME(6) NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS khcms_album_order_revisions (
                club_id VARCHAR(64) NOT NULL, version INT UNSIGNED NOT NULL,
                ids_json MEDIUMTEXT NOT NULL, actor_id VARCHAR(64) NOT NULL, created_at DATETIME(6) NOT NULL,
                PRIMARY KEY (club_id,version)
            )",
            "CREATE TABLE IF NOT EXISTS khcms_pages (
                id CHAR(32) NOT NULL PRIMARY KEY, club_id VARCHAR(64) NOT NULL,
                slug VARCHAR(180) NOT NULL, version INT UNSIGNED NOT NULL,
                draft_json MEDIUMTEXT NOT NULL, published_revision INT UNSIGNED NULL,
                published_slug VARCHAR(180) NULL, created_by VARCHAR(64) NOT NULL,
                updated_by VARCHAR(64) NOT NULL, created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL, published_at DATETIME(6) NULL,
                UNIQUE KEY cms_draft_slug (club_id, slug),
                UNIQUE KEY cms_published_slug (club_id, published_slug),
                KEY cms_club_pages (club_id, updated_at)
            )",
            "CREATE TABLE IF NOT EXISTS khcms_path_history (
                id CHAR(32) NOT NULL UNIQUE, club_id VARCHAR(64) NOT NULL,
                slug VARCHAR(180) NOT NULL, page_id CHAR(32) NOT NULL,
                page_version INT UNSIGNED NOT NULL, created_by VARCHAR(64) NOT NULL,
                created_at DATETIME(6) NOT NULL,
                PRIMARY KEY (club_id,slug), KEY cms_history_page (club_id,page_id),
                CONSTRAINT cms_history_original FOREIGN KEY (page_id) REFERENCES khcms_pages(id) ON DELETE CASCADE
            )",
            "CREATE TABLE IF NOT EXISTS khcms_revisions (
                page_id CHAR(32) NOT NULL, revision INT UNSIGNED NOT NULL,
                club_id VARCHAR(64) NOT NULL, payload_json MEDIUMTEXT NOT NULL,
                actor_id VARCHAR(64) NOT NULL, action VARCHAR(20) NOT NULL,
                created_at DATETIME(6) NOT NULL,
                PRIMARY KEY (page_id, revision), KEY cms_club_revisions (club_id, page_id),
                CONSTRAINT cms_revision_page FOREIGN KEY (page_id) REFERENCES khcms_pages(id) ON DELETE CASCADE
            )",
            "CREATE TABLE IF NOT EXISTS khcms_media (
                id CHAR(32) NOT NULL PRIMARY KEY, club_id VARCHAR(64) NOT NULL,
                original_name VARCHAR(240) NOT NULL, alt VARCHAR(500) NOT NULL,
                mime VARCHAR(32) NOT NULL, width INT UNSIGNED NOT NULL, height INT UNSIGNED NOT NULL,
                variants_json TEXT NOT NULL, sha256 CHAR(64) NOT NULL,
                created_by VARCHAR(64) NOT NULL, created_at DATETIME(6) NOT NULL,
                KEY cms_club_media (club_id, created_at)
            )",
            "CREATE TABLE IF NOT EXISTS khcms_published_media (
                page_id CHAR(32) NOT NULL, media_id CHAR(32) NOT NULL,
                club_id VARCHAR(64) NOT NULL, PRIMARY KEY (page_id, media_id),
                KEY cms_public_media (club_id, media_id),
                CONSTRAINT cms_public_media_page FOREIGN KEY (page_id) REFERENCES khcms_pages(id) ON DELETE CASCADE,
                CONSTRAINT cms_public_media_asset FOREIGN KEY (media_id) REFERENCES khcms_media(id) ON DELETE RESTRICT
            )",
        ];
        // The trash foreign key requires the original page table on a fresh installation.
        $trash = array_shift($tables);
        $tables[] = $trash;
        foreach ($tables as $sql) {
            $db->exec($sql . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }
        PageBatches::install($db);
        TreeCopyJobs::install($db);
        PagePurge::install($db);
        TemplatePolicy::install($db);
        Navigation::install($db);
        PublicationSchedule::install($db);
        \Kulturhus\Native\Events\Schema::install($db);
        EventIndex::install($db);
        EventPublications::install($db);
        WebsiteSettings::install($db);
        NewsPolicy::install($db);
        MediaMetadata::install($db);
        MediaQuota::install($db);
        MediaPolicy::install($db);
        MediaArchive::install($db);
        MediaRemoval::install($db);
        MediaRetention::install($db);
        WebsiteDocuments::install($db);
        DocumentQuota::install($db);
        WebsiteDesign::install($db);
    }
}
