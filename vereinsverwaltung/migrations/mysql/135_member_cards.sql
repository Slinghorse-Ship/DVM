-- Membership-card originals are independent of payments, mail and wallet providers.
CREATE TABLE IF NOT EXISTS member_cards (
 club_id VARCHAR(128) NOT NULL,
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 contact_id VARCHAR(128) NOT NULL,
 membership_id VARCHAR(128) NOT NULL,
 issued_by VARCHAR(128) NOT NULL,
 issued_at DATETIME NOT NULL,
 valid_until DATE NOT NULL,
 snapshot_json MEDIUMTEXT NOT NULL,
 source_json MEDIUMTEXT NOT NULL,
 source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 token_cipher MEDIUMTEXT NOT NULL,
 PRIMARY KEY(club_id,id),
 UNIQUE KEY member_card_token(token_hash),
 UNIQUE KEY member_card_owner(club_id,contact_id,id),
 KEY member_card_history(club_id,contact_id,issued_at,id),
 CONSTRAINT member_card_person FOREIGN KEY(club_id,contact_id) REFERENCES contacts(club_id,id),
 CONSTRAINT member_card_membership FOREIGN KEY(club_id,membership_id) REFERENCES memberships(club_id,id),
 CONSTRAINT member_card_snapshot CHECK(JSON_VALID(snapshot_json)),
 CONSTRAINT member_card_source CHECK(JSON_VALID(source_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_card_heads (
 club_id VARCHAR(128) NOT NULL,
 contact_id VARCHAR(128) NOT NULL,
 card_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 version INT UNSIGNED NOT NULL,
 revoked TINYINT UNSIGNED NOT NULL DEFAULT 0,
 reason VARCHAR(500) NOT NULL DEFAULT '',
 updated_by VARCHAR(128) NOT NULL,
 updated_at DATETIME NOT NULL,
 PRIMARY KEY(club_id,contact_id),
 CONSTRAINT member_card_head_owner FOREIGN KEY(club_id,contact_id,card_id) REFERENCES member_cards(club_id,contact_id,id),
 CONSTRAINT member_card_head_version CHECK(version>0),
 CONSTRAINT member_card_head_revoked CHECK(revoked IN(0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_card_commands (
 club_id VARCHAR(128) NOT NULL,
 request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 actor_id VARCHAR(128) NOT NULL,
 contact_id VARCHAR(128) NOT NULL,
 operation VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 card_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 result_json MEDIUMTEXT NOT NULL,
 happened_at DATETIME NOT NULL,
 PRIMARY KEY(club_id,request_id),
 KEY member_card_command_history(club_id,contact_id,happened_at,request_id),
 CONSTRAINT member_card_command_owner FOREIGN KEY(club_id,contact_id,card_id) REFERENCES member_cards(club_id,contact_id,id),
 CONSTRAINT member_card_command_kind CHECK(operation IN('issue','revoke')),
 CONSTRAINT member_card_command_result CHECK(JSON_VALID(result_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_card_wallet_accounts (
 club_id VARCHAR(128) NOT NULL,
 provider VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 version INT UNSIGNED NOT NULL,
 enabled TINYINT UNSIGNED NOT NULL DEFAULT 0,
 public_json MEDIUMTEXT NOT NULL,
 ciphertext MEDIUMTEXT NULL,
 credential_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 checked_at DATETIME NULL,
 updated_by VARCHAR(128) NOT NULL,
 updated_at DATETIME NOT NULL,
 PRIMARY KEY(club_id,provider),
 CONSTRAINT member_card_wallet_provider CHECK(provider IN('apple','google')),
 CONSTRAINT member_card_wallet_version CHECK(version>0),
 CONSTRAINT member_card_wallet_enabled CHECK(enabled IN(0,1)),
 CONSTRAINT member_card_wallet_public CHECK(JSON_VALID(public_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
