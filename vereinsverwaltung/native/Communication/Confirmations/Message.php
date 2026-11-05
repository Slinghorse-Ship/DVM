<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication\Confirmations;

use PDO;
use Kulturhus\Native\Shared\{Identity, PrintedBrand, MailDesign};

/** One two-step explanation and the common club branding for initial and renewed DOI. */
final class Message
{
    public const INSTRUCTION = "Öffne den Link und klicke anschließend auf der Website auf „Newsletter bestätigen“. Erst dann ist deine Anmeldung abgeschlossen.";

    public static function brand(
        PDO $db,
        string $club,
        ?string $mediaRoot = null,
    ): array {
        $identity = Identity::forClub($db, $club);
        if ($identity["origin"] === "") {
            throw new \DomainException(
                "Newsletter ist noch nicht eingerichtet.",
            );
        }
        $brand = PrintedBrand::snapshot(
            $db,
            $club,
            $identity,
            $mediaRoot ?? "",
        );
        if ($identity["logo_id"] !== "" && $brand["logo_status"] !== "frozen") {
            throw new \DomainException(
                "Das Vereinslogo ist im privaten Bildspeicher nicht verfügbar.",
            );
        }
        return MailDesign::brand($identity + ["logo_png" => $brand["logo_png"]]);
    }

    public static function compose(
        array $identity,
        string $confirm,
        string $unsubscribe,
        bool $renewed,
    ): array {
        $e = static fn(string $s): string => htmlspecialchars(
            $s,
            ENT_QUOTES | ENT_SUBSTITUTE,
            "UTF-8",
        );
        $title = $renewed
            ? "Newsletter-Anmeldung nochmal bestätigen"
            : "Newsletter-Anmeldung bestätigen";
        $button = $renewed ? "Jetzt nochmal bestätigen" : "Jetzt bestätigen";
        $origin = $identity["origin"];
        $html = MailDesign::confirmation($identity, $title, self::INSTRUCTION, $confirm, $button, $unsubscribe);
        $text =
            $identity["name"] .
            "\n\n" .
            $title .
            "\n\n" .
            self::INSTRUCTION .
            "\n\nDer Link gilt 48 Stunden. Deine bisherigen Angaben bleiben erhalten.\n" .
            $confirm .
            "\n\nAnmeldung abmelden: " .
            $unsubscribe .
            "\n\n" .
            $identity["name"] .
            "\n" .
            $identity["address"] .
            "\nImpressum: " .
            $origin .
            "/impressum/" .
            "\nDatenschutz: " .
            $origin .
            "/datenschutz/";
        return [
            "subject" => $title,
            "html" => $html,
            "text" => $text,
            "logo_png" => $identity["logo_png"] ?? "",
        ];
    }
}
