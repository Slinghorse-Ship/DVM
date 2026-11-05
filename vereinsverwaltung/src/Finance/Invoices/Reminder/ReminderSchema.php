<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Invoices\Reminder;

use PDO;
use Kulturhus\Verein\Settings\GeneralRequestSchema;

/** Explicit installer/upgrade step. Financial requests only check availability. */
final class ReminderSchema
{
    public static function install(PDO $db):void
    {
        if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$db->inTransaction())throw new \DomainException('Erinnerungsablage benötigt einen eigenen Installationsschritt.');
        GeneralRequestSchema::install($db);
        $db->exec("CREATE TABLE IF NOT EXISTS native_invoice_reminder_deliveries (
            club_id VARCHAR(128) NOT NULL,id CHAR(32) NOT NULL,invoice_id CHAR(32) NOT NULL,
            actor_id VARCHAR(128) NOT NULL,request_key CHAR(32) NOT NULL,
            document_id CHAR(32) NOT NULL,document_sha256 CHAR(64) NOT NULL,
            source_sha256 CHAR(64) NOT NULL,message_sha256 CHAR(64) NOT NULL,
            sealed_message LONGTEXT NOT NULL,recipient VARCHAR(254) NOT NULL,
            state VARCHAR(24) NOT NULL,version INT UNSIGNED NOT NULL,
            attempt_id CHAR(32) NULL,reason_code VARCHAR(64) NOT NULL DEFAULT '',
            created_at DATETIME(6) NOT NULL,updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY(club_id,id),UNIQUE(club_id,request_key),
            INDEX(club_id,invoice_id,state,created_at,id),INDEX(state,created_at,id),
            FOREIGN KEY(club_id,request_key) REFERENCES native_settings_requests(club_id,request_key),
            CHECK(state IN ('pending','sending','sent','blocked','uncertain','cancelled','superseded')),
            CHECK(version>=1)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS native_invoice_reminder_attempts (
            club_id VARCHAR(128) NOT NULL,id CHAR(32) NOT NULL,delivery_id CHAR(32) NOT NULL,
            actor_id VARCHAR(128) NOT NULL,message_sha256 CHAR(64) NOT NULL,
            state VARCHAR(24) NOT NULL,external_reference VARCHAR(200) NOT NULL DEFAULT '',
            reason_code VARCHAR(64) NOT NULL DEFAULT '',resolution_key CHAR(32) NULL,
            started_at DATETIME(6) NOT NULL,completed_at DATETIME(6) NULL,
            PRIMARY KEY(club_id,id),UNIQUE(club_id,delivery_id),
            FOREIGN KEY(club_id,delivery_id) REFERENCES native_invoice_reminder_deliveries(club_id,id),
            CHECK(state IN ('claimed','sent','uncertain','not_sent'))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
    }
}
