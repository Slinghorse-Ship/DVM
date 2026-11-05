<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration;

use PDO;
use DomainException;
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Verein\{Permissions, BoardAccess};

/** Constrained worker authority for exactly one approved personal member request. */
final class MemberInvitationAccess extends Access
{
    public function __construct(
        private PDO $connection,
        private string $managerClub,
        private string $contact,
    ) {
        parent::__construct($connection, "");
    }

    public function require(string $module, string $capability): array
    {
        if ($module !== "accounts" || $capability !== "manage") {
            throw new DomainException(
                "Nur persönliche Mitgliedseinladungen erlaubt.",
            );
        }
        $lock = $this->connection->inTransaction() ? " FOR SHARE" : "";
        $row = Db::run(
            $this->connection,
            "SELECT * FROM manual_member_accounts WHERE club_id=? AND contact_id=?" .
                $lock,
            [$this->managerClub, $this->contact],
        )->fetch();
        if (!$row) {
            throw new DomainException("Gespeicherter Zugangsauftrag fehlt.");
        }
        $actor = Permissions::actor($this->connection, $row["requested_by"]);
        if ($actor["club_id"] !== $this->managerClub) {
            throw new DomainException("Vereinsbindung geändert.");
        }
        if ($actor["members_access"] !== "write") {
            BoardAccess::require($this->connection, $actor["id"]);
            if (
                !$row["application_id"] ||
                !Db::run(
                    $this->connection,
                    "SELECT 1 FROM membership_applications WHERE club_id=? AND id=? AND contact_id=? AND reviewed_by=? AND status='accepted'" .
                        $lock,
                    [
                        $this->managerClub,
                        $row["application_id"],
                        $this->contact,
                        $actor["id"],
                    ],
                )->fetchColumn()
            ) {
                throw new DomainException(
                    "Eigene bestätigte Aufnahme erforderlich.",
                );
            }
        }
        $target = Db::run(
            $this->connection,
            "SELECT a.id FROM actors a JOIN native_manager_identities i ON i.manager_actor_id=a.id AND i.manager_club_id=a.club_id WHERE a.club_id=? AND a.contact_id=? AND a.active=1 AND a.role='member' AND a.is_superuser=0 AND NOT EXISTS(SELECT 1 FROM settings_role_assignments r WHERE r.club_id=a.club_id AND r.actor_id=a.id)" .
                $lock,
            [$this->managerClub, $this->contact],
        )->fetchAll();
        if (count($target) !== 1) {
            throw new DomainException(
                "Persönliche Mitgliederbindung vor Einladung prüfen.",
            );
        }
        return [
            "id" => ManagerBridge::actorKey($actor["id"], $this->managerClub),
            "club_id" => ManagerBridge::clubKey($this->managerClub),
            "name" => $actor["id"],
            "active" => 1,
        ];
    }
    public function assertTarget(string $native): void
    {
        if (
            !Db::run(
                $this->connection,
                "SELECT 1 FROM native_manager_identities i JOIN actors a ON a.id=i.manager_actor_id AND a.club_id=i.manager_club_id WHERE i.native_id=? AND a.club_id=? AND a.contact_id=? AND a.role='member' AND a.is_superuser=0 AND a.active=1" .
                    ($this->connection->inTransaction() ? " FOR SHARE" : ""),
                [$native, $this->managerClub, $this->contact],
            )->fetchColumn()
        ) {
            throw new DomainException(
                "Einladung gehört zu einem anderen Mitgliederprofil.",
            );
        }
    }
}
