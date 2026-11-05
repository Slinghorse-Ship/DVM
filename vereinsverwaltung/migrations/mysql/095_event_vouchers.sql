CREATE TABLE IF NOT EXISTS event_voucher_events (
 club_id VARCHAR(128) NOT NULL, id VARCHAR(128) NOT NULL, source_id VARCHAR(128) NOT NULL,
 title VARCHAR(255) NOT NULL, starts_at VARCHAR(128) NOT NULL, location TEXT NOT NULL,
 enabled TINYINT NOT NULL DEFAULT 1, version INT NOT NULL DEFAULT 1,
 PRIMARY KEY(club_id,id), UNIQUE KEY ev_source(club_id,source_id),
 FOREIGN KEY(club_id) REFERENCES clubs(id), CHECK(enabled IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS event_vouchers (
 club_id VARCHAR(128) NOT NULL, id VARCHAR(128) NOT NULL, event_id VARCHAR(128) NOT NULL,
 contact_id VARCHAR(128) NOT NULL, label VARCHAR(120) NOT NULL, kind VARCHAR(16) NOT NULL,
 unit VARCHAR(16) NOT NULL, granted INT NOT NULL, remaining INT NOT NULL, code CHAR(16) NOT NULL,
 state VARCHAR(16) NOT NULL DEFAULT 'active', version INT NOT NULL DEFAULT 1,
 created_at VARCHAR(128) NOT NULL,
 PRIMARY KEY(club_id,id), UNIQUE KEY ev_code(club_id,code), KEY ev_person(club_id,event_id,contact_id),
 FOREIGN KEY(club_id,event_id) REFERENCES event_voucher_events(club_id,id),
 FOREIGN KEY(club_id,contact_id) REFERENCES contacts(club_id,id),
 CHECK(kind IN ('food','drink','other')), CHECK(unit IN ('piece','eur')),
 CHECK(granted>0 AND granted<=1000000 AND remaining>=0 AND remaining<=granted),
 CHECK(state IN ('active','revoked')), CHECK(state='active' OR remaining=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS event_voucher_moves (
 club_id VARCHAR(128) NOT NULL, id VARCHAR(128) NOT NULL, voucher_id VARCHAR(128) NOT NULL,
 actor_id VARCHAR(128) NOT NULL, kind VARCHAR(16) NOT NULL, amount INT NOT NULL,
 reversal_of VARCHAR(128) NULL, reason VARCHAR(255) NOT NULL, created_at VARCHAR(128) NOT NULL,
 PRIMARY KEY(club_id,id), UNIQUE KEY ev_reversal(club_id,reversal_of),
 FOREIGN KEY(club_id,voucher_id) REFERENCES event_vouchers(club_id,id),
 FOREIGN KEY(actor_id) REFERENCES actors(id),
 FOREIGN KEY(club_id,reversal_of) REFERENCES event_voucher_moves(club_id,id),
 CHECK(kind IN ('grant','redeem','reverse','revoke')),
 CHECK((kind IN ('grant','reverse') AND amount>0) OR (kind='redeem' AND amount<0) OR (kind='revoke' AND amount<=0)),
 CHECK((kind='reverse' AND reversal_of IS NOT NULL) OR (kind<>'reverse' AND reversal_of IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS event_voucher_helpers (
 club_id VARCHAR(128) NOT NULL, event_id VARCHAR(128) NOT NULL, actor_id VARCHAR(128) NOT NULL,
 PRIMARY KEY(club_id,event_id,actor_id),
 FOREIGN KEY(club_id,event_id) REFERENCES event_voucher_events(club_id,id),
 FOREIGN KEY(actor_id) REFERENCES actors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS event_voucher_requests (
 club_id VARCHAR(128) NOT NULL, request_key CHAR(32) NOT NULL, actor_id VARCHAR(128) NOT NULL,
 fingerprint CHAR(64) NOT NULL, result_json TEXT NOT NULL, created_at VARCHAR(128) NOT NULL,
 PRIMARY KEY(club_id,request_key), FOREIGN KEY(club_id) REFERENCES clubs(id), FOREIGN KEY(actor_id) REFERENCES actors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS event_voucher_outbox (
 club_id VARCHAR(128) NOT NULL, id VARCHAR(128) NOT NULL, event_id VARCHAR(128) NOT NULL,
 contact_id VARCHAR(128) NOT NULL, actor_id VARCHAR(128) NOT NULL, email VARCHAR(255) NOT NULL,
 snapshot_json MEDIUMTEXT NOT NULL, fingerprint CHAR(64) NOT NULL,
 state VARCHAR(16) NOT NULL DEFAULT 'pending', error VARCHAR(255) NOT NULL DEFAULT '', updated_at VARCHAR(128) NOT NULL,
 PRIMARY KEY(club_id,id), KEY ev_delivery(state,updated_at),
 FOREIGN KEY(club_id,event_id) REFERENCES event_voucher_events(club_id,id),
 FOREIGN KEY(club_id,contact_id) REFERENCES contacts(club_id,id), FOREIGN KEY(actor_id) REFERENCES actors(id),
 CHECK(state IN ('pending','sending','sent','uncertain','blocked','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
