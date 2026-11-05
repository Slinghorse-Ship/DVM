-- Append-only application history. The current source binding is only a pointer.
CREATE TABLE IF NOT EXISTS balance_source_history (
 club_id varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 source_type varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
 source_id varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
 revision int NOT NULL,
 payload mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
 sha256 char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
 PRIMARY KEY(club_id,source_type,source_id,revision),
 CONSTRAINT balance_history_source FOREIGN KEY(club_id,source_type,source_id) REFERENCES balance_sources(club_id,source_type,source_id),
 CONSTRAINT balance_history_revision CHECK(revision>0),
 CONSTRAINT balance_history_payload CHECK(JSON_VALID(payload))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
