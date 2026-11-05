<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication\Confirmations;

use DateTimeImmutable;
use DomainException;
use PDO;
use Kulturhus\Native\Communication\Content;
use Kulturhus\Native\Delivery\Outbox;
use Kulturhus\Native\Integration\ManagerAccess;
use Kulturhus\Native\Shared\{Access, Db, SecretBox};

/** Explicit single/bulk requests; neither this service nor the web action sends mail. */
final class Renewals
{
    public function __construct(
        private PDO $db,
        private Access $access,
        private SecretBox $box,
        private ?string $mediaRoot = null,
    ) {}

    public function preview(
        string $subscriber = "",
        ?DateTimeImmutable $now = null,
    ): array {
        $actor = $this->access->require("newsletter", "manage");
        if ($subscriber !== "") {
            Content::id($subscriber);
        }
        $selection = Selection::load(
            $this->db,
            $this->box,
            $actor["club_id"],
            $subscriber,
            $now ?? new DateTimeImmutable(),
        );
        return ["counts" => $selection["counts"], "hash" => $selection["hash"]];
    }

    public function queue(
        string $subscriber,
        string $previewHash,
        string $request,
        bool $confirmed,
        ?DateTimeImmutable $now = null,
    ): array {
        if (!$confirmed) {
            throw new DomainException(
                "Bitte die erneute Bestätigungsanfrage ausdrücklich bestätigen.",
            );
        }
        Content::id($request);
        if ($subscriber !== "") {
            Content::id($subscriber);
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', $previewHash)) {
            throw new DomainException("Vorschau neu laden.");
        }
        $now ??= new DateTimeImmutable();
        return Db::atomic($this->db, function () use (
            $subscriber,
            $previewHash,
            $request,
            $now,
        ): array {
            $actor = $this->access->require("newsletter", "manage");
            $club = $actor["club_id"];
            $authority =
                $this->access instanceof ManagerAccess ? "manager" : "native";
            $insert = Db::run(
                $this->db,
                "INSERT IGNORE INTO native_newsletter_confirmation_batches(club_id,id,actor_id,authority,subscriber_id,preview_hash,job_ids,created_at) VALUES(?,?,?,?,?,?,'[]',UTC_TIMESTAMP())",
                [
                    $club,
                    $request,
                    $actor["id"],
                    $authority,
                    $subscriber,
                    $previewHash,
                ],
            );
            $batch = Db::run(
                $this->db,
                "SELECT * FROM native_newsletter_confirmation_batches WHERE club_id=? AND id=? FOR UPDATE",
                [$club, $request],
            )->fetch();
            if (
                $batch["actor_id"] !== $actor["id"] ||
                $batch["authority"] !== $authority ||
                $batch["subscriber_id"] !== $subscriber ||
                !hash_equals($batch["preview_hash"], $previewHash)
            ) {
                throw new DomainException(
                    "Auftrag gehört zu einer anderen Bestätigung.",
                );
            }
            if ($insert->rowCount() === 0) {
                return json_decode(
                    $batch["job_ids"],
                    true,
                    16,
                    JSON_THROW_ON_ERROR,
                );
            }
            $selection = Selection::load(
                $this->db,
                $this->box,
                $club,
                $subscriber,
                $now,
                true,
            );
            if (!hash_equals($selection["hash"], $previewHash)) {
                throw new DomainException(
                    "Anmeldungen oder Aufträge inzwischen geändert. Bitte Auswahl neu laden.",
                );
            }
            if (!$selection["eligible"]) {
                throw new DomainException(
                    "Keine erreichbare unbestätigte Anmeldung verfügbar.",
                );
            }
            $brand = Message::brand($this->db, $club, $this->mediaRoot);
            $ids = [];
            foreach ($selection["eligible"] as $row) {
                $job = (new Outbox($this->db, $this->box))->enqueue(
                    $club,
                    "renewal",
                    $request . ":" . $row["id"],
                    [
                        "subscriber" => $row["id"],
                        "to" => $row["email"],
                        "renewal_snapshot" => $row["snapshot"],
                        "renewal_brand" => $brand,
                    ],
                    gmdate("Y-m-d H:i:s"),
                    $this->access,
                    "manage",
                );
                Db::run(
                    $this->db,
                    "INSERT INTO native_newsletter_renewals(club_id,subscriber_id,job_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE job_id=VALUES(job_id)",
                    [$club, $row["id"], $job],
                );
                Db::run(
                    $this->db,
                    "INSERT INTO native_newsletter_renewal_events(job_id,club_id,subscriber_id,queued_at) VALUES(?,?,?,UTC_TIMESTAMP())",
                    [$job, $club, $row["id"]],
                );
                $ids[] = $job;
            }
            Db::run(
                $this->db,
                "UPDATE native_newsletter_confirmation_batches SET job_ids=? WHERE club_id=? AND id=?",
                [json_encode($ids, JSON_THROW_ON_ERROR), $club, $request],
            );
            return $ids;
        });
    }

    public function history(string $subscriber): array
    {
        (new \Kulturhus\Native\Communication\Subscribers(
            $this->db,
            $this->access,
        ))->get($subscriber);
        // Only delivery state and dates. No address, clear token or decrypted message.
        return Db::run(
            $this->db,
            "SELECT j.id,j.status,j.reason,e.queued_at,j.started_at,j.completed_at FROM native_newsletter_renewal_events e JOIN native_delivery_jobs j ON j.id=e.job_id AND j.club_id=e.club_id WHERE e.club_id=? AND e.subscriber_id=? ORDER BY e.queued_at,j.id",
            [$this->access->club(), $subscriber],
        )->fetchAll();
    }
}
