<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration;

use PDO;
use DomainException;
use Kulturhus\Native\Auth\Service;
use Kulturhus\Native\Shared\{Db, SecretBox};

/** Personal native accounts keep original actors, roles and profile identities. */
final class AccountIdentity
{
    public function __construct(
        private PDO $db,
        private ManagerAccess $access,
        private Service $auth,
        private SecretBox $box,
    ) {}

    public function directory(?string $managerActor = null): array
    {
        $owned=!$this->db->inTransaction();
        try{
        if($owned)$this->db->exec('START TRANSACTION');
        $this->access->require("accounts", "manage");
        $club = Db::run($this->db, "SELECT club_id FROM actors WHERE id=? FOR SHARE NOWAIT", [
            $this->access->managerActor(),
        ])->fetchColumn();
        $rows=Db::run(
            $this->db,
            "SELECT au.id,au.login_name,au.email,au.active AS account_active,au.suspended,au.verified,au.version,i.native_id,a.id AS manager_actor,a.club_id,a.contact_id,a.active,a.role,a.is_superuser,r.role_id,COALESCE(r.version,0) AS role_version,u.name FROM native_manager_identities i JOIN actors a ON a.id=i.manager_actor_id AND a.club_id=i.manager_club_id JOIN native_users u ON u.id=i.native_id JOIN native_auth_accounts au ON au.actor_id=i.native_id LEFT JOIN settings_role_assignments r ON r.actor_id=a.id AND r.club_id=a.club_id WHERE a.club_id=?" . ($managerActor !== null ? " AND a.id=?" : "") . " ORDER BY u.name,au.email,au.id FOR SHARE NOWAIT",
            $managerActor !== null ? [$club, $managerActor] : [$club],
        )->fetchAll();
        if($owned)$this->db->commit();
        return$rows;
        }catch(\Throwable $failure){
            if($owned&&$this->db->inTransaction())$this->db->rollBack();
            if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Persönliche Kontozuordnung wird gerade bearbeitet. Bitte erneut öffnen.',503,$failure);
            throw$failure;
        }
    }

    public function choices(string $kind,array $input=[],?string $selected=null):array
    {return (new IdentityChoices($this->db,$this->access))->listing($kind,$input,$selected);}

    private function target(string $id, int $version): array
    {
        $this->access->require("accounts", "manage");
        if (!preg_match("/\A[a-f0-9]{32}\z/D", $id)) {
            throw new DomainException("Persönliches Konto auswählen.");
        }
        $row = Db::run(
            $this->db,
            "SELECT au.id,au.login_name,au.email,au.active AS account_active,au.suspended,au.verified,au.version,i.native_id,a.id AS manager_actor,a.club_id,a.contact_id,a.active,a.role,a.is_superuser,r.role_id,COALESCE(r.version,0) AS role_version,u.name FROM native_auth_accounts au JOIN native_manager_identities i ON i.native_id=au.actor_id JOIN actors a ON a.id=i.manager_actor_id AND a.club_id=i.manager_club_id JOIN native_users u ON u.id=i.native_id LEFT JOIN settings_role_assignments r ON r.actor_id=a.id AND r.club_id=a.club_id WHERE au.id=? FOR UPDATE",
            [$id],
        )->fetch();
        $club = Db::run($this->db, "SELECT club_id FROM actors WHERE id=?", [
            $this->access->managerActor(),
        ])->fetchColumn();
        if (
            !$row ||
            $row["club_id"] !== $club ||
            $row["native_id"] !==
                ManagerBridge::actorKey($row["manager_actor"], $club) ||
            (int) $row["version"] !== $version
        ) {
            throw new DomainException(
                "Aktuelle persönliche Vereinszuordnung auswählen.",
            );
        }
        if ($row["manager_actor"] === $this->access->managerActor()) {
            throw new DomainException(
                "Eigene Anmeldezuordnung und technische Rechte bleiben geschützt.",
            );
        }
        return $row;
    }

