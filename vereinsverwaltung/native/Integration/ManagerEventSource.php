<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration;

use PDO;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Verein\{Registry, Permissions};

/** Calendar originals use their own current visibility, never website or social grants as calendar authority. */
final class ManagerEventSource
{
    public function __construct(private PDO $db, private Access $access) {}

    private function registry(): Registry
    {
        if (!($this->access instanceof ManagerAccess)) {
            throw new \DomainException(
                "Vereinskalender ist hier nicht angebunden.",
            );
        }
        $id = $this->access->managerActor();
        $actor = Permissions::actor($this->db, $id);
        if (
            !hash_equals(
                ManagerBridge::clubKey($actor["club_id"]),
                $this->access->club(),
            )
        ) {
            throw new \DomainException("Kalenderzuordnung geändert.");
        }
        return new Registry($this->db, $id);
    }

    public function available(): bool
    {
        if (!($this->access instanceof ManagerAccess)) {
            return false;
        }
        try {
            return $this->registry()->canReadCalendar();
        } catch (\DomainException) {
            return false;
        }
    }

    public function listing(array $query,int $pageSize=20): array
    {
        $page = $this->registry()->socialEventAppointments($query,$pageSize);
        $page["rows"] = array_map(self::normalize(...), $page["rows"]);
        return $page;
    }

    public function get(string $id, int $version): array
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException(
                "Terminoriginal nur im Speichervorgang auswählen.",
            );
        }
        $event = $this->registry()->appointment($id);
        if ((int) $event["version"] !== $version) {
            throw new \DomainException(
                "Kalendertermin inzwischen geändert. Bitte neu auswählen.",
            );
        }
        return self::normalize($event);
    }

    private static function normalize(array $event): array
    {
        // Do not copy participants, responses, report notes or a private manager URL.
        $original = array_intersect_key(
            $event,
            array_flip([
                "id",
                "club_id",
                "title",
                "starts_at",
                "ends_at",
                "location",
                "description",
                "category",
                "version",
                "activity_state",
                "activity_kind",
                "source_reference",
            ]),
        );
        return [
            "id" => $event["id"],
            "title" => $event["title"],
            "published_version" => (int) $event["version"],
            "event_source" => "manager",
            "_source_kind" => "manager",
            "_manager_original" => $original,
            "event_start" => $event["starts_at"],
            "event_end" => $event["ends_at"],
            "event_timezone" => "Europe/Berlin",
            "event_status" => match ($event["activity_state"]) {
                "cancelled" => "cancelled",
                "completed" => "ended",
                default => "scheduled",
            },
            "event_location" => $event["location"],
            "event_address" => "",
            "excerpt" => "",
            "body" => htmlspecialchars(
                $event["description"],
                ENT_QUOTES | ENT_SUBSTITUTE,
                "UTF-8",
            ),
            "media_ids" => [],
            "slug" => "",
            "source_url" => $event["source_reference"] ?? "",
        ];
    }
}
