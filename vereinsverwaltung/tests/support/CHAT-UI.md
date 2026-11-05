# Reproducible local chat UI regression

MySQL prerequisite: [MYSQL-FIXTURES.md](MYSQL-FIXTURES.md). The commands below require a live parent-owned test database and compatible isolated browser runtime; they do not provision either.

Use the existing synthetic server on `http://127.0.0.1:8091` in the same checkout. Do not use a production login or database. The fixture guard rejects production environment overrides and accepts only the parent-owned `kh_test_*` database on `mysql-test`.

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
$env:KULTURHUS_CHAT_UI_FIXTURE = & $env:KULTURHUS_TEST_PHP vereinsverwaltung/tests/support/chat-ui-fixture.php
if ($LASTEXITCODE -ne 0) { throw 'Fixture creation failed.' }
node vereinsverwaltung/tests/chat-ui-browser.cjs
```

PHP needs PDO MySQL and mbstring. Node needs an existing Playwright installation and Edge. `NODE_PATH` can point to the installed dependency directory.

The fixture creates a unique synthetic club, four fictional contacts/accounts and three fictional conversations. One has 205 messages to exercise pagination. Generated sessions and result artifacts remain in ignored `.local/chat-ui-fixtures/<run>/`. The browser verifies the served checkout hash and blocks external page requests. No push device is registered, no real email account is configured, and the synthetic runtime never starts a production push worker.

`chat-ui-control.php` is query-only by default and only accepts a generated private fixture file. Its sole mutation, `peer-message`, adds a fixed fictional message to one of that fixture's two predefined conversations, simulating another participant during refresh.

The fifteen browser/HTTP/DB checks cover layout and room search, missing creation fields, actual creation, sending only by button, failed-send body/request-key retention, stale edit body/token retention, successful editing, staged PNG upload plus download, participant addition and outsider protection, refresh without sending or losing draft/reading position, 200-message history and read marker, non-owner rights/leaving, 390px layout and absence of JavaScript errors or finance/document/mail/push-device changes.

Generate a fresh fixture for each full run. Results and desktop/mobile screenshots are written beside its `fixture.json`. Do not commit the fixture, session values or private output. The existing `chat-extensions.php` separately covers service permissions, idempotent participant addition, stale/ABA edit rejection and access loss after leaving. These checks do not claim live push delivery or production transport validation.

## Conversation navigation regression

With a fresh fixture and the same environment, run `node vereinsverwaltung/tests/chat-navigation-browser.cjs`. This uses two independent signed-in browser contexts (desktop owner and mobile participant). Nine checks exercise actual conversation creation and two message submissions, their immediate redirects and reloads, stale `#chat-create` links, opening/cancelling a new conversation, failed-send draft retention, manual refresh, and the real 15-second polling interval. It also confirms exactly one new conversation and two correctly attributed messages, with unchanged finance/document/mail/push-device counts.

The regression deliberately inspects the redirected page **before** navigating elsewhere: the former `#chat-create` fragment survived POST redirects, so the create panel reopened even though the selected conversation ID was correct. `navigation-result.json` and two synthetic screenshots are written beside the fixture. No test message is sent to live users.

## Push activation UI and endpoint contract

With another fresh fixture run `node vereinsverwaltung/tests/push-ui-browser.cjs`. Select an installed engine with `KULTURHUS_TEST_BROWSER=edge|chromium|webkit|firefox` (default: Edge). The suite reports the actual engine separately from deliberately emulated device identifiers and Notification/ServiceWorker/PushManager APIs. It does **not** test a physical iPhone/Android device or actual APNs/FCM delivery.

Twenty-one cases cover iPhone Safari versus Home Screen, iPad desktop identifiers, Android Chrome/Edge and desktop API paths, user-triggered permission, default/denied/missing capabilities, insecure context, bounded worker activation and scope, authentication/HTTP/invalid-response errors, cleanup, disabling without a VAPID key, and truthful partial-unsubscribe failure. Successful browser API doubles submit real CSRF-protected `push-save` and `push-remove` requests to this fixture's local account, and the MySQL readback confirms storage/removal. No push provider is contacted; no messages are sent; finance, documents, mail, and final subscription counts remain unchanged. Results are stored as `push-ui-<engine>.json` with two synthetic UI screenshots.
