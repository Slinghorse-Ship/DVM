<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication\Confirmations;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Db, SecretBox};

/** A renewed link belongs to an unchanged, evidenced, still pending request. */
final class Selection
{
    public static function fingerprint(
        SecretBox $box,
        array $subscriber,
    ): string {
        $purposes = json_decode(
            $subscriber["purposes"],
            true,
            16,
            JSON_THROW_ON_ERROR,
        );
        sort($purposes);
        return $box->fingerprint(
            "confirmation-request:" .
                json_encode(
                    [
                        $subscriber["id"],
                        $subscriber["email"],
                        $subscriber["name"],
                        $subscriber["status"],
                        $purposes,
                        $subscriber["consent_evidence"],
                        (int) $subscriber["version"],
                        $subscriber["created_at"],
                        $subscriber["confirmed_at"],
                        $subscriber["unsubscribed_at"],
                    ],
                    JSON_THROW_ON_ERROR,
                ),
        );
    }

    public static function evidenced(array $row): bool
    {
        $purposes = json_decode(
            $row["purposes"],
            true,
            16,
            JSON_THROW_ON_ERROR,
        );
        return $row["status"] === "pending" &&
            $row["confirmed_at"] === null &&
            $row["unsubscribed_at"] === null &&
            trim($row["consent_evidence"]) !== "" &&
            $row["created_at"] !== "" &&
            filter_var($row["email"], FILTER_VALIDATE_EMAIL) &&
            !str_ends_with($row["email"], "@removed.invalid") &&
            is_array($purposes) &&
            array_is_list($purposes) &&
            $purposes &&
            !array_diff($purposes, ["events", "news"]);
    }

    public static function blocked(
        PDO $db,
        SecretBox $box,
        string $club,
        string $email,
    ): bool {
        return (bool) Db::run(
            $db,
            "SELECT 1 FROM native_newsletter_blocks WHERE club_id=? AND email_hash=?",
            [$club, $box->fingerprint("email:" . $email)],
        )->fetchColumn();
    }

    public static function load(
        PDO $db,
        SecretBox $box,
        string $club,
        string $id,
        DateTimeImmutable $now,
        bool $lock = false,
    ): array {
        $where = $id === "" ? "s.status='pending'" : "s.id=?";
        $parameters = $id === "" ? [$club] : [$club, $id];
        if ($lock) {
            // All subscription mutations, including redemption, take this lock first.
            Db::run(
                $db,
                "SELECT s.id FROM khn_newsletter_subscribers s WHERE s.club_id=? AND $where ORDER BY s.id LIMIT 10001 FOR UPDATE",
                $parameters,
            )->fetchAll();
        }
        $rows = Db::run(
            $db,
            "SELECT s.*, r.job_id, r.attempted_at, j.status AS renewal_status,
            (SELECT MAX(t.token_hash) FROM khn_newsletter_tokens t WHERE t.club_id=s.club_id AND t.subscriber_id=s.id AND t.kind='confirm' AND t.consumed_at IS NULL) AS confirmation_token,
            EXISTS(SELECT 1 FROM khn_newsletter_tokens t JOIN native_delivery_jobs o ON o.club_id=t.club_id
                AND o.dedupe IN (SHA2(CONCAT('confirm:',t.token_hash),256),SHA2(CONCAT('reminder:',t.token_hash),256))
                WHERE t.club_id=s.club_id AND t.subscriber_id=s.id AND t.kind='confirm'
                AND o.status IN ('pending','processing','uncertain')) AS original_active
            FROM khn_newsletter_subscribers s
            LEFT JOIN native_newsletter_renewals r ON r.club_id=s.club_id AND r.subscriber_id=s.id
            LEFT JOIN native_delivery_jobs j ON j.club_id=r.club_id AND j.id=r.job_id
            WHERE s.club_id=? AND $where ORDER BY s.id LIMIT 10001",
            $parameters,
        )->fetchAll();
        if (count($rows) > 10000) {
            throw new DomainException(
                "Mehr als 10.000 Anmeldungen: Bitte einzelne Kontakte bearbeiten.",
            );
        }
        if ($id !== "" && !$rows) {
            throw new DomainException("Anmeldung nicht verfügbar.");
        }
        $counts = array_fill_keys(
            ["eligible", "queued", "uncertain", "cooldown", "invalid"],
            0,
        );
        $eligible = [];
        $signatures = [];
        foreach ($rows as $row) {
            $state = "eligible";
            if (
                !self::evidenced($row) ||
                !$row["confirmation_token"] ||
                self::blocked($db, $box, $club, $row["email"])
            ) {
                $state = "invalid";
            } elseif ($row["renewal_status"] === "uncertain") {
                $state = "uncertain";
            } elseif (
                in_array(
                    $row["renewal_status"],
                    ["pending", "processing"],
                    true,
                ) ||
                $row["original_active"]
            ) {
                $state = "queued";
            } elseif (
                $row["attempted_at"] !== null &&
                $now <
                    (new DateTimeImmutable(
                        $row["attempted_at"],
                        new DateTimeZone("UTC"),
                    ))->modify("+48 hours")
            ) {
                $state = "cooldown";
            }
            ++$counts[$state];
            $snapshot = self::fingerprint($box, $row);
            $signatures[] = [
                $row["id"],
                $snapshot,
                $state,
                $row["confirmation_token"],
                $row["job_id"],
                $row["attempted_at"],
                $row["renewal_status"],
            ];
            if ($state === "eligible") {
                $eligible[] = $row + ["snapshot" => $snapshot];
            }
        }
        return [
            "counts" => $counts,
            "eligible" => $eligible,
            "hash" => $box->fingerprint(
                "confirmation-preview:" .
                    $club .
                    ":" .
                    $id .
                    ":" .
                    json_encode($signatures, JSON_THROW_ON_ERROR),
            ),
        ];
    }
}
