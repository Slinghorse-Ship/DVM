<?php declare(strict_types=1);
require dirname(__DIR__, 2) . '/native/bootstrap.php';
require dirname(__DIR__, 2) . '/src/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Integration\PublicSite;

if (PHP_SAPI !== 'cli-server' || getenv('KH_NATIVE_CI') !== '1') exit;
$file = getenv('DVM_PUBLIC_FORM_FIXTURE');
if (!$file || !is_file($file)) throw new RuntimeException('Owned public form fixture required.');
$fixture = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
if (isset($fixture['secret_file'])) putenv('KH_NATIVE_PRIVATE_KEY_FILE=' . $fixture['secret_file']);
session_save_path($fixture['runtime'] . '/sessions');
session_name('own_membership_test');
session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'cookie_secure' => false]);
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$db = TestSupport::db();
if (!PublicSite::serve($db, new Access($db, $fixture['reader']), $fixture['runtime'] . '/media', $fixture['runtime'], $fixture['club'], $fixture['origin'])) {
    http_response_code(404);
}
