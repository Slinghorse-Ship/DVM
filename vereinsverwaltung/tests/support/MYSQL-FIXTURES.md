# Isolated MySQL support fixtures

All database-backed helpers use `tests/mysql-bootstrap.php`. A parent process creates a disposable database with `MySqlFixture::connect()` and passes `MySqlFixture::dsn($db)` as `KULTURHUS_DEMO_DATABASE` to its children. The parent must stay alive through fixture generation, application requests, and readback; it drops the database on shutdown. Children use `existing(dsn)` and cannot accept an arbitrary server or database name.

Required environment: `VEREIN_TEST_MYSQL=isolated-regression-only`, `VEREIN_MODE=synthetic`, `KULTURHUS_DISABLE_MAIL=1`, `VEREIN_DATABASE_USER=root`, `VEREIN_DATABASE_PASSWORD=isolated-regression-only`, and the parent-provided `KULTURHUS_DEMO_DATABASE`. Leave `VEREIN_DATABASE` and `VEREIN_AUTH` empty. PHP processes need access to the internal Docker service `mysql-test`; a host PHP process cannot use these DSNs directly. Readback opens an actual read-only MySQL transaction. Snapshot ordering uses explicit primary keys, including composite keys.

Run the support regression from the checkout root in PowerShell:

```powershell
$fixtureRepo = (Get-Location).Path
docker run --rm --network kulturhus-mysql-regression `
  -e VEREIN_TEST_MYSQL=isolated-regression-only `
  --mount "type=bind,source=$fixtureRepo/vereinsverwaltung,target=/workspace/vereinsverwaltung,readonly" `
  --mount "type=bind,source=$fixtureRepo/früheres CMS,target=/workspace/früheres CMS,readonly" `
  --mount "type=bind,source=$fixtureRepo/banking,target=/workspace/banking,readonly" `
  --tmpfs /workspace/.local --tmpfs /workspace/tmp `
  --workdir /workspace --entrypoint php kulturhus-mysql-regression:php8.4 `
  vereinsverwaltung/tests/support/mysql-fixtures-regression.php
```

The regression keeps the database alive while spawning the existing generators and readbacks, verifies cross-process persistence, exercises the synthetic chat control mutation, and checks database integrity and write rejection in read-only transactions. Generated files and synthetic sessions stay in the container's temporary mounts and disappear with the container. No mail, browser server, bank connection, or worker is started.

The browser recipes in this folder still require a compatible synthetic server at loopback port 8091, with the same DSN, checkout hash, sessions, and private files. They are not covered by the support regression. The current runtime requires `KULTURHUS_LOCAL_DEMO=1` to select a MySQL demo database, but that flag also selects the Apache/8092 request gate and URL prefix in `LocalRuntime`. Adapting the old standalone 8091 browser launcher and runtime is outside this folder's scope. Do not substitute the shared früheres CMS instance or a real database.

`recovery-verify.php` encrypts and verifies a real association MySQL dump, imports the decrypted archive bytes into a new database, and checks schema, integrity, foreign keys, and original document bytes. Its website SQL entry remains an explicit synthetic placeholder. Synthetic runtime-components, MySQL credentials, cron-secret, and member-bank key payloads test encryption and checksum verification, including key tampering; they do not prove runDaily's file selection.

`recovery-backup.phpfrühere Integrationsdateienmysql-test` in an isolated `kh_test_*` database with mail disabled. The standard MySQL runner does not provision that fixture. No successful native recovery is implied by the portable archive test.
