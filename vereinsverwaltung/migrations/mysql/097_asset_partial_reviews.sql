-- Prepared reviews ONLY. These rows are not asset events, annual values or postings.
CREATE TABLE IF NOT EXISTS asset_partial_reviews (
 club_id varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 id char(32) COLLATE utf8mb4_bin NOT NULL,
 item_id varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 tax_year int NOT NULL,
 revision int NOT NULL,
 request_key char(32) COLLATE utf8mb4_bin NOT NULL,
 request_sha256 char(64) COLLATE utf8mb4_bin NOT NULL,
 payload mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
 sha256 char(64) COLLATE utf8mb4_bin NOT NULL,
 PRIMARY KEY(club_id,id),
 UNIQUE KEY partial_review_revision(club_id,item_id,tax_year,revision),
 UNIQUE KEY partial_review_request(club_id,request_key),
 CONSTRAINT partial_review_plan FOREIGN KEY(club_id,item_id) REFERENCES asset_book_plans(club_id,item_id),
 CONSTRAINT partial_review_year FOREIGN KEY(club_id,tax_year) REFERENCES balance_years(club_id,tax_year),
 CONSTRAINT partial_review_revision_number CHECK(revision BETWEEN 1 AND 1000),
 CONSTRAINT partial_review_json CHECK(JSON_VALID(payload))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
