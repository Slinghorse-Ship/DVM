<?php declare(strict_types=1);
namespace Kulturhus\Native\Installer;

/** Hosting instructions only: generating a plan never starts or enables a job. */
final class ScheduledJobs
{
    public static function executable(mixed $value): string
    {
        if ($value === null || $value === '') return 'php';
        if (!is_string($value) || strlen($value) > 4096 || !mb_check_encoding($value, 'UTF-8')
            || preg_match('/[\x00-\x1f\x7f\\\\]/', $value)
            || ($value !== 'php' && (!str_starts_with($value, '/') || str_ends_with($value, '/') || preg_match('~(?:^|/)\.\.?(/|$)~', $value)))) {
            throw new \DomainException('PHP-CLI als php oder vollständigen PHP-Dateipfad ohne zusätzliche Optionen eintragen.');
        }
        return $value;
    }

    private static function quote(string $value): string
    {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }

    public static function plan(string $target, mixed $executable = null): array
    {
        $target = rtrim($target, '/');
        if ($target === '' || !str_starts_with($target, '/') || !mb_check_encoding($target, 'UTF-8')
            || preg_match('/[\x00-\x1f\x7f\\\\]/', $target) || preg_match('~(?:^|/)\.\.?(/|$)~', $target)) {
            throw new \DomainException('Vollständigen eigenen Installationspfad für den Hostingzeitplan prüfen.');
        }
        $php = self::executable($executable);
        $rows = [
            ['prepare', 'Vorgänge vorbereiten', '* * * * *', 'native-delivery-worker.php', [], 'Bereitet aktivierte Veranstaltungsentwürfe mit Bildern und Animationen sowie weitere fällige Entwürfe vor.'],
            ['mail', 'Freigegebene E-Mails versenden', '* * * * *', 'native-delivery-worker.php', ['--send'], 'Vereinsmail und Versandzwecke zuerst einrichten. Beitragsautomatik benötigt zusätzlich Verantwortlichen und Startdatum.'],
            ['website', 'Geplante Websitebeiträge veröffentlichen', '* * * * *', 'native-delivery-worker.php', ['--publish'], 'Nur ausdrücklich geplante und weiterhin freigegebene Fassungen.'],
            ['meta', 'Facebook und Instagram veröffentlichen', '* * * * *', 'native-delivery-worker.php', ['--meta', '--send'], 'Eigenes Anbieterkonto und freigegebene Beitrags-, Reel- oder Storyaufträge erforderlich.'],
            ['stories', 'Automatische Veranstaltungsstorys veröffentlichen', '* * * * *', 'native-delivery-worker.php', ['--stories', '--send', '--limit=3'], 'Die Story-Regel im Social Hub mit Veröffentlichungsrecht aktivieren; erstes Bild und Tageshinweis werden aus der Veranstaltungsfolge übernommen.'],
            ['stadtlandfunk', 'StadtLand.Funk veröffentlichen', '* * * * *', 'native-delivery-worker.php', ['--stadtlandfunk', '--send'], 'Eigenes Anbieterkonto und freigegebene Terminaufträge erforderlich.'],
            ['social', 'Social-Aktivitäten abgleichen', '* * * * *', 'native-delivery-worker.php', ['--social-check'], 'Jede Minute starten: Meta rund um die Uhr nach Intervall, StadtLand.Funk tagsüber stündlich zu einer zufälligen Minute.'],
            ['inbox', 'Rechnungspostfach abrufen', '*/5 * * * *', 'native-delivery-worker.php', ['--receive'], 'Eigenes Postfach ausdrücklich für den Abruf einrichten.'],
            ['replies', 'Antwortpostfach abrufen', '*/5 * * * *', 'native-delivery-worker.php', ['--mail-replies'], 'Optionales Antwortpostfach und dessen Verarbeitungsmodus vorher einrichten.'],
            ['push', 'Freigegebene Pushhinweise versenden', '* * * * *', 'native-delivery-worker.php', ['--push'], 'Pushzugang und aktuelle Geräteabonnements erforderlich.'],
            ['events', 'Veranstaltungsanbieter abgleichen', '*/5 * * * *', 'native-delivery-worker.php', ['--events'], 'Eigenen Veranstaltungsanbieter und berechtigten Verantwortlichen einrichten.'],
            ['press', 'Freigegebene Pressemitteilungen versenden', '* * * * *', 'native-delivery-worker.php', ['--press', '--send'], 'Pressepostfach und ursprüngliche Versandfreigaben erforderlich.'],
            ['billing', 'Fällige Tarifabrechnungen vorbereiten', '*/5 * * * *', 'native-delivery-worker.php', ['--catalog-billing'], 'Verantwortlichen mit aktuellen Finanzrechten für die Tarifabrechnung einrichten.'],
            ['bank', 'Bankumsätze abrufen', '*/5 * * * *', 'native-delivery-worker.php', ['--fints'], 'FinTS-Verbindung, Abruffreigabe und Verantwortlicher mit aktuellen Finanzrechten erforderlich. Bankfreigaben erfolgen weiterhin im Bankdialog.'],
            ['system', 'Fällige Systemaufgaben ausführen', '* * * * *', 'native-system-worker.php', [], 'Die gewünschten Aufgaben unter Einstellungen → Systemverwaltung aktivieren.'],
            ['status', 'Verbindungsstatus prüfen', '*/5 * * * *', 'service-status-worker.php', [], 'Automatische Prüfung unter Einstellungen → Systemstatus aktivieren.'],
        ];
        $jobs = [];
        foreach ($rows as [$id, $label, $schedule, $entry, $options, $requires]) {
            $script = $target . '/app/vereinsverwaltung/bin/' . $entry;
            $arguments = [$php, $script, '--config=' . $target . '/private/worker.php', ...$options];
            // The two dedicated workers deliberately accept their exact two-argument config form.
            if ($id === 'system' || $id === 'status') $arguments = [$php, $script, '--config', $target . '/private/worker.php'];
            $command = implode(' ', array_map(self::quote(...), $arguments));
            // cron interprets percent signs before the shell, including inside shell quotes.
            $cronCommand = str_replace('%', '\\%', $command);
            $jobs[] = ['id' => $id, 'label' => $label, 'schedule' => $schedule, 'script' => $script,
                'command' => $command, 'cron_command' => $cronCommand, 'crontab' => $schedule . ' ' . $cronCommand,
                'requires' => $requires];
        }
        return ['status' => 'not-verified', 'php' => $php, 'timezone' => 'Europe/Berlin', 'jobs' => $jobs,
            'message' => 'Die Aufgaben sind noch nicht im Hosting eingerichtet. Nur benötigte Aufrufe eintragen und einen tatsächlichen erfolgreichen Lauf prüfen.'];
    }

