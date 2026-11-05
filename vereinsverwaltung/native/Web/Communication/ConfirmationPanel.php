<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;

use Kulturhus\Native\Communication\Confirmations\Renewals;
use Kulturhus\Native\Web\{Html, Workspace};

/** Counts, explicit confirmation and private delivery history share one UI. */
final class ConfirmationPanel extends Workspace
{
    public function render(string $subscriber = ""): string
    {
        $service = new Renewals(
            $this->db,
            $this->access,
            $this->box(),
            $this->mediaRoot,
        );
        $preview = $service->preview($subscriber);
        $counts = $preview["counts"];
        $e = Html::e(...);
        $title =
            $subscriber === ""
                ? "Anmeldung nochmal bestätigen"
                : "Bestätigung erneut anfordern";
        $h =
            '<details class="confirmation-renewal"><summary>' .
            $e($title) .
            " · " .
            $counts["eligible"] .
            " erreichbar</summary>";
        if ($subscriber === "") {
            $h .=
                "<p>Diese Aktion umfasst alle erreichbaren unbestätigten Anmeldungen des Vereins, unabhängig von Suche und aktueller Seite.</p>";
        }
        $h .=
            "<p>" .
            $counts["queued"] .
            " bereits eingeplant oder in Verarbeitung · " .
            $counts["cooldown"] .
            " innerhalb der 48-Stunden-Wartezeit · " .
            $counts["uncertain"] .
            " mit unklarem Zustellausgang · " .
            $counts["invalid"] .
            " nicht erneut anzuschreiben.</p>";
        $h .=
            "<p>Ein neuer Link gilt ab Verarbeitung 48 Stunden. Das Einplanen bestätigt keine Einwilligung. Pro Hintergrundlauf werden höchstens 20 erneute Anfragen verarbeitet; unklare Zustellungen werden nicht automatisch wiederholt.</p>";
        if ($counts["eligible"] > 0) {
            $h .=
                $this->start("newsletter-renew-confirmation") .
                Html::hidden("subscriber_id", $subscriber) .
                Html::hidden("preview_hash", $preview["hash"]) .
                Html::hidden("request_id", bin2hex(random_bytes(16))) .
                '<label class="check"><input class="check" type="checkbox" name="renewal_confirmed" value="1" required>Ich möchte für ' .
                $counts["eligible"] .
                " unbestätigte Anmeldung(en) eine erneute Bestätigungsmail einplanen.</label>" .
                '<button class="btn btn-solid theme-primary">' .
                $counts["eligible"] .
                " Bestätigungsanfrage(n) einplanen</button></form>";
        }
        if ($subscriber !== "") {
            $states = [
                "pending" => "Eingeplant",
                "processing" => "In Verarbeitung",
                "sent" => "Transport bestätigt",
                "uncertain" => "Zustellausgang unklar – prüfen",
                "cancelled" => "Abgebrochen",
            ];
            $history = $service->history($subscriber);
            if ($history) {
                $h .= "<h3>Bisherige erneute Anfragen</h3><ul>";
                foreach ($history as $job) {
                    $h .=
                        "<li>" .
                        $e($this->displayTime($job["queued_at"])) .
                        " · " .
                        $e($states[$job["status"]] ?? "Status prüfen") .
                        "</li>";
                }
                $h .= "</ul>";
            }
        }
        return $h . "</details>";
    }
}
