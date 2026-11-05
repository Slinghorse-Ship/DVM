# Inventory list and loan roundtrips

MySQL prerequisite: [MYSQL-FIXTURES.md](MYSQL-FIXTURES.md). The commands below require a live parent-owned test database and compatible isolated browser runtime; they do not provision either.

This regression uses the existing standalone synthetic server on loopback port 8091. It creates a new fictional club with two contacts, two locations, two items, one reservation and one issued loan. No production configuration, real names, invoice documents or mail accounts are used.

From the same checkout as the running local server:

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
$env:KULTURHUS_INVENTORY_FLOWS_FIXTURE = & $env:KULTURHUS_TEST_PHP vereinsverwaltung/tests/support/inventory-flows-fixture.php
if ($LASTEXITCODE -ne 0) { throw 'Fixture creation failed.' }
node vereinsverwaltung/tests/inventory-flows-browser.cjs
```

PHP requires PDO MySQL and mbstring. Node requires Playwright and an installed Edge browser (`KULTURHUS_BROWSER_CHANNEL` can override Edge). An existing Node dependency installation can be selected with `NODE_PATH`.

The test checks the served checkout hash before using its session. The environment guard accepts only the parent-owned `kh_test_*` database on `mysql-test`, rejects production database/auth variables and accepts only loopback port 8091. The browser blocks external requests. Readback uses a MySQL read-only transaction and only the fixture tenant. Session values stay in the ignored private fixture file; they are not printed or committed.

Fifteen checks cover:

- Consistent open-loan count and filtered destination.
- Category-only filter help, existing categories, zero matches and automatic result updates.
- Item search and whereabouts filter, complete-set contents and no repeated zero minimum hint.
- Real rejected issue, cancel, relocate and extend POSTs: all draft fields preserved, matching section open, DB unchanged.
- Required issue field visibly marked before any POST.
- Real two-position reservation with quantities and availability verified after reload.
- Cancellation and release of reserved quantities.
- Issue, date change and confirmed place change, including display under whereabouts and human actor names.
- Partial return to another location with usable and damaged complete sets separated.
- Remaining return completing the loan, with totals and status checked in DB and fresh GET.
- 390px list/form layout without page overflow and no JavaScript errors.
- No finance entries, transactions, documents or document links created by the inventory workflow.

Generate a fresh fixture for every run. The private run directory contains `result.json` and a mobile screenshot. No automatic destructive cleanup is performed. These tests prove the specified local roundtrips, not every role, external integration, live mail delivery or production deployment. The broad navigation audit is maintained separately in `manager-routes-browser.cjs`.
