<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;
/** Additive tables; web requests never install or modify the schema. */
final class Schema
{
    public static function install(\PDO $db):void
    {
        if($db->getAttribute(\PDO::ATTR_DRIVER_NAME)!=='mysql'||$db->inTransaction())throw new \DomainException('Sprachen nur im Wartungsweg installieren.');
        foreach([
            'native_language_settings'=>'club_id VARCHAR(64) PRIMARY KEY,version INT UNSIGNED NOT NULL,config_json MEDIUMTEXT NOT NULL,actor_id VARCHAR(64) NOT NULL,changed_at DATETIME(6) NOT NULL',
            'native_language_settings_revisions'=>'club_id VARCHAR(64) NOT NULL,version INT UNSIGNED NOT NULL,config_json MEDIUMTEXT NOT NULL,actor_id VARCHAR(64) NOT NULL,changed_at DATETIME(6) NOT NULL,PRIMARY KEY(club_id,version)',
            'native_language_rights'=>'club_id VARCHAR(64) NOT NULL,actor_id VARCHAR(64) NOT NULL,locale VARCHAR(16) NOT NULL,version INT UNSIGNED NOT NULL,capabilities_json TEXT NOT NULL,PRIMARY KEY(club_id,actor_id,locale)',
            'native_language_right_revisions'=>'club_id VARCHAR(64) NOT NULL,subject_id VARCHAR(64) NOT NULL,locale VARCHAR(16) NOT NULL,version INT UNSIGNED NOT NULL,capabilities_json TEXT NOT NULL,actor_id VARCHAR(64) NOT NULL,changed_at DATETIME(6) NOT NULL,PRIMARY KEY(club_id,subject_id,locale,version)',
            'native_language_pages'=>'club_id VARCHAR(64) NOT NULL,page_id CHAR(32) NOT NULL,locale VARCHAR(16) NOT NULL,version INT UNSIGNED NOT NULL,source_revision INT UNSIGNED NOT NULL,draft_json MEDIUMTEXT NOT NULL,published_revision INT UNSIGNED NULL,PRIMARY KEY(club_id,page_id,locale)',
            'native_language_revisions'=>'club_id VARCHAR(64) NOT NULL,page_id CHAR(32) NOT NULL,locale VARCHAR(16) NOT NULL,revision INT UNSIGNED NOT NULL,source_revision INT UNSIGNED NOT NULL,payload_json MEDIUMTEXT NOT NULL,actor_id VARCHAR(64) NOT NULL,action VARCHAR(16) NOT NULL,changed_at DATETIME(6) NOT NULL,PRIMARY KEY(club_id,page_id,locale,revision)',
            'native_language_routes'=>'club_id VARCHAR(64) NOT NULL,locale VARCHAR(16) NOT NULL,slug VARCHAR(180) NOT NULL,page_id CHAR(32) NOT NULL,PRIMARY KEY(club_id,locale,slug),KEY language_page_route(club_id,page_id,locale)',
            'native_language_preferences'=>'club_id VARCHAR(64) NOT NULL,actor_id VARCHAR(64) NOT NULL,locale VARCHAR(16) NOT NULL,PRIMARY KEY(club_id,actor_id)',
        ] as $table=>$columns)$db->exec('CREATE TABLE IF NOT EXISTS '.$table.' ('.$columns.') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
    }
}
