# Native MySQL migrations

The application supports MySQL only. `mysql/083_baseline.sql` preserves the earlier MySQL structure for upgrade tests; new installations use the current native baseline selected by `Database::migrate()`.

Migrations run explicitly under an operator lock, not during ordinary page requests. Never use the retired historische Dateidatenbank importer or copy an empty/demo database over an existing installation.

The 83 obsolete historische Dateidatenbank SQL scripts were removed from this active directory after byte-for-byte comparison with `.local/before-mysql-only-20260912/migrations`. They remain recoverable there and in Git history. No historical database or recovery backup was deleted.
