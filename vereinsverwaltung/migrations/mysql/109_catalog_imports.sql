CREATE TABLE IF NOT EXISTS catalog_imports (
 club_id varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 id char(32) COLLATE utf8mb4_bin NOT NULL,
 from_year int NOT NULL,
 target_year int NOT NULL,
 version int NOT NULL,
 state varchar(32) COLLATE utf8mb4_bin NOT NULL,
 payload mediumtext COLLATE utf8mb4_bin NOT NULL,
 actor_id varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 updated_at varchar(40) COLLATE utf8mb4_bin NOT NULL,
 PRIMARY KEY(club_id,id),
 CONSTRAINT catalog_import_club FOREIGN KEY(club_id) REFERENCES clubs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS catalog_import_files (
 club_id varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 import_id char(32) COLLATE utf8mb4_bin NOT NULL,
 revision int NOT NULL,
 name varchar(64) COLLATE utf8mb4_bin NOT NULL,
 sha256 char(64) COLLATE utf8mb4_bin NOT NULL,
 bytes mediumblob NOT NULL,
 PRIMARY KEY(club_id,import_id,revision,name),
 CONSTRAINT catalog_import_file_parent FOREIGN KEY(club_id,import_id) REFERENCES catalog_imports(club_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
