<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication\Digests;

use PDO;
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Native\Integration\ManagerAccess;

/** Plans and single-period pauses have independent optimistic versions. */
final class Plans
{
    public function __construct(private PDO $db, private Access $access) {}

    public function state(string $mode): array
    {
        Schedule::mode($mode);
        $actor = $this->access->require("newsletter", "manage");
        $row = Db::run(
            $this->db,
            "SELECT paused,version,prepare_days,send_hour FROM native_digest_plans WHERE club_id=? AND period=?",
            [$actor["club_id"], $mode],
        )->fetch();
        return [
            "configured" => (bool) $row,
            "paused" => $row ? (bool) $row["paused"] : false,
            "version" => $row ? (int) $row["version"] : 0,
            "prepare_days" => $row ? (int) $row["prepare_days"] : 7,
            "send_hour" => $row ? (int) $row["send_hour"] : 10,
        ];
    }

    public function save(
        string $mode,
        bool $paused,
        int $version,
        int $prepareDays = 7,
        int $sendHour = 10,
    ): void {
        Schedule::at($mode, new \DateTimeImmutable(), $prepareDays, $sendHour);
        if ($version < 0) {
            throw new \DomainException(
                "Aktuelle Planungsversion erforderlich.",
            );
        }
        Db::atomic($this->db, function () use (
            $mode,
            $paused,
            $version,
            $prepareDays,
            $sendHour,
        ): void {
            $actor = $this->access->require("newsletter", "manage");
            $this->access->require("website", "read");
            $rows = Db::run(
                $this->db,
                "SELECT period,version,paused FROM native_digest_plans WHERE club_id=? ORDER BY period FOR UPDATE",
                [$actor["club_id"]],
            )->fetchAll();
            $old = array_column($rows, null, "period")[$mode] ?? null;
            if (($old ? (int) $old["version"] : 0) !== $version) {
                throw new \DomainException(
                    "Periodenplanung inzwischen geändert. Bitte neu laden.",
                );
            }
            if (!$paused) {
                foreach ($rows as $row) {
                    if (
                        !$row["paused"] &&
                        $row["period"] !== $mode &&
                        ($mode === "calendar" || $row["period"] === "calendar")
                    ) {
                        throw new \DomainException(
                            "Zuerst den anderen Periodenmodus pausieren, damit keine doppelten Ausgaben vorbereitet werden.",
                        );
                    }
                };
            }
            Db::run(
                $this->db,
                "INSERT INTO native_digest_plans(club_id,period,actor_id,authority,paused,version,prepare_days,send_hour) VALUES(?,?,?,?,?,1,?,?) ON DUPLICATE KEY UPDATE actor_id=VALUES(actor_id),authority=VALUES(authority),paused=VALUES(paused),version=version+1,prepare_days=VALUES(prepare_days),send_hour=VALUES(send_hour)",
                [
                    $actor["club_id"],
                    $mode,
                    $actor["id"],
                    $this->access instanceof ManagerAccess
                        ? "manager"
                        : "native",
                    (int) $paused,
                    $prepareDays,
                    $sendHour,
                ],
            );
        });
    }

    public function period(string $mode, string $key): array
    {
        Schedule::mode($mode);
        Schedule::key($key);
        $actor = $this->access->require("newsletter", "manage");
        $row = Db::run(
            $this->db,
            "SELECT paused,version FROM native_digest_periods WHERE club_id=? AND period=? AND period_key=?",
            [$actor["club_id"], $mode, $key],
        )->fetch();
        return [
            "paused" => $row ? (bool) $row["paused"] : false,
            "version" => $row ? (int) $row["version"] : 0,
        ];
    }

    public function pause(
        string $mode,
        string $key,
        bool $paused,
        int $version,
    ): void {
        Schedule::mode($mode);
        Schedule::key($key);
        Db::atomic($this->db, function () use (
            $mode,
            $key,
            $paused,
            $version,
        ): void {
            $actor = $this->access->require("newsletter", "manage");
            $old = Db::run(
                $this->db,
                "SELECT version FROM native_digest_periods WHERE club_id=? AND period=? AND period_key=? FOR UPDATE",
                [$actor["club_id"], $mode, $key],
            )->fetch();
            if (($old ? (int) $old["version"] : 0) !== $version) {
                throw new \DomainException(
                    "Periodenpause inzwischen geändert. Bitte neu laden.",
                );
            }
            Db::run(
                $this->db,
                "INSERT INTO native_digest_periods(club_id,period,period_key,paused,version,updated_by,updated_at) VALUES(?,?,?,?,1,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE paused=VALUES(paused),version=version+1,updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)",
                [$actor["club_id"], $mode, $key, (int) $paused, $actor["id"]],
            );
        });
    }

    public static function paused(
        PDO $db,
        string $club,
        string $mode,
        string $key,
    ): bool {
        $lock = $db->inTransaction() ? " FOR SHARE" : "";
        return (bool) Db::run(
            $db,
            "SELECT paused FROM native_digest_periods WHERE club_id=? AND period=? AND period_key=?" .
                $lock,
            [$club, $mode, $key],
        )->fetchColumn();
    }

    public static function edition(PDO $db, string $club, string $item): ?array
    {
        $row = Db::run(
            $db,
            "SELECT period,period_key,schedule_json,events_json FROM native_digest_editions WHERE club_id=? AND item_id=?" .
                ($db->inTransaction() ? " FOR SHARE" : ""),
            [$club, $item],
        )->fetch();
        if (!$row || $row["schedule_json"] === null) {
            return null;
        }
        return [
            "period" => $row["period"],
            "period_key" => $row["period_key"],
            "item_id" => $item,
            "schedule" => json_decode(
                $row["schedule_json"],
                true,
                16,
                JSON_THROW_ON_ERROR,
            ),
            "events" => json_decode(
                $row["events_json"],
                true,
                32,
                JSON_THROW_ON_ERROR,
            ),
        ];
    }

    public static function guard(
        PDO $db,
        string $club,
        array $edition,
        ?\DateTimeImmutable $now = null,
    ): void {
        Schedule::mode($edition["period"]);
        Schedule::key($edition["period_key"]);
        $plan = Db::run(
            $db,
            "SELECT paused FROM native_digest_plans WHERE club_id=? AND period=?" .
                ($db->inTransaction() ? " FOR SHARE" : ""),
            [$club, $edition["period"]],
        )->fetch();
        if (
            !$plan ||
            $plan["paused"] ||
            self::paused($db, $club, $edition["period"], $edition["period_key"])
        ) {
            throw new Paused("Diese Newsletterperiode ist pausiert.");
        }
        if ($edition["period"] === "calendar") {
            $now ??= new \DateTimeImmutable();
            if (
                $now > new \DateTimeImmutable($edition["schedule"]["deadline"])
            ) {
                throw new Expired(
                    "Versandfenster dieser Newsletterperiode ist abgelaufen.",
                );
            }
        }
    }
}
