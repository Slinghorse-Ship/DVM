<?php
declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use DomainException;
use Kulturhus\Native\Shared\{Access, Db};
use PDO;

/** Website policy has its own revisions; branding and approved social artwork stay unchanged. */
final class WebsiteSettings
{
    use ClubPublicationLock;
    public function __construct(
        private readonly PDO $db,
        private readonly Access $access,
    ) {}

    public static function install(PDO $db): void
    {
        foreach (
            [
                "CREATE TABLE IF NOT EXISTS khcms_website_settings (club_id VARCHAR(64) PRIMARY KEY, version INT UNSIGNED NOT NULL, indexing_enabled TINYINT UNSIGNED NOT NULL, updated_by VARCHAR(64) NOT NULL, updated_at DATETIME(6) NOT NULL)",
                "CREATE TABLE IF NOT EXISTS khcms_website_settings_history (club_id VARCHAR(64) NOT NULL, version INT UNSIGNED NOT NULL, indexing_enabled TINYINT UNSIGNED NOT NULL, actor_id VARCHAR(64) NOT NULL, created_at DATETIME(6) NOT NULL, PRIMARY KEY (club_id,version))",
            ]
            as $sql
        ) {
            $db->exec(
                $sql .
                    " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            );
        }
    }

    public function get(): array
    {
        $this->access->require("website", "read");
        return self::forClub($this->db, $this->access->club());
    }

    /** Internal public projection; the caller supplies its already-validated club key. */
    public static function forClub(PDO $db, string $club): array
    {
        $row = Db::run(
            $db,
            "SELECT version,indexing_enabled,updated_by,updated_at FROM khcms_website_settings WHERE club_id=?",
            [$club],
        )->fetch(PDO::FETCH_ASSOC);
        return $row
            ? [
                "version" => (int) $row["version"],
                "enabled" => (int) $row["indexing_enabled"] === 1,
                "updated_by" => $row["updated_by"],
                "updated_at" => $row["updated_at"],
            ]
            : [
                "version" => 0,
                "enabled" => false,
                "updated_by" => null,
                "updated_at" => null,
            ];
    }

    public function save(mixed $enabled, int $version): array
    {
        $this->access->require("website", "manage");
        if (!is_bool($enabled) || $version < 0 || $version >= 4294967295) {
            throw new DomainException(
                "Website-Einstellung als Ja/Nein mit gültiger Version angeben.",
            );
        }
        return Db::atomic($this->db, function () use (
            $enabled,
            $version,
        ): array {
            $actor = $this->access->require("website", "manage");
            $this->lockClub();
            $club = (string) $actor["club_id"];
            if (self::forClub($this->db, $club)["version"] !== $version) {
                throw new Conflict(
                    "Website-Einstellungen inzwischen geändert. Bitte Formular neu laden.",
                );
            }
            Db::run(
                $this->db,
                "INSERT INTO khcms_website_settings(club_id,version,indexing_enabled,updated_by,updated_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE version=VALUES(version),indexing_enabled=VALUES(indexing_enabled),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)",
                [$club, $version + 1, (int) $enabled, $actor["id"]],
            );
            Db::run(
                $this->db,
                "INSERT INTO khcms_website_settings_history(club_id,version,indexing_enabled,actor_id,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6))",
                [$club, $version + 1, (int) $enabled, $actor["id"]],
            );
            Db::run(
                $this->db,
                "UPDATE khcms_state SET generation=generation+1 WHERE club_id=?",
                [$club],
            );
            return self::forClub($this->db, $club);
        });
    }

    public function history(): array
    {
        $this->access->require("website", "manage");
        return Db::run(
            $this->db,
            "SELECT version,indexing_enabled,actor_id,created_at FROM khcms_website_settings_history WHERE club_id=? ORDER BY version DESC LIMIT 20",
            [$this->access->club()],
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}
