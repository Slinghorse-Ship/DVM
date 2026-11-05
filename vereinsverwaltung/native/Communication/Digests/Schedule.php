<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication\Digests;

use DateTimeImmutable;
use DateTimeZone;

/** Berlin calendar rules; UTC is used only for stored worker timestamps. */
final class Schedule
{
    public static function at(
        string $mode,
        DateTimeImmutable $now,
        int $prepareDays = 7,
        int $sendHour = 10,
    ): array {
        self::mode($mode);
        if (
            $prepareDays < 1 ||
            $prepareDays > 21 ||
            $sendHour < 0 ||
            $sendHour > 23
        ) {
            throw new \DomainException(
                "Vorbereitungstage (1–21) und Versandstunde (0–23) prüfen.",
            );
        }
        $now = $now->setTimezone(new DateTimeZone("Europe/Berlin"));
        $start = $now->modify("first day of this month")->setTime(0, 0);
        if ($mode === "calendar") {
            $next = $start->modify("+1 month");
            if (
                $now >=
                $next->modify("-" . $prepareDays . " days")->setTime(10, 0)
            ) {
                $start = $next;
            }
        } elseif ($mode === "quarter") {
            $start = $start->modify(
                "-" . ((int) $start->format("n") - 1) % 3 . " months",
            );
        }
        $quarter =
            $mode === "quarter" ||
            ($mode === "calendar" &&
                in_array((int) $start->format("n"), [1, 4, 7, 10], true));
        $end = $start->modify($quarter ? "+3 months" : "+1 month");
        $key = $quarter
            ? "quarter-" .
                $start->format("Y") .
                "-Q" .
                (intdiv((int) $start->format("n") - 1, 3) + 1)
            : "month-" . $start->format("Y-m");
        $prepare = $start->modify("-" . $prepareDays . " days")->setTime(10, 0);
        $send = $start->setTime($sendHour, 0);
        $deadline = $start->modify("+2 days")->setTime(23, 59, 59);
        return [
            "period_key" => $key,
            "quarter" => $quarter,
            "start" => $start->format(DATE_ATOM),
            "end" => $end->format(DATE_ATOM),
            "prepare_at" => $prepare->format(DATE_ATOM),
            "send_at" => $send->format(DATE_ATOM),
            "deadline" => $deadline->format(DATE_ATOM),
            "ready" =>
                $mode !== "calendar" || ($now >= $prepare && $now <= $deadline),
        ];
    }

    public static function mode(string $mode): void
    {
        if (!in_array($mode, ["calendar", "month", "quarter"], true)) {
            throw new \DomainException("Unbekannter Newsletter-Periodenplan.");
        }
    }

    public static function key(string $key): void
    {
        if (
            !preg_match(
                '/^(?:month-[0-9]{4}-(?:0[1-9]|1[0-2])|quarter-[0-9]{4}-Q[1-4])$/D',
                $key,
            )
        ) {
            throw new \DomainException("Ungültige Newsletterperiode.");
        }
    }
}
