-- Internal-advance workpapers. No ledger postings or tax-export authorization.
CREATE TABLE IF NOT EXISTS balance_clearing_reviews (
 club_id varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 tax_year int NOT NULL,
 revision int NOT NULL,
 payload mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
 sha256 char(64) COLLATE utf8mb4_bin NOT NULL,
 PRIMARY KEY(club_id,tax_year,revision),
 CONSTRAINT balance_clearing_year FOREIGN KEY(club_id,tax_year) REFERENCES balance_years(club_id,tax_year),
 CONSTRAINT balance_clearing_revision CHECK(revision BETWEEN 1 AND 1000),
 CONSTRAINT balance_clearing_json CHECK(JSON_VALID(payload))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
