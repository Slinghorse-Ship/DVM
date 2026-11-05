<?php declare(strict_types=1);
namespace Kulturhus\Verein\Events\Calendar;

use Kulturhus\Verein\{BoardAccess, DocumentChecks, Registry};
use PDO;

/** Expiry and review dates from the original document check. */
final class DocumentCheckSource
{
    public function __construct(
        private PDO $db,
        private string $actor,
        private Registry $registry,
    ) {}

    public function available(): bool
    {
        return BoardAccess::allowed($this->db, $this->actor);
    }

    public function rows(string $from, string $to): array
    {
        if (!$this->available()) {
            return [];
        }
        $rows = [];
        foreach (
            (new DocumentChecks(
                $this->db,
                $this->actor,
                $this->registry,
            ))->listing()
            as $check
        ) {
            // Review and expiry are different facts. Same-day facts share one entry.
            $dates = [];
            foreach (
                ["review_on" => "Prüfen", "valid_until" => "Gültigkeit endet"]
                as $field => $label
            ) {
                if ($check[$field] !== "") {
                    $dates[$check[$field]][] = $label;
                }
            }
            foreach ($dates as $date => $labels) {
                if ($date < $from || $date >= $to) {
                    continue;
                }
                $rows[] = Entry::dated(
                    "check:" . $check["id"] . ":" . $date,
                    implode(" / ", $labels) . ": " . $check["title"],
                    $date,
                    "deadlines",
                    [
                        "view" => "documents",
                        "tab" => "checks",
                        "check" => $check["id"],
                    ],
                    true,
                    $check["status"]["label"],
                );
            }
        }
        return $rows;
    }
}
