<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args', '1');
require dirname(__DIR__) . '/native/bootstrap.php';
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/support/original-database-hash.php';
use Kulturhus\Native\{TestSupport, Installer\ScheduledJobs, Installer\SchemaRegistry, Integration\ManagerBridge, Shared\Db, Shared\Identity};
use Kulturhus\Native\Integration\Jobs\{FinTs, Worker};

$cfg = TestSupport::config();
if (($cfg['owner'] ?? '') !== 'isolated-native-ci' || ($cfg['label'] ?? '') !== 'native-hosting-jobs') throw new RuntimeException('Exact owned hosting fixture required.');
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void { if (!$ok) throw new RuntimeException('FAIL ' . $label); ++$checks; echo 'PASS ' . $label . "\n"; };
$deny = static function (callable $f, string $label) use ($check): void { try { $f(); } catch (DomainException) { $check(true, $label); return; } throw new RuntimeException('Unexpected acceptance: ' . $label); };
$root = sys_get_temp_dir() . '/dvm-hosting-' . $cfg['database'] . "-'ä%\$(own-command)";
if (!mkdir($root, 0700)) throw new RuntimeException('Fresh owned hosting root required.');
$remove = static function (string $dir) use (&$remove): void { if (is_link($dir)) throw new RuntimeException('Own cleanup refuses symlinks.'); foreach (new DirectoryIterator($dir) as $f) { if ($f->isDot()) continue; if ($f->isDir() && !$f->isLink()) $remove($f->getPathname()); else unlink($f->getPathname()); } rmdir($dir); };
$old = []; foreach (['VEREIN_FINTS_ROOT', 'VEREIN_BANKING_ROOT', 'KH_NATIVE_PRIVATE_KEY_FILE', 'KH_NATIVE_AUTH_KEY_FILE', 'VEREIN_MODE', 'VEREIN_WORKER_ACTOR', 'VEREIN_AUTOMATION_START', 'VEREIN_FINTS_WORKER_ACTOR', 'KH_NATIVE_CI'] as $name) $old[$name] = getenv($name);
$run = static function (array $args): array { $p = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes); if (!is_resource($p)) throw new RuntimeException('Own CLI unavailable.'); fclose($pipes[0]); $out = stream_get_contents($pipes[1]); fclose($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[2]); return [proc_close($p), $out, $error]; };
try {
    mkdir($root . '/private', 0700); mkdir($root . '/private/banking', 0700);
    mkdir($root . '/app/vereinsverwaltung/bin', 0700, true);
    $plan = ScheduledJobs::plan($root, PHP_BINARY);
    $check(count($plan['jobs']) === 16 && count(array_unique(array_column($plan['jobs'], 'id'))) === 16 && $plan['status'] === 'not-verified', 'hosting plan lists all distinct fixed tasks without claiming registration or a successful run');
    $byId = array_column($plan['jobs'], null, 'id');
    $check($byId['bank']['schedule'] === '*/5 * * * *' && str_contains($byId['bank']['command'], "'--fints'") && !str_contains($byId['bank']['command'], "'--send'"), 'bank plan selects only the read-only five-minute mode');
    $check(str_contains($byId['meta']['command'], "'--meta' '--send'") && !str_contains($byId['meta']['command'], "'--receive'") && str_contains($byId['system']['command'], "'--config' "), 'Meta and dedicated system workers retain their actual independent CLI contracts');
    $check(str_contains($byId['mail']['cron_command'], '\\%') && str_contains($byId['mail']['crontab'], '* * * * * '), 'cron percent handling is explicit even within quoted original paths');
    foreach ([[], true, "php -r bad", 'relative/php', '/tmp/../php', "/tmp/php\n--send", "/tmp/php\0bad", '/tmp/php\\bad'] as $value) $deny(fn() => ScheduledJobs::executable($value), 'unsafe or structured PHP executable is refused before any installation or execution');
    foreach (['', '/', 'relative', '/tmp/../target', "/tmp/target\n* * * * * bad", '/tmp/target\\bad'] as $value) $deny(fn() => ScheduledJobs::plan($value), 'invalid hosting root cannot become another cron line');
    $check(ScheduledJobs::executable(null) === 'php' && ScheduledJobs::executable('') === 'php', 'omitted optional PHP selection has the documented editable default');
    $fixtureScript = $root . '/app/vereinsverwaltung/bin/native-delivery-worker.php';
    file_put_contents($fixtureScript, '<?php echo json_encode(array_slice($argv,1),JSON_THROW_ON_ERROR);');
    [$exit, $out, $error] = $run(['/bin/sh', '-c', $byId['meta']['command']]);
    $check($exit === 0 && $error === '' && json_decode($out, true) === ['--config=' . $root . '/private/worker.php', '--meta', '--send'], 'actual POSIX shell preserves full apostrophe Unicode percent and command-like path bytes as harmless arguments');
    file_put_contents($root . '/private/worker.php', '<?php throw new RuntimeException("Configuration must not execute during help");'); chmod($root . '/private/worker.php', 0600);
    [$exit, $help, $error] = $run([...TestSupport::phpCommand(), dirname(__DIR__) . '/bin/native-delivery-worker.php', '--schedule-help', '--config=' . $root . '/private/worker.php', '--php=' . PHP_BINARY]);
    $check($exit === 0 && $error === '' && $help === ScheduledJobs::text($plan), 'real CLI regenerates the complete current hosting guide without reading or executing private credentials');
    [$exit, $out, $error] = $run([...TestSupport::phpCommand(), dirname(__DIR__) . '/bin/native-delivery-worker.php', '--schedule-help', '--config=' . $root . '/private/worker.php', '--send']);
    $check($exit === 1 && $out === '', 'hosting help cannot silently become a sending command');
    $db = TestSupport::db(); SchemaRegistry::install($db);
    Db::run($db, "INSERT INTO clubs VALUES('own-hosting','Own hosting'),('foreign-hosting','Foreign hosting')");
    Db::run($db, "INSERT INTO actors(id,club_id,role,is_superuser) VALUES('own-hosting-admin','own-hosting','member',1),('foreign-hosting-admin','foreign-hosting','member',1),('own-hosting-reader','own-hosting','member',0)");
    $access = (new ManagerBridge($db, 'own-hosting-admin', 'native', static fn() => null))->access();
    $identity = new Identity($db, $access); $identityState = $identity->get(); $identityProfile = $identity->source();
    $identity->save(['club_version' => $identityProfile['version'], 'origin' => 'https://own-hosting.example.invalid'], $identityState['version']);
    \Kulturhus\Verein\Database::ensureContributionRates($db);
    putenv('VEREIN_FINTS_ROOT=' . $root . '/private/banking'); putenv('VEREIN_BANKING_ROOT=' . $root . '/private/banking');
    putenv('VEREIN_WORKER_ACTOR'); putenv('VEREIN_AUTOMATION_START'); putenv('VEREIN_FINTS_WORKER_ACTOR');
    $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); $before = ownOriginalDatabaseHash($db, $tables);
    $check(FinTs::run($db, 'own-hosting', null, $root . '/private')['transport'] === 'disabled', 'an unconfigured bank actor never starts an automatic read');
    $check(FinTs::run($db, 'own-hosting', 'own-hosting-admin', $root . '/private')['transport'] === 'disabled', 'missing bank configuration remains disabled instead of inventing a connection');
    foreach (['foreign-hosting-admin', 'own-hosting-reader'] as $actor) $deny(fn() => FinTs::run($db, 'own-hosting', $actor, $root . '/private'), 'foreign or insufficient current finance rights cannot start the hosting bank job');
    putenv('VEREIN_FINTS_ROOT=' . $root . '/foreign'); $deny(fn() => FinTs::run($db, 'own-hosting', 'own-hosting-admin', $root . '/private'), 'another private bank root cannot replace the installation binding'); putenv('VEREIN_FINTS_ROOT=' . $root . '/private/banking');
    file_put_contents($root . '/private/banking/fints.json', '{}'); chmod($root . '/private/banking/fints.json', 0600);
    putenv('KH_NATIVE_CI=0'); putenv('VEREIN_MODE=production');
    $emptyBank = FinTs::run($db, 'own-hosting', 'own-hosting-admin', $root . '/private');
    $check($emptyBank['transport'] === 'read-only' && $emptyBank['bank_sync'] === ['status' => 'checked', 'rows' => 0, 'counts' => []], 'actual production-mode bank schedule with no mappings completes without loading credentials or an engine');
    $other = TestSupport::db(); $lock = 'dvm-fints-job-' . substr(hash('sha256', 'own-hosting'), 0, 40);
    Db::run($other, 'SELECT GET_LOCK(?,0)', [$lock]);
    try { $check(FinTs::run($db, 'own-hosting', 'own-hosting-admin', $root . '/private')['bank_sync']['status'] === 'busy', 'independent real MySQL lease excludes an overlapping bank cron run'); }
    finally { Db::run($other, 'SELECT RELEASE_LOCK(?)', [$lock]); }
    rename($root . '/private/banking/fints.json', $root . '/private/banking/own-original.json'); symlink($root . '/private/banking/own-original.json', $root . '/private/banking/fints.json');
    try { $deny(fn() => FinTs::run($db, 'own-hosting', 'own-hosting-admin', $root . '/private'), 'bank configuration symlink is refused before the actual scheduled service'); }
    finally { unlink($root . '/private/banking/fints.json'); rename($root . '/private/banking/own-original.json', $root . '/private/banking/fints.json'); }
    chmod($root . '/private/banking/fints.json', 0644); clearstatcache();
    try { $deny(fn() => FinTs::run($db, 'own-hosting', 'own-hosting-admin', $root . '/private'), 'publicly readable private bank configuration is refused before a read'); }
    finally { chmod($root . '/private/banking/fints.json', 0600); clearstatcache(); }
    $old['KH_NATIVE_CI'] === false ? putenv('KH_NATIVE_CI') : putenv('KH_NATIVE_CI=' . $old['KH_NATIVE_CI']);
    $old['VEREIN_MODE'] === false ? putenv('VEREIN_MODE') : putenv('VEREIN_MODE=' . $old['VEREIN_MODE']);
    foreach (['send', 'receive', 'push', 'publish', 'meta', 'stadtlandfunk', 'social-check', 'events', 'mail-replies', 'press', 'catalog-billing', 'fixture'] as $mode) $deny(fn() => Worker::run(['fints' => false, $mode => false, 'config' => $root . '/private/worker.php']), 'bank job refuses another mode before reading any private configuration ' . $mode);
    $check(ownOriginalDatabaseHash($db, $tables) === $before, 'all disabled refused and planning calls preserve every whole business row and schema');
    $privateKey = random_bytes(32); file_put_contents($root . '/private/private.key', $privateKey); chmod($root . '/private/private.key', 0600);
    file_put_contents($root . '/private/auth.key', random_bytes(32)); chmod($root . '/private/auth.key', 0600);
    $config = ['dsn' => 'mysql:host=127.0.0.1;port=13371;dbname=' . $cfg['database'] . ';charset=utf8mb4', 'username' => $cfg['username'], 'password' => $cfg['password'], 'key_file' => $root . '/private/private.key', 'manager_key_file' => $root . '/private/private.key', 'auth_key_file' => $root . '/private/auth.key', 'club_id' => 'own-hosting', 'origin' => 'https://own-hosting.example.invalid', 'fints_actor' => 'own-hosting-admin'];
    file_put_contents($root . '/private/worker.php', '<?php return ' . var_export($config, true) . ';');
    $protected = array_values(array_diff($tables, ['native_worker_runs'])); $originals = ownOriginalDatabaseHash($db, $protected);
    [$exit, $out, $error] = $run([...TestSupport::phpCommand(), dirname(__DIR__) . '/bin/native-delivery-worker.php', '--config=' . $root . '/private/worker.php', '--fints']);
    $result = json_decode($out, true); $check($exit === 0 && $error === '' && ($result['worker'] ?? '') === 'native-fints' && ($result['transport'] ?? '') === 'disabled' && !str_contains($out, $cfg['password']), 'real private bank CLI completes without a configured bank or credential disclosure');
    $runs = Db::run($db, 'SELECT * FROM native_worker_runs WHERE club_id=?', [$access->club()])->fetchAll(); $modes = json_decode($runs[0]['modes_json'] ?? '{}', true);
    $check(count($runs) === 1 && $runs[0]['status'] === 'completed' && ($modes['fints'] ?? false) === true && count(array_filter($modes)) === 1, 'real bank job records exactly one correctly labelled isolated completed hosting run');
    $check(ownOriginalDatabaseHash($db, $protected) === $originals && file_get_contents($root . '/private/private.key') === $privateKey, 'real hosting CLI preserves all earlier business originals and the existing private key');
    $finance = new \Kulturhus\Verein\Finance($db, 'own-hosting-admin');
    $bank = $finance->createAccount('Own actual hosting bank', 'offline', '0', '2026-01-01');
    $mapping = (new \Kulturhus\Verein\BankImport($db, 'own-hosting-admin'))->map('fints', 'PRODUCTION', ['iban' => 'DE89370400440532013000', 'name' => 'Own actual hosting account'], $bank);
    $bankProtected = array_values(array_diff($tables, ['native_worker_runs', 'fints_sync_runs', 'audit_events']));
    $bankOriginals = ownOriginalDatabaseHash($db, $bankProtected); $auditBefore = $db->query('SELECT * FROM audit_events ORDER BY sequence')->fetchAll();
    $check(!is_file($root . '/private/banking/fints-state.key'), 'own mapped-bank fixture has no credential key or usable bank access');
    putenv('KH_NATIVE_CI=0'); putenv('VEREIN_MODE=production');
    try { [$exit, $out, $error] = $run([...TestSupport::phpCommand(), dirname(__DIR__) . '/bin/native-delivery-worker.php', '--config=' . $root . '/private/worker.php', '--fints']); }
    finally {
        $old['KH_NATIVE_CI'] === false ? putenv('KH_NATIVE_CI') : putenv('KH_NATIVE_CI=' . $old['KH_NATIVE_CI']);
        $old['VEREIN_MODE'] === false ? putenv('VEREIN_MODE') : putenv('VEREIN_MODE=' . $old['VEREIN_MODE']);
    }
    $result = json_decode($out, true);
    $check($exit === 0 && $error === '' && ($result['bank_sync']['status'] ?? '') === 'needs_review' && ($result['bank_sync']['counts']['needs_setup'] ?? 0) === 1 && ($result['bank_sync']['rows'] ?? -1) === 0, 'actual hosted bank CLI delegates to the real schedule and reports its one missing-authorization result without an engine call');
    $bankRuns = Db::run($db, 'SELECT * FROM fints_sync_runs WHERE club_id=?', ['own-hosting'])->fetchAll();
    $review = Db::run($db, 'SELECT * FROM native_worker_runs WHERE club_id=? AND status=?', [$access->club(), 'needs_review'])->fetchAll();
    $check(count($bankRuns) === 1 && $bankRuns[0]['mapping_id'] === $mapping && $bankRuns[0]['state'] === 'needs_setup' && count($review) === 1, 'bank scheduling records the original mapped account and marks the actual hosting run for review');
    $auditAfter = $db->query('SELECT * FROM audit_events ORDER BY sequence')->fetchAll();
    $check(ownOriginalDatabaseHash($db, $bankProtected) === $bankOriginals && array_slice($auditAfter, 0, count($auditBefore)) === $auditBefore && count($auditAfter) === count($auditBefore) + 1 && file_get_contents($root . '/private/private.key') === $privateKey, 'actual mapped-bank setup refusal preserves every earlier financial and profile original key and whole audit row');
    echo json_encode(['suite' => 'native-hosting-jobs', 'checks' => $checks, 'original_tables' => count($tables), 'external_actions' => 0], JSON_THROW_ON_ERROR) . "\n";
} finally {
    foreach ($old as $name => $value) $value === false ? putenv($name) : putenv($name . '=' . $value);
    $remove($root);
}
