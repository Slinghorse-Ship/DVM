-- Immutable first requests and source/result evidence; existing claims are retained unchanged.
CREATE TABLE IF NOT EXISTS finance_reimbursement_actions (
 club_id VARCHAR(128) NOT NULL,
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 entry_id VARCHAR(128) NOT NULL,
 claim_id VARCHAR(128) NOT NULL,
 action VARCHAR(16) NOT NULL,
 request_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 actor_id VARCHAR(128) NOT NULL,
 created_at VARCHAR(40) NOT NULL,
 request_json LONGTEXT COLLATE utf8mb4_bin NOT NULL,
 request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 review_json LONGTEXT COLLATE utf8mb4_bin NOT NULL,
 review_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 result_json LONGTEXT COLLATE utf8mb4_bin NOT NULL,
 result_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(club_id,id),
 UNIQUE KEY reimbursement_action_request(club_id,request_key),
 KEY reimbursement_action_claim(club_id,claim_id,created_at,id),
 KEY reimbursement_action_entry(club_id,entry_id,created_at,id),
 FOREIGN KEY(club_id,entry_id) REFERENCES finance_entries(club_id,id),
 FOREIGN KEY(club_id,claim_id) REFERENCES finance_reimbursement_claims(club_id,id),
 CONSTRAINT kh130_reimbursement_action_kind CHECK(CAST(action AS BINARY) IN ('create','approve','associate')),
 CONSTRAINT kh130_reimbursement_action_keys CHECK(REGEXP_LIKE(id,'^[a-f0-9]{32}$','c') AND REGEXP_LIKE(request_key,'^[a-f0-9]{32}$','c')),
 CONSTRAINT kh130_reimbursement_action_originals CHECK(
  JSON_VALID(request_json) AND JSON_TYPE(request_json)='OBJECT' AND
  JSON_VALID(review_json) AND JSON_TYPE(review_json)='OBJECT' AND
  JSON_VALID(result_json) AND JSON_TYPE(result_json)='OBJECT' AND
  CAST(request_sha256 AS BINARY)=CAST(SHA2(request_json,256) AS BINARY) AND
  CAST(review_sha256 AS BINARY)=CAST(SHA2(review_json,256) AS BINARY) AND
  CAST(result_sha256 AS BINARY)=CAST(SHA2(result_json,256) AS BINARY)
 )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
