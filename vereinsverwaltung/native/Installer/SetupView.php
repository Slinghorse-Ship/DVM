<?php declare(strict_types=1);
namespace Kulturhus\Native\Installer;

final class SetupView
{
    public static function render(string $csrf, array $requirements): string
    {
        $e = static fn($s) => htmlspecialchars(
            (string) $s,
            ENT_QUOTES | ENT_SUBSTITUTE,
            "UTF-8",
        );
        $checks = "";
        foreach ($requirements["checks"] as $check) {
            $checks .=
                "<li>" .
                ($check["available"] ? "✓ " : "✗ ") .
                $e($check["name"]) .
                "</li>";
        }
        $fields = "";
        foreach (
            [
                "token" => ["Einrichtungsschlüssel", "password", "", "64"],
                "club_name" => ["Vereinsname", "text", "", "160"],
                "club_id" => [
                    "Vereinskennung (klein, ohne Leerzeichen)",
                    "text",
                    "",
                    "63",
                ],
                "db_host" => ["Datenbankserver", "text", "localhost", "180"],
                "db_port" => ["Datenbankport", "number", "3306", "5"],
                "db_name" => ["Leere Datenbank", "text", "", "120"],
                "db_user" => ["Datenbankbenutzer", "text", "", "120"],
                "db_password" => ["Datenbankpasswort", "password", "", "1024"],
                "admin_email" => [
                    "E-Mail der Administration",
                    "email",
                    "",
                    "254",
                ],
                "admin_password" => [
                    "Neues Kontopasswort (mindestens 16 Zeichen)",
                    "password",
                    "",
                    "1024",
                ],
            ]
            as $name => [$label, $type, $value, $length]
        ) {
            $extra =
                $type === "password"
                    ? ' autocomplete="new-password"'
                    : ($name === "admin_email"
                        ? ' autocomplete="email"'
                        : "");
            $fields .=
                "<label>" .
                $e($label) .
                '<input class="form-control" name="' .
                $name .
                '" type="' .
                $type .
                '" value="' .
                $e($value) .
                '" maxlength="' .
                $length .
                '"' .
                ($name === "admin_password" ? ' minlength="16"' : "") .
                $extra .
                " required></label>";
        }
        $fints = $requirements["integrations"]["fints"] ?? false;
        $ocr = $requirements["integrations"]["ocr"] ?? [];
        $addons =
            '<details class="setup-addons"><summary>FinTS und OCR einrichten (optional)</summary><div class="setup-addon"><h2>FinTS · Bankanbindung</h2><p>' .
            ($fints
                ? "Geprüfte FinTS-Bibliothek 4.1.0 ist enthalten."
                : "Geprüfte FinTS-Bibliothek fehlt; Paket prüfen.") .
            '</p><p>Eigene Registrierungs-ID der Deutschen Kreditwirtschaft und den von deiner Bank bestätigten FinTS-Endpunkt eintragen. Bankkennung, PIN und TAN werden später im Bankdialog eingegeben. Die Einrichtung öffnet keine Bankverbindung.</p><label class="setup-check"><input class="check" type="checkbox" name="fints_enable" value="1"' .
            ($fints ? "" : " disabled") .
            '> FinTS für diesen Verein vorbereiten</label><div class="setup-addon-fields">';
        foreach (
            [
                "url" => ["Bestätigter HTTPS-Bankendpunkt", "url", "300"],
                "bank_code" => ["Bankleitzahl (8 Ziffern)", "text", "8"],
                "product_id" => [
                    "Eigene FinTS-Registrierungs-ID (25 Zeichen)",
                    "text",
                    "25",
                ],
                "product_version" => [
                    "Produktversion (höchstens 5 Zeichen)",
                    "text",
                    "5",
                ],
            ]
            as $key => [$label, $type, $max]
        ) {
            $addons .=
                "<label>" .
                $e($label) .
                '<input class="form-control" name="fints_' .
                $key .
                '" type="' .
                $type .
                '" maxlength="' .
                $max .
                '" autocomplete="off"></label>';
        }
        $addons .=
            '</div><label class="setup-check"><input class="check" type="checkbox" name="fints_confirm" value="1"> Ich habe die Registrierung und den Bankendpunkt geprüft.</label></div><div class="setup-addon"><h2>OCR · Rechnungen auslesen</h2><ul><li>' .
            (!empty($ocr["packaged"])
                ? "✓ OCR/PDF-Laufzeit, Modelle und Lizenzquellen enthalten"
                : "OCR-Binärpaket fehlt; Upload-Paket mit --with-ocr erstellen") .
            "</li><li>" .
            (!empty($ocr["host"])
                ? "✓ Linux x86_64"
                : "Linux x86_64 für dieses OCR-Paket erforderlich") .
            "</li><li>" .
            (!empty($ocr["processes"])
                ? "✓ PHP-Prozessfunktionen verfügbar"
                : "PHP-Prozessfunktionen einschließlich proc_open fehlen") .
            '</li></ul><p>Landlock ABI 6, seccomp und Ausführungsrechte werden beim gewählten Einrichtungsschritt mit einer echten synthetischen Bildrechnung geprüft. Der Schutz bleibt eingeschaltet. XML-Rechnungsdaten können ohne OCR-Laufzeit gelesen werden.</p><label class="setup-check"><input class="check" type="checkbox" name="ocr_enable" value="1"' .
            (!empty($ocr["can_check"]) ? "" : " disabled") .
            "> OCR/PDF-Laufzeit jetzt prüfen und aktivieren</label></div></details>";
        return '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>DVM einrichten</title>' .
            \Kulturhus\Native\Web\BootstrapAssets::tags("./") .
            '<link rel="stylesheet" href="setup.css"><script src="setup.js" defer></script></head><body><main><p class="eyebrow">DVM · DeinVereinsManager</p><h1>Deinen Verein einrichten</h1><p>Die Einrichtung legt den Manager, eine eigene Website und dein Administratorkonto an. Verwende eine leere Datenbank und den privaten Schlüssel aus deinem Upload-Paket.</p><details><summary>Hosting prüfen' .
            ($requirements["ready"]
                ? " · Voraussetzungen erfüllt"
                : " · Voraussetzungen fehlen") .
            "</summary><ul>" .
            $checks .
            '</ul><p>app/ und private/ gehören außerhalb des öffentlichen Webroots. FinTS und OCR kannst du im folgenden Abschnitt vorbereiten. Weitere Video- und Steuerprogramme werden gesondert eingerichtet.</p></details><form data-setup method="post"><input type="hidden" name="csrf" value="' .
            $e($csrf) .
            '">' .
            $fields .
            \Kulturhus\Native\Web\PhotoRequirements::render($requirements['optional']['photo']??[]) .
            $addons .
            '<details class="setup-hosting"><summary>Automatische Aufgaben im Hosting einrichten</summary><p>Damit E-Mails, geplante Beiträge und Abrufe auch bei geschlossenem Manager laufen, benötigst du Cron-Jobs in cPanel oder geplante Aufgaben in Plesk. Nach der Einrichtung erhältst du die passenden Zeitmuster und kopierbaren Aufrufe für diese Installation.</p><label>PHP-CLI im Hosting<input class="form-control" name="scheduler_php" type="text" value="php" maxlength="4096" autocomplete="off" aria-describedby="setup-php-help"></label><p id="setup-php-help">PHP-CLI ab 8.3 mit den erforderlichen Erweiterungen verwenden. Den vollständigen PHP-Pfad findest du beim Hoster. Beispiele: Plesk /opt/plesk/php/8.3/bin/php; cPanel /opt/cpanel/ea-php83/root/usr/bin/php. Der Assistent legt im Hosting noch keinen Zeitplan an und prüft diesen PHP-Pfad noch nicht.</p></details>' .
            '<div class="actions"><button class="btn btn-solid theme-primary" type="submit"' .
            ($requirements["ready"] ? "" : " disabled") .
            '>DVM einrichten</button><button class="btn btn-solid theme-secondary" type="button" data-resume>Begonnene Einrichtung fortsetzen</button><button class="btn btn-solid theme-secondary" type="button" data-addons hidden>OCR-Auswahl im begonnenen Vorgang übernehmen</button></div></form><section aria-live="polite"><p data-status></p><progress value="0" max="100" hidden></progress><p data-error role="alert"></p><a data-login href="/login/" hidden>Bei DVM anmelden →</a></section><section class="setup-jobs" data-scheduler hidden><h2>Automatische Aufgaben einrichten</h2><p data-scheduler-message></p><p>cPanel: Cron-Jobs öffnen und Zeitmuster sowie Aufruf getrennt eintragen. Plesk: Geplante Aufgaben öffnen, „Befehl ausführen“ wählen und das Zeitmuster eintragen.</p><p>Richte nur die benötigten Aufgaben ein. Die jeweiligen Vereins-, Konto- und Versandfreigaben gelten weiterhin. Prüfe anschließend einen tatsächlichen erfolgreichen Lauf. Die Anleitung enthält keine Zugangsdaten.</p><p><a data-scheduler-download download="DVM-Hintergrundaufgaben.txt">Anleitung herunterladen</a></p><div data-scheduler-jobs></div></section><p class="hint">Bei einem OCR-Fehler kannst du die Voraussetzungen korrigieren und fortsetzen oder OCR abwählen und die Auswahl übernehmen. Vereins- und Kontodaten bleiben im begonnenen Vorgang erhalten. Nach erfolgreicher Einrichtung ist der Assistent gesperrt. Entferne anschließend den Ordner setup/ vom Webspace.</p></main></body></html>';
    }
}
