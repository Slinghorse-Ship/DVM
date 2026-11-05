-- Additive source bindings; no existing finance records are changed.
CREATE TABLE IF NOT EXISTS balance_sources (
 club_id varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 source_type varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
 source_id varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
 tax_year int NOT NULL,
 source_hash char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
 snapshot mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
 options mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
 entry_ids mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
 created_by varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 created_at varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
 PRIMARY KEY(club_id,source_type,source_id),
 KEY balance_source_year(club_id,tax_year),
 CONSTRAINT balance_source_year_fk FOREIGN KEY(club_id,tax_year) REFERENCES balance_years(club_id,tax_year),
 CONSTRAINT balance_source_type CHECK(source_type IN ('invoice','transaction','depreciation')),
 CONSTRAINT balance_source_snapshot CHECK(JSON_VALID(snapshot)),
 CONSTRAINT balance_source_options CHECK(JSON_VALID(options)),
 CONSTRAINT balance_source_entries CHECK(JSON_VALID(entry_ids))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
