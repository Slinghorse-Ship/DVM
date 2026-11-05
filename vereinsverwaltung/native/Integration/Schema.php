<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration;
use PDO;
/** Explicit installation only; a request never installs or changes the manager schema. */
final class Schema
{
    public static function install(PDO $db): void
    {
        \Kulturhus\Native\Shared\Schema::install($db);
        require_once dirname(__DIR__,2).'/src/Settings/RoleRequestSchema.php';
        \Kulturhus\Verein\Settings\RoleRequestSchema::install($db);
        require_once dirname(__DIR__,2).'/src/Settings/GeneralRequestSchema.php';
        \Kulturhus\Verein\Settings\GeneralRequestSchema::install($db);
        // Explicit package step only: keep the assignment and its version when returning to defaults.
        $nullable=$db->query("SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='settings_role_assignments' AND column_name='role_id'")->fetchColumn();
        if($nullable==='NO')$db->exec("ALTER TABLE settings_role_assignments MODIFY role_id VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL");
        $db->exec("CREATE TABLE IF NOT EXISTS native_assignment_prices (
            club_id VARCHAR(128) NOT NULL,assignment_id CHAR(32) NOT NULL,
            version INT NOT NULL,mode VARCHAR(12) NOT NULL,value_units BIGINT NOT NULL,
            changed_by VARCHAR(128) NOT NULL,changed_at DATETIME(6) NOT NULL,
            PRIMARY KEY(club_id,assignment_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS native_assignment_price_changes (
            club_id VARCHAR(128) NOT NULL,request_key CHAR(32) NOT NULL,request_hash CHAR(64) NOT NULL,
            assignment_id CHAR(32) NOT NULL,version INT NOT NULL,actor_id VARCHAR(128) NOT NULL,
            state_json JSON NOT NULL,created_at DATETIME(6) NOT NULL,
            PRIMARY KEY(club_id,request_key),UNIQUE(club_id,assignment_id,version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS native_catalog_charges (
            club_id VARCHAR(128) NOT NULL,id CHAR(32) NOT NULL,
            assignment_id CHAR(32) NOT NULL,period_start DATE NOT NULL,period_end DATE NOT NULL,
            entry_id CHAR(32) NOT NULL,batch_key CHAR(32) NOT NULL,batch_hash CHAR(64) NOT NULL,
            ordinal SMALLINT NOT NULL,quote_json JSON NOT NULL,actor_id VARCHAR(128) NOT NULL,
            created_at DATETIME NOT NULL,PRIMARY KEY(club_id,id),
            UNIQUE(club_id,assignment_id,period_start,period_end),UNIQUE(club_id,batch_key,ordinal),
            UNIQUE(club_id,entry_id),INDEX(club_id,assignment_id,period_start,period_end)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS native_catalog_automation (
            club_id VARCHAR(128) NOT NULL,assignment_id CHAR(32) NOT NULL,
            version INT NOT NULL,status VARCHAR(24) NOT NULL,next_on DATE NOT NULL,
            schedule_day SMALLINT NOT NULL,interval_months SMALLINT NOT NULL,
            due_days SMALLINT NOT NULL,mode VARCHAR(8) NOT NULL,source_hash CHAR(64) NOT NULL,
            approved_quote JSON NOT NULL,approved_by VARCHAR(128) NOT NULL,reason VARCHAR(24) NOT NULL,
            changed_at DATETIME(6) NOT NULL,PRIMARY KEY(club_id,assignment_id),INDEX(club_id,status,next_on)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS native_catalog_automation_changes (
            club_id VARCHAR(128) NOT NULL,request_key CHAR(32) NOT NULL,request_hash CHAR(64) NOT NULL,
            assignment_id CHAR(32) NOT NULL,version INT NOT NULL,actor_id VARCHAR(128) NOT NULL,
            state_json JSON NOT NULL,created_at DATETIME(6) NOT NULL,PRIMARY KEY(club_id,request_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS native_catalog_automation_runs (
            club_id VARCHAR(128) NOT NULL,id CHAR(32) NOT NULL,assignment_id CHAR(32) NOT NULL,
            scheduled_on DATE NOT NULL,policy_version INT NOT NULL,status VARCHAR(24) NOT NULL,
            entry_id CHAR(32) NOT NULL,quote_json JSON NOT NULL,actor_id VARCHAR(128) NOT NULL,
            created_at DATETIME(6) NOT NULL,PRIMARY KEY(club_id,id),
            UNIQUE(club_id,assignment_id,scheduled_on),INDEX(club_id,assignment_id,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS native_manager_identities (
            native_id VARCHAR(64) PRIMARY KEY,
            manager_actor_id VARCHAR(128) NOT NULL,
            manager_club_id VARCHAR(128) NOT NULL,
            UNIQUE(manager_club_id,manager_actor_id),
            FOREIGN KEY(native_id) REFERENCES native_users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS native_member_onboarding (
            club_id VARCHAR(128) NOT NULL, contact_id VARCHAR(128) NOT NULL,
            account_id CHAR(32) NOT NULL, username VARCHAR(90) NOT NULL,
            email VARCHAR(254) NOT NULL, invite_cipher TEXT NULL, expires_at BIGINT NULL,
            created_at DATETIME NOT NULL, PRIMARY KEY(club_id,contact_id), UNIQUE(account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS native_identity_changes (
            id CHAR(32) PRIMARY KEY,club_id VARCHAR(128) NOT NULL,changed_by VARCHAR(128) NOT NULL,
            account_id CHAR(32) NOT NULL,action VARCHAR(100) NOT NULL,before_json MEDIUMTEXT NOT NULL,
            after_json MEDIUMTEXT NOT NULL,changed_at DATETIME NOT NULL,INDEX(club_id,account_id,changed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS native_daily_jobs (
            club_id VARCHAR(128) NOT NULL,job VARCHAR(32) NOT NULL,day DATE NOT NULL,
            status VARCHAR(24) NOT NULL,completed_at DATETIME NOT NULL,
            PRIMARY KEY(club_id,job,day)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS native_application_newsletters (
            club_id VARCHAR(64) NOT NULL,request_id CHAR(32) NOT NULL,application_id CHAR(32) NOT NULL,
            subscriber_id CHAR(32) NULL,job_id CHAR(32) NULL,status VARCHAR(24) NOT NULL,
            created_at DATETIME NOT NULL,PRIMARY KEY(club_id,request_id),INDEX(club_id,job_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS native_worker_runs (
            id CHAR(32) PRIMARY KEY,club_id VARCHAR(64) NOT NULL,status VARCHAR(24) NOT NULL,
            modes_json JSON NOT NULL,summary_json JSON NULL,started_at DATETIME NOT NULL,
            finished_at DATETIME NULL,INDEX(club_id,started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
    }
}