    public static function text(array $plan): string
    {
        $text = "DVM – Automatische Aufgaben\n\n" . $plan['message'] . "\n\n"
            . "cPanel: Cron-Jobs öffnen; Zeitmuster und Aufruf jeweils getrennt eintragen.\n"
            . "Plesk: Geplante Aufgaben öffnen; Befehl ausführen wählen und Zeitmuster eintragen.\n"
            . "PHP-CLI ab 8.3 mit denselben erforderlichen Erweiterungen wie die Website verwenden.\n"
            . "Der PHP-Pfad muss zum eigenen Hosting passen. Beispiele: /opt/plesk/php/8.3/bin/php oder /opt/cpanel/ea-php83/root/usr/bin/php.\n"
            . "Die Befehle enthalten nur Dateipfade; Zugangsdaten bleiben in private/worker.php.\n"
            . "Ein aktivierter Plan im Manager benötigt zusätzlich diesen Hosting-Zeitplan.\n"
            . "Bei minütlichem Start kann eine Veröffentlichung bis zum nächsten erfolgreichen Lauf warten.\n"
            . "Beitrags- und Mahnfälligkeiten folgen dem Berliner Kalendertag.\n\n";
        foreach ($plan['jobs'] as $job) $text .= $job['label'] . "\n" . $job['requires'] . "\nZeitmuster: " . $job['schedule']
            . "\nAufruf: " . $job['cron_command'] . "\nCrontab: " . $job['crontab'] . "\n\n";
        return $text . "Prüfen: Einen benötigten Job im Hosting bewusst starten, Rückgabe und letzte erfolgreiche Ausführung kontrollieren. Versand- und Veröffentlichungsjobs können echte Aktionen ausführen.\n"
            . "Hintergrundläufe: Website → Vereinsdaten & Mail → Hintergrundaufgaben. Bankabrufe zusätzlich im Bankbereich; Systemaufgaben und Status unter Einstellungen.\n"
            . "Die Bestätigung eines Zeitplans ersetzt keinen erfolgreichen Versand-, Veröffentlichungs- oder Banknachweis.\n"
            . "Datensicherung und Aufbewahrung im Hosting zusätzlich nach dem eigenen Sicherungsplan einrichten.\n";
    }
}
