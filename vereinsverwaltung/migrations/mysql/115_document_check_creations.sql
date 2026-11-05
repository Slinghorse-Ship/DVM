CREATE TABLE IF NOT EXISTS document_check_creations (
 club_id VARCHAR(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 actor_id VARCHAR(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 request_key CHAR(32) COLLATE utf8mb4_bin NOT NULL,
 intent_json MEDIUMTEXT COLLATE utf8mb4_bin NOT NULL,
 intent_sha256 CHAR(64) COLLATE utf8mb4_bin NOT NULL,
 result_id VARCHAR(128) COLLATE utf8mb4_unicode_ci NULL,
 title MEDIUMTEXT COLLATE utf8mb4_bin NULL,
 original_json MEDIUMTEXT COLLATE utf8mb4_bin NULL,
 original_sha256 CHAR(64) COLLATE utf8mb4_bin NULL,
 created_at VARCHAR(40) COLLATE utf8mb4_bin NOT NULL,
 PRIMARY KEY(club_id,actor_id,request_key),
 CONSTRAINT document_check_creation_source FOREIGN KEY(club_id,result_id) REFERENCES document_checks(club_id,id),
 CONSTRAINT document_check_creation_json CHECK(JSON_VALID(intent_json) AND (original_json IS NULL OR JSON_VALID(original_json)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
