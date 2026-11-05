<?php
declare(strict_types=1);

namespace Kulturhus\Native\Cms;

use DomainException;
use Kulturhus\Native\Integration\{ManagerBridge, ManagerClub};
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\ManagerNotifications;
use PDO;

require_once dirname(__DIR__, 2) . "/src/autoload.php";

/** Enqueue-only publication lifecycle. Existing manager topics, preferences and device queues stay authoritative. */
final class EventPublications
{
    public static function install(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS khcms_event_publications (
            club_id VARCHAR(64) NOT NULL, page_id CHAR(32) NOT NULL, published_seen TINYINT UNSIGNED NOT NULL,
            first_seen_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY(club_id,page_id),
            CONSTRAINT cms_event_publication_original FOREIGN KEY(page_id) REFERENCES khcms_pages(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Explicit schema maintenance establishes the baseline from original publication history.
        // Repeated or interrupted installation never resets an existing first-publication decision.
        $after = "";
        $event =
            "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(%s,'$.template_definition.renderer')),JSON_UNQUOTE(JSON_EXTRACT(%s,'$.template')))='event'";
        $published =
            "EXISTS(SELECT 1 FROM khcms_revisions r WHERE r.club_id=p.club_id AND r.page_id=p.id AND r.action='publish' AND " .
            sprintf($event, "r.payload_json", "r.payload_json") .
            ")";
        do {
            $rows = Db::run(
                $db,
                "SELECT p.id,p.club_id,($published OR a.page_id IS NOT NULL OR s.source LIKE 'meta%' OR s.external_id LIKE 'meta:%') AS seen FROM khcms_pages p LEFT JOIN khcms_archives a ON a.club_id=p.club_id AND a.page_id=p.id LEFT JOIN native_event_sources s ON s.club_id=p.club_id AND s.page_id=p.id WHERE p.id>? AND (" .
                    sprintf($event, "p.draft_json", "p.draft_json") .
                    " OR $published) ORDER BY p.id LIMIT 200",
                [$after],
            )->fetchAll();
            foreach ($rows as $row) {
                Db::run(
                    $db,
                    "INSERT IGNORE INTO khcms_event_publications(club_id,page_id,published_seen,first_seen_at,updated_at) VALUES(?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",
                    [$row["club_id"], $row["id"], (int) ($row["seen"] ?? 0)],
                );
                $after = $row["id"];
            }
        } while (count($rows) === 200);
    }

    /** Called after the public original and catalogue have changed, inside Pages' own transaction. */
    public static function published(PDO $db, string $club, string $id): void
    {
        self::original($db, $club, $id);
        $page = (new Events($db, $club))->publicationState($id);
        if (!$page) {
            self::removed($db, $club, $id);
            return;
        }
        Db::run(
            $db,
            "INSERT IGNORE INTO khcms_event_publications(club_id,page_id,published_seen,first_seen_at,updated_at) VALUES(?,?,0,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",
            [$club, $id],
        );
        $seen = (bool) Db::run(
            $db,
            "SELECT published_seen FROM khcms_event_publications WHERE club_id=? AND page_id=? FOR UPDATE",
            [$club, $id],
        )->fetchColumn();
        $start = EventDetails::start($page);
        $eligible = self::eligiblePage($page);
        $managerClub = ManagerClub::id($db, $club);
        if ($managerClub !== null) {
            $source = "website:" . $id;
            if (!$eligible) {
                ManagerNotifications::eventRemoved($db, $managerClub, $source);
            } elseif (!$seen) {
                ManagerNotifications::eventPublished(
                    $db,
                    $managerClub,
                    $source,
                    $start->format("c"),
                );
            } else {
                ManagerNotifications::eventRescheduled(
                    $db,
                    $managerClub,
                    $source,
                    $start->format("c"),
                );
            }
        }
        // Past, cancelled, postponed and archive publications must not become new announcements later.
        if (!$seen && ($start !== null || self::archiveSource($page))) {
            Db::run(
                $db,
                "UPDATE khcms_event_publications SET published_seen=1,updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND page_id=?",
                [$club, $id],
            );
        }
    }

    /** Withdrawal never erases first-publication history or restores cancelled deliveries. */
    public static function removed(
        PDO $db,
        string $club,
        string $id,
        bool $archive = false,
    ): void {
        $original = self::original($db, $club, $id);
        if ($archive) {
            if (
                (bool) $original["is_event"] ||
                Db::run(
                    $db,
                    "SELECT 1 FROM khcms_event_publications WHERE club_id=? AND page_id=?",
                    [$club, $id],
                )->fetchColumn()
            ) {
                Db::run(
                    $db,
                    "INSERT INTO khcms_event_publications(club_id,page_id,published_seen,first_seen_at,updated_at) VALUES(?,?,1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE published_seen=1,updated_at=UTC_TIMESTAMP(6)",
                    [$club, $id],
                );
            }
        }
        $managerClub = ManagerClub::id($db, $club);
        if ($managerClub !== null) {
            ManagerNotifications::eventRemoved(
                $db,
                $managerClub,
                "website:" . $id,
            );
        }
    }

    /** Read-only delivery guard, bound to the original manager club and the current public native revision. */
    public static function eligible(
        PDO $db,
        string $managerClub,
        string $id,
    ): bool {
        if (
            !preg_match('/^[a-f0-9]{32}$/D', $id) ||
            !self::hasTable($db, "khcms_events")
        ) {
            return false;
        }
        $club = ManagerBridge::clubKey($managerClub);
        if (ManagerClub::id($db, $club) !== $managerClub) {
            return false;
        }
        $page = (new Events($db, $club))->publicationState($id);
        return $page !== null && self::eligiblePage($page);
    }

    private static function eligiblePage(array $page): bool
    {
        return !($page["cancellation_notice"] ?? false) &&
            !self::archiveSource($page) &&
            trim($page["title"]) !== "" &&
            in_array($page["event_status"], ["scheduled", "started"], true) &&
            (EventDetails::start($page)?->getTimestamp() ?? 0) > time();
    }

    private static function archiveSource(array $page): bool
    {
        return str_starts_with($page["event_source"] ?? "", "meta") ||
            str_starts_with($page["event_external_id"] ?? "", "meta:");
    }

    private static function original(PDO $db, string $club, string $id): array
    {
        if (!$db->inTransaction()) {
            throw new DomainException(
                "Veranstaltungshinweis benötigt die eigene Veröffentlichungstransaktion.",
            );
        }
        $row = Db::run(
            $db,
            "SELECT COALESCE(JSON_UNQUOTE(JSON_EXTRACT(draft_json,'$.template_definition.renderer')),JSON_UNQUOTE(JSON_EXTRACT(draft_json,'$.template')))='event' AS is_event FROM khcms_pages WHERE club_id=? AND id=? FOR UPDATE",
            [$club, $id],
        )->fetch();
        if (!$row) {
            throw new DomainException(
                "Ursprüngliche Veranstaltung nicht verfügbar.",
            );
        }
        return $row;
    }

    private static function hasTable(PDO $db, string $name): bool
    {
        return (bool) Db::run(
            $db,
            "SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?",
            [$name],
        )->fetchColumn();
    }
}
