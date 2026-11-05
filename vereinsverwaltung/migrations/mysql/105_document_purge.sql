-- Transaction-local permission for expressly confirmed, unused intake mistakes only.
CREATE TABLE IF NOT EXISTS document_purge_requests (
 club_id VARCHAR(128) NOT NULL,
 document_id VARCHAR(128) NOT NULL,
 document_version INTEGER NOT NULL,
 connection_id BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (club_id,document_id),
 FOREIGN KEY (club_id,document_id) REFERENCES documents(club_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS document_purge_mail_requests (
 club_id VARCHAR(128) NOT NULL,
 document_id VARCHAR(128) NOT NULL,
 mailbox_key VARCHAR(128) NOT NULL,
 message_uid VARCHAR(128) NOT NULL,
 PRIMARY KEY (club_id,document_id,mailbox_key,message_uid),
 FOREIGN KEY (club_id,document_id) REFERENCES document_purge_requests(club_id,document_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
