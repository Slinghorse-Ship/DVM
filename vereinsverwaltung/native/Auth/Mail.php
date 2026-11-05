<?php declare(strict_types=1);
namespace Kulturhus\Native\Auth;

use PDO;
use DomainException;
use Kulturhus\Native\Shared\{Db, Identity, SecretBox};
use Kulturhus\Native\Integration\ManagerClub;
use Kulturhus\Verein\{ApplicationMail, MailPolicy};
require_once dirname(__DIR__, 2) . "/src/autoload.php";

/** Anonymous requests enqueue only protected credentials; private workers own delivery. */
final class Mail
{
    public static function requestReset(
        PDO $db,
        Service $auth,
        SecretBox $box,
        string $identifier,
        string $client,
    ): void {
        Db::atomic($db, static function () use (
            $db,
            $auth,
            $box,
            $identifier,
            $client,
        ): void {
            $reset = $auth->requestReset($identifier, $client);
            if ($reset === null) {
                return;
            }
            $account = Db::run(
                $db,
                "SELECT a.*,u.club_id,u.name FROM native_auth_accounts a JOIN native_users u ON u.id=a.actor_id WHERE a.id=? FOR UPDATE",
                [$reset["account_id"]],
            )->fetch();
            $identity = Identity::forClub($db, $account["club_id"]);
            $origin = rtrim($identity["origin"], "/");
            if (
                !preg_match("~\Ahttps://[a-zA-Z0-9.-]+\z~D", $origin) ||
                filter_var(parse_url($origin, PHP_URL_HOST), FILTER_VALIDATE_IP)
            ) {
                throw new DomainException(
                    "Feste Vereinsdomain für Kontonachrichten erforderlich.",
                );
            }
            $id = bin2hex(random_bytes(16));
            $link = $origin . "/reset?token=" . $reset["token"];
            $name = $identity["name"] ?: "Mein Verein";
            $text =
                "Hallo " .
                $account["name"] .
                ",\n\nfür deinen persönlichen Zugang zu " .
                $name .
                " wurde ein neues Passwort angefordert.\n" .
                ($account["login_name"]
                    ? "Benutzername: " . $account["login_name"] . "\n"
                    : "") .
                "\nLege über diesen einmaligen Link dein eigenes Passwort fest. Der Link ist 30 Minuten gültig:\n" .
                $link .
                "\n\nDein zweiter Faktor bleibt erhalten. Wenn du diese Anfrage nicht gestellt hast, brauchst du nichts zu tun. Dein bisheriges Passwort bleibt gültig.\n\nDein " .
                $name;
            $subject = "Passwort zurücksetzen · " . $name;
            $message = [
                "to" => $account["email"],
                "subject" => $subject,
                "text" => $text,
                "html" => ApplicationMail::html(
                    $text,
                    $origin,
                    $subject,
                    $link,
                    "Eigenes Passwort festlegen",
                    "KONTO & SICHERHEIT",
                    $name,
                ),
                "origin" => $origin,
            ];
            Db::run(
                $db,
                "INSERT INTO native_auth_mail(id,account_id,club_id,token_hash,expires_at,cipher,status,created_at) VALUES(?,?,?,?,?,?,'queued',UTC_TIMESTAMP())",
                [
                    $id,
                    $account["id"],
                    $account["club_id"],
                    hash("sha256", $reset["token"]),
                    $reset["expires_at"],
                    $box->seal("auth-mail:" . $id, $message),
                ],
            );
        });
    }

