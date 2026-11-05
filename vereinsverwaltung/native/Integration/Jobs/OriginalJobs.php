<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration\Jobs;

use PDO;
use Kulturhus\Native\Integration\{ManagerBridge, ManagerClub};
use DomainException;
use Kulturhus\Native\Shared\{Db, Identity, SecretBox};
use Kulturhus\Native\Communication\NewsletterGateway;
use Kulturhus\Verein\{
    MailPolicy,
    ApplicationMail,
    ApplicationConfirmation,
    ApplicationNotifications,
    CalendarInvitations,
    ClubMailings,
    WorkflowReminderMail,
    EventVoucherMail,
    OutgoingInvoices,
    ContributionReminders,
    InvoiceMailbox,
    WebsiteCalendar,
    Permissions,
    WebPushDelivery,
};
require_once dirname(__DIR__, 3) . "/src/autoload.php";

/** Private adapters retain existing queues, original bytes and current domain rights. */
final class OriginalJobs
{
    public static function context(
        PDO $db,
        string $club,
        string $origin,
        ?string $workerActor = null,
        ?string $automationStart = null,
    ): array {
        $nativeClub = ManagerBridge::clubKey($club);
        if (
            ManagerClub::id($db, $nativeClub) !== $club ||
            !preg_match("~\Ahttps://[a-zA-Z0-9.-]+\z~D", $origin) ||
            filter_var(parse_url($origin, PHP_URL_HOST), FILTER_VALIDATE_IP)
        ) {
            throw new DomainException(
                "Ursprünglicher Verein und feste HTTPS-Domain für private Jobs erforderlich.",
            );
        }
        $identity = Identity::forClub($db, $nativeClub);
        if ($identity["origin"] !== $origin) {
            throw new DomainException(
                "Private Jobs gehören nicht zur aktuellen Vereinsdomain.",
            );
        }
        if (($workerActor === null) !== ($automationStart === null)) {
            throw new DomainException(
                "Beitragsautomatik benötigt ausdrücklich Zugang und Startdatum.",
            );
        }
        if ($workerActor !== null) {
            $date = \DateTimeImmutable::createFromFormat(
                "!Y-m-d",
                $automationStart,
            );
            if (!$date || $date->format("Y-m-d") !== $automationStart) {
                throw new DomainException(
                    "Beginn der Beitragsautomatik prüfen.",
                );
            }
            $actor = Permissions::actor($db, $workerActor);
            if (
                $actor["club_id"] !== $club ||
                $actor["finance_access"] !== "write"
            ) {
                throw new DomainException(
                    "Aktueller Finanzzugang des eigenen Vereins für Beitragsautomatik erforderlich.",
                );
            }
        }
        return ["club" => $nativeClub, "identity" => $identity];
    }

