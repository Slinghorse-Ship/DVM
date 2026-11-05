<?php declare(strict_types=1);
namespace Kulturhus\Native\Events;
use PDO;
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Native\Communication\Content;
use Kulturhus\Native\Cms\{ClubPublicationLock, EventIndex, Pages};
/** Complete input first, then guarded imports. Eventbrite events never become Facebook events here. */
final class Eventbrite
{
    use ClubPublicationLock;
    public function __construct(private PDO $db, private Access $access) {}
    public function fetch(string $organization, callable $request): array
    {
        $fetchClub=$this->access->require("website", "edit")['club_id'];
        if (!preg_match('/^[0-9]{1,40}$/D', $organization)) {
            throw new \DomainException("Eventbrite-Organisation prüfen.");
        }
        $events = [];
        $seen = [];
        for ($page = 1; $page <= 100; $page++) {
            $r = $request(
                "/v3/organizations/" .
                    $organization .
                    "/events/?status=all&expand=venue,logo&page=" .
                    $page,
            );
            if($this->access->require('website','edit')['club_id']!==$fetchClub)throw new \DomainException('Vereinszuordnung während der Eventbrite-Abfrage geändert.');
            if (
                !is_array($r) ||
                !is_array($r["events"] ?? null) ||
                !array_is_list($r["events"]) ||
                !is_array($r["pagination"] ?? null) ||
                ($r["pagination"]["page_number"] ?? null) !== $page ||
                !is_int($r["pagination"]["page_count"] ?? null) ||
                $r["pagination"]["page_count"] < $page ||
                $r["pagination"]["page_count"] > 100
            ) {
                throw new \DomainException(
                    "Unvollständige Eventbrite-Antwort.",
                );
            }
            foreach ($r["events"] as $event) {
                $normalized = self::normalize($event);
                if (isset($seen[$normalized["external_id"]])) {
                    throw new \DomainException(
                        "Doppelte Eventbrite-Identität in der Antwort.",
                    );
                }
                $seen[$normalized["external_id"]] = true;
                $events[] = $normalized;
                if (count($events) > 5000) {
                    throw new \DomainException(
                        "Eventbrite-Lauflimit überschritten.",
                    );
                }
            }
            if ($page === $r["pagination"]["page_count"]) {
                return $events;
            }
        }
        throw new \DomainException("Eventbrite-Antwort nicht vollständig.");
    }
    public static function normalize(array $event, bool $optionalTicket = false): array
    {
        $id = $event["id"] ?? "";
        if (!is_string($id) || !preg_match('/^[0-9]{1,40}$/D', $id)) {
            throw new \DomainException("Eventbrite-ID fehlt.");
        }
        $status = $event["status"] ?? "";
        if (
            !in_array(
                $status,
                [
                    "draft",
                    "live",
                    "started",
                    "ended",
                    "canceled",
                    "cancelled",
                    "completed",
                ],
                true,
            )
        ) {
            throw new \DomainException("Eventbrite-Status unbekannt.");
        }
        $timezone = $event["start"]["timezone"] ?? "";
        if (
            !is_string($timezone) ||
            !in_array($timezone, \DateTimeZone::listIdentifiers(), true)
        ) {
            throw new \DomainException("Eventbrite-Zeitzone fehlt.");
        }
        $start = $event["start"]["utc"] ?? "";
        $end = $event["end"]["utc"] ?? "";
        foreach ([$start, $end] as $date) {
            if (
                !is_string($date) ||
                !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $date)
            ) {
                throw new \DomainException("Eventbrite-UTC-Zeit fehlt.");
            }
        }
        $begin = new \DateTimeImmutable($start);
        $finish = new \DateTimeImmutable($end);
        if (
            $begin->format("Y-m-d\TH:i:s\Z") !== $start ||
            $finish->format("Y-m-d\TH:i:s\Z") !== $end ||
            $finish < $begin
        ) {
            throw new \DomainException("Eventbrite-Zeiten ungültig.");
        }
        $image =
            $event["logo"]["original"]["url"] ?? ($event["logo"]["url"] ?? "");
        if ($image !== "") {
            $image = ImageDownload::url($image);
        }
        return [
            "external_id" => $id,
            "title" => Content::text($event["name"]["text"] ?? "", 200, true),
            "body" => Content::text(
                $event["description"]["text"] ?? "",
                100000,
            ),
            "event_start" => $begin
                ->setTimezone(new \DateTimeZone($timezone))
                ->format("Y-m-d\TH:i"),
            "event_timezone" => $timezone,
            "starts_at" => $start,
            "ends_at" => $end,
            "event_location" => Content::text(
                $event["venue"]["name"] ?? "",
                300,
            ),
            "event_address" => Content::text(
                $event["venue"]["address"]["localized_address_display"] ?? "",
                1200,
            ),
            "event_price" =>
                ($event["is_free"] ?? false) === true ? "0.00" : "",
            "status" => $status,
            "ticket_url" => $optionalTicket && ($event['url']??'')==='' ? '' : Content::url($event["url"] ?? ""),
            "image_url" => $image,
        ];
    }
    public function import(array $events,?string $expectedClub=null,?int $workflowVersion=null): array
    {
        return (new SourceImporter($this->db,$this->access,'eventbrite'))->import($events,$expectedClub,$workflowVersion);
    }
}
