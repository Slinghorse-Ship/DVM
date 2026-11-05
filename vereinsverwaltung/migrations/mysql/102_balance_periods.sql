-- Period identities are dates, not a second use of the calendar-year primary key.
-- Existing calendar journals and their signed review histories remain untouched.
CREATE TABLE IF NOT EXISTS balance_periods (
 club_id varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 id char(17) COLLATE utf8mb4_bin NOT NULL,
 starts_on date NOT NULL,
 ends_on date NOT NULL,
 definition mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
 PRIMARY KEY(club_id,id),
 UNIQUE KEY balance_period_start(club_id,starts_on),
 UNIQUE KEY balance_period_end(club_id,ends_on),
 CONSTRAINT balance_period_club FOREIGN KEY(club_id) REFERENCES clubs(id),
 CONSTRAINT balance_period_dates CHECK(ends_on>=starts_on),
 CONSTRAINT balance_period_definition CHECK(JSON_VALID(definition))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS balance_period_events (
 club_id varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 period_id char(17) COLLATE utf8mb4_bin NOT NULL,
 revision int NOT NULL,
 payload mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
 sha256 char(64) COLLATE utf8mb4_bin NOT NULL,
 PRIMARY KEY(club_id,period_id,revision),
 CONSTRAINT balance_period_event_period FOREIGN KEY(club_id,period_id) REFERENCES balance_periods(club_id,id),
 CONSTRAINT balance_period_event_revision CHECK(revision BETWEEN 1 AND 20000),
 CONSTRAINT balance_period_event_json CHECK(JSON_VALID(payload))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
