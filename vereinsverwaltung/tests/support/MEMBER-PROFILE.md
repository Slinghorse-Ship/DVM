# Repeatable member profile browser regression

MySQL prerequisite: [MYSQL-FIXTURES.md](MYSQL-FIXTURES.md). The commands below require a live parent-owned test database and compatible isolated browser runtime; they do not provision either.

These helpers create a new synthetic tenant for each run in a parent-owned isolated MySQL database on `mysql-test. They do not load frühere Integration or production configuration, use only invented names and example.invalid` addresses, and leave all access-mail requests disabled. No test worker or mail sender is started.

Provision the isolated MySQL/browser environment described in [MYSQL-FIXTURES.md](MYSQL-FIXTURES.md). Use the same PHP executable for fixture generation and browser readback. PHP needs PDO MySQL and mbstring; Node needs Playwright and an installed Edge browser (or `KULTURHUS_BROWSER_CHANNEL`).

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
$env:KULTURHUS_MEMBER_PROFILE_FIXTURE = & $env:KULTURHUS_TEST_PHP vereinsverwaltung/tests/support/member-profile-fixture.php
if ($LASTEXITCODE -ne 0) { throw 'Fixture creation failed.' }
node vereinsverwaltung/tests/member-profile-browser.cjs
```

`NODE_PATH` may point at an existing Playwright installation. Generate a fresh fixture for each full run. The generator prints only the private fixture path under `.local/member-profile-fixtures/<unique tenant>/`; this contains generated test sessions and must not be committed. Browser results and synthetic desktop/mobile screenshots are written alongside it. Old fixtures remain available for inspection; these helpers do not delete or replace databases.

The browser verifies the server's checkout header before writes and permits requests only to the local origin. The readback helper accepts only a generated fixture path, starts a MySQL read-only transaction, and reads only that tenant. The test covers existing profiles, company contacts, new member/contact creation, exact-date/year-only entry, family assignment and payer changes, collapsed form fields, invalid hidden fields, stale POST preservation, independent form ownership, own-profile read permissions, and 390px layout. Every claimed persistence check includes an actual form POST followed by fresh GET and database comparison.

To verify the role editor with the same isolation, generate a **new** fixture as above and run `node vereinsverwaltung/tests/role-editor-http-browser.cjs` instead. It saves an invented role, changes and revokes website permissions, assigns it only to the synthetic reader, checks the comparison table and effective rights, verifies stale submissions and the JavaScript-disabled fallback, and checks 390px layout. `role-editor-readback.php` reads only the generated tenant. The independent `role-editor-controls-browser.cjs` exercises the smaller browser-control behavior; it does not replace the HTTP roundtrip test.
