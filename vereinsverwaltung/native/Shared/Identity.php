<?php declare(strict_types=1);
namespace Kulturhus\Native\Shared;
use PDO;
use Kulturhus\Native\Communication\Content;
use Kulturhus\Native\Integration\ManagerClub;

/** Current branding reads common manager data; issued originals keep their snapshots. */
final class Identity
{
    public function __construct(private PDO $db, private Access $access) {}

    public function get(): array
    {
        return self::forClub($this->db, $this->access->club());
    }

    public static function forClub(PDO $db, string $club): array
    {
        $identity = self::stored($db, $club);
        $profile = ManagerClub::profile($db, $club);
        if ($profile && $profile["configured"]) {
            $identity["name"] = $profile["name"];
            $identity["address"] = $profile["address"];
        }
        return $identity;
    }

    /** Settings-only information, excluded from public identity and approval snapshots. */
    public function source(): ?array
    {
        $this->access->require("website", "manage");
        $club = $this->access->club();
        $profile = ManagerClub::profile($this->db, $club);
        if (!$profile) {
            return null;
        }
        $old = self::stored($this->db, $club);
        $profile["previous"] =
            $profile["configured"] &&
            $old["name"] !== "" &&
            ($old["name"] !== $profile["name"] ||
                $old["address"] !== $profile["address"])
                ? ["name" => $old["name"], "address" => $old["address"]]
                : null;
        return $profile;
    }

    public function save(array $data, int $version): array
    {
        $this->access->require("website", "manage");
        $origin = Content::url($data["origin"] ?? "");
        $parts = parse_url($origin);
        if (
            isset($parts["port"]) ||
            isset($parts["query"]) ||
            isset($parts["fragment"]) ||
            !in_array($parts["path"] ?? "", ["", "/"], true)
        ) {
            throw new \DomainException(
                "Domain als HTTPS-Origin ohne Pfad angeben.",
            );
        }
        $logo = $data["logo_id"] ?? "";
        if ($logo !== "") {
            Content::id($logo);
        }
        return Db::atomic($this->db, function () use (
            $data,
            $version,
            $origin,
            $logo,
        ): array {
            $actor = $this->access->require("website", "manage");
            $club = $actor["club_id"];
            $old = self::stored($this->db, $club, true);
            if ($old["version"] !== $version) {
                throw new \DomainException("Vereinsdaten inzwischen geändert.");
            }
            $profile = ManagerClub::profile($this->db, $club);
            if ($profile !== null) {
                if (
                    !isset($data["club_version"]) ||
                    (string) $data["club_version"] !==
                        (string) $profile["version"]
                ) {
                    throw new \DomainException(
                        "Gemeinsame Vereinsdaten inzwischen geändert. Bitte Formular neu laden.",
                    );
                }
                // Retain earlier native values for review. A forged POST cannot overwrite
                // the common manager profile or previously issued document snapshots.
                $name = $old["name"];
                $address = $old["address"];
            } else {
                $name = Content::text($data["name"] ?? "", 200, true);
                $address = Content::text($data["address"] ?? "", 1000);
            }
            if (
                $logo !== "" &&
                !Db::run(
                    $this->db,
                    "SELECT 1 FROM khcms_media WHERE club_id=? AND id=? FOR SHARE",
                    [$club, $logo],
                )->fetchColumn()
            ) {
                throw new \DomainException("Logo nicht verfügbar.");
            }
            if ($logo !== '') \Kulturhus\Native\Cms\MediaAvailability::assertSelectable($this->db,$club,$logo);
            $payload = [
                "name" => $name,
                "origin" => rtrim($origin, "/"),
                "logo_id" => $logo,
                "address" => $address,
            ];
            Db::run(
                $this->db,
                "INSERT INTO native_club_settings(club_id,version,payload,updated_by,updated_at) VALUES(?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE version=VALUES(version),payload=VALUES(payload),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)",
                [
                    $club,
                    $version + 1,
                    json_encode($payload, JSON_THROW_ON_ERROR),
                    $actor["id"],
                ],
            );
            return self::forClub($this->db, $club);
        });
    }

    private static function stored(
        PDO $db,
        string $club,
        bool $lock = false,
    ): array {
        // The CMS can run without the optional communication/runtime module.
        // Reading a public page must neither install that module nor query its missing table.
        $exists = Db::run($db, 'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=\'native_club_settings\'')->fetchColumn();
        $row = $exists ? Db::run(
            $db,
            "SELECT version,payload FROM native_club_settings WHERE club_id=?" .
                ($lock ? " FOR UPDATE" : ""),
            [$club],
        )->fetch() : false;
        return $row
            ? json_decode($row["payload"], true, 32, JSON_THROW_ON_ERROR) + [
                    "version" => (int) $row["version"],
                ]
            : [
                "name" => "",
                "origin" => "",
                "logo_id" => "",
                "address" => "",
                "version" => 0,
            ];
    }
}
