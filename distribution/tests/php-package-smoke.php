<?php declare(strict_types=1);
// Run with PHP 8.3 against a newly unpacked project directory. No network or app bootstrap.
if (PHP_SAPI !== 'cli' || $argc !== 3) exit(2);
[$script, $kind, $root] = $argv;
if (!in_array($kind, ['root', 'banking', 'push'], true)) exit(2);
require $root . '/vendor/autoload.php';
$checks = 0;
$expect = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    ++$checks;
};
$lock = json_decode((string) file_get_contents($root . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
foreach ($lock['packages'] as $package) {
    $expect(Composer\InstalledVersions::getPrettyVersion($package['name']) === $package['version'], 'Installed version matches lock: ' . $package['name']);
}
if ($kind === 'root') {
    $expect(!class_exists('ProcessWire\\ProcessWire'), 'No legacy CMS core is available through the dependency loader');
    foreach(spl_autoload_functions() as $callback){
        if(is_array($callback)&&$callback[0] instanceof Composer\Autoload\ClassLoader){
            $prefixes=$callback[0]->getPrefixesPsr4();
            $expect(!isset($prefixes['Kulturhus\\']), 'Dependency loader has no old application namespace or website paths');
        }
    }
    $expect((string) new GuzzleHttp\Psr7\Uri('https://example.invalid/offline') === 'https://example.invalid/offline', 'HTTP URI handling');
    $email = (new Symfony\Component\Mime\Email())->from('sender@example.invalid')->to('recipient@example.invalid')->subject('Offlineprüfung')->text('Nur ein Speichertest.');
    $expect(str_contains($email->toString(), 'recipient@example.invalid'), 'MIME message builds without send');
    $expect(class_exists(Symfony\Component\Mailer\Mailer::class), 'Mailer autoload');
    $expect(Dotenv\Dotenv::parse("SYNTHETIC_NAME=offline\n") === ['SYNTHETIC_NAME' => 'offline'], 'Environment parser');
} elseif ($kind === 'banking') {
    $expect(class_exists(Fhp\FinTs::class), 'FinTS library autoload');
    $expect(interface_exists(Psr\Log\LoggerInterface::class), 'Banking logger autoload');
} else {
    $expect(class_exists(Minishlink\WebPush\WebPush::class), 'Web Push library autoload');
    $keys = Minishlink\WebPush\VAPID::createVapidKeys();
    $expect(is_string($keys['publicKey'] ?? null) && is_string($keys['privateKey'] ?? null), 'Ephemeral in-memory VAPID key generation');
    $expect(strlen($keys['publicKey']) === 87 && strlen($keys['privateKey']) === 43, 'Generated VAPID key shape');
    unset($keys);
}
echo "$kind: $checks offline PHP package checks passed.\n";
