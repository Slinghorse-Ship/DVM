<?php declare(strict_types=1);
namespace Kulturhus\Native\System;

final class Schema
{
    public static function install(\PDO $db):void
    {
        if($db->inTransaction()||$db->getAttribute(\PDO::ATTR_DRIVER_NAME)!=='mysql')throw new \DomainException('Systemschema über die private native Schemaverwaltung installieren.');
        foreach([
            'native_system_scopes'=>"club_id VARCHAR(128) NOT NULL PRIMARY KEY",
            'native_system_tasks'=>"club_id VARCHAR(128) NOT NULL,task VARCHAR(40) NOT NULL,version INT UNSIGNED NOT NULL,enabled TINYINT NOT NULL,interval_minutes INT NOT NULL,owner_id VARCHAR(128) NOT NULL,manager_actor VARCHAR(128) NULL,account_version INT UNSIGNED NOT NULL,next_due BIGINT NULL,failures INT NOT NULL DEFAULT 0,lease_hash CHAR(64) NULL,lease_until BIGINT NULL,run_id CHAR(32) NULL,updated_at BIGINT NOT NULL,PRIMARY KEY(club_id,task),KEY due(enabled,next_due)",
            'native_system_runs'=>"id CHAR(32) NOT NULL,club_id VARCHAR(128) NOT NULL,task VARCHAR(40) NOT NULL,status VARCHAR(16) NOT NULL,attempt INT NOT NULL,started_at BIGINT NOT NULL,heartbeat_at BIGINT NOT NULL,finished_at BIGINT NULL,summary_json JSON NULL,PRIMARY KEY(id),KEY history(club_id,started_at,id),KEY retention(club_id,finished_at,id)",
            'native_system_events'=>"id CHAR(32) NOT NULL,club_id VARCHAR(128) NOT NULL,code VARCHAR(40) NOT NULL,severity VARCHAR(12) NOT NULL,notice TINYINT NOT NULL,context_json JSON NOT NULL,source_hash CHAR(64) NOT NULL,created_at BIGINT NOT NULL,PRIMARY KEY(id),UNIQUE KEY dedup(club_id,source_hash),KEY journal(club_id,created_at,id),KEY retention(club_id,notice,created_at,id)",
            'native_system_acknowledgements'=>"club_id VARCHAR(128) NOT NULL,event_id CHAR(32) NOT NULL,actor_id VARCHAR(128) NOT NULL,acknowledged_at BIGINT NOT NULL,PRIMARY KEY(club_id,event_id,actor_id)",
        ]as$table=>$columns)$db->exec('CREATE TABLE IF NOT EXISTS '.$table.'('.$columns.') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
    }
}
