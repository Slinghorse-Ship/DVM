<?php declare(strict_types=1);
/** Five-minute read-only FinTS worker. No TAN/PIN is ever printed or stored. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../src/bootstrap.php';

use Kulturhus\Verein\{Database, FintsScheduledSync};

$options = getopt('', ['actor:', 'database:', 'banking-root:', 'now:', 'help']);
if (isset($options['help'])) {
    echo "FinTS-Umsatzabruf (nur Lesen)\n";
    echo "php bin/fints-scheduled-sync.php --actor=AKTEUR [--database=MYSQL_DSN] [--banking-root=VERZEICHNIS] [--now=ISO]\n";
    echo "Der Host-Scheduler startet diesen read-only Abruf alle fünf Minuten.\n";
    echo "Der Netcup-Zeitplan ruft diesen Worker alle fünf Minuten auf. Eine geforderte PIN/TAN/Bank-App-Freigabe wird als needs_auth protokolliert und niemals umgangen.\n";
    exit(0);
}
try {
    $actor = $options['actor'] ?? getenv('VEREIN_FINTS_WORKER_ACTOR') ?: '';
    $database = $options['database'] ?? getenv('VEREIN_DATABASE') ?: '';
    $root = $options['banking-root'] ?? getenv('VEREIN_FINTS_ROOT') ?: dirname(__DIR__, 2).'/.local/enable-banking';
    if (!is_string($actor) || $actor === '') throw new RuntimeException('Finanzberechtigten Worker-Zugang mit --actor angeben.');
    if (!is_string($database) || !str_starts_with(strtolower($database),'mysql:')) throw new RuntimeException('Bestehende Datenbank erforderlich; der Worker initialisiert keine Datenbank.');
    if (!is_string($root) || !is_dir($root)) throw new RuntimeException('Privates FinTS-Verzeichnis fehlt.');
    $now = null;
    if (isset($options['now'])) {
        if (!is_string($options['now']) || $options['now'] === '') throw new RuntimeException('Zeitpunkt prüfen.');
        $now = new DateTimeImmutable($options['now'], new DateTimeZone('Europe/Berlin'));
    }
    $db = Database::connect($database);
    $result = (new FintsScheduledSync($db, $actor, rtrim($root, '/\\')))->runDue($now);
    echo json_encode($result, JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FinTS-Lauf abgebrochen: '.$error->getMessage()."\n");
    exit(1);
}