    public static function run(
        PDO $db,
        SecretBox $box,
        string $club,
        ?callable $send,
        int $limit = 20,
    ): array {
        if ($limit < 1 || $limit > 20) {
            throw new DomainException(
                "Höchstens 20 Kontonachrichten je Worker-Lauf.",
            );
        }
        Db::run(
            $db,
            "UPDATE native_auth_mail SET status=CASE WHEN status='queued' THEN 'blocked' WHEN status='sending' THEN 'uncertain' ELSE status END,cipher=NULL WHERE club_id=? AND expires_at<=? AND status IN ('queued','sending','sent','blocked','uncertain')",
            [$club, time()],
        );
        $result = ["sent" => 0, "blocked" => 0, "uncertain" => 0];
        if ($send === null) {
            return $result;
        }
        $rows = Db::run(
            $db,
            "SELECT id FROM native_auth_mail WHERE club_id=? AND status='queued' ORDER BY created_at,id LIMIT " .
                $limit,
            [$club],
        )->fetchAll();
        foreach ($rows as $row) {
            $message = Db::atomic($db, static function () use (
                $db,
                $box,
                $club,
                $row,
                &$result,
            ): ?array {
                $mail = Db::run(
                    $db,
                    "SELECT * FROM native_auth_mail WHERE id=? AND club_id=? FOR UPDATE",
                    [$row["id"], $club],
                )->fetch();
                if (!$mail || $mail["status"] !== "queued") {
                    return null;
                }
                $message = $box->open(
                    "auth-mail:" . $mail["id"],
                    $mail["cipher"],
                );
                if (!MailPolicy::allowed($message["origin"])) {
                    return null;
                }
                $account = Db::run(
                    $db,
                    "SELECT a.email,a.active,a.verified,u.active AS person_active,u.club_id FROM native_auth_accounts a JOIN native_users u ON u.id=a.actor_id WHERE a.id=? FOR SHARE",
                    [$mail["account_id"]],
                )->fetch();
                $token = Db::run(
                    $db,
                    "SELECT consumed_at,expires_at FROM native_auth_tokens WHERE token_hash=? AND account_id=? AND purpose='reset' FOR SHARE",
                    [$mail["token_hash"], $mail["account_id"]],
                )->fetch();
                $original = Db::run(
                    $db,
                    "SELECT a.active FROM native_manager_identities i LEFT JOIN actors a ON a.id=i.manager_actor_id AND a.club_id=i.manager_club_id JOIN native_auth_accounts au ON au.actor_id=i.native_id WHERE au.id=? FOR SHARE",
                    [$mail["account_id"]],
                )->fetch();
                $identity = Identity::forClub($db, $club);
                if (
                    !$account ||
                    !$account["active"] ||
                    !$account["verified"] ||
                    !$account["person_active"] ||
                    $account["club_id"] !== $club ||
                    ($original && !$original["active"]) ||
                    (ManagerClub::id($db, $club) !== null && !$original) ||
                    $account["email"] !== $message["to"] ||
                    $identity["origin"] !== $message["origin"] ||
                    !$token ||
                    $token["consumed_at"] !== null ||
                    (int) $token["expires_at"] <= time()
                ) {
                    Db::run(
                        $db,
                        "UPDATE native_auth_mail SET status='blocked',cipher=NULL WHERE id=?",
                        [$mail["id"]],
                    );
                    $result["blocked"]++;
                    return null;
                }
                Db::run(
                    $db,
                    "UPDATE native_auth_mail SET status='sending' WHERE id=? AND status='queued'",
                    [$mail["id"]],
                );
                unset($message["origin"]);
                return $message;
            });
            if ($message === null) {
                continue;
            }
            try {
                $send($message);
                Db::run(
                    $db,
                    "UPDATE native_auth_mail SET status='sent',cipher=NULL,sent_at=UTC_TIMESTAMP() WHERE id=? AND status='sending'",
                    [$row["id"]],
                );
                $result["sent"]++;
            } catch (\Kulturhus\Native\Delivery\DeliveryNotStarted $e) {
                $now=time();Db::run($db,"UPDATE native_auth_mail SET status=IF(expires_at>? AND ?=1,'queued','blocked'),cipher=IF(expires_at>? AND ?=1,cipher,NULL) WHERE id=? AND club_id=? AND status='sending'",[$now,(int)$e->retryable,$now,(int)$e->retryable,$row['id'],$club]);
                $state=Db::run($db,'SELECT status FROM native_auth_mail WHERE id=? AND club_id=?',[$row['id'],$club])->fetchColumn();if(in_array($state,['queued','sending'],true))$result['held']=($result['held']??0)+1;else$result['blocked']++;
            } catch (\Throwable) {
                if(Db::run($db,"SELECT 1 FROM native_auth_mail WHERE club_id=? AND id=? AND status='sent' AND cipher IS NULL AND sent_at IS NOT NULL",[$club,$row['id']])->fetchColumn()){$result['sent']++;continue;}
                Db::run(
                    $db,
                    "UPDATE native_auth_mail SET status='uncertain' WHERE id=? AND status='sending'",
                    [$row["id"]],
                );
                $result["uncertain"]++;
            }
        }
        Db::run(
            $db,
            "UPDATE native_auth_mail SET cipher=NULL WHERE club_id=? AND expires_at<=? AND status IN ('sent','blocked','uncertain')",
            [$club, time()],
        );
        return $result;
    }
}
