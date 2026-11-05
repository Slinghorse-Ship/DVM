<?php declare(strict_types=1);
namespace Kulturhus\Native\Auth;

use PDO;
use DomainException;
use Closure;
use Kulturhus\Native\Shared\{Db, Identity, SecretBox};
use Kulturhus\Native\Integration\ManagerClub;
use Kulturhus\Verein\{ApplicationMail, MailPolicy};
require_once dirname(__DIR__, 2) . "/src/autoload.php";

/** Internal authentication adapter. Only Service calls issue after password proof. */
final class EmailFactor
{
    public function __construct(
        private PDO $db,
        private SecretBox $box,
        private ?Closure $clock = null,
    ) {}
    private function now(): int
    {
        return $this->clock ? ($this->clock)() : time();
    }

    /** The code stays protected; the browser receives only an opaque challenge. */
    public function issue(
        array $account,
        string $purpose,
        ?string $sessionHash,
        ?string $clientHash,
    ): array {
        if (
            !in_array($purpose, ["login", "enable", "disable", "stepup", "address"], true)
        ) {
            throw new DomainException("Unbekannte Faktorbestätigung.");
        }
        if (
            !$account["active"] ||
            !$account["verified"] ||
            !filter_var($account["email"], FILTER_VALIDATE_EMAIL)
        ) {
            throw new DomainException(
                "Aktives persönliches Konto erforderlich.",
            );
        }
        $user = Db::run(
            $this->db,
            "SELECT club_id,name,active FROM native_users WHERE id=? FOR SHARE",
            [$account["actor_id"]],
        )->fetch();
        if (!$user || !$user["active"]) {
            throw new DomainException(
                "Aktives persönliches Konto erforderlich.",
            );
        }
        $identity = Identity::forClub($this->db, $user["club_id"]);
        $origin = rtrim($identity["origin"], "/");
        if (
            !preg_match("~\Ahttps://[a-zA-Z0-9.-]+\z~D", $origin) ||
            filter_var(parse_url($origin, PHP_URL_HOST), FILTER_VALIDATE_IP)
        ) {
            throw new DomainException(
                "Feste Vereinsdomain für Sicherheitscodes erforderlich.",
            );
        }
        $challenge = bin2hex(random_bytes(32));
        $id = hash("sha256", $challenge);
        $code = (string) random_int(10000000, 99999999);
        $expires = $this->now() + ($purpose === "address" ? 900 : 300);
        $name = $identity["name"] ?: "Mein Verein";
        $task = match ($purpose) {
            "enable" => "Aktivierung der E-Mail-Zwei-Faktor-Anmeldung",
            "disable" => "Deaktivierung der E-Mail-Zwei-Faktor-Anmeldung",
            "stepup" => "Bestätigung deiner Bankdaten-Aktion",
            "address" => "Bestätigung deiner neuen Login-E-Mail-Adresse",
            default => "Anmeldung",
        };
        $text =
            "Hallo " .
            $user["name"] .
            ",\n\n" .
            $name .
            " · " .
            $task .
            "\n\nDein Sicherheitscode: " .
            $code .
            "\n\nDer Code gilt " . ($purpose === "address" ? "15" : "fünf") . " Minuten und funktioniert einmal für diese Aktion. Gib ihn ausschließlich auf " .
            $origin .
            " ein.\n\nWenn du diese Aktion nicht begonnen hast, gib den Code nicht weiter.\n\nDein " .
            $name;
        $message = [
            "to" => $account["email"],
            "subject" => "Sicherheitscode · " . $name,
            "text" => $text,
            "html" => ApplicationMail::html(
                $text,
                $origin,
                "Sicherheitscode",
                "",
                "",
                "KONTO & SICHERHEIT",
                $name,
            ),
            "origin" => $origin,
        ];
        if($purpose==='address')$message['previous_email']=$account['previous_email'];
        Db::run(
            $this->db,
            "UPDATE native_auth_email_codes SET consumed_at=?,cipher=NULL,status=IF(status='queued','blocked',status) WHERE account_id=? AND purpose=? AND session_hash<=>? AND client_hash<=>? AND consumed_at IS NULL",
            [$this->now(), $account["id"], $purpose, $sessionHash, $clientHash],
        );
        Db::run(
            $this->db,
            "INSERT INTO native_auth_email_codes(id,account_id,purpose,email,account_version,session_hash,client_hash,code_hash,expires_at,cipher,status,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,'queued',?)",
            [
                $id,
                $account["id"],
                $purpose,
                $account["email"],
                $account["version"],
                $sessionHash,
                $clientHash,
                $this->box->fingerprint($id . ":" . $code),
                $expires,
                $this->box->seal("email-factor:" . $id, $message),
                $this->now(),
            ],
        );
        return [
            "challenge" => $challenge,
            "expires_at" => $expires,
            "method" => "email",
        ];
    }

