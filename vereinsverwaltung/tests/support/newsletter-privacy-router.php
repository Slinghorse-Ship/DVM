<?php declare(strict_types=1);
require dirname(__DIR__, 2).'/native/bootstrap.php';
require dirname(__DIR__, 2).'/src/bootstrap.php';

use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\Access;

// No public fault switch: faults belong only to this isolated test database.
$config = TestSupport::config();
if (PHP_SAPI !== 'cli-server' || getenv('KH_NATIVE_CI') !== '1'
    || ($config['owner'] ?? '') !== 'isolated-native-ci'
    || ($config['label'] ?? '') !== 'native-newsletter-privacy'
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403); exit;
}
$file = getenv('DVM_NEWSLETTER_PRIVACY_FIXTURE');
if (!is_string($file) || is_link($file) || !is_file($file)) throw new RuntimeException('Own private newsletter fixture required.');
$fixture = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
if (!in_array($fixture['app'] ?? '', ['native','integrated'], true)
    || 'http://'.($_SERVER['HTTP_HOST'] ?? '') !== $fixture['origin']) {
    http_response_code(403); exit;
}
putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$fixture['key']);
putenv('KULTURHUS_DISABLE_MAIL=1');
putenv('KULTURHUS_DISABLE_WEBSITE=1');
if ($fixture['app'] === 'native') {
    putenv('KH_NATIVE_LOCAL_DEMO=synthetic');
    putenv('KH_NATIVE_DEMO_PORT='.(string)parse_url($fixture['origin'], PHP_URL_PORT));
    require dirname(__DIR__, 2).'/native/Web/router.php';
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] !== $fixture['origin']) {
    http_response_code(403); exit;
}
session_save_path($fixture['runtime'].'/sessions');
session_name('OWNNEWSLETTERPRIVACY');
session_start(['use_strict_mode'=>1,'cookie_httponly'=>1,'cookie_samesite'=>'Strict']);
$db = TestSupport::db();
if (!Kulturhus\Native\Integration\PublicSite::serve(
    $db, new Access($db, $fixture['reader']), $fixture['runtime'].'/media',
    $fixture['runtime'], $fixture['manager_club'], $fixture['origin'],
)) http_response_code(404);
