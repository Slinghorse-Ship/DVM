# Repeatable inventory form regression

MySQL prerequisite: [MYSQL-FIXTURES.md](MYSQL-FIXTURES.md). The commands below require a live parent-owned test database and compatible isolated browser runtime; they do not provision either.

The fixture generator creates a new, isolated synthetic club on each run. It contains three invented inventory records, two storage locations, one invented contact and one synthetic expense. It imports no files, sends no mail and uses no production configuration.

Requirements: PHP with PDO MySQL and mbstring, Node.js with Playwright, and an installed Edge browser (or `KULTURHUS_BROWSER_CHANNEL`). Run from the repository root. `NODE_PATH` may point to an existing installation of Playwright; no package installation is required by these helpers.

Provision the isolated MySQL/browser environment first; see [MYSQL-FIXTURES.md](MYSQL-FIXTURES.md). The former standalone launcher is not sufficient:

```powershell
# Use a parent-owned MySqlFixture database and an isolated synthetic browser server.
```

The fixture generator, browser server, and readback processes must share the same isolated MySQL DSN while its parent remains alive. See the current browser-runtime gap in MYSQL-FIXTURES.md before running the browser commands.

In another terminal in the same checkout:

```powershell
$env:VEREIN_MODE = 'synthetic'
# Inherit KULTURHUS_DEMO_DATABASE from the live parent MySqlFixture process.
$env:VEREIN_TEST_MYSQL = 'isolated-regression-only'
$env:VEREIN_DATABASE_USER = 'root'
$env:VEREIN_DATABASE_PASSWORD = 'isolated-regression-only'
$env:VEREIN_DATABASE = ''
$env:VEREIN_AUTH = ''
$env:KULTURHUS_DISABLE_MAIL = '1'
$env:KULTURHUS_TEST_BASE = 'http://127.0.0.1:8091'
$env:KULTURHUS_TEST_PHP = (Get-Command php).Source
$env:KULTURHUS_INVENTORY_FIELDS_FIXTURE = & $env:KULTURHUS_TEST_PHP vereinsverwaltung/tests/support/inventory-fields-fixture.php
if ($LASTEXITCODE -ne 0) { throw 'Fixture creation failed.' }
node vereinsverwaltung/tests/inventory-fields-browser.cjs
```

Use the same PHP executable for the server and fixture commands. The generator prints only the path of its new private `fixture.json` under `.local/inventory-fields-fixtures/<unique-run>/`. That file contains the generated test session and must not be committed. Sessions stay in the application's local session folder. There are no persisted IDs, credentials, real names or real documents in the versioned support code.

The guard requires explicit synthetic mode, rejects production database/auth configuration and non-loopback URLs, and only accepts the parent-owned `kh_test_*` MySQL database. The browser also checks the server's checkout header before submitting. The readback helper accepts only generated fixture paths, starts a MySQL read-only transaction, and reads only the synthetic tenant.

The test performs eleven real form/HTTP roundtrips covering metadata, unit/content, prices and evidence, serial numbers, mandatory-field feedback, stale submissions, quantity correction, transfer, selected-piece retirement, individual tracking and 390px layout. Generate a fresh fixture before each full run. Previous synthetic runs remain available for inspection; no cleanup or destructive database operation is performed.
