<?php declare(strict_types=1);
namespace Kulturhus\Verein\Events\Calendar;

use DateTimeImmutable;
use DateTimeZone;

/** Shared metadata shape; dates stay local for all-day planning. */
final class Entry
{
    public static function dated(
        string $id,
        string $title,
        string $date,
        string $kind,
        array $route,
        bool $allDay = false,
        string $state = "",
    ): array {
        $time = new DateTimeImmutable(
            $date,
            new DateTimeZone($allDay ? "Europe/Berlin" : "UTC"),
        );
        $stamp = $time
            ->setTimezone(new DateTimeZone("UTC"))
            ->format("Y-m-d\TH:i:s\Z");
        return [
            "id" => $id,
            "title" => $title,
            "starts_at" => $stamp,
            "ends_at" => $stamp,
            "end_known" => false,
            "all_day" => $allDay,
            "source_kind" => $kind,
            "category" => "verein",
            "location" => $state,
            "route" => $route,
        ];
    }
}
