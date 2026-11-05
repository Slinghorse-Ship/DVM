<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication\Digests;

use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Native\Communication\Content;

/** Only transport-confirmed campaign messages count as previously announced events. */
final class Announcements
{
    public static function remaining(
        PDO $db,
        string $club,
        array $events,
        string $quarterKey,
    ): array {
        $sent = Db::run(
            $db,
            "SELECT event_id,starts_at FROM native_digest_announcements WHERE club_id=? AND period_key=?",
            [$club, $quarterKey],
        )->fetchAll();
        $known = [];
        foreach ($sent as $row) {
            $known[$row["event_id"] . ":" . $row["starts_at"]] = true;
        }
        return array_values(
            array_filter(
                $events,
                static fn(array $event): bool => !isset(
                    $known[$event["id"] . ":" . $event["starts_at"]],
                ),
            ),
        );
    }

    public static function selected(array $edition, array $blocks): array
    {
        $hashes = [];
        foreach ($blocks as $block) {
            $hashes[self::blockHash($block)] = true;
        }
        return array_values(
            array_filter(
                $edition["events"],
                static fn(array $event): bool => isset(
                    $hashes[$event["block_hash"]],
                ),
            ),
        );
    }

    public static function blockHash(array $block): string
    {
        // MySQL's JSON storage may reorder keys. Compare normalized semantic fields.
        return hash(
            "sha256",
            json_encode(Content::blocks([$block])[0], JSON_THROW_ON_ERROR),
        );
    }

    public static function record(
        PDO $db,
        string $club,
        array $digest,
        string $job,
    ): void {
        $edition = Plans::edition($db, $club, $digest["item_id"]);
        if (!$edition || $edition["period_key"] !== $digest["period_key"]) {
            throw new \DomainException("Originale Newsletterperiode fehlt.");
        }
        $allowed = array_column($edition["events"], null, "id");
        foreach ($digest["events"] as $event) {
            if (
                !isset($allowed[$event["id"]]) ||
                $allowed[$event["id"]] !== $event
            ) {
                throw new \DomainException(
                    "Originaler Veranstaltungshinweis geändert.",
                );
            }
            Db::run(
                $db,
                "INSERT IGNORE INTO native_digest_announcements(club_id,period_key,event_id,event_revision,starts_at,slug,job_id,announced_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP())",
                [
                    $club,
                    $digest["period_key"],
                    $event["id"],
                    $event["revision"],
                    $event["starts_at"],
                    $event["slug"],
                    $job,
                ],
            );
        }
    }
}
