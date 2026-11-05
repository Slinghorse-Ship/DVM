<?php declare(strict_types=1);
/** Installation check: no credentials, bank connection or simulated account data. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$autoload = getenv('VEREIN_FINTS_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "FinTS-Abhängigkeiten fehlen. In banking/ composer install ausführen.\n");
    exit(1);
}
require $autoload;
$errors = [];
if (PHP_VERSION_ID < 80300) $errors[] = 'PHP 8.3 oder neuer erforderlich';
foreach (['curl', 'mbstring', 'openssl', 'dom', 'xmlreader'] as $extension) {
    if (!extension_loaded($extension)) $errors[] = 'PHP-Erweiterung fehlt: '.$extension;
}
foreach ([\Fhp\FinTs::class, \Fhp\Options\FinTsOptions::class,
    \Fhp\Action\GetSEPAAccounts::class, \Fhp\Action\GetStatementOfAccountXML::class] as $class) {
    if (!class_exists($class)) $errors[] = 'Bibliotheksklasse fehlt: '.$class;
}
$version = \Composer\InstalledVersions::getPrettyVersion('nemiah/php-fints');
if ($version !== '4.1.0') $errors[] = 'Unerwartete FinTS-Version';
echo json_encode([
    'php' => PHP_VERSION,
    'library' => 'nemiah/php-fints',
    'version' => $version,
    'reference' => \Composer\InstalledVersions::getReference('nemiah/php-fints'),
    'installation' => $errors ? 'failed' : 'ready',
    'bank_connection' => 'not_tested',
    'registration' => 'not_checked',
    'network_requests' => 0,
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
exit($errors ? 1 : 0);