    public function consume(
        array $account,
        string $purpose,
        string $challengeHash,
        string $code,
        ?string $sessionHash,
        ?string $clientHash,
    ): void {
        $row = Db::run(
            $this->db,
            "SELECT * FROM native_auth_email_codes WHERE id=? AND account_id=? AND purpose=? FOR UPDATE",
            [$challengeHash, $account["id"], $purpose],
        )->fetch();
        if (
            !$row ||
            !in_array($row["status"],$purpose==='address'?['sent','sending','uncertain']:['sent'],true) ||
            $row["consumed_at"] !== null ||
            (int) $row["expires_at"] <= $this->now() ||
            (int) $row["account_version"] !== (int) $account["version"] ||
            $row["email"] !== $account["email"] ||
            $row["session_hash"] !== $sessionHash ||
            $row["client_hash"] !== $clientHash ||
            !preg_match("/\A[0-9]{8}\z/D", $code) ||
            !hash_equals(
                $row["code_hash"],
                $this->box->fingerprint($challengeHash . ":" . $code),
            )
        ) {
            throw new DomainException(
                "Sicherheitscode ungültig oder abgelaufen.",
            );
        }
        Db::run(
            $this->db,
            "UPDATE native_auth_email_codes SET consumed_at=?,cipher=NULL WHERE id=?",
            [$this->now(), $challengeHash],
        );
    }

    public function latest(
        array $account,
        string $purpose,
        string $sessionHash,
    ): string {
        $id = Db::run(
            $this->db,
            "SELECT id FROM native_auth_email_codes WHERE account_id=? AND purpose=? AND session_hash=? AND consumed_at IS NULL ORDER BY created_at DESC,id DESC LIMIT 1",
            [$account["id"], $purpose, $sessionHash],
        )->fetchColumn();
        if (!$id) {
            throw new DomainException(
                "Bitte die Bankdaten-Aktion mit deinem Passwort neu beginnen.",
            );
        }
        return $id;
    }

