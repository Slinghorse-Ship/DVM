-- A complete asset sale can use its issued invoice exactly once within its club.
CREATE TABLE IF NOT EXISTS asset_sale_links (
 club_id varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 item_id varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 year int NOT NULL,
 entry_id varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
 PRIMARY KEY(club_id,item_id),
 UNIQUE KEY asset_sale_invoice(club_id,entry_id),
 CONSTRAINT asset_sale_annual FOREIGN KEY(club_id,item_id,year) REFERENCES asset_book_years(club_id,item_id,year),
 CONSTRAINT asset_sale_entry FOREIGN KEY(club_id,entry_id) REFERENCES finance_entries(club_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
