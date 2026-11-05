<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration;

use PDO;
use DomainException;
use Kulturhus\Native\Auth\Service;
use Kulturhus\Native\Shared\{Db, SecretBox, Identity};
use Kulturhus\Verein\{Permissions, BoardAccess, MemberLoginNames};
require_once dirname(__DIR__, 2) . "/src/autoload.php";

/** Original manual/admission requests, native credentials and personal family bindings. */
final class MemberAccounts
{
    public function __construct(
        private PDO $db,
        private Service $auth,
        private SecretBox $box,
    ) {}

    private function request(string $club, string $contact): array
    {
        $lock = $this->db->inTransaction() ? " FOR UPDATE" : "";
        $row = Db::run(
            $this->db,
            "SELECT * FROM manual_member_accounts WHERE club_id=? AND contact_id=?" .
                $lock,
            [$club, $contact],
        )->fetch();
        if (!$row) {
            throw new DomainException("Gespeicherter Zugangsauftrag fehlt.");
        }
        $actor = Permissions::actor($this->db, $row["requested_by"]);
        if ($actor["club_id"] !== $club) {
            throw new DomainException("Vereinsbindung geändert.");
        }
        if ($actor["members_access"] !== "write") {
            BoardAccess::require($this->db, $actor["id"]);
            if (
                !$row["application_id"] ||
                !Db::run(
                    $this->db,
                    "SELECT 1 FROM membership_applications WHERE club_id=? AND id=? AND contact_id=? AND reviewed_by=? AND status='accepted'" .
                        ($this->db->inTransaction() ? " FOR SHARE" : ""),
                    [$club, $row["application_id"], $contact, $actor["id"]],
                )->fetchColumn()
            ) {
                throw new DomainException("Aufnahme nicht mehr gültig.");
            }
        }
        $person = Db::run(
            $this->db,
            "SELECT * FROM contacts WHERE club_id=? AND id=? AND kind='person' AND archived_at IS NULL" .
                $lock,
            [$club, $contact],
        )->fetch();
        if (
            !$person ||
            !filter_var($person["email"], FILTER_VALIDATE_EMAIL) ||
            strcasecmp(trim($person["email"]), trim($row["email"])) !== 0 ||
            !Db::run(
                $this->db,
                "SELECT 1 FROM memberships WHERE club_id=? AND contact_id=? AND status IN ('aktiv','passiv') AND ends_on IS NULL" .
                    ($this->db->inTransaction() ? " FOR SHARE" : ""),
                [$club, $contact],
            )->fetchColumn()
        ) {
            throw new DomainException(
                "Persönliche Mitgliedschaft oder Adresse inzwischen geändert.",
            );
        }
        return $row + ["person" => $person];
    }

    private function linked(string $club, string $contact): ?array
    {
        $rows = Db::run(
            $this->db,
            "SELECT au.*,a.id AS manager_actor,a.active AS manager_active,i.native_id,u.active AS native_active FROM actors a JOIN native_manager_identities i ON i.manager_club_id=a.club_id AND i.manager_actor_id=a.id JOIN native_users u ON u.id=i.native_id JOIN native_auth_accounts au ON au.actor_id=u.id WHERE a.club_id=? AND a.contact_id=?" .
                ($this->db->inTransaction() ? " FOR UPDATE" : ""),
            [$club, $contact],
        )->fetchAll();
        if (count($rows) > 1) {
            throw new DomainException(
                "Mehrere persönliche Kontobindungen prüfen.",
            );
        }
        $row = $rows[0] ?? null;
        if (
            $row &&
            ($row["suspended"] || !$row["manager_active"] ||
                !$row["native_active"] ||
                ($row["verified"] && !$row["active"]))
        ) {
            throw new DomainException(
                "Gesperrter Zugang wird nicht reaktiviert.",
            );
        }
        return $row;
    }

