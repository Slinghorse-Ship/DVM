-- Permanent ownership of each imported source; corrections are appended to period events.
CREATE TABLE IF NOT EXISTS balance_period_sources (
 club_id varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 source_type varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
 source_id varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
 period_id char(17) COLLATE utf8mb4_bin NOT NULL,
 event_revision int NOT NULL,
 PRIMARY KEY(club_id,source_type,source_id),
 CONSTRAINT balance_period_source_event FOREIGN KEY(club_id,period_id,event_revision) REFERENCES balance_period_events(club_id,period_id,revision),
 CONSTRAINT balance_period_source_type CHECK(source_type IN ('invoice','transaction'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
