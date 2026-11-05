<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use PDO;
use Kulturhus\Native\Shared\{Access, Db};

/** Private manual publication ledger; it never calls a provider or queues a send. */
final class StoryHandoff
{
    public function __construct(
        private PDO $db,
        private Access $access,
        private string $mediaRoot,
    ) {}

    public function history(string $item): array
    {
        (new Workspace($this->db, $this->access, "social"))->get($item);
        return array_map(
            self::decode(...),
            Db::run(
                $this->db,
                "SELECT * FROM native_social_handoffs WHERE club_id=? AND item_id=? ORDER BY created_at DESC,channel",
                [$this->access->club(), $item],
            )->fetchAll(),
        );
    }

    private static function decode(array $row): array
    {
        $row["snapshot"] = json_decode(
            $row["snapshot_json"],
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        unset($row["snapshot_json"]);
        $row["version"] = (int) $row["version"];
        $row["item_version"] = (int) $row["item_version"];
        return $row;
    }

    private function event(
        string $id,
        string $action,
        array $details = [],
    ): void {
        Db::run(
            $this->db,
            "INSERT INTO native_social_handoff_events(id,club_id,handoff_id,action,actor_id,authority,details_json,created_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(6))",
            [
                bin2hex(random_bytes(16)),
                $this->access->club(),
                $id,
                $action,
                $this->access->user()["id"],
                $this->access instanceof
                \Kulturhus\Native\Integration\ManagerAccess
                    ? "manager"
                    : "native",
                json_encode($details, JSON_THROW_ON_ERROR),
            ],
        );
    }

    public function prepare(string $itemId, int $expectedVersion): array
    {
        return Db::atomic($this->db, function () use (
            $itemId,
            $expectedVersion,
        ): array {
            Content::id($itemId);
            $this->access->require("social", "publish");
            Db::run(
                $this->db,
                "SELECT id FROM khn_social_items WHERE club_id=? AND id=? FOR UPDATE",
                [$this->access->club(), $itemId],
            );
            $publication = PublicationSnapshot::social(
                $this->db,
                $this->access,
                $itemId,
                $this->mediaRoot,
            );
            $item = $publication["item"];
            if (
                $item["version"] !== $expectedVersion ||
                $item["status"] !== "approved"
            ) {
                throw new \DomainException(
                    "Die aktuelle freigegebene Fassung neu öffnen.",
                );
            }
            if (
                $item["kind"] !== "story" ||
                empty($item["story_link_requested"])
            ) {
                throw new \DomainException(
                    "Diese Übergabe ist für Stories mit gewünschtem Link-Sticker.",
                );
            }
            $images = [];
            $destinations = [];
            foreach ($publication["images"] as $image) {
                $metadata = json_decode(
                    $image["metadata_json"],
                    true,
                    32,
                    JSON_THROW_ON_ERROR,
                );
                $destinations[] = Content::url($metadata["destination"] ?? "");
                $images[] = [
                    "id" => $image["id"],
                    "slide" => (int) $image["slide"],
                    "sha256" => $image["sha256"],
                ];
            }
            if (count(array_unique($destinations)) !== 1) {
                throw new \DomainException(
                    "Bildfolien haben unterschiedliche Zieladressen; neu erzeugen und freigeben.",
                );
            }
            $destination = Content::url(
                $item["destination_url"] ?: $destinations[0],
            );
            if ($destination !== $destinations[0]) {
                throw new \DomainException(
                    "Freigegebene Bilder gehören zu einer anderen Zieladresse.",
                );
            }
            foreach ($item["channels"] as $channel) {
                $old = Db::run(
                    $this->db,
                    "SELECT id FROM native_social_handoffs WHERE club_id=? AND item_id=? AND item_version=? AND channel=? FOR UPDATE",
                    [
                        $this->access->club(),
                        $itemId,
                        $expectedVersion,
                        $channel,
                    ],
                )->fetchColumn();
                if ($old) {
                    continue;
                }
                $id = bin2hex(random_bytes(16));
                $snapshot = [
                    "title" => $item["title"],
                    "content_hash" => Images::fingerprint($item),
                    "images" => $images,
                    "destination_url" => $destination,
                    "channel" => $channel,
                ];
                Db::run(
                    $this->db,
                    "INSERT INTO native_social_handoffs(id,club_id,item_id,item_version,channel,snapshot_json,status,version,created_at,updated_at) VALUES(?,?,?,?,?,?,'prepared',1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",
                    [
                        $id,
                        $this->access->club(),
                        $itemId,
                        $expectedVersion,
                        $channel,
                        json_encode(
                            $snapshot,
                            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                        ),
                    ],
                );
                $this->event($id, "prepared");
            }
            return $this->history($itemId);
        });
    }

    private function row(string $id, int $version): array
    {
        $this->access->require("social", "publish");
        $this->access->require("social", "read");
        Content::id($id);
        $row = Db::run(
            $this->db,
            "SELECT * FROM native_social_handoffs WHERE club_id=? AND id=? FOR UPDATE",
            [$this->access->club(), $id],
        )->fetch();
        if (!$row || (int) $row["version"] !== $version) {
            throw new \DomainException(
                "Übergabe nicht verfügbar oder inzwischen geändert.",
            );
        }
        return self::decode($row);
    }

    public function complete(
        string $id,
        int $version,
        array $checks,
        string $reference = "",
    ): void {
        Db::atomic($this->db, function () use (
            $id,
            $version,
            $checks,
            $reference,
        ): void {
            $row = $this->row($id, $version);
            if ($row["status"] !== "prepared") {
                throw new \DomainException(
                    "Nur offene Übergaben können bestätigt werden.",
                );
            }
            foreach (["images_checked", "link_added", "published"] as $check) {
                if (($checks[$check] ?? false) !== true) {
                    throw new \DomainException(
                        "Bilder, Link-Sticker und tatsächliche Veröffentlichung einzeln bestätigen.",
                    );
                }
            }
            $current = PublicationSnapshot::social(
                $this->db,
                $this->access,
                $row["item_id"],
                $this->mediaRoot,
            );
            $item = $current["item"];
            if (
                $item["status"] !== "approved" ||
                $item["version"] !== $row["item_version"] ||
                !hash_equals(
                    $row["snapshot"]["content_hash"],
                    Images::fingerprint($item),
                ) ||
                array_column($current["images"], "id") !==
                    array_column($row["snapshot"]["images"], "id")
            ) {
                throw new \DomainException(
                    "Inhalt oder Freigabe geändert. Alte Übergabe abbrechen und neue Fassung freigeben.",
                );
            }
            $reference = trim($reference);
            if ($reference !== "") {
                $reference = Content::url($reference);
                $url = parse_url($reference);
                $hosts =
                    $row["channel"] === "instagram"
                        ? ["instagram.com", "www.instagram.com"]
                        : [
                            "facebook.com",
                            "www.facebook.com",
                            "m.facebook.com",
                        ];
                if (
                    !in_array(strtolower($url["host"]), $hosts, true) ||
                    isset($url["port"]) ||
                    trim($url["path"] ?? "", "/") === ""
                ) {
                    throw new \DomainException(
                        "Optionalen Link zur Story auf dem gewählten Kanal prüfen.",
                    );
                }
            }
            Db::run(
                $this->db,
                "UPDATE native_social_handoffs SET status='confirmed',version=version+1,confirmed_at=UTC_TIMESTAMP(6),reference_url=?,updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND id=?",
                [$reference, $this->access->club(), $id],
            );
            $this->event($id, "manually_confirmed", [
                "checks" => $checks,
                "reference_url" => $reference,
            ]);
        });
    }

    public function cancel(string $id, int $version, string $reason): void
    {
        $reason = Content::text($reason, 1000, true);
        if (strlen($reason) < 10) {
            throw new \DomainException(
                "Abbruch kurz nachvollziehbar begründen.",
            );
        }
        Db::atomic($this->db, function () use ($id, $version, $reason): void {
            $row = $this->row($id, $version);
            if ($row["status"] !== "prepared") {
                throw new \DomainException(
                    "Nur offene Übergaben können abgebrochen werden.",
                );
            }
            Db::run(
                $this->db,
                "UPDATE native_social_handoffs SET status='cancelled',version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND id=?",
                [$this->access->club(), $id],
            );
            $this->event($id, "cancelled", ["reason" => $reason]);
        });
    }

    public function events(string $id): array
    {
        $this->access->require("social", "read");
        Content::id($id);
        return Db::run(
            $this->db,
            "SELECT action,actor_id,authority,details_json,created_at FROM native_social_handoff_events WHERE club_id=? AND handoff_id=? ORDER BY created_at,id",
            [$this->access->club(), $id],
        )->fetchAll();
    }
}
