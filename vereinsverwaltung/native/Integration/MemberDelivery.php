<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration;

use PDO;
use DomainException;
use Kulturhus\Native\Auth\Service;
use Kulturhus\Native\Shared\{Db, SecretBox};
use Kulturhus\Verein\{MailPolicy, MembershipWelcome};
require_once dirname(__DIR__, 2) . "/src/autoload.php";

/** Private worker adapter for original admission, access-mail and contribution queues. */
final class MemberDelivery
{
    public static function run(
        PDO $db,
        Service $auth,
        SecretBox $box,
        string $club,
        string $origin,
        ?callable $membershipSend = null,
        ?callable $invoiceSend = null,
    ): array {
        $accounts = new MemberAccounts($db, $auth, $box);
        $enabled = $membershipSend !== null && MailPolicy::allowed($origin);
        $manual = $accounts->run(
            $club,
            $origin,
            $enabled ? $membershipSend : null,
        );
        if (!$enabled) {
            return [
                "accounts" => $manual,
                "welcome" => 0,
                "transport" => "disabled",
            ];
        }
        $adapt = static fn(callable $send): \Closure => static fn(
            $email,
            $subject,
            $text,
            $html,
            $attachments,
        ) => $send([
            "to" => $email,
            "subject" => $subject,
            "text" => $text,
            "html" => $html,
            "attachments" => $attachments,
        ]);
        $sent = MembershipWelcome::dispatch(
            $db,
            $club,
            $origin,
            $adapt($membershipSend),
            static function (string $application, string $contact) use (
                $db,
                $accounts,
                $club,
                $origin,
            ): array {
                if (
                    !Db::run(
                        $db,
                        "SELECT 1 FROM manual_member_accounts m JOIN membership_applications a ON a.club_id=m.club_id AND a.id=m.application_id WHERE m.club_id=? AND m.contact_id=? AND m.application_id=? AND a.status='accepted'",
                        [$club, $contact, $application],
                    )->fetchColumn()
                ) {
                    throw new DomainException(
                        "Originaler Aufnahmeauftrag fehlt.",
                    );
                }
                $account = $accounts->prepare($club, $contact);
                if (isset($account["setup_token"])) {
                    $account["setup_url"] =
                        $origin . "/invite?token=" . $account["setup_token"];
                } elseif ($account["created"]) {
                    throw new DomainException(
                        "Einladung vor Versand ausdrücklich prüfen.",
                    );
                }
                unset($account["setup_token"]);
                return $account;
            },
            static function (string $application) use (
                $db,
                $accounts,
                $club,
            ): void {
                $contact = Db::run(
                    $db,
                    "SELECT contact_id FROM membership_welcome WHERE club_id=? AND application_id=? AND welcome_status='sent'",
                    [$club, $application],
                )->fetchColumn();
                if (!$contact) {
                    throw new DomainException(
                        "Bestätigter Willkommensversand fehlt.",
                    );
                }
                $accounts->acknowledge($club, $contact);
            },
            $invoiceSend
                ? $adapt($invoiceSend)
                : static function (): never {
                    throw new \Kulturhus\Native\Delivery\DeliveryNotStarted('mail_configuration_missing');
                },
        );
        return [
            "accounts" => $manual,
            "welcome" => $sent,
            "transport" => "enabled",
        ];
    }
}
