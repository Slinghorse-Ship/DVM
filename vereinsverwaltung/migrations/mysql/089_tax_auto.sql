-- Additive drafts only; never alters journal, members, documents or existing filings.
CREATE TABLE IF NOT EXISTS tax_auto_cases (
 club_id varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 tax_year int NOT NULL,
 version int NOT NULL DEFAULT 1,
 input_data mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
 filing_id varchar(32) COLLATE utf8mb4_unicode_ci NULL,
 updated_by varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 updated_at varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
 PRIMARY KEY(club_id,tax_year),
 CONSTRAINT tax_auto_club FOREIGN KEY(club_id) REFERENCES clubs(id),
 CONSTRAINT tax_auto_filing FOREIGN KEY(club_id,filing_id) REFERENCES tax_filings(club_id,id),
 CONSTRAINT tax_auto_json CHECK(JSON_VALID(input_data)),
 CONSTRAINT tax_auto_version CHECK(version>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
