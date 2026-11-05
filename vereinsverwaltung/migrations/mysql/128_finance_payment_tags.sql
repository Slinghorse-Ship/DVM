CREATE TABLE IF NOT EXISTS finance_payment_tags (
 club_id VARCHAR(128) NOT NULL,
 id CHAR(32) NOT NULL,
 name VARCHAR(80) NOT NULL,
 name_key CHAR(64) NOT NULL,
 active TINYINT NOT NULL,
 version INT NOT NULL,
 PRIMARY KEY(club_id,id),
 UNIQUE KEY payment_tag_name(club_id,name_key),
 CHECK(active IN(0,1) AND version>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS finance_payment_tag_states (
 club_id VARCHAR(128) NOT NULL,
 transaction_id VARCHAR(128) NOT NULL,
 version INT NOT NULL,
 PRIMARY KEY(club_id,transaction_id),
 FOREIGN KEY(club_id,transaction_id) REFERENCES finance_transactions(club_id,id),
 CHECK(version>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS finance_payment_tag_assignments (
 club_id VARCHAR(128) NOT NULL,
 transaction_id VARCHAR(128) NOT NULL,
 tag_id CHAR(32) NOT NULL,
 PRIMARY KEY(club_id,transaction_id,tag_id),
 FOREIGN KEY(club_id,transaction_id) REFERENCES finance_payment_tag_states(club_id,transaction_id),
 FOREIGN KEY(club_id,tag_id) REFERENCES finance_payment_tags(club_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS finance_payment_tag_events (
 club_id VARCHAR(128) NOT NULL,
 id CHAR(32) NOT NULL,
 scope VARCHAR(16) NOT NULL,
 object_id VARCHAR(128) NOT NULL,
 source_version INT NOT NULL,
 result_version INT NOT NULL,
 actor_id VARCHAR(128) NOT NULL,
 happened_at VARCHAR(40) NOT NULL,
 request_key CHAR(32) NOT NULL,
 request_json LONGTEXT NOT NULL,
 request_sha256 CHAR(64) NOT NULL,
 previous_snapshot LONGTEXT NOT NULL,
 next_snapshot LONGTEXT NOT NULL,
 PRIMARY KEY(club_id,id),
 UNIQUE KEY payment_tag_request(club_id,request_key),
 UNIQUE KEY payment_tag_revision(club_id,scope,object_id,result_version),
 CHECK(scope IN('catalogue','transaction')),
 CHECK(source_version>=0 AND result_version=source_version+1),
 CHECK(JSON_VALID(request_json) AND JSON_VALID(previous_snapshot) AND JSON_VALID(next_snapshot))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
