<?php declare(strict_types=1);
namespace Kulturhus\Verein\Events\Calendar;

use Kulturhus\Verein\Registry;

/** Existing protected appointments, including published website events. */
final class AppointmentSource
{
    public function __construct(private Registry $registry) {}

    public function rows(string $from, string $to, string $filter): array
    {
        if (
            !in_array(
                $filter,
                ["all", "events", "external", "internal", "board"],
                true,
            )
        ) {
            return [];
        }
        $rows = [];
        foreach ($this->registry->appointments($from, $to) as $event) {
            $event["source_kind"] =
                ($event["source"] ?? "") === "website"
                    ? "events"
                    : (($event["activity_kind"] ?? "") === "external"
                        ? "external"
                        : (in_array(
                            $event["activity_kind"] ?? "",
                            ["board", "assembly"],
                            true,
                        )
                            ? "board"
                            : "internal"));
            if (
                $filter !== "all" &&
                $event["source_kind"] !== $filter &&
                !($filter === "external" && $event["source_kind"] === "events")
            ) {
                continue;
            }
            $event["route"] = ["view" => "calendar", "event" => $event["id"]];
            $rows[] = $event;
        }
        return $rows;
    }
}
