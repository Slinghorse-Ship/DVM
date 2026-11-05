-- Additive attachment linkage, durable negative feedback and retrieval checkpoints.
CREATE TABLE IF NOT EXISTS tax_filing_evidence (
 club_id varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 filing_id varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
 evidence_id varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
 PRIMARY KEY(club_id,filing_id),
 UNIQUE KEY tax_evidence_child(club_id,evidence_id),
 CONSTRAINT tax_evidence_parent FOREIGN KEY(club_id,filing_id) REFERENCES tax_filings(club_id,id),
 CONSTRAINT tax_evidence_filing FOREIGN KEY(club_id,evidence_id) REFERENCES tax_filings(club_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS tax_attachment_feedback (
 club_id varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 filing_id varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
 provision_id varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
 transfer_ticket varchar(256) COLLATE utf8mb4_unicode_ci NOT NULL,
 payload mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
 payload_sha256 char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
 details mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
 received_by varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 received_at varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
 acknowledged_at varchar(40) COLLATE utf8mb4_unicode_ci NULL,
 PRIMARY KEY(club_id,filing_id,provision_id),
 CONSTRAINT tax_feedback_filing FOREIGN KEY(club_id,filing_id) REFERENCES tax_filings(club_id,id),
 CONSTRAINT tax_feedback_json CHECK(JSON_VALID(details))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS tax_attachment_checks (
 club_id varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 filing_id varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
 cursor_id varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
 checked_at varchar(40) COLLATE utf8mb4_unicode_ci NULL,
 last_error mediumtext COLLATE utf8mb4_unicode_ci NULL,
 PRIMARY KEY(club_id,filing_id),
 CONSTRAINT tax_check_filing FOREIGN KEY(club_id,filing_id) REFERENCES tax_filings(club_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