    private function audit(array $before, string $action): void
    {
        $after = Db::run(
            $this->db,
            "SELECT a.contact_id,a.active,a.is_superuser,r.role_id,au.version FROM actors a JOIN native_manager_identities i ON i.manager_actor_id=a.id AND i.manager_club_id=a.club_id JOIN native_auth_accounts au ON au.actor_id=i.native_id LEFT JOIN settings_role_assignments r ON r.actor_id=a.id AND r.club_id=a.club_id WHERE au.id=?",
            [$before["id"]],
        )->fetch();
        Db::run(
            $this->db,
            "INSERT INTO native_identity_changes VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP())",
            [
                bin2hex(random_bytes(16)),
                $before["club_id"],
                $this->access->managerActor(),
                $before["id"],
                $action,
                json_encode($before, JSON_THROW_ON_ERROR),
                json_encode($after, JSON_THROW_ON_ERROR),
            ],
        );
        Db::run(
            $this->db,
            "INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)",
            [
                $before["club_id"],
                $this->access->managerActor(),
                $action,
                $before["manager_actor"],
                (int) $after["version"],
                gmdate("c"),
            ],
        );
    }

    public function assign(
        string $id,
        int $version,
        int $roleVersion,
        string $expectedContact,
        string $role,
        string $contact,
        bool $enabled,
        bool $confirmed,
        bool $defaults = false,
    ): void {
        Db::atomic($this->db, function () use (
            $id,
            $version,
            $roleVersion,
            $expectedContact,
            $role,
            $contact,
            $enabled,
            $confirmed,
            $defaults,
        ): void {
            $managerActor=$this->access->managerActor();$club=Db::run($this->db,"SELECT club_id FROM actors WHERE id=?",[$managerActor])->fetchColumn();\Kulturhus\Verein\RoleAssignments::lockClub($this->db,(string)$club);
            $row = $this->target($id, $version);
            $club = $row["club_id"];
            if (
                (int) $row["role_version"] !== $roleVersion ||
                ($row["contact_id"] ?? "") !== $expectedContact
            ) {
                throw new DomainException(
                    "Rolle oder Profil inzwischen geändert. Bitte neu laden.",
                );
            }
            if (
                $role !== "" &&
                !Db::run(
                    $this->db,
                    "SELECT 1 FROM settings_roles WHERE club_id=? AND id=? FOR SHARE",
                    [$club, $role],
                )->fetchColumn()
            ) {
                throw new DomainException("Vereinsrolle auswählen.");
            }
            $person =
                $contact !== ""
                    ? Db::run(
                        $this->db,
                        "SELECT id,name,email FROM contacts WHERE club_id=? AND id=? AND kind='person' AND archived_at IS NULL FOR SHARE",
                        [$club, $contact],
                    )->fetch()
                    : null;
            if ($contact !== "" && !$person) {
                throw new DomainException(
                    "Eigenes persönliches Profil des Vereins auswählen.",
                );
            }
            if ($contact !== $expectedContact) {
                if (!$confirmed) {
                    throw new DomainException(
                        "Änderung der persönlichen Profilzuordnung ausdrücklich bestätigen.",
                    );
                }
                if (
                    $contact !== "" &&
                    Db::run(
                        $this->db,
                        "SELECT 1 FROM actors a JOIN native_manager_identities i ON i.manager_actor_id=a.id AND i.manager_club_id=a.club_id JOIN native_auth_accounts au ON au.actor_id=i.native_id WHERE a.club_id=? AND a.contact_id=? AND au.id<>? FOR SHARE",
                        [$club, $contact, $id],
                    )->fetchColumn()
                ) {
                    throw new DomainException(
                        "Das persönliche Profil gehört bereits zu einem anderen Konto.",
                    );
                }
                $onboarding = Db::run(
                    $this->db,
                    "SELECT * FROM native_member_onboarding WHERE account_id=? FOR UPDATE",
                    [$id],
                )->fetch();
                if ($onboarding) {
                    if (
                        !$person ||
                        strcasecmp($person["email"], $row["email"]) !== 0 ||
                        !Db::run(
                            $this->db,
                            "SELECT 1 FROM memberships WHERE club_id=? AND contact_id=? AND status IN ('aktiv','passiv') AND ends_on IS NULL FOR SHARE",
                            [$club, $contact],
                        )->fetchColumn()
                    ) {
                        throw new DomainException(
                            "Ein Mitgliedszugang bleibt mit einer aktuellen persönlichen Mitgliedschaft und seiner bestätigten Kontoadresse verbunden.",
                        );
                    }
                    if (
                        Db::run(
                            $this->db,
                            "SELECT 1 FROM native_member_onboarding WHERE club_id=? AND contact_id=? AND account_id<>? FOR UPDATE",
                            [$club, $contact, $id],
                        )->fetchColumn()
                    ) {
                        throw new DomainException(
                            "Zielprofil hat bereits einen anderen Zugangsauftrag.",
                        );
                    }
                    $cipher = $onboarding["invite_cipher"];
                    if ($cipher !== null) {
                        $cipher = $this->box->seal(
                            "member-invite:" . $club . ":" . $contact,
                            $this->box->open(
                                "member-invite:" .
                                    $club .
                                    ":" .
                                    $onboarding["contact_id"],
                                $cipher,
                            ),
                        );
                    }
                    Db::run(
                        $this->db,
                        "UPDATE native_member_onboarding SET contact_id=?,invite_cipher=? WHERE account_id=?",
                        [$contact, $cipher, $id],
                    );
                    Db::run(
                        $this->db,
                        "UPDATE manual_member_accounts SET state='needs_review' WHERE club_id=? AND contact_id=?",
                        [$club, $onboarding["contact_id"]],
                    );
                }
            }
            Db::run(
                $this->db,
                "UPDATE actors SET contact_id=?,active=? WHERE id=? AND club_id=?",
                [
                    $contact ?: null,
                    (int) $enabled,
                    $row["manager_actor"],
                    $club,
                ],
            );
            Db::run(
                $this->db,
                "UPDATE native_users SET active=?,name=? WHERE id=?",
                [
                    (int) $enabled,
                    $person["name"] ?? $row["name"],
                    $row["native_id"],
                ],
            );
            if ($role !== "" || $defaults) {
                \Kulturhus\Verein\RoleAssignments::write($this->db,$club,$row["manager_actor"],$role,$roleVersion,$defaults);
            }
            if (!$enabled || $row["verified"]) {
                $this->auth->setActive($this->access, $id, $enabled, $version);
            } else {
                Db::run(
                    $this->db,
                    "UPDATE native_auth_accounts SET suspended=0,version=version+1 WHERE id=?",
                    [$id],
                );
            }
            $this->audit($row, "identity.native.assigned");
        });
    }

    public function setSuperuser(
        string $id,
        int $version,
        bool $enabled,
        bool $confirmed,
    ): void {
        Db::atomic($this->db, function () use (
            $id,
            $version,
            $enabled,
            $confirmed,
        ): void {
            if (!$confirmed) {
                throw new DomainException(
                    "Technische Kontoverwaltung ausdrücklich bestätigen.",
                );
            }
            $row = $this->target($id, $version);
            if (
                !$row["verified"] ||
                !$row["active"] ||
                !$row["account_active"] ||
                $row["role"] === "editor"
            ) {
                throw new DomainException(
                    "Aktiven persönlichen Zugang außerhalb der getrennten Redaktions-Grundrolle auswählen.",
                );
            }
            Db::run($this->db, "UPDATE actors SET is_superuser=? WHERE id=?", [
                (int) $enabled,
                $row["manager_actor"],
            ]);
            Db::run(
                $this->db,
                "UPDATE native_auth_accounts SET suspended=0,version=version+1 WHERE id=?",
                [$id],
            );
            $this->audit(
                $row,
                $enabled
                    ? "identity.native.superuser.granted"
                    : "identity.native.superuser.revoked",
            );
        });
    }
}
