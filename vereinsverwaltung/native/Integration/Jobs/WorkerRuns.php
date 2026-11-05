<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration\Jobs;

use PDO;
use Kulturhus\Native\Integration\ManagerClub;
use Kulturhus\Native\Shared\{Access, Db};

/** Operational metadata only; recipient addresses, payloads, tokens and errors are never persisted. */
final class WorkerRuns
{
    public static function begin(PDO $db, string $club, array $modes): string
    {
        if (ManagerClub::id($db, $club) === null) {
            throw new \DomainException(
                "Ursprünglicher Verein für den Worker erforderlich.",
            );
        }
        $flags = [];
        foreach (
            ["send", "receive", "push", "meta", "stories", "stadtlandfunk", "publish", "social-check", "mail-replies", "events", "press", "catalog-billing", "fints"]
            as $mode
        ) {
            $flags[$mode] = ($modes[$mode] ?? false) === true;
        }
        $id = bin2hex(random_bytes(16));
        Db::run(
            $db,
            "INSERT INTO native_worker_runs(id,club_id,status,modes_json,started_at) VALUES(?,?,'running',?,UTC_TIMESTAMP())",
            [$id, $club, json_encode($flags, JSON_THROW_ON_ERROR)],
        );
        return $id;
    }
    public static function finish(
        PDO $db,
        string $club,
        string $id,
        ?array $result,
    ): void {
        $summary = [];
        if ($result !== null) {
            if (isset($result['bank_sync'])) {
                $summary['bank_sync'] = ['status' => $result['bank_sync']['status'] ?? 'needs_review', 'rows' => max(0, (int) ($result['bank_sync']['rows'] ?? 0)), 'counts' => []];
                foreach (['complete', 'needs_auth', 'needs_setup', 'failed', 'skipped'] as $state) if (isset($result['bank_sync']['counts'][$state])) $summary['bank_sync']['counts'][$state] = max(0, (int) $result['bank_sync']['counts'][$state]);
            }
            if(isset($result['catalog_billing']))foreach(['billed','existing','paused','skipped']as$key)$summary['catalog_billing'][$key]=min(20,max(0,(int)($result['catalog_billing'][$key]??0)));
            if(isset($result['story_automation']))foreach(['prepared','review']as$key)$summary['story_automation'][$key]=min(20,max(0,(int)($result['story_automation'][$key]??0)));
            if(isset($result['events'])){
                $summary['events']=['source'=>$result['events']['source']??null,'counts'=>[]];
                foreach($result['events']['results']??[]as$row){$status=$row['status']??'';if(in_array($status,['draft','published','unchanged','conflict','cancelled','cancelled-conflict'],true))$summary['events']['counts'][$status]=($summary['events']['counts'][$status]??0)+1;}
            }
            if(isset($result['mail_replies'])){
                $summary['mail_replies']=['status'=>in_array($result['mail_replies']['status']??null,['disabled','checked'],true)?$result['mail_replies']['status']:'failed'];
                foreach(['new','existing','ignored','failed']as$key)$summary['mail_replies'][$key]=min(25,max(0,(int)($result['mail_replies'][$key]??0)));
                if(isset($result['mail_replies']['delivery']))foreach(['sent','queued','blocked','uncertain','rejected']as$key)$summary['mail_replies']['delivery'][$key]=min(5000,max(0,(int)($result['mail_replies']['delivery'][$key]??0)));
            }
            $summary["social_checks"] = [];
            foreach (["meta", "stadtlandfunk"] as $channel) {
                $state = $result["social_checks"][$channel] ?? null;
                if (
                    in_array(
                        $state,
                        ["checked", "needs_review", "cooldown"],
                        true,
                    )
                ) {
                    $summary["social_checks"][$channel] = $state;
                }
            }
            foreach (["drafts", "reminders", "suggestions"] as $name) {
                $summary[$name] = max(0, (int) ($result[$name] ?? 0));
            }
            $summary["deliveries"] = count($result["deliveries"] ?? []);
            foreach(['ticket_mails','account_mails','factor_mails']as$kind)if(isset($result[$kind])&&is_array($result[$kind]))foreach(['sent','blocked','uncertain','held']as$state)$summary[$kind][$state]=min(20,max(0,(int)($result[$kind][$state]??0)));
            $summary["publications"] = [];
            foreach (
                ["published", "conflicted", "blocked", "failed"]
                as $status
            ) {
                $summary["publications"][$status] = max(
                    0,
                    (int) ($result["publications"][$status] ?? 0),
                );
            }
            foreach (
                [
                    "application_confirmations",
                    "application_notifications",
                    "calendar_invitations",
                    "club_mailings",
                    "document_reminders",
                    "event_vouchers",
                    "outgoing_invoices",
                ]
                as $name
            ) {
                $summary[$name] = max(
                    0,
                    (int) ($result["original_jobs"][$name] ?? 0),
                );
            }
        }
        $status =
            $result === null
                ? "failed"
                : (self::needsReview($result)
                    ? "needs_review"
                    : "completed");
        Db::run(
            $db,
            "UPDATE native_worker_runs SET status=?,summary_json=?,finished_at=UTC_TIMESTAMP() WHERE club_id=? AND id=? AND status='running'",
            [$status, json_encode($summary, JSON_THROW_ON_ERROR), $club, $id],
        );
        $old = Db::run(
            $db,
            "SELECT id FROM native_worker_runs WHERE club_id=? AND finished_at IS NOT NULL ORDER BY started_at DESC,id DESC LIMIT 1000,100",
            [$club],
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($old as $oldId) {
            Db::run(
                $db,
                "DELETE FROM native_worker_runs WHERE club_id=? AND id=? AND finished_at IS NOT NULL",
                [$club, $oldId],
            );
        }
    }
    private static function needsReview(array $result): bool
    {
        if(($result['story_automation']['review']??0)>0)return true;
        if(($result['catalog_billing']['paused']??0)>0)return true;
        foreach($result['events']['results']??[]as$row)if(str_contains($row['status']??'','conflict'))return true;
        if (
            array_intersect(
                ["needs_review", "cooldown"],
                $result["social_checks"] ?? [],
            )
        ) {
            return true;
        }
        foreach ($result as $key => $value) {
            if (
                $key === "status" &&
                in_array(
                    $value,
                    [
                        "failed",
                        "error",
                        "uncertain",
                        "needs_review",
                        "conflicted",
                        "blocked",
                        "rejected",
                    ],
                    true,
                )
            ) {
                return true;
            }
            if (
                in_array(
                    $key,
                    ["blocked", "uncertain", "failed", "conflicted", "rejected"],
                    true,
                ) &&
                is_numeric($value) &&
                (int) $value > 0
            ) {
                return true;
            }
            if (is_array($value) && self::needsReview($value)) {
                return true;
            }
        }
        return false;
    }
    public static function listing(PDO $db, Access $access): array
    {
        $access->require("website", "manage");
        // The standalone editorial CMS has no manager jobs or worker schema.
        if (ManagerClub::id($db, $access->club()) === null) return [];
        return Db::run(
            $db,
            "SELECT status,modes_json,summary_json,started_at,finished_at FROM native_worker_runs WHERE club_id=? ORDER BY started_at DESC,id DESC LIMIT 30",
            [$access->club()],
        )->fetchAll();
    }
}
