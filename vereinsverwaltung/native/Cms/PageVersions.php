<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;
use InvalidArgumentException;
use RuntimeException;
use Kulturhus\Native\Shared\{Access, Db};
use PDO;

/** Bounded original revision metadata and two immutable snapshots, with no current-model coercion. */
final class PageVersions
{
    public function __construct(private PDO $db, private Access $access) {}

    public function overview(string $id, int $requestedPage = 1): array
    {
        if ($requestedPage < 1 || $requestedPage > 1000000) {
            throw new InvalidArgumentException("Versionsseite prüfen.");
        }
        return Db::atomic($this->db, function () use (
            $id,
            $requestedPage,
        ): array {
            $current = $this->owned($id);
            $counts = Db::run(
                $this->db,
                "SELECT COUNT(*) AS total,MAX(CASE WHEN revision<? THEN revision END) AS previous FROM khcms_revisions WHERE page_id=? AND club_id=?",
                [(int) $current["version"], $id, $current["club_id"]],
            )->fetch(PDO::FETCH_ASSOC);
            $total = (int) $counts["total"];
            $last = max(1, (int) ceil($total / 25));
            $page = min($requestedPage, $last);
            $rows = Db::run(
                $this->db,
                $this->metadataSql() .
                    " WHERE r.page_id=? AND r.club_id=? ORDER BY r.revision DESC LIMIT 25 OFFSET " .
                    ($page - 1) * 25,
                [$id, $current["club_id"]],
            )->fetchAll(PDO::FETCH_ASSOC);
            return [
                "id" => $id,
                "current" => (int) $current["version"],
                "previous" =>
                    $counts["previous"] === null
                        ? null
                        : (int) $counts["previous"],
                "published" =>
                    $current["published_revision"] === null
                        ? null
                        : (int) $current["published_revision"],
                "total" => $total,
                "page" => $page,
                "pages" => $last,
                "rows" => array_map(self::metadata(...), $rows),
            ];
        });
    }

    /** Selected historical originals stay selectable outside the current 25-row history page. */
    public function selected(string $id, array $revisions): array
    {
        if (!array_is_list($revisions) || count($revisions) > 2) {
            throw new InvalidArgumentException(
                "Höchstens zwei Originalversionen wählen.",
            );
        }
        foreach ($revisions as $revision) {
            self::version($revision);
        }
        return Db::atomic($this->db, function () use ($id, $revisions): array {
            $owner = $this->owned($id)["club_id"];
            $result = [];
            foreach (array_unique($revisions) as $revision) {
                $row = Db::run(
                    $this->db,
                    $this->metadataSql() .
                        " WHERE r.page_id=? AND r.club_id=? AND r.revision=?",
                    [$id, $owner, $revision],
                )->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    throw new RuntimeException(
                        "Gespeicherte Version nicht gefunden.",
                    );
                }
                $result[$revision] = self::metadata($row);
            }
            return $result;
        });
    }

    public function compare(string $id, int $from, int $to): array
    {
        self::version($from);
        self::version($to);
        return Db::atomic($this->db, function () use ($id, $from, $to): array {
            $owner = $this->owned($id)["club_id"];
            $snapshots = [];
            foreach (array_unique([$from, $to]) as $revision) {
                $row = Db::run(
                    $this->db,
                    $this->metadataSql(",r.payload_json") .
                        " WHERE r.page_id=? AND r.club_id=? AND r.revision=?",
                    [$id, $owner, $revision],
                )->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    throw new RuntimeException(
                        "Gespeicherte Version nicht gefunden.",
                    );
                }
                $payload = json_decode(
                    $row["payload_json"],
                    true,
                    64,
                    JSON_THROW_ON_ERROR,
                );
                if (!is_array($payload) || array_is_list($payload)) {
                    throw new RuntimeException(
                        "Originalversion ist beschädigt.",
                    );
                }
                $snapshots[$revision] = [
                    "metadata" => self::metadata($row),
                    "payload" => $payload,
                ];
            }
            return [
                "id" => $id,
                "from" => $snapshots[$from]["metadata"],
                "to" => $snapshots[$to]["metadata"],
                "changes" => VersionChanges::between(
                    $snapshots[$from]["payload"],
                    $snapshots[$to]["payload"],
                ),
                "before_labels" => VersionChanges::labels(
                    $snapshots[$from]["payload"],
                ),
                "after_labels" => VersionChanges::labels(
                    $snapshots[$to]["payload"],
                ),
            ];
        });
    }

    private function owned(string $id): array
    {
        $owner = $this->access->require("website", "read")["club_id"];
        if (!preg_match("/\A[a-f0-9]{32}\z/D", $id)) {
            throw new InvalidArgumentException("Seitenkennung prüfen.");
        }
        $row = Db::run(
            $this->db,
            "SELECT id,club_id,version,published_revision FROM khcms_pages WHERE id=? AND club_id=?",
            [$id, $owner],
        )->fetch(PDO::FETCH_ASSOC);
        return $row ?: throw new RuntimeException("Seite nicht gefunden.");
    }

    private function metadataSql(string $extra = ""): string
    {
        return "SELECT r.revision,r.actor_id,r.action,r.created_at,u.name AS actor_name" .
            $extra .
            " FROM khcms_revisions r LEFT JOIN native_users u ON u.id=r.actor_id AND u.club_id=r.club_id";
    }
    private static function metadata(array $row): array
    {
        return [
            "revision" => (int) $row["revision"],
            "actor_id" => $row["actor_id"],
            "action" => $row["action"],
            "created_at" => $row["created_at"],
            "actor_name" => $row["actor_name"],
        ];
    }
    private static function version(mixed $version): void
    {
        if (!is_int($version) || $version < 1 || $version > 4294967295) {
            throw new InvalidArgumentException(
                "Gültige gespeicherte Version wählen.",
            );
        }
    }
}
