CREATE TABLE IF NOT EXISTS event_voucher_receipts (
 club_id VARCHAR(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 request_key CHAR(32) COLLATE utf8mb4_unicode_ci NOT NULL,
 payload_json MEDIUMTEXT COLLATE utf8mb4_bin NOT NULL,
 PRIMARY KEY(club_id,request_key),
 CONSTRAINT event_voucher_receipt_request FOREIGN KEY(club_id,request_key)
  REFERENCES event_voucher_requests(club_id,request_key),
 CONSTRAINT event_voucher_receipt_json CHECK(JSON_VALID(payload_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