    private function checkSharedAddress(
        string $club,
        string $contact,
        string $email,
    ): void {
        $others = Db::run(
            $this->db,
            "SELECT au.id,au.login_name,i.manager_club_id,a.contact_id,a.active FROM native_auth_accounts au JOIN native_users u ON u.id=au.actor_id LEFT JOIN native_manager_identities i ON i.native_id=u.id LEFT JOIN actors a ON a.id=i.manager_actor_id AND a.club_id=i.manager_club_id WHERE au.email=?" .
                ($this->db->inTransaction() ? " FOR SHARE" : ""),
            [strtolower(trim($email))],
        )->fetchAll();
        foreach ($others as $other) {
            if (
                $other["manager_club_id"] === $club &&
                $other["contact_id"] === $contact
            ) {
                continue;
            }
            if (
                $other["manager_club_id"] !== $club ||
                !$other["active"] ||
                !$other["login_name"] ||
                !Db::run(
                    $this->db,
                    "SELECT 1 FROM member_family_people own JOIN member_family_people relative ON relative.club_id=own.club_id AND relative.family_id=own.family_id WHERE own.club_id=? AND own.contact_id=? AND relative.contact_id=?" .
                        ($this->db->inTransaction() ? " FOR SHARE" : ""),
                    [$club, $contact, $other["contact_id"]],
                )->fetchColumn()
            ) {
                throw new DomainException(
                    "Bestehendes E-Mail-Konto ausdrücklich zuordnen; nur bestätigte Familienzugehörigkeit erlaubt eine gemeinsame Adresse.",
                );
            }
        }
    }

    public function prepare(string $club, string $contact): array
    {
        return Db::atomic($this->db, function () use ($club, $contact): array {
            $request = $this->request($club, $contact);
            $account = $this->linked($club, $contact);
            $old = Db::run(
                $this->db,
                "SELECT * FROM native_member_onboarding WHERE club_id=? AND contact_id=? FOR UPDATE",
                [$club, $contact],
            )->fetch();
            if ($old && (!$account || $account["id"] !== $old["account_id"])) {
                throw new DomainException(
                    "Originale Kontobindung inzwischen geändert.",
                );
            }
            if (
                $account &&
                strcasecmp($account["email"], $request["email"]) !== 0
            ) {
                throw new DomainException(
                    "Kontoadresse und aktuelles Profil ausdrücklich abgleichen.",
                );
            }
            if (
                $old &&
                !$account["verified"] &&
                (int) $old["expires_at"] <= time() &&
                in_array(
                    $request["mail_status"],
                    ["sending", "sent", "uncertain"],
                    true,
                )
            ) {
                throw new DomainException(
                    "Abgelaufene bereits versendete Einladung ausdrücklich erneuern.",
                );
            }
            if (
                $old &&
                ($account["verified"] ||
                    ((int) $old["expires_at"] > time() &&
                        ($old["invite_cipher"] !== null ||
                            in_array(
                                $request["mail_status"],
                                ["sending", "sent", "uncertain", "disabled"],
                                true,
                            ))))
            ) {
                if (!$account["verified"]) {
                    $this->checkSharedAddress(
                        $club,
                        $contact,
                        $request["email"],
                    );
                }
                Db::run(
                    $this->db,
                    "UPDATE manual_member_accounts SET state='ready',username=? WHERE club_id=? AND contact_id=?",
                    [$old["username"], $club, $contact],
                );
                return $this->result($old, $account);
            }
            if (!$account) {
                $this->checkSharedAddress($club, $contact, $request["email"]);
                if (
                    Db::run(
                        $this->db,
                        "SELECT 1 FROM actors a JOIN processwire_identities p ON p.actor_id=a.id WHERE a.club_id=? AND a.contact_id=? FOR SHARE",
                        [$club, $contact],
                    )->fetchColumn()
                ) {
                    throw new DomainException(
                        "Vorhandene ältere Kontobindung ausdrücklich übernehmen.",
                    );
                }
                $actor =
                    "member-" .
                    substr(hash("sha256", $club . "\0" . $contact), 0, 56);
                $native = ManagerBridge::actorKey($actor, $club);
                if (
                    Db::run(
                        $this->db,
                        "SELECT 1 FROM actors WHERE id=? FOR UPDATE",
                        [$actor],
                    )->fetchColumn()
                ) {
                    throw new DomainException(
                        "Vorhandene Personenbindung prüfen.",
                    );
                }
                Db::run(
                    $this->db,
                    "INSERT INTO actors(id,club_id,role,contact_id,is_superuser) VALUES(?,?,'member',?,0)",
                    [$actor, $club, $contact],
                );
                Db::run(
                    $this->db,
                    "INSERT INTO native_users(id,club_id,name,active) VALUES(?,?,?,1)",
                    [
                        $native,
                        ManagerBridge::clubKey($club),
                        $request["person"]["name"],
                    ],
                );
                Db::run(
                    $this->db,
                    "INSERT INTO native_manager_identities VALUES(?,?,?)",
                    [$native, $actor, $club],
                );
            } else {
                $native = $account["actor_id"];
            }
            $username =
                $old["username"] ??
                ($account["login_name"] ??
                    MemberLoginNames::fromName($request["person"]["name"]) .
                        "-" .
                        substr(hash("sha256", $club . "\0" . $contact), 0, 8));
            $cipher = null;
            $expires = null;
            if (!$account || !$account["verified"]) {
                $this->checkSharedAddress($club, $contact, $request["email"]);
                $invite = $this->auth->createInvitation(
                    new MemberInvitationAccess($this->db, $club, $contact),
                    $native,
                    $request["email"],
                    172800,
                    $username,
                );
                $account = $invite["account"];
                $expires = $invite["expires_at"];
                $cipher = $this->box->seal(
                    "member-invite:" . $club . ":" . $contact,
                    ["token" => $invite["token"]],
                );
            }
            Db::run(
                $this->db,
                "INSERT INTO native_member_onboarding VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE invite_cipher=VALUES(invite_cipher),expires_at=VALUES(expires_at)",
                [
                    $club,
                    $contact,
                    $account["id"],
                    $username,
                    strtolower(trim($request["email"])),
                    $cipher,
                    $expires,
                ],
            );
            Db::run(
                $this->db,
                "UPDATE manual_member_accounts SET state='ready',username=? WHERE club_id=? AND contact_id=?",
                [$username, $club, $contact],
            );
            Db::run(
                $this->db,
                "INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)",
                [
                    $club,
                    $request["requested_by"],
                    "identity.native.member.prepared",
                    $contact,
                    (int) $request["person"]["version"],
                    gmdate("c"),
                ],
            );
            return $this->result(
                Db::run(
                    $this->db,
                    "SELECT * FROM native_member_onboarding WHERE club_id=? AND contact_id=?",
                    [$club, $contact],
                )->fetch(),
                $account,
            );
        });
    }

