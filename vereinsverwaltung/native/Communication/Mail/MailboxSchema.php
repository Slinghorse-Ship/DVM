<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication\Mail;

/** Installed only by explicit schema maintenance, never by a mailbox request. */
final class MailboxSchema
{
    public static function install(\PDO $db):void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS native_mailbox_messages (
            club_id VARCHAR(64) NOT NULL,id CHAR(32) NOT NULL,binding CHAR(32) NOT NULL,
            uidvalidity BIGINT UNSIGNED NOT NULL,message_uid BIGINT UNSIGNED NOT NULL,
            original_hash CHAR(64) NOT NULL,envelope_cipher MEDIUMTEXT NOT NULL,original_cipher LONGBLOB NOT NULL,
            state VARCHAR(16) NOT NULL,assigned_to VARCHAR(64) NULL,version INT UNSIGNED NOT NULL,
            received_at DATETIME(6) NOT NULL,
            PRIMARY KEY(club_id,id),UNIQUE(club_id,binding,uidvalidity,message_uid),
            INDEX(club_id,state,received_at,id),INDEX(club_id,assigned_to,state,received_at,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS native_mailbox_replies (
            club_id VARCHAR(64) NOT NULL,id CHAR(32) NOT NULL,message_id CHAR(32) NOT NULL,
            version INT UNSIGNED NOT NULL,state VARCHAR(16) NOT NULL,ciphertext MEDIUMTEXT NOT NULL,
            created_by VARCHAR(64) NOT NULL,sent_by VARCHAR(64) NULL,updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY(club_id,id),INDEX(club_id,message_id,updated_at,id),
            FOREIGN KEY(club_id,message_id) REFERENCES native_mailbox_messages(club_id,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS native_mailbox_events (
            club_id VARCHAR(64) NOT NULL,message_id CHAR(32) NOT NULL,version INT UNSIGNED NOT NULL,
            actor_id VARCHAR(64) NOT NULL,ciphertext MEDIUMTEXT NOT NULL,happened_at DATETIME(6) NOT NULL,
            PRIMARY KEY(club_id,message_id,version),
            FOREIGN KEY(club_id,message_id) REFERENCES native_mailbox_messages(club_id,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
    }
}
