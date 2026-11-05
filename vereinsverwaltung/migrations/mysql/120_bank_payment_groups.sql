CREATE TABLE IF NOT EXISTS bank_payment_groups (
 club_id VARCHAR(128) NOT NULL,
 id CHAR(32) NOT NULL,
 account_id VARCHAR(128) NOT NULL,
 record_id VARCHAR(128) NOT NULL,
 total_cents BIGINT NOT NULL,
 original_json LONGTEXT NOT NULL,
 original_sha256 CHAR(64) NOT NULL,
 created_by VARCHAR(128) NOT NULL,
 created_at VARCHAR(40) NOT NULL,
 PRIMARY KEY(club_id,id),
 KEY bank_group_account(club_id,account_id,total_cents),
 FOREIGN KEY(club_id,account_id) REFERENCES finance_accounts(club_id,id),
 FOREIGN KEY(club_id,record_id) REFERENCES bank_import_records(club_id,id),
 CHECK(total_cents<>0 AND JSON_VALID(original_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_payment_group_items (
 club_id VARCHAR(128) NOT NULL,
 group_id CHAR(32) NOT NULL,
 transaction_id VARCHAR(128) NOT NULL,
 ordinal INT NOT NULL,
 cents BIGINT NOT NULL,
 PRIMARY KEY(club_id,group_id,transaction_id),
 UNIQUE KEY bank_group_ordinal(club_id,group_id,ordinal),
 KEY bank_group_transaction(club_id,transaction_id),
 FOREIGN KEY(club_id,group_id) REFERENCES bank_payment_groups(club_id,id),
 FOREIGN KEY(club_id,transaction_id) REFERENCES finance_transactions(club_id,id),
 CHECK(cents<>0 AND ordinal BETWEEN 1 AND 40)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_payment_group_links (
 club_id VARCHAR(128) NOT NULL,
 id CHAR(32) NOT NULL,
 record_id VARCHAR(128) NOT NULL,
 record_version INT NOT NULL,
 group_id CHAR(32) NOT NULL,
 operation VARCHAR(20) NOT NULL,
 actor_id VARCHAR(128) NOT NULL,
 reason VARCHAR(500) NOT NULL,
 original_json LONGTEXT NOT NULL,
 original_sha256 CHAR(64) NOT NULL,
 created_at VARCHAR(40) NOT NULL,
 version INT NOT NULL DEFAULT 1,
 released_at VARCHAR(40) NULL,
 released_by VARCHAR(128) NULL,
 release_reason VARCHAR(500) NULL,
 PRIMARY KEY(club_id,id),
 UNIQUE KEY bank_group_first(club_id,record_id,record_version),
 KEY bank_group_record(club_id,record_id,released_at),
 KEY bank_group_proof(club_id,group_id,released_at),
 FOREIGN KEY(club_id,record_id) REFERENCES bank_import_records(club_id,id),
 FOREIGN KEY(club_id,group_id) REFERENCES bank_payment_groups(club_id,id),
 CHECK(record_version>0 AND operation IN('existing','duplicate')),
 CHECK(JSON_VALID(original_json)),
 CHECK((released_at IS NULL AND released_by IS NULL AND release_reason IS NULL AND version=1)
  OR (released_at IS NOT NULL AND released_by IS NOT NULL AND release_reason IS NOT NULL AND version=2))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
