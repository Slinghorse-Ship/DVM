<?php declare(strict_types=1);
/** Private CLI entry point. HTTP cannot activate a worker or configure a transport. */
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit();
}
ini_set("zend.exception_ignore_args", "1");
require dirname(__DIR__) . "/native/bootstrap.php";
require dirname(__DIR__, 2) . "/vendor/autoload.php";
try {
    $options = getopt("", [
        "config:",
        "fixture",
        "schedule-help",
        "php:",
        "fints",
        "send",
        "receive",
        "push",
        "publish",
        "meta",
        "stories",
        "stadtlandfunk",
        "social-check",
        "events",
        "mail-replies",
        "press",
        "catalog-billing",
        "limit:",
    ]);
    if (isset($options['schedule-help'])) {
        if (array_diff(array_keys($options), ['schedule-help', 'config', 'php']) || !is_string($options['config'] ?? null) || basename($options['config']) !== 'worker.php' || basename(dirname($options['config'])) !== 'private') throw new DomainException('Hostinghilfe nur mit dem eigenen privaten Konfigurationspfad aufrufen.');
        $plan = \Kulturhus\Native\Installer\ScheduledJobs::plan(dirname($options['config'], 2), $options['php'] ?? null);
        echo \Kulturhus\Native\Installer\ScheduledJobs::text($plan); exit;
    }
    if (isset($options['php'])) throw new DomainException('PHP-Auswahl nur in der Hostinghilfe verwenden.');
    $result = \Kulturhus\Native\Integration\Jobs\Worker::run($options);
    echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable) {
    fwrite(
        STDERR,
        "Privater Worker nicht abgeschlossen. Konfiguration, Schlüssel und Dienststatus prüfen. Keine automatische Wiederholung unklarer Zustellungen.\n",
    );
    exit(1);
}
