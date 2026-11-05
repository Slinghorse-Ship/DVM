CREATE TABLE IF NOT EXISTS appointment_series (
 club_id VARCHAR(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 id CHAR(32) COLLATE utf8mb4_bin NOT NULL,
 template_json MEDIUMTEXT COLLATE utf8mb4_bin NOT NULL,
 rule_json MEDIUMTEXT COLLATE utf8mb4_bin NOT NULL,
 attendees_json MEDIUMTEXT COLLATE utf8mb4_bin NOT NULL,
 version INT NOT NULL,
 created_by VARCHAR(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 created_at VARCHAR(40) COLLATE utf8mb4_bin NOT NULL,
 PRIMARY KEY(club_id,id),
 CONSTRAINT appointment_series_club FOREIGN KEY(club_id) REFERENCES clubs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS appointment_series_occurrences (
 club_id VARCHAR(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 series_id CHAR(32) COLLATE utf8mb4_bin NOT NULL,
 ordinal INT NOT NULL,
 appointment_id VARCHAR(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 managed_version INT NOT NULL,
 managed_state VARCHAR(16) COLLATE utf8mb4_bin NOT NULL,
 is_exception TINYINT NOT NULL DEFAULT 0,
 origin_json MEDIUMTEXT COLLATE utf8mb4_bin NOT NULL,
 PRIMARY KEY(club_id,series_id,ordinal),
 UNIQUE KEY appointment_series_event(club_id,appointment_id),
 CONSTRAINT appointment_series_parent FOREIGN KEY(club_id,series_id) REFERENCES appointment_series(club_id,id),
 CONSTRAINT appointment_series_state CHECK(managed_state IN ('active','retired')),
 CONSTRAINT appointment_series_exception CHECK(is_exception IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS appointment_series_intents (
 club_id VARCHAR(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 request_key CHAR(32) COLLATE utf8mb4_bin NOT NULL,
 actor_id VARCHAR(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 series_id CHAR(32) COLLATE utf8mb4_bin NOT NULL,
 request_hash CHAR(64) COLLATE utf8mb4_bin NOT NULL,
 operation VARCHAR(24) COLLATE utf8mb4_bin NOT NULL,
 payload_json MEDIUMTEXT COLLATE utf8mb4_bin NOT NULL,
 outcome_json MEDIUMTEXT COLLATE utf8mb4_bin NOT NULL,
 created_at VARCHAR(40) COLLATE utf8mb4_bin NOT NULL,
 sequence BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 PRIMARY KEY(club_id,request_key),
 UNIQUE KEY appointment_series_sequence(sequence),
 CONSTRAINT appointment_series_intent FOREIGN KEY(club_id,series_id) REFERENCES appointment_series(club_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
