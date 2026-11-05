<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration;

use PDO;
use Kulturhus\Native\Shared\Db;

/** Read-only link to the manager's club. Standalone native clubs have no link. */
final class ManagerClub
{
    public static function id(PDO $db, string $nativeClub): ?string
    {
        if (!self::hasTable($db, "native_manager_identities")) {
            return null;
        }
        $rows = Db::run(
            $db,
            "SELECT DISTINCT manager_club_id FROM native_manager_identities WHERE SHA2(CONCAT('manager-club',CHAR(0),manager_club_id),256)=?" .
                ($db->inTransaction() ? " FOR SHARE" : ""),
            [$nativeClub],
        )->fetchAll(PDO::FETCH_COLUMN);
        if (!$rows) {
            return null;
        }
        if (
            count($rows) !== 1 ||
            !hash_equals(ManagerBridge::clubKey($rows[0]), $nativeClub)
        ) {
            throw new \DomainException("Vereinszuordnung nicht eindeutig.");
        }
        return $rows[0];
    }

    public static function profile(PDO $db, string $nativeClub): ?array
    {
        $club = self::id($db, $nativeClub);
        if ($club === null || !self::hasTable($db, "club_settings")) {
            return null;
        }
        $row = Db::run(
            $db,
            "SELECT version,payload FROM club_settings WHERE club_id=? AND section='club'" .
                ($db->inTransaction() ? " FOR SHARE" : ""),
            [$club],
        )->fetch();
        if (!$row) {
            return ["club_id" => $club, "configured" => false, "version" => 0];
        }
        $data = json_decode($row["payload"], true, 32, JSON_THROW_ON_ERROR);
        $lines = array_filter(
            [
                trim((string) ($data["street"] ?? "")),
                trim((string) ($data["address2"] ?? "")),
                trim(($data["postcode"] ?? "") . " " . ($data["city"] ?? "")),
            ],
            static fn(string $line): bool => $line !== "",
        );
        return [
            "club_id" => $club,
            "configured" => true,
            "version" => (int) $row["version"],
            "name" => (string) ($data["name"] ?? ""),
            "address" => implode("\n", $lines),
        ];
    }

    private static function hasTable(PDO $db, string $table): bool
    {
        return (bool) Db::run(
            $db,
            "SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?",
            [$table],
        )->fetchColumn();
    }
}
