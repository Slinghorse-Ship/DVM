CREATE TABLE IF NOT EXISTS paypal_balance_snapshots (
 club_id varchar(128) NOT NULL, mapping_id varchar(128) NOT NULL,
 observed_at varchar(40) NOT NULL, payload JSON NOT NULL,
 PRIMARY KEY(club_id,mapping_id),
 FOREIGN KEY(club_id,mapping_id) REFERENCES bank_account_mappings(club_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE bank_account_mappings DROP CHECK kh86_ck_3a637106a20c99866513e41a,
 ADD CONSTRAINT kh111_bank_provider CHECK (CAST(provider AS CHAR CHARACTER SET BINARY) IN ('enable','fints','paypal'));
