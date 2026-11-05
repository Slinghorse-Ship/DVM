-- Additive report drafts and immutable revision history. No updates to existing business data.
CREATE TABLE IF NOT EXISTS activity_report_reviews (
 club_id varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 id varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
 report_kind varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'tax',
 from_year int NOT NULL,
 to_year int NOT NULL,
 version int NOT NULL DEFAULT 1,
 state varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'review',
 body mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
 supplement mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
 source_hash char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
 approved_by varchar(128) COLLATE utf8mb4_unicode_ci NULL,
 approved_at varchar(40) COLLATE utf8mb4_unicode_ci NULL,
 approval_json mediumtext COLLATE utf8mb4_unicode_ci NULL,
 approved_pdf mediumblob NULL,
 updated_by varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 updated_at varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
 PRIMARY KEY(club_id,id),
 UNIQUE KEY activity_report_period(club_id,report_kind,from_year,to_year),
 CONSTRAINT activity_report_club FOREIGN KEY(club_id) REFERENCES clubs(id),
 CONSTRAINT activity_report_state CHECK(state IN ('review','approved')),
 CONSTRAINT activity_report_kind CHECK(report_kind IN ('tax','assembly')),
 CONSTRAINT activity_report_version CHECK(version>0),
 CONSTRAINT activity_report_json CHECK(approval_json IS NULL OR JSON_VALID(approval_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_report_revisions (
 club_id varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 report_id varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
 version int NOT NULL,
 snapshot mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
 pdf mediumblob NULL,
 PRIMARY KEY(club_id,report_id,version),
 CONSTRAINT activity_revision_report FOREIGN KEY(club_id,report_id) REFERENCES activity_report_reviews(club_id,id),
 CONSTRAINT activity_revision_json CHECK(JSON_VALID(snapshot))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
