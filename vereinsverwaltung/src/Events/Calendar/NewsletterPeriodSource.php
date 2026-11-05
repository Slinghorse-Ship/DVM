<?php declare(strict_types=1);
namespace Kulturhus\Verein\Events\Calendar;

use PDO;
use DateTimeImmutable;
use DateTimeZone;
use Kulturhus\Native\Communication\Digests\{Plans, Schedule};
use Kulturhus\Native\Shared\{Access, Db};

/** Period dates are views of the same plans; no second appointments or jobs are stored. */
final class NewsletterPeriodSource
{
    public function __construct(private PDO $db, private Access $access) {}

    public function rows(string $from, string $to): array
    {
        $actor = $this->access->require("newsletter", "read");
        $club = $actor["club_id"];
        $utc = new DateTimeZone("UTC");
        $zone = new DateTimeZone("Europe/Berlin");
        $start = (new DateTimeImmutable($from, $utc))
            ->setTimezone($zone)
            ->modify("first day of this month")
            ->setTime(0, 0);
        $last = (new DateTimeImmutable($to, $utc))
            ->setTimezone($zone)
            ->modify("first day of this month")
            ->setTime(0, 0)
            ->modify("+1 month");
        $rows = [];
        foreach (
            Db::run(
                $this->db,
                "SELECT period,paused,prepare_days,send_hour FROM native_digest_plans WHERE club_id=? ORDER BY period",
                [$club],
            )->fetchAll()
            as $plan
        ) {
            $seen = [];
            for (
                $month = $start;
                $month <= $last;
                $month = $month->modify("+1 month")
            ) {
                $schedule = Schedule::at(
                    $plan["period"],
                    $month,
                    (int) $plan["prepare_days"],
                    (int) $plan["send_hour"],
                );
                $key = $schedule["period_key"];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $storedKey =
                    $plan["period"] === "calendar"
                        ? $key
                        : preg_replace("/^(month|quarter)-/", "", $key);
                $item = Db::run(
                    $this->db,
                    "SELECT item_id FROM native_digest_editions WHERE club_id=? AND period=? AND period_key=?",
                    [$club, $plan["period"], $storedKey],
                )->fetchColumn();
                $route = $item
                    ? ["view" => "newsletter", "native_item" => $item]
                    : ["view" => "newsletter", "section" => "planning"];
                $paused =
                    $plan["paused"] ||
                    Plans::paused($this->db, $club, $plan["period"], $key);
                foreach (
                    [
                        "prepare_at" => "Ausgabe vorbereiten",
                        "send_at" => "Versandfenster beginnt",
                        "deadline" => "Versandfenster endet",
                    ]
                    as $field => $label
                ) {
                    $date = (new DateTimeImmutable($schedule[$field]))
                        ->setTimezone($utc)
                        ->format("Y-m-d H:i:s");
                    if ($date < $from || $date >= $to) {
                        continue;
                    }
                    $this->access->require("newsletter", "read");
                    $rows[] = Entry::dated(
                        "newsletter-period:" .
                            $plan["period"] .
                            ":" .
                            $key .
                            ":" .
                            $field,
                        $label . ": " . $key,
                        $date,
                        "newsletter",
                        $route,
                        false,
                        $paused ? "Pausiert" : "Newsletterperiode",
                    );
                }
            }
        }
        return $rows;
    }
}
