<?php
declare(strict_types=1);

namespace Kulturhus\Native\Cms;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** One interpretation of event data for the website, calendar and exports. */
final class EventDetails
{
    public const STATUSES = [
        "scheduled" => "Geplant",
        "started" => "Läuft bereits",
        "ended" => "Beendet",
        "cancelled" => "Abgesagt",
        "postponed" => "Verschoben",
        "sold_out" => "Ausverkauft",
    ];

    public static function normalize(array $input): array
    {
        $status = $input["event_status"] ?? "scheduled";
        $mode=$input['event_status_mode']??(in_array($status,['scheduled','started','automatic'],true)?'automatic':'manual');
        if(!is_string($mode)||!in_array($mode,['automatic','manual'],true))throw new InvalidArgumentException('Automatischen oder manuellen Veranstaltungsstatus prüfen.');
        if($status==='automatic'){$status='scheduled';$mode='automatic';}
        if (!is_string($status) || !isset(self::STATUSES[$status])) {
            throw new InvalidArgumentException("Veranstaltungsstatus prüfen.");
        }
        $price = $input["event_price"] ?? "";
        if (is_string($price)) {
            $price = str_replace(",", ".", trim($price));
        }
        if (
            !is_string($price) ||
            ($price !== "" &&
                !preg_match(
                    '/^(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{2})?$/D',
                    $price,
                ))
        ) {
            throw new InvalidArgumentException(
                "Eintritt als Betrag mit optional zwei Nachkommastellen angeben.",
            );
        }
        return [
            "event_status" => $status,
            "event_status_mode" => $mode,
            "event_address" => self::text($input["event_address"] ?? "", 1200),
            "event_ticket_url" => self::url($input["event_ticket_url"] ?? ""),
            "event_reference_url" => self::url(
                $input["event_reference_url"] ?? "",
            ),
            "event_price" => $price,
        ];
    }

    private static function text(mixed $value, int $limit): string
    {
        if (
            !is_string($value) ||
            preg_match("//u", $value) !== 1 ||
            preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) ||
            preg_match_all("/./us", $value) > $limit
        ) {
            throw new InvalidArgumentException("Veranstaltungsangabe prüfen.");
        }
        return trim(str_replace(["\r\n", "\r"], "\n", $value));
    }

    private static function url(mixed $value): string
    {
        if ($value === "") {
            return "";
        }
        if (
            !is_string($value) ||
            strlen($value) > 2000 ||
            preg_match('/[\x00-\x20\x7f\\\\]/', $value) ||
            filter_var($value, FILTER_VALIDATE_URL) === false
        ) {
            throw new InvalidArgumentException(
                "Gültigen HTTPS-Veranstaltungslink angeben.",
            );
        }
        $parts = parse_url($value);
        if (
            ($parts["scheme"] ?? "") !== "https" ||
            empty($parts["host"]) ||
            isset($parts["user"]) ||
            isset($parts["pass"])
        ) {
            throw new InvalidArgumentException(
                "Veranstaltungslinks benötigen HTTPS ohne Zugangsdaten.",
            );
        }
        return $value;
    }

    public static function start(array $page): ?DateTimeImmutable
    {
        if (($page["event_start"] ?? "") === "") {
            return null;
        }
        return (new DateTimeImmutable(
            $page["event_start_utc"] ?? "" ?: $page["event_start"],
            new DateTimeZone($page["event_timezone"] ?? "Europe/Berlin"),
        ))->setTimezone(new DateTimeZone("UTC"));
    }

    public static function end(array $page): ?DateTimeImmutable
    {
        return ($page["event_end"] ?? "") !== ""
            ? new DateTimeImmutable($page["event_end"], new DateTimeZone($page["event_timezone"] ?? "Europe/Berlin"))
            : null;
    }

    public static function isEvent(array $page): bool
    {
        return ($page["template_definition"]["renderer"] ??
            ($page["template"] ?? "")) ===
            "event";
    }

    public static function cancelled(array $page): bool
    {
        return ($page["event_status"] ?? "scheduled") === "cancelled";
    }

    public static function mode(array $page):string
    {
        return $page['event_status_mode']??(in_array($page['event_status']??'scheduled',['scheduled','started'],true)?'automatic':'manual');
    }

    /** Derive the current display without rewriting a published original or a human override. */
    public static function status(array $page,?DateTimeImmutable $now=null):string
    {
        $saved=$page['event_status']??'scheduled';
        if(self::mode($page)!=='automatic'||!in_array($saved,['scheduled','started'],true))return $saved;
        $start=self::start($page);if($start===null)return $saved;
        $now??=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        // With no known end, keep the event for its whole local calendar day.
        $end=self::end($page)??$start->setTimezone(new DateTimeZone($page['event_timezone']??'Europe/Berlin'))->modify('+1 day')->setTime(0,0);
        if($now>=$end)return 'ended';
        return $now>=$start?'started':'scheduled';
    }

    public static function label(array $page): string
    {
        return self::STATUSES[self::status($page)]??self::STATUSES['scheduled'];
    }
}
