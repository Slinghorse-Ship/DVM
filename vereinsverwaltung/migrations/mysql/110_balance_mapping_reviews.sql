CREATE TABLE balance_mapping_reviews (
 club_id varchar(128) NOT NULL, tax_year int NOT NULL, version int NOT NULL,
 source_sha256 char(64) NOT NULL, mapping mediumtext NOT NULL,
 actor_id varchar(128) NOT NULL, happened_at varchar(40) NOT NULL,
 PRIMARY KEY(club_id,tax_year), FOREIGN KEY(club_id) REFERENCES clubs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE balance_mapping_review_history (
 club_id varchar(128) NOT NULL, tax_year int NOT NULL, version int NOT NULL,
 source_sha256 char(64) NOT NULL, mapping mediumtext NOT NULL,
 actor_id varchar(128) NOT NULL, happened_at varchar(40) NOT NULL,
 PRIMARY KEY(club_id,tax_year,version), FOREIGN KEY(club_id) REFERENCES clubs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
