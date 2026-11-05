<?php declare(strict_types=1);
namespace Kulturhus\Native\Chat;

use PDO;
use RuntimeException;

/** Chat tables are independent of CMS, documents, and delivery workers. */
final class Schema
{
    public static function install(PDO $db): void
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('Der native Chat benötigt MySQL.');
        }
        $tables = [
            'native_chat_threads' => 'id CHAR(32) NOT NULL, club_id VARCHAR(64) NOT NULL, title VARCHAR(150) NOT NULL,
                owner_id VARCHAR(64) NOT NULL, version INT UNSIGNED NOT NULL DEFAULT 1,
                created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
                PRIMARY KEY (id), UNIQUE KEY thread_scope (club_id,id)',
            'native_chat_people' => 'club_id VARCHAR(64) NOT NULL, thread_id CHAR(32) NOT NULL,
                actor_id VARCHAR(64) NOT NULL, last_read BIGINT UNSIGNED NOT NULL DEFAULT 0,
                joined_at DATETIME(6) NOT NULL, PRIMARY KEY (club_id,thread_id,actor_id),
                KEY people_actor (club_id,actor_id,thread_id),
                FOREIGN KEY (club_id,thread_id) REFERENCES native_chat_threads(club_id,id)',
            'native_chat_messages' => 'seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                club_id VARCHAR(64) NOT NULL, thread_id CHAR(32) NOT NULL, actor_id VARCHAR(64) NOT NULL,
                body TEXT NOT NULL, version INT UNSIGNED NOT NULL DEFAULT 1,
                request_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                request_hash CHAR(64) CHARACTER SET ascii NOT NULL,
                created_at DATETIME(6) NOT NULL, edited_at DATETIME(6) NULL,
                PRIMARY KEY (seq), UNIQUE KEY message_scope (club_id,thread_id,seq),
                UNIQUE KEY request_once (club_id,thread_id,actor_id,request_key),
                FOREIGN KEY (club_id,thread_id) REFERENCES native_chat_threads(club_id,id)',
            'native_chat_revisions' => 'club_id VARCHAR(64) NOT NULL, thread_id CHAR(32) NOT NULL,
                message_seq BIGINT UNSIGNED NOT NULL, version INT UNSIGNED NOT NULL, body TEXT NOT NULL,
                actor_id VARCHAR(64) NOT NULL, saved_at DATETIME(6) NOT NULL,
                PRIMARY KEY (club_id,thread_id,message_seq,version),
                FOREIGN KEY (club_id,thread_id,message_seq) REFERENCES native_chat_messages(club_id,thread_id,seq)',
            'native_chat_polls' => 'id CHAR(32) NOT NULL, club_id VARCHAR(64) NOT NULL, thread_id CHAR(32) NOT NULL,
                message_seq BIGINT UNSIGNED NOT NULL, author_id VARCHAR(64) NOT NULL, question VARCHAR(500) NOT NULL,
                closes_at DATETIME(6) NULL, closed_at DATETIME(6) NULL, version INT UNSIGNED NOT NULL DEFAULT 1,
                PRIMARY KEY (id), UNIQUE KEY poll_scope (club_id,thread_id,id),
                UNIQUE KEY poll_message (club_id,thread_id,message_seq),
                FOREIGN KEY (club_id,thread_id,message_seq) REFERENCES native_chat_messages(club_id,thread_id,seq)',
            'native_chat_poll_options' => 'club_id VARCHAR(64) NOT NULL, thread_id CHAR(32) NOT NULL,
                poll_id CHAR(32) NOT NULL, option_number INT UNSIGNED NOT NULL, label VARCHAR(160) NOT NULL,
                PRIMARY KEY (club_id,thread_id,poll_id,option_number),
                FOREIGN KEY (club_id,thread_id,poll_id) REFERENCES native_chat_polls(club_id,thread_id,id)',
            'native_chat_poll_votes' => 'club_id VARCHAR(64) NOT NULL, thread_id CHAR(32) NOT NULL,
                poll_id CHAR(32) NOT NULL, actor_id VARCHAR(64) NOT NULL, option_number INT UNSIGNED NOT NULL,
                version INT UNSIGNED NOT NULL, updated_at DATETIME(6) NOT NULL,
                PRIMARY KEY (club_id,thread_id,poll_id,actor_id),
                FOREIGN KEY (club_id,thread_id,poll_id,option_number) REFERENCES native_chat_poll_options(club_id,thread_id,poll_id,option_number)',
            'native_chat_vote_requests' => 'club_id VARCHAR(64) NOT NULL, thread_id CHAR(32) NOT NULL,
                poll_id CHAR(32) NOT NULL, actor_id VARCHAR(64) NOT NULL,
                request_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                request_hash CHAR(64) CHARACTER SET ascii NOT NULL, resulting_version INT UNSIGNED NOT NULL,
                PRIMARY KEY (club_id,thread_id,poll_id,actor_id,request_key),
                FOREIGN KEY (club_id,thread_id,poll_id) REFERENCES native_chat_polls(club_id,thread_id,id)',
            'native_chat_replies' => 'club_id VARCHAR(64) NOT NULL, thread_id CHAR(32) NOT NULL,
                message_seq BIGINT UNSIGNED NOT NULL, reply_to BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY(club_id,thread_id,message_seq),
                FOREIGN KEY(club_id,thread_id,message_seq) REFERENCES native_chat_messages(club_id,thread_id,seq),
                FOREIGN KEY(club_id,thread_id,reply_to) REFERENCES native_chat_messages(club_id,thread_id,seq)',
            'native_chat_files' => 'club_id VARCHAR(64) NOT NULL, thread_id CHAR(32) NOT NULL,
                message_seq BIGINT UNSIGNED NOT NULL, file_id CHAR(32) NOT NULL, name VARCHAR(200) NOT NULL,
                mime VARCHAR(80) NOT NULL, bytes INT UNSIGNED NOT NULL, sha256 CHAR(64) NOT NULL,
                PRIMARY KEY(club_id,thread_id,message_seq), UNIQUE(file_id),
                FOREIGN KEY(club_id,thread_id,message_seq) REFERENCES native_chat_messages(club_id,thread_id,seq)',
            'native_chat_file_extras' => 'club_id VARCHAR(64) NOT NULL, thread_id CHAR(32) NOT NULL,
                message_seq BIGINT UNSIGNED NOT NULL, ordinal INT UNSIGNED NOT NULL,
                file_id CHAR(32) NOT NULL, name VARCHAR(200) NOT NULL,
                mime VARCHAR(80) NOT NULL, bytes INT UNSIGNED NOT NULL, sha256 CHAR(64) NOT NULL,
                PRIMARY KEY(club_id,thread_id,message_seq,ordinal), UNIQUE(file_id),
                CHECK(ordinal BETWEEN 2 AND 20),
                FOREIGN KEY(club_id,thread_id,message_seq) REFERENCES native_chat_files(club_id,thread_id,message_seq)',
            'native_chat_alert_preferences' => 'club_id VARCHAR(64) NOT NULL, actor_id VARCHAR(64) NOT NULL,
                enabled TINYINT NOT NULL DEFAULT 0, version INT UNSIGNED NOT NULL DEFAULT 1,
                PRIMARY KEY(club_id,actor_id)',
            'native_chat_mutes' => 'club_id VARCHAR(64) NOT NULL, thread_id CHAR(32) NOT NULL,
                actor_id VARCHAR(64) NOT NULL, muted TINYINT NOT NULL DEFAULT 0,
                PRIMARY KEY(club_id,thread_id,actor_id),
                FOREIGN KEY(club_id,thread_id) REFERENCES native_chat_threads(club_id,id)',
            'native_chat_devices' => 'club_id VARCHAR(64) NOT NULL, actor_id VARCHAR(64) NOT NULL,
                device_key CHAR(64) NOT NULL, enabled TINYINT NOT NULL DEFAULT 1,
                subscription_json TEXT NULL, updated_at DATETIME(6) NOT NULL,
                PRIMARY KEY(club_id,actor_id,device_key)',
            'native_chat_outbox' => 'club_id VARCHAR(64) NOT NULL, thread_id CHAR(32) NOT NULL,
                message_seq BIGINT UNSIGNED NOT NULL, actor_id VARCHAR(64) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT "pending", created_at DATETIME(6) NOT NULL,
                PRIMARY KEY(club_id,thread_id,message_seq,actor_id), KEY actor_status(club_id,actor_id,status),
                FOREIGN KEY(club_id,thread_id,message_seq) REFERENCES native_chat_messages(club_id,thread_id,seq)',
            'native_chat_push_deliveries' => 'club_id VARCHAR(64) NOT NULL, thread_id CHAR(32) NOT NULL,
                message_seq BIGINT UNSIGNED NOT NULL, actor_id VARCHAR(64) NOT NULL,
                subscription_id CHAR(32) NOT NULL, manager_club_id VARCHAR(64) NOT NULL,
                manager_actor_id VARCHAR(128) NOT NULL, subscription_hash CHAR(64) NOT NULL,
                payload JSON NOT NULL, state VARCHAR(16) NOT NULL DEFAULT "pending",
                created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
                PRIMARY KEY(club_id,thread_id,message_seq,actor_id,subscription_id),
                KEY push_scope(manager_club_id,state,created_at),
                FOREIGN KEY(club_id,thread_id,message_seq,actor_id) REFERENCES native_chat_outbox(club_id,thread_id,message_seq,actor_id)',
        ];
        foreach ($tables as $name => $definition) {
            $db->exec("CREATE TABLE IF NOT EXISTS $name ($definition) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        }
    }
}
