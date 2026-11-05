CREATE TABLE IF NOT EXISTS dwf_creation_requests (
    club_id VARCHAR(128) COLLATE utf8mb4_bin NOT NULL,
    created_by VARCHAR(128) COLLATE utf8mb4_bin NOT NULL,
    request_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    intent_json LONGTEXT COLLATE utf8mb4_bin NOT NULL,
    intent_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    result_version INT UNSIGNED NULL,
    title VARCHAR(240) NOT NULL DEFAULT '',
    original_json LONGTEXT COLLATE utf8mb4_bin NULL,
    original_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (club_id,created_by,request_key),
    KEY dwf_creation_own (club_id,created_by,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
