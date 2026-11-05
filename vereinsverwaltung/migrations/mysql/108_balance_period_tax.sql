CREATE TABLE IF NOT EXISTS balance_period_tax_reviews (
 club_id varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 period_id char(17) COLLATE utf8mb4_bin NOT NULL,
 revision int NOT NULL,
 payload mediumtext COLLATE utf8mb4_bin NOT NULL,
 sha256 char(64) COLLATE utf8mb4_bin NOT NULL,
 PRIMARY KEY(club_id,period_id,revision),
 CONSTRAINT balance_period_tax_period FOREIGN KEY(club_id,period_id) REFERENCES balance_periods(club_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
