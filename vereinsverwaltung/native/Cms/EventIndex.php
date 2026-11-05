<?php
declare(strict_types=1);

namespace Kulturhus\Native\Cms;

use Kulturhus\Native\Shared\Db;
use PDO;

/** References public originals; no copied body or draft enters the catalogue. */
final class EventIndex
{
    public static function install(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS khcms_events (
            club_id VARCHAR(64) NOT NULL, page_id CHAR(32) NOT NULL, revision INT UNSIGNED NOT NULL,
            slug VARCHAR(180) NOT NULL, starts_at DATETIME NULL, ends_at DATETIME NULL,
            local_year SMALLINT UNSIGNED NULL, status VARCHAR(20) NOT NULL, is_notice TINYINT UNSIGNED NOT NULL DEFAULT 0,
            sequence INT UNSIGNED NOT NULL, published_at DATETIME(6) NOT NULL, changed_at DATETIME(6) NOT NULL,
            PRIMARY KEY(club_id,page_id), UNIQUE KEY cms_event_slug(club_id,slug), KEY cms_event_start(club_id,starts_at,page_id),
            CONSTRAINT cms_event_revision FOREIGN KEY(page_id,revision) REFERENCES khcms_revisions(page_id,revision) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Bounded migration of existing published references, without re-publishing anything.
        $after = "";
        do {
            $rows = Db::run(
                $db,
                "SELECT p.id,p.club_id,p.published_revision,p.published_slug,p.published_at,r.payload_json FROM khcms_pages p JOIN khcms_revisions r ON r.page_id=p.id AND r.club_id=p.club_id AND r.revision=p.published_revision LEFT JOIN khcms_events e ON e.club_id=p.club_id AND e.page_id=p.id WHERE e.page_id IS NULL AND p.id>? ORDER BY p.id LIMIT 200",
                [$after],
            )->fetchAll();
            foreach ($rows as $row) {
                $payload=json_decode($row["payload_json"],true,512,JSON_THROW_ON_ERROR);
                // Keep existing original sequence, cancellation and timestamps. Never withdraw here.
                if (EventDetails::isEvent($payload)) self::write(
                    $db,$row["club_id"],$row["id"],(int)$row["published_revision"],
                    $payload["slug"],$payload,(int)$row["published_revision"],$row["published_at"],false,
                );
                $after = $row["id"];
            }
        } while (count($rows) === 200);
    }

    public static function publish(
        PDO $db,
        string $club,
        string $id,
        int $revision,
        array $payload,
        ?string $publishedAt = null,
    ): void {
        self::withdraw($db, $club, $id);
        if (!EventDetails::isEvent($payload)) {
            return;
        }
        self::write(
            $db,
            $club,
            $id,
            $revision,
            $payload["slug"],
            $payload,
            $revision,
            $publishedAt,
            false,
        );
    }

    public static function withdraw(PDO $db, string $club, string $id): void
    {
        Db::run($db, "DELETE FROM khcms_events WHERE club_id=? AND page_id=?", [
            $club,
            $id,
        ]);
    }

    /** Called while the original event and club publication transaction are locked. */
    public static function capture(PDO $db, string $club, string $id): ?array
    {
        if (!$db->inTransaction()) {
            throw new \DomainException(
                "Absage benötigt die ursprüngliche Veröffentlichungstransaktion.",
            );
        }
        $row = Db::run(
            $db,
            "SELECT p.id,p.published_revision,p.published_slug,p.published_at,r.payload_json FROM khcms_pages p JOIN khcms_revisions r ON r.page_id=p.id AND r.club_id=p.club_id AND r.revision=p.published_revision WHERE p.club_id=? AND p.id=? FOR UPDATE",
            [$club, $id],
        )->fetch();
        if (
            !$row ||
            !EventDetails::isEvent(
                json_decode(
                    $row["payload_json"],
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                ),
            )
        ) {
            return null;
        }
        $row["media_ids"] = Db::run(
            $db,
            "SELECT media_id FROM khcms_published_media WHERE club_id=? AND page_id=?",
            [$club, $id],
        )->fetchAll(PDO::FETCH_COLUMN);
        return $row;
    }

    /** An external cancellation retains only a genuinely published revision and its media. */
    public static function cancellation(
        PDO $db,
        string $club,
        ?array $snapshot,
        int $version,
    ): void {
        if (!$snapshot) {
            return;
        }
        if (!$db->inTransaction()) {
            throw new \DomainException(
                "Absage benötigt die ursprüngliche Veröffentlichungstransaktion.",
            );
        }
        $original = Db::run(
            $db,
            "SELECT r.payload_json FROM khcms_pages p JOIN khcms_revisions r ON r.page_id=p.id AND r.club_id=p.club_id WHERE p.club_id=? AND p.id=? AND p.version=? AND p.published_revision IS NULL AND r.revision=? AND r.action='publish' FOR UPDATE",
            [$club, $snapshot["id"], $version, $snapshot["published_revision"]],
        )->fetchColumn();
        if (!$original || !hash_equals($original, $snapshot["payload_json"])) {
            throw new \DomainException(
                "Ursprüngliche veröffentlichte Veranstaltung nicht verfügbar.",
            );
        }
        $payload = json_decode($original, true, 512, JSON_THROW_ON_ERROR);
        $payload["event_status"] = "cancelled";
        self::write(
            $db,
            $club,
            $snapshot["id"],
            (int) $snapshot["published_revision"],
            $snapshot["published_slug"],
            $payload,
            $version,
            $snapshot["published_at"],
            true,
        );
        foreach ($snapshot["media_ids"] as $id) {
            Db::run(
                $db,
                "INSERT INTO khcms_published_media(page_id,media_id,club_id) VALUES(?,?,?)",
                [$snapshot["id"], $id, $club],
            );
        }
    }

    public static function clearNotice(PDO $db, string $club, string $id): void
    {
        if (
            Db::run(
                $db,
                "DELETE FROM khcms_events WHERE club_id=? AND page_id=? AND is_notice=1",
                [$club, $id],
            )->rowCount()
        ) {
            Db::run(
                $db,
                "DELETE FROM khcms_published_media WHERE club_id=? AND page_id=?",
                [$club, $id],
            );
            Db::run(
                $db,
                "INSERT INTO khcms_state(club_id,generation) VALUES(?,1) ON DUPLICATE KEY UPDATE generation=generation+1",
                [$club],
            );
        }
    }

    private static function write(
        PDO $db,
        string $club,
        string $id,
        int $revision,
        string $slug,
        array $payload,
        int $sequence,
        ?string $publishedAt,
        bool $notice,
    ): void {
        $start = EventDetails::start($payload);
        $end = EventDetails::end($payload);
        Db::run(
            $db,
            "INSERT INTO khcms_events(club_id,page_id,revision,slug,starts_at,ends_at,local_year,status,is_notice,sequence,published_at,changed_at) VALUES(?,?,?,?,?,?,?,?,?,?,COALESCE(?,UTC_TIMESTAMP(6)),UTC_TIMESTAMP(6))",
            [
                $club,
                $id,
                $revision,
                $slug,
                $start?->format("Y-m-d H:i:s"),
                $end?->format("Y-m-d H:i:s"),
                $start
                    ?->setTimezone(new \DateTimeZone("Europe/Berlin"))
                    ->format("Y"),
                $payload["event_status"] ?? "scheduled",
                (int) $notice,
                $sequence,
                $publishedAt,
            ],
        );
    }
}
