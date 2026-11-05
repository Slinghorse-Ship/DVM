<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration\Jobs;

use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Native\Communication\NewsletterGateway;

/** Confirmed application opt-in prepares native DOI; application confirmation is no subscription grant. */
final class ApplicationNewsletter
{
    public static function run(
        PDO $db,
        string $club,
        NewsletterGateway $gateway,
        ?int $now = null,
    ): array {
        $now ??= time();
        $result = ["prepared" => 0, "completed" => 0, "held" => 0];
        $rows = Db::run(
            $db,
            "SELECT id,newsletter_status FROM application_confirmations WHERE club_id=? AND confirmed_at IS NOT NULL AND newsletter=1 AND (newsletter_status IN ('pending','queued') OR (newsletter_status REGEXP '^retry:[0-9]{10}:[12]$' AND CAST(SUBSTRING(newsletter_status,7,10) AS SIGNED)<=?)) ORDER BY created_at,id LIMIT 20",
            [$club, $now],
        )->fetchAll();
        foreach ($rows as $row) {
            try {
                $state = $gateway->applicationRequest($row["id"]);
                $status = match ($state["status"]) {
                    "sent", "confirmed" => "done",
                    "blocked" => "skipped",
                    "uncertain" => "uncertain",
                    "cancelled", "failed" => "failed_before_send",
                    default => "queued",
                };
                Db::run(
                    $db,
                    "UPDATE application_confirmations SET newsletter_status=? WHERE club_id=? AND id=? AND newsletter_status=?",
                    [$status, $club, $row["id"], $row["newsletter_status"]],
                );
                ++$result[
                    $status === "done"
                        ? "completed"
                        : ($state["created"]
                            ? "prepared"
                            : "held")
                ];
            } catch (\Throwable) {
                // No transport occurs in preparation. Only this bounded stage may retry.
                $attempt = preg_match(
                    '/^retry:[0-9]{10}:([12])$/D',
                    $row["newsletter_status"],
                    $match,
                )
                    ? (int) $match[1] + 1
                    : 1;
                $status =
                    $attempt < 3
                        ? "retry:" .
                            ($now + ($attempt === 1 ? 300 : 1800)) .
                            ":" .
                            $attempt
                        : "failed_before_send";
                Db::run(
                    $db,
                    "UPDATE application_confirmations SET newsletter_status=? WHERE club_id=? AND id=? AND newsletter_status=?",
                    [$status, $club, $row["id"], $row["newsletter_status"]],
                );
                ++$result["held"];
            }
        }
        return $result;
    }
}
