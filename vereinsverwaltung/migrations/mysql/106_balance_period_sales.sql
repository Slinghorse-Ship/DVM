-- A sale invoice and an asset belong to one immutable dated disposal only.
CREATE TABLE IF NOT EXISTS balance_period_asset_sales (
 club_id varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 item_id varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 entry_id varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 period_id char(17) COLLATE utf8mb4_bin NOT NULL,
 event_revision int NOT NULL,
 PRIMARY KEY(club_id,item_id),
 UNIQUE KEY balance_period_sale_invoice(club_id,entry_id),
 CONSTRAINT balance_period_sale_item FOREIGN KEY(club_id,item_id) REFERENCES inventory_items(club_id,id),
 CONSTRAINT balance_period_sale_invoice_fk FOREIGN KEY(club_id,entry_id) REFERENCES finance_entries(club_id,id),
 CONSTRAINT balance_period_sale_event FOREIGN KEY(club_id,period_id,event_revision) REFERENCES balance_period_events(club_id,period_id,revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