    public static function run(
        PDO $db,
        string $club,
        string $origin,
        #[\SensitiveParameter] string $managerKey,
        SecretBox $box,
        ?callable $send = null,
        bool $receive = false,
        ?callable $readInbox = null,
        bool $push = false,
        ?callable $pushSend = null,
        ?string $workerActor = null,
        ?string $automationStart = null,
        ?string $today = null,
        ?callable $reminderSend = null,
    ): array {
        $context = self::context(
            $db,
            $club,
            $origin,
            $workerActor,
            $automationStart,
        );
        $today ??= (new \DateTimeImmutable(
            "now",
            new \DateTimeZone("Europe/Berlin"),
        ))->format("Y-m-d");
        $date = \DateTimeImmutable::createFromFormat("!Y-m-d", $today);
        if (!$date || $date->format("Y-m-d") !== $today) {
            throw new DomainException("Datum des privaten Jobs prüfen.");
        }
        $calendar = WebsiteCalendar::fromNative(
            $db,
            $club,
            $context["club"],
            $origin,
        );
        $managerUrl = $origin . "/vereinsverwaltung";
        $enabled = $send !== null && MailPolicy::allowed($origin);
        $result = [
            "application_confirmations" => 0,
            "application_notifications" => 0,
            "calendar_invitations" => 0,
            "club_mailings" => 0,
            "document_reminders" => 0,
            "event_vouchers" => 0,
            "outgoing_invoices" => 0,
            "invoice_reminders" => ["status"=>"disabled", "sent"=>0, "blocked"=>0, "uncertain"=>0, "unavailable"=>0],
            "application_newsletters" => [
                "prepared" => 0,
                "completed" => 0,
                "held" => 0,
            ],
            "contributions" => ["status" => "not_configured"],
            "invoice_inbox" => ["status" => "disabled"],
            "push" => ["status" => "disabled"],
            "transport" => $enabled ? "enabled" : "disabled",
        ];
        if ($enabled) {
            $adapt = static fn(string $purpose): \Closure => static function (
                $email,
                $subject,
                $text,
                $html,
                $attachments,
                ?string $rowClub = null,
            ) use ($send, $purpose, $club): void {
                if ($rowClub !== null && $rowClub !== $club) {
                    throw new DomainException(
                        "Mailauftrag gehört nicht zum konfigurierten Verein.",
                    );
                }
                $send($purpose, [
                    "to" => $email,
                    "subject" => $subject,
                    "text" => $text,
                    "html" => $html,
                    "attachments" => $attachments,
                ]);
            };
            $plain = static fn(string $purpose): \Closure => static function($email,$subject,$text,?array $review=null)use($send,$purpose,$origin,$context):void{
                if($review!==null){
                    try{$message=\Kulturhus\Verein\People\Members\ConfirmationMail::message($review,$text,$subject,$origin);}catch(\Throwable){throw new \Kulturhus\Native\Delivery\DeliveryNotStarted('mail_message_invalid');}
                }else $message=['subject'=>$subject,'text'=>$text,'html'=>ApplicationMail::html($text,$origin,$subject,'','Mitgliedsantrag bestätigen','',$context['identity']['name']),'attachments'=>[]];
                $send($purpose,['to'=>$email]+$message);
            };
            $result[
                "application_confirmations"
            ] = ApplicationConfirmation::dispatch(
                $db,
                $club,
                $origin . "/mitglied-werden",
                $plain("membership"),
                $context["identity"]["name"],
            );
            $result[
                "application_notifications"
            ] = ApplicationNotifications::dispatch(
                $db,
                $club,
                $managerUrl,
                $plain("membership"),
                $context["identity"]["name"],
            );
            $result["calendar_invitations"] = CalendarInvitations::dispatch(
                $db,
                $club,
                $managerUrl,
                $plain("invitations"),
            );
            $result["club_mailings"] = ClubMailings::dispatch(
                $db,
                $managerUrl,
                $adapt("invitations"),
                $club,
            );
            $result["document_reminders"] = WorkflowReminderMail::dispatch(
                $db,
                $managerUrl,
                $adapt("invitations"),
                $club,
            );
            $result["event_vouchers"] = EventVoucherMail::dispatch(
                $db,
                $managerUrl,
                $adapt("invitations"),
                $calendar,
                $club,
            );
            $result["outgoing_invoices"] = OutgoingInvoices::dispatch(
                $db,
                $managerUrl,
                $adapt("invoices"),
                $club,
            );
            $result["invoice_reminders"] = \Kulturhus\Verein\Finance\Invoices\Reminder\ReminderDelivery::dispatchPending(
                $db, $club, $managerKey, $origin, $reminderSend, $workerActor,
            );
            $result["application_newsletters"] = ApplicationNewsletter::run(
                $db,
                $club,
                new NewsletterGateway($db, $context["club"], $box),
            );
        }
        if ($receive && MailPolicy::allowed($origin)) {
            $configured = Db::run(
                $db,
                "SELECT configured_by FROM invoice_mailboxes WHERE club_id=?",
                [$club],
            )->fetchColumn();
            if ($configured) {
                $actor = Permissions::actor($db, $configured);
                if (
                    $actor["club_id"] !== $club ||
                    $actor["finance_access"] !== "write"
                ) {
                    throw new DomainException(
                        "Aktueller eigener Finanzzugang für den Postfachabruf erforderlich.",
                    );
                }
                $result["invoice_inbox"] = (new InvoiceMailbox(
                    $db,
                    $configured,
                    $managerKey,
                ))->fetch($readInbox);
            } else {
                $result["invoice_inbox"] = ["status" => "not_configured"];
            }
        }
        if ($push) {
            $result["push"] = WebPushDelivery::run($db, 20, $club, $pushSend);
        }
        if ($workerActor !== null) {
            if ($today < $automationStart) {
                $result["contributions"] = ["status" => "not_started"];
            } else {
                $worker = new ContributionReminders($db, $workerActor);
                $result["contributions"] = self::contributions(
                    $db,
                    $club,
                    $today,
                    $worker,
                    $enabled,
                );
                $result["contributions"]["sent"] = $enabled
                    ? $worker->dispatch(
                        $managerUrl,
                        static fn(
                            $email,
                            $subject,
                            $text,
                            $html,
                            $files,
                            $purpose,
                        ) => $send($purpose, [
                            "to" => $email,
                            "subject" => $subject,
                            "text" => $text,
                            "html" => $html,
                            "attachments" => $files,
                        ]),
                        $today,
                    )
                    : 0;
            }
        }
        return $result;
    }

    private static function contributions(
        PDO $db,
        string $club,
        string $today,
        ContributionReminders $worker,
        bool $execute,
    ): array {
        $lock =
            "dvm-daily:" .
            substr(hash("sha256", $club . ":contributions:" . $today), 0, 48);
        if (
            (int) Db::run($db, "SELECT GET_LOCK(?,0)", [
                $lock,
            ])->fetchColumn() !== 1
        ) {
            return ["status" => "running"];
        }
        try {
            if (
                $execute &&
                Db::run(
                    $db,
                    "SELECT 1 FROM native_daily_jobs WHERE club_id=? AND job='contributions' AND day=? AND status='completed'",
                    [$club, $today],
                )->fetchColumn()
            ) {
                return [
                    "status" => "already_completed",
                    "created" => 0,
                    "queued" => 0,
                ];
            }
            $run = $worker->run($today, $execute);
            $summary = [
                "status" => $execute
                    ? (count($run["errors"])
                        ? "failed"
                        : "completed")
                    : "preview",
                "created" => $run["created"],
                "queued" => $run["queued"],
                "blocked" => count($run["errors"]),
                "annual_candidates" => count($run["annual_candidates"]),
                "reminder_candidates" => count($run["reminders"]),
            ];
            if ($execute) {
                Db::run(
                    $db,
                    "INSERT INTO native_daily_jobs(club_id,job,day,status,completed_at) VALUES(?,'contributions',?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE status=VALUES(status),completed_at=VALUES(completed_at)",
                    [$club, $today, $summary["status"]],
                );
            }
            return $summary;
        } finally {
            Db::run($db, "SELECT RELEASE_LOCK(?)", [$lock]);
        }
    }
}