    public function run(string $club, ?callable $send, int $limit = 20): array
    {
        if ($limit < 1 || $limit > 20) {
            throw new DomainException(
                "Höchstens 20 Sicherheitscodes je Worker-Lauf.",
            );
        }
        Db::run(
            $this->db,
            "UPDATE native_auth_email_codes c JOIN native_auth_accounts a ON a.id=c.account_id JOIN native_users u ON u.id=a.actor_id SET c.cipher=NULL,c.status=IF(c.status='queued','blocked',c.status) WHERE u.club_id=? AND c.expires_at<=?",
            [$club, $this->now()],
        );
        $result = ["sent" => 0, "blocked" => 0, "uncertain" => 0];
        if ($send === null) {
            return $result;
        }
        $rows = Db::run(
            $this->db,
            "SELECT c.id FROM native_auth_email_codes c JOIN native_auth_accounts a ON a.id=c.account_id JOIN native_users u ON u.id=a.actor_id WHERE u.club_id=? AND c.status='queued' ORDER BY c.created_at,c.id LIMIT " .
                $limit,
            [$club],
        )->fetchAll();
        foreach ($rows as $selected) {
            $message = Db::atomic($this->db, function () use (
                $selected,
                $club,
                &$result,
            ): ?array {
                $row = Db::run(
                    $this->db,
                    "SELECT * FROM native_auth_email_codes WHERE id=? FOR UPDATE",
                    [$selected["id"]],
                )->fetch();
                if (!$row || $row["status"] !== "queued") {
                    return null;
                }
                $message = $this->box->open(
                    "email-factor:" . $row["id"],
                    $row["cipher"],
                );
                if (!MailPolicy::allowed($message["origin"])) {
                    return null;
                }
                $account = Db::run(
                    $this->db,
                    "SELECT a.*,u.club_id,u.active AS person_active FROM native_auth_accounts a JOIN native_users u ON u.id=a.actor_id WHERE a.id=? FOR SHARE",
                    [$row["account_id"]],
                )->fetch();
                $original = Db::run(
                    $this->db,
                    "SELECT a.active FROM native_manager_identities i LEFT JOIN actors a ON a.id=i.manager_actor_id AND a.club_id=i.manager_club_id JOIN native_auth_accounts au ON au.actor_id=i.native_id WHERE au.id=? FOR SHARE",
                    [$row["account_id"]],
                )->fetch();
                $identity = Identity::forClub($this->db, $club);
                $valid =
                    $account &&
                    $account["active"] &&
                    $account["verified"] &&
                    !$account["suspended"] &&
                    $account["person_active"] &&
                    $account["club_id"] === $club &&
                    $account["email"] === ($row["purpose"]==='address'?($message['previous_email']??null):$row["email"]) &&
                    (int) $account["version"] ===
                        (int) $row["account_version"] &&
                    (!$original || $original["active"]) &&
                    (ManagerClub::id($this->db, $club) === null || $original) &&
                    $identity["origin"] === $message["origin"] &&
                    $row["consumed_at"] === null &&
                    (int) $row["expires_at"] > $this->now() &&
                    ($row["purpose"]==='address'||$account["totp_secret"] === null);
                $valid =
                    $valid &&
                    ($row["purpose"]==='address' || ($row["purpose"] === "enable"
                        ? !$account["email_factor"]
                        : $account["email_factor"] &&
                            $account["factor_email"] === $row["email"]));
                if (!empty($row["session_hash"])) {
                    $valid =
                        $valid &&
                        (bool) Db::run(
                            $this->db,
                            "SELECT 1 FROM native_auth_sessions WHERE token_hash=? AND account_id=? AND revoked_at IS NULL AND expires_at>? FOR SHARE",
                            [
                                $row["session_hash"],
                                $row["account_id"],
                                $this->now(),
                            ],
                        )->fetchColumn();
                }
                if (!$valid) {
                    Db::run(
                        $this->db,
                        "UPDATE native_auth_email_codes SET status='blocked',cipher=NULL WHERE id=?",
                        [$row["id"]],
                    );
                    $result["blocked"]++;
                    return null;
                }
                Db::run(
                    $this->db,
                    "UPDATE native_auth_email_codes SET status='sending' WHERE id=?",
                    [$row["id"]],
                );
                unset($message["origin"],$message["previous_email"]);
                return $message;
            });
            if ($message === null) {
                continue;
            }
            try {
                $send($message);
                Db::run(
                    $this->db,
                    "UPDATE native_auth_email_codes SET status='sent',sent_at=?,cipher=NULL WHERE id=? AND status='sending'",
                    [$this->now(), $selected["id"]],
                );
                $result["sent"]++;
            } catch (\Kulturhus\Native\Delivery\DeliveryNotStarted $e) {
                $now=$this->now();Db::run($this->db,"UPDATE native_auth_email_codes c JOIN native_auth_accounts a ON a.id=c.account_id JOIN native_users u ON u.id=a.actor_id SET c.status=IF(c.expires_at>? AND ?=1,'queued','blocked'),c.cipher=IF(c.expires_at>? AND ?=1,c.cipher,NULL) WHERE c.id=? AND u.club_id=? AND c.status='sending'",[$now,(int)$e->retryable,$now,(int)$e->retryable,$selected['id'],$club]);
                $state=Db::run($this->db,'SELECT c.status FROM native_auth_email_codes c JOIN native_auth_accounts a ON a.id=c.account_id JOIN native_users u ON u.id=a.actor_id WHERE c.id=? AND u.club_id=?',[$selected['id'],$club])->fetchColumn();if(in_array($state,['queued','sending'],true))$result['held']=($result['held']??0)+1;else$result['blocked']++;
            } catch (\Throwable) {
                if(Db::run($this->db,"SELECT 1 FROM native_auth_email_codes c JOIN native_auth_accounts a ON a.id=c.account_id JOIN native_users u ON u.id=a.actor_id WHERE u.club_id=? AND c.id=? AND c.status='sent' AND c.cipher IS NULL AND c.sent_at IS NOT NULL",[$club,$selected['id']])->fetchColumn()){$result['sent']++;continue;}
                Db::run(
                    $this->db,
                    "UPDATE native_auth_email_codes SET status='uncertain' WHERE id=? AND status='sending'",
                    [$selected["id"]],
                );
                $result["uncertain"]++;
            }
        }
        return $result;
    }
}
