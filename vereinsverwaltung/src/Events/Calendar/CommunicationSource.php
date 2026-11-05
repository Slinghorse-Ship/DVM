<?php declare(strict_types=1);
namespace Kulturhus\Verein\Events\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Kulturhus\Native\Communication\Workspace;
use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Verein\Permissions;
use PDO;

/** Planning and grouped delivery metadata, never private recipient payloads. */
final class CommunicationSource
{
    private const MODULES = [
        "newsletter" => "Newsletter",
        "social" => "Social Media",
        "press" => "Presse",
    ];

    public function __construct(
        private PDO $db,
        private string $actor,
        private Access $access,
    ) {}

    public function filters(): array
    {
        $filters = [];
        foreach (self::MODULES as $module => $label) {
            try {
                $this->access->require($module, "read");
                $filters[$module] = $label;
            } catch (DomainException) {
            }
        }
        return $filters;
    }

    public function rows(string $from, string $to, string $filter): array
    {
        $managerClub = Permissions::actor($this->db, $this->actor)["club_id"];
        if (
            !hash_equals(
                ManagerBridge::clubKey($managerClub),
                $this->access->club(),
            )
        ) {
            throw new DomainException(
                "Kalender und Kommunikationszugang gehören nicht zum selben Verein.",
            );
        }
        $zone = new DateTimeZone("Europe/Berlin");
        $utc = new DateTimeZone("UTC");
        $start = (new DateTimeImmutable($from, $zone))
            ->setTimezone($utc)
            ->format("Y-m-d H:i:s");
        $end = (new DateTimeImmutable($to, $zone))
            ->setTimezone($utc)
            ->format("Y-m-d H:i:s");
        $filters = $this->filters();
        $rows = [];
        foreach (self::MODULES as $module => $label) {
            if (
                !in_array($filter, ["all", $module], true) ||
                !isset($filters[$module])
            ) {
                continue;
            }
            $workspace = new Workspace($this->db, $this->access, $module);
            foreach ($workspace->automations() as $plan) {
                if (
                    !in_array($plan["status"], ["enabled", "paused"], true) ||
                    $plan["due_at"] < $start ||
                    $plan["due_at"] >= $end
                ) {
                    continue;
                }
                $item = $workspace->get($plan["item_id"]);
                $rows[] = Entry::dated(
                    "plan:" . $module . ":" . $plan["id"],
                    "Entwurfsvorschlag: " . $item["title"],
                    $plan["due_at"],
                    $module,
                    ["view" => $module, "native_item" => $item["id"]],
                    false,
                    $plan["status"] === "paused"
                        ? "Pausiert"
                        : "Vorschlag geplant",
                );
            }
            if (in_array($module,['newsletter','social','press'],true)) {
                $rows = array_merge(
                    $rows,
                    (new PublicationSource($this->db, $this->access))->rows(
                        $module,
                        $label,
                        $start,
                        $end,
                    ),
                );
            }
            if ($module === "newsletter") {
                $rows = array_merge(
                    $rows,
                    (new NewsletterPeriodSource(
                        $this->db,
                        $this->access,
                    ))->rows($start, $end),
                );
            }
        }
        return $rows;
    }
}
