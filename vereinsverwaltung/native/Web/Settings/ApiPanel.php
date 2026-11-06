<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Settings;

use Kulturhus\Native\Web\{Workspace, Html};
use Kulturhus\Native\Integration\Accounts;
use Kulturhus\Native\Integration\StadtLandFunk\Connection;

/** Editable public account details, empty secret inputs, explicit disconnect controls. */
final class ApiPanel extends Workspace
{
    public function render(array $webhookFailure=[]): string
    {
        $this->access->require("website", "manage");
        $h =
            '<section id="connections"><h2>Konten &amp; Verbindungen</h2><p>Zugangsdaten bleiben verschlüsselt. Du kannst sie austauschen oder die Verbindung trennen. Gespeicherte Geheimnisse werden nicht angezeigt. Benutzerpasswörter des Managers werden dagegen als nicht rückrechenbare Passwort-Hashes geprüft.</p>';
        $accounts = new Accounts($this->db, $this->box());
        foreach (
            [
                "eventbrite" => [
                    "Eventbrite",
                    ["organization" => "Organisation", "token" => "Token"],
                ],
                "sumup" => [
                    "SumUp",
                    [
                        "merchant_code" => "Händlerkennung",
                        "token" => "API-Token",
                        "affiliate_app_id" => "Affiliate-App-ID",
                        "affiliate_key" => "Affiliate-Schlüssel",
                        "reader_id" => "Kartenleser-Kennung (einmalige Einrichtung)",
                        "reader_name" => "Name des Kartenlesers",
                        "reader_list" => "Weitere Kartenleser (je Zeile: Name | Kennung)",
                    ],
                ],
                "stripe" => [
                    "Stripe",
                    ["account_id"=>"Stripe-Konto-ID", "mode"=>"Modus", "secret_key"=>"Privater API-Schlüssel", "webhook_secret"=>"Webhook-Signaturschlüssel"],
                ],
                "paypal" => [
                    "PayPal",
                    [
                        "client_id" => "Client-ID",
                        "client_secret" => "Client-Secret",
                        "merchant_id" => "PayPal-Kontokennung",
                        "mode" => "Modus",
                    ],
                ],
                "meta" => [
                    "Facebook & Instagram · Meta",
                    [
                        "page_id" => "Facebook-Seiten-ID",
                        "instagram_id" => "Instagram-Konto-ID",
                        "api_version" => "API-Version",
                        "token" => "Zugangstoken",
                    ],
                ],
            ]
            as $provider => [$label, $fields]
        ) {
            $s = $accounts->status($this->access, $provider);
            $h .=
                '<details class="panel" data-connection="' .
                $provider .
                '"><summary>' .
                Html::e($label) .
                " · " .
                ($s["configured"] ? "Zugang hinterlegt" : "nicht verbunden") .
                "</summary>";
            if ($provider === "meta") {
                $h .=
                    '<p>Facebook-Seite und verbundenes Instagram-Profikonto einrichten. Welche Funktionen der Token freigibt, prüfst du im <a data-nav href="/?view=social&amp;section=library">Social Hub</a>. Die tatsächliche Token-Laufzeit hängt von Meta und dem verwendeten Zugang ab.</p>';
            }
            $h .=
                $this->start("api-account") .
                Html::hidden("provider", $provider) .
                Html::hidden("version", $s["version"]);
            foreach ($fields as $field => $title) {
                $secret = in_array(
                    $field,
                    ["token", "affiliate_key", "client_secret", "secret_key", "webhook_secret"],
                    true,
                );
                if ($field === "reader_list") {
                    $h.='<label>'.Html::e($title).'<textarea class="form-control" rows="4" name="reader_list" maxlength="4096">'.Html::e($s['details']['reader_list']??'').'</textarea></label><p>Jede Veranstaltung kann mehrere Kassen haben. Den passenden Leser wählst du beim Öffnen oder Einrichten jeder Kasse aus.</p>';
                } elseif ($field === "mode") {
                    $h .= Html::select(
                        $field,
                        $title,
                        $provider==='stripe'?["test"=>"Test", "live"=>"Live"]:["sandbox" => "Sandbox", "live" => "Live"],
                        $s["details"][$field] ?? ($provider==='stripe'?'test':'sandbox'),
                    );
                } else {
                    $h .= Html::input(
                        $field,
                        $title,
                        $secret ? "" : $s["details"][$field] ?? "",
                        $secret ? "password" : "text",
                        'autocomplete="' .
                            ($secret ? "new-password" : "off") .
                            '"' .
                            ($secret && $s["configured"]
                                ? ' placeholder="Leer lassen: vorhandenen Zugang behalten"'
                                : ""),
                    );
                }
            }
            $h .=
                '<p class="hint">Bei demselben Zielkonto behalten leere Geheimnisfelder den gespeicherten Zugang. Für ein anderes Zielkonto gib einen neuen Zugang ein. Speichern löst keine Veröffentlichung oder Zahlung aus.</p><button class="btn btn-solid theme-primary">Verbindung speichern</button></form>';
            if ($s["configured"]) {
                $h .= $this->disconnect(
                    "api-account-disconnect",
                    $s["version"],
                    $provider,
                );
            }
            $h .= "</details>";
        }
        $s = (new Connection($this->db, $this->box()))->status($this->access);
        $h .=
            '<details class="panel" data-connection="stadtlandfunk"><summary>StadtLand.Funk · ' .
            ($s["configured"] ? "Konto verbunden" : "nicht verbunden") .
            "</summary><p>Persönliches Konto ohne kostenpflichtigen Partner-Schlüssel verbinden. Die Anmeldung verwendet den zentralen Digitale-Dörfer-Login. Passwort und Tokens werden verschlüsselt gespeichert; du kannst das gespeicherte Passwort auch weglassen.</p>";
        if ($s["configured"]) {
            $h .= '<p><strong>Absender: '.Html::e($s['organization_name'] !== '' ? $s['organization_name'] : 'Persönliches Profil').'</strong></p>';
            $choices = [''=>'Persönliches Profil'];
            foreach ($s['organizations'] as $organization) $choices[$organization['id']] = $organization['name'];
            if ($s['organization_id'] !== '') $choices[$s['organization_id']] = $s['organization_name'];
            $h .= $this->start('stadtlandfunk-sender').Html::hidden('version',$s['version']).Html::select('organization_id','Absender für Beiträge und Antworten',$choices,$s['organization_id']).'<button class="btn btn-solid theme-primary">Absender speichern</button></form>';
            $h .= $this->start('stadtlandfunk-senders').Html::hidden('version',$s['version']).'<button class="btn btn-outline theme-secondary">Vereinsprofile laden</button></form>';
            $h .=
                "<p><strong>Zielgebiet: " .
                Html::e($s["home_area_name"]) .
                "</strong><br>Zugangstoken gültig bis " .
                Html::e(self::time($s["expires_at"])) .
                "<br>Token-Erneuerung: " .
                ($s["refresh_expires_at"] !== null
                    ? "vom Anbieter gemeldete Frist " .
                        Html::e(self::time($s["refresh_expires_at"]))
                    : "keine feste Frist gemeldet; Widerruf oder erneute Anmeldung bleiben möglich") .
                "</p><p>" .
                ($s["password_saved"]
                    ? "Passwort für erneute Anmeldungen verschlüsselt hinterlegt."
                    : "Erneute Kontoanmeldung benötigt dein Passwort.") .
                "</p>";
            $h .=
                $this->start("stadtlandfunk-verify") .
                Html::hidden("version", $s["version"]) .
                '<button class="btn btn-outline theme-secondary secondary">Verbindung prüfen &amp; Token bei Bedarf erneuern</button></form>';
        }
        $h .=
            $this->start("stadtlandfunk-connect") .
            Html::hidden("version", $s["version"]) .
            Html::input(
                "username",
                "Anmeldename",
                $s["username"],
                "text",
                'required autocomplete="username"',
            ) .
            Html::input(
                "password",
                "Passwort",
                "",
                "password",
                'autocomplete="new-password"' .
                    ($s["password_saved"]
                        ? ' placeholder="Leer lassen: gespeichertes Passwort verwenden"'
                        : " required"),
            ) .
            '<label class="check"><input class="check" type="checkbox" name="remember_password"' .
            (!$s["configured"] || $s["password_saved"] ? " checked" : "") .
            '>Passwort verschlüsselt für erneute Anmeldungen hinterlegen</label><p class="hint">Die Laufzeit richtet sich nach den Angaben des Anbieters. Zuerst wird der Token erneuert; nach abgelaufener Erneuerung wird einmal eine neue Anmeldung versucht. Zusätzliche Anmeldeschritte und fehlende Rechte müssen beim Anbieter geklärt werden. Abwählen entfernt das gespeicherte Passwort nach bestätigter Anmeldung.</p><button class="btn btn-solid theme-primary">' .
            ($s["configured"]
                ? "Zugang ändern &amp; neu verbinden"
                : "Konto verbinden") .
            '</button></form>';
        if ($s["configured"]) {
            $h .= $this->disconnect("stadtlandfunk-disconnect", $s["version"]);
        }
        return $h . "</details>".(new MetaWebhookPanel($this->db,$this->access,$this->mediaRoot,$this->csrf))->render($webhookFailure)."</section>";
    }

    private static function time(int $stamp): string
    {
        return (new \DateTimeImmutable("@" . $stamp))
            ->setTimezone(new \DateTimeZone("Europe/Berlin"))
            ->format("d.m.Y H:i") . " Uhr (Europe/Berlin)";
    }

    private function disconnect(
        string $operation,
        int $version,
        string $provider = "",
    ): string {
        return "<details><summary>Verbindung trennen</summary>" .
            $this->start($operation) .
            Html::hidden("version", $version) .
            ($provider !== "" ? Html::hidden("provider", $provider) : "") .
            '<label class="check"><input class="check" type="checkbox" name="disconnect_confirm" required>Gespeicherten Zugang entfernen</label><p>Vorhandene Beiträge beim Anbieter bleiben erhalten. Erneute Verbindung benötigt Zugangsdaten. Ausstehende Meta-Aufträge werden gestoppt; unklare laufende Ergebnisse benötigen weiterhin eine Prüfung.</p><button class="btn btn-outline theme-secondary secondary">Verbindung trennen</button></form></details>';
    }
}
