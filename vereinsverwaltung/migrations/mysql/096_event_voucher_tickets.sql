CREATE TABLE IF NOT EXISTS event_voucher_tickets (
 club_id VARCHAR(128) NOT NULL, id VARCHAR(128) NOT NULL, voucher_id VARCHAR(128) NOT NULL,
 unit_no INT NOT NULL, code CHAR(16) NOT NULL, state VARCHAR(16) NOT NULL DEFAULT 'active',
 redeemed_move_id VARCHAR(128) NULL,
 PRIMARY KEY(club_id,id), UNIQUE KEY ev_ticket_unit(club_id,voucher_id,unit_no), UNIQUE KEY ev_ticket_code(club_id,code),
 FOREIGN KEY(club_id,voucher_id) REFERENCES event_vouchers(club_id,id),
 FOREIGN KEY(club_id,redeemed_move_id) REFERENCES event_voucher_moves(club_id,id),
 CHECK(unit_no>0), CHECK(state IN ('active','redeemed','revoked'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