    private function result(array $row, array $account): array
    {
        $result = [
            "account_id" => $row["account_id"],
            "username" => $row["username"],
            "created" => !$account["verified"],
            "password_change_required" => false,
        ];
        if (!$account["verified"] && $row["invite_cipher"] !== null) {
            $result["setup_token"] = $this->box->open(
                "member-invite:" . $row["club_id"] . ":" . $row["contact_id"],
                $row["invite_cipher"],
            )["token"];
        }
        return $result;
    }

    public function acknowledge(string $club, string $contact): void
    {
        Db::run(
            $this->db,
            "UPDATE native_member_onboarding SET invite_cipher=NULL WHERE club_id=? AND contact_id=?",
            [$club, $contact],
        );
    }

    public function run(
        string $club,
        string $origin,
        ?callable $send = null,
        int $limit = 20,
    ): array {
        if (
            $limit < 1 ||
            $limit > 20 ||
            !preg_match("~\Ahttps://[a-zA-Z0-9.-]+(?::[0-9]+)?\z~D", $origin)
        ) {
            throw new DomainException(
                "Feste HTTPS-Adresse und höchstens 20 Zugangsaufträge erforderlich.",
            );
        }
        $result = ["ready" => 0, "sent" => 0, "blocked" => 0];
        $rows = Db::run(
            $this->db,
            "SELECT contact_id FROM manual_member_accounts WHERE club_id=? AND (state='prepared' OR (state='ready' AND mail_status='pending')) ORDER BY created_at,contact_id LIMIT " .
                $limit,
            [$club],
        )->fetchAll();
        foreach ($rows as $row) {
            try {
                $account = $this->prepare($club, $row["contact_id"]);
                $result["ready"]++;
                if (!$send) {
                    continue;
                }
                $message = Db::atomic($this->db, function () use (
                    $club,
                    $row,
                    $account,
                    $origin,
                ): ?array {
                    $request = $this->request($club, $row["contact_id"]);
                    $current = $this->linked($club, $row["contact_id"]);
                    if (
                        !$current ||
                        $current["id"] !== $account["account_id"]
                    ) {
                        throw new DomainException(
                            "Originales Konto vor Versand geändert.",
                        );
                    }
                    if (
                        Db::run(
                            $this->db,
                            "UPDATE manual_member_accounts SET mail_status='sending' WHERE club_id=? AND contact_id=? AND state='ready' AND send_mail=1 AND mail_status='pending'",
                            [$club, $row["contact_id"]],
                        )->rowCount() !== 1
                    ) {
                        return null;
                    }
                    $link = isset($account["setup_token"])
                        ? $origin . "/invite?token=" . $account["setup_token"]
                        : $origin . "/login/";
                    $name =
                        Identity::forClub(
                            $this->db,
                            ManagerBridge::clubKey($club),
                        )["name"] ?:
                        "Mein Verein";
                    $text =
                        "Hallo " .
                        $request["person"]["name"] .
                        ",\n\ndein persönlicher Mitgliedszugang zu " .
                        $name .
                        " ist eingerichtet.\nBenutzername: " .
                        $account["username"] .
                        "\n\n" .
                        (isset($account["setup_token"])
                            ? "Lege über diesen einmaligen Link dein eigenes Passwort fest. Der Link ist zwei Tage gültig:"
                            : "Dein vorhandenes Passwort bleibt erhalten:") .
                        "\n" .
                        $link .
                        "\n\nDein " .
                        $name;
                    return [
                        "to" => $request["email"],
                        "subject" => "Dein persönlicher Mitgliedszugang",
                        "text" => $text,
                        "html" =>
                            "<p>" .
                            nl2br(
                                htmlspecialchars(
                                    $text,
                                    ENT_QUOTES | ENT_SUBSTITUTE,
                                    "UTF-8",
                                ),
                            ) .
                            "</p>",
                    ];
                });
                if ($message === null) {
                    continue;
                }
                try {
                    $send($message);
                    Db::run(
                        $this->db,
                        "UPDATE manual_member_accounts SET mail_status='sent',sent_at=? WHERE club_id=? AND contact_id=? AND mail_status='sending'",
                        [gmdate("c"), $club, $row["contact_id"]],
                    );
                    Db::run(
                        $this->db,
                        "UPDATE membership_welcome SET welcome_status='sent',welcome_sent_at=? WHERE club_id=? AND contact_id=? AND welcome_status='manual_access'",
                        [gmdate("c"), $club, $row["contact_id"]],
                    );
                    $this->acknowledge($club, $row["contact_id"]);
                    $result["sent"]++;
                } catch (\Kulturhus\Native\Delivery\DeliveryNotStarted $e) {
                    Db::run($this->db,"UPDATE manual_member_accounts SET mail_status=?,state=IF(?=1,state,'needs_review') WHERE club_id=? AND contact_id=? AND mail_status='sending'",[$e->retryable?'pending':'disabled',(int)$e->retryable,$club,$row['contact_id']]);if($e->retryable)$result['held']=($result['held']??0)+1;else$result['blocked']++;
                } catch (\Throwable) {
                    Db::run(
                        $this->db,
                        "UPDATE manual_member_accounts SET mail_status='uncertain' WHERE club_id=? AND contact_id=? AND mail_status='sending'",
                        [$club, $row["contact_id"]],
                    );
                }
            } catch (\Throwable) {
                Db::run(
                    $this->db,
                    "UPDATE manual_member_accounts SET state='needs_review' WHERE club_id=? AND contact_id=? AND mail_status<>'sending'",
                    [$club, $row["contact_id"]],
                );
                $result["blocked"]++;
            }
        }
        return $result;
    }
}
