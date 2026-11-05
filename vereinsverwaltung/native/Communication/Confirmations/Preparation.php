<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication\Confirmations;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Kulturhus\Native\Communication\NewsletterGateway;
use Kulturhus\Native\Shared\{Db, SecretBox};

/** Commit a usable renewed link before SMTP; an uncertain transport must retain it. */
final class Preparation
{
    public static function guard(
        PDO $db,
        SecretBox $box,
        array $job,
        array $payload,
    ): void {
        $row = Db::run(
            $db,
            "SELECT * FROM khn_newsletter_subscribers WHERE club_id=? AND id=? FOR SHARE",
            [$job["club_id"], $payload["subscriber"] ?? ""],
        )->fetch();
        $current = Db::run(
            $db,
            "SELECT job_id FROM native_newsletter_renewals WHERE club_id=? AND subscriber_id=? FOR SHARE",
            [$job["club_id"], $payload["subscriber"] ?? ""],
        )->fetchColumn();
        if (
            !$row ||
            !Selection::evidenced($row) ||
            $current !== $job["id"] ||
            !hash_equals(
                Selection::fingerprint($box, $row),
                $payload["renewal_snapshot"] ?? "",
            ) ||
            Selection::blocked($db, $box, $job["club_id"], $row["email"])
        ) {
            throw new DomainException(
                "Anmeldung oder Erneuerungsauftrag nicht mehr aktuell.",
            );
        }
    }

    /** Caller has already rechecked live authority in this transaction. */
    public static function prepare(
        PDO $db,
        SecretBox $box,
        array $job,
        array $payload,
        DateTimeImmutable $now,
    ): array {
        // Match the lock order used by signup, public confirmation and withdrawal.
        Db::run(
            $db,
            "SELECT id FROM khn_newsletter_subscribers WHERE club_id=? AND id=? FOR UPDATE",
            [$job["club_id"], $payload["subscriber"]],
        )->fetch();
        self::guard($db, $box, $job, $payload);
        $pointer = Db::run(
            $db,
            "SELECT attempted_at FROM native_newsletter_renewals WHERE club_id=? AND subscriber_id=? FOR UPDATE",
            [$job["club_id"], $payload["subscriber"]],
        )->fetch();
        if (
            $pointer["attempted_at"] !== null &&
            $now <
                (new DateTimeImmutable(
                    $pointer["attempted_at"],
                    new DateTimeZone("UTC"),
                ))->modify("+48 hours")
        ) {
            throw new DomainException(
                "Die nächste manuelle Anfrage ist erst nach 48 Stunden möglich.",
            );
        }
        if (
            !Db::run(
                $db,
                "SELECT 1 FROM khn_newsletter_tokens WHERE club_id=? AND subscriber_id=? AND kind='confirm' AND consumed_at IS NULL FOR UPDATE",
                [$job["club_id"], $payload["subscriber"]],
            )->fetchColumn()
        ) {
            throw new DomainException(
                "Ursprünglicher Einwilligungsantrag fehlt.",
            );
        }
        $token = bin2hex(random_bytes(32));
        $hash = hash("sha256", $token);
        $utc = $now->setTimezone(new DateTimeZone("UTC"));
        Db::run(
            $db,
            "DELETE FROM khn_newsletter_tokens WHERE club_id=? AND subscriber_id=? AND kind='confirm'",
            [$job["club_id"], $payload["subscriber"]],
        );
        Db::run(
            $db,
            "INSERT INTO khn_newsletter_tokens(token_hash,club_id,subscriber_id,kind,expires_at,created_at) VALUES(?,?,?,'confirm',?,?)",
            [
                $hash,
                $job["club_id"],
                $payload["subscriber"],
                $utc->modify("+48 hours")->format("Y-m-d H:i:s"),
                $utc->format("Y-m-d H:i:s"),
            ],
        );
        $unsubscribe = (new NewsletterGateway(
            $db,
            $job["club_id"],
            $box,
        ))->unsubscribeToken($payload["subscriber"]);
        $brand = $payload["renewal_brand"];
        $message = Message::compose(
            $brand,
            $brand["origin"] . "/newsletter/bestaetigen/?token=" . $token,
            $brand["origin"] . "/newsletter/abmelden/?token=" . $unsubscribe,
            true,
        );
        $ready = $payload + $message + ["confirmation_hash" => $hash];
        unset($ready["renewal_brand"]);
        Db::run(
            $db,
            "UPDATE native_delivery_jobs SET ciphertext=? WHERE id=? AND status='processing'",
            [
                $box->seal("job:" . $job["club_id"] . ":" . $job["id"], $ready),
                $job["id"],
            ],
        );
        Db::run(
            $db,
            "INSERT INTO khn_newsletter_consent_events(id,club_id,subscriber_id,action,evidence,actor_id,created_at) VALUES(?,?,?,'confirmation_renewed','Neuer Bestätigungslink: 48 Stunden ab Verarbeitung',?,?)",
            [
                bin2hex(random_bytes(16)),
                $job["club_id"],
                $payload["subscriber"],
                $job["actor_id"],
                $utc->format("Y-m-d H:i:s"),
            ],
        );
        // Durable before the transport: a crash cannot cause a second reminder.
        Db::run(
            $db,
            "UPDATE native_newsletter_renewals SET attempted_at=? WHERE club_id=? AND subscriber_id=? AND job_id=?",
            [
                $utc->format("Y-m-d H:i:s"),
                $job["club_id"],
                $payload["subscriber"],
                $job["id"],
            ],
        );
        return $ready;
    }
}
