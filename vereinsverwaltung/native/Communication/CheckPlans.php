<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use PDO;
use Kulturhus\Native\Shared\{Access, Authority, Db};

/** Durable due-time claims. Recovery does not replay a missed check in a burst. */
final class CheckPlans
{
    public function __construct(private PDO $db) {}

    private static function lastSlot(string $channel,array|false $row):?string
    {
        if(!$row)return null;$slot=$row['last_slot']??null;
        if($channel!=='stadtlandfunk'||is_string($slot)&&preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}:hour-[0-9]{2}\z/D',$slot))return $slot;
        return is_string($row['last_checked']??null)?CheckSchedule::hourKey(new \DateTimeImmutable($row['last_checked'],new \DateTimeZone('UTC'))):null;
    }

    public function status(Access $access, string $channel): array
    {
        $access->require("social", "manage");
        $default = CheckSchedule::config($channel, []);
        $row = Db::run(
            $this->db,
            "SELECT * FROM native_social_check_plans WHERE club_id=? AND channel=?",
            [$access->club(), $channel],
        )->fetch();
        if (!$row) {
            return [
                "version" => 0,
                "enabled" => false,
                "config" => $default,
                "next_at" => null,
                "last_checked" => null,
                "state" => "paused",
            ];
        }
        return [
            "version" => (int) $row["version"],
            "enabled" => (bool) $row["enabled"],
            "config" => CheckSchedule::stored($channel,json_decode($row["config_json"],true,32,JSON_THROW_ON_ERROR)),
            "next_at" => $row["next_at"],
            "last_checked" => $row["last_checked"],
            "state" => $row["state"],
        ];
    }

    public function save(
        Access $access,
        string $channel,
        array $config,
        bool $enabled,
        int $version,
        ?\DateTimeImmutable $now = null,
    ): void {
        $access->require("social", "manage");
        $normalized = CheckSchedule::config($channel, $config);
        $now ??= new \DateTimeImmutable();
        Db::atomic($this->db, function () use (
            $access,
            $channel,
            $normalized,
            $enabled,
            $version,
            $now,
        ) {
            $u = $access->require("social", "manage");
            $row = Db::run(
                $this->db,
                "SELECT version,offsets_json,last_slot,last_checked FROM native_social_check_plans WHERE club_id=? AND channel=? FOR UPDATE",
                [$u["club_id"], $channel],
            )->fetch();
            if (($row ? (int) $row["version"] : 0) !== $version) {
                throw new \DomainException(
                    "Abrufplan inzwischen geändert. Bitte neu laden.",
                );
            }
            $next = $enabled
                ? CheckSchedule::next(
                    $channel,
                    $normalized,
                    $now,
                    $row
                        ? json_decode(
                            $row["offsets_json"],
                            true,
                            32,
                            JSON_THROW_ON_ERROR,
                        )
                        : [],
                    self::lastSlot($channel,$row),
                )
                : null;
            Db::run(
                $this->db,
                "INSERT INTO native_social_check_plans(club_id,channel,actor_id,authority,version,enabled,config_json,next_at,offsets_json,state) VALUES(?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE actor_id=VALUES(actor_id),authority=VALUES(authority),version=VALUES(version),enabled=VALUES(enabled),config_json=VALUES(config_json),next_at=VALUES(next_at),offsets_json=VALUES(offsets_json),state=VALUES(state)",
                [
                    $u["club_id"],
                    $channel,
                    $u["id"],
                    $access instanceof
                    \Kulturhus\Native\Integration\ManagerAccess
                        ? "manager"
                        : "native",
                    $version + 1,
                    (int) $enabled,
                    json_encode($normalized, JSON_THROW_ON_ERROR),
                    $next["at"] ?? null,
                    json_encode($next["offsets"] ?? [], JSON_THROW_ON_ERROR),
                    $enabled ? "scheduled" : "paused",
                ],
            );
        });
    }

    public function run(
        string $club,
        callable $check,
        ?\DateTimeImmutable $now = null,
    ): array {
        $now ??= new \DateTimeImmutable();
        $stamp = $now
            ->setTimezone(new \DateTimeZone("UTC"))
            ->format("Y-m-d H:i:s");
        $result = [];
        foreach (
            Db::run(
                $this->db,
                "SELECT channel FROM native_social_check_plans WHERE club_id=? AND enabled=1 AND (next_at<=? OR (channel='stadtlandfunk' AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(offsets_json,'$.hourly')),'0')<>'1')) ORDER BY channel",
                [$club, $stamp],
            )->fetchAll(PDO::FETCH_COLUMN)
            as $channel
        ) {
            $lock =
                "social-check:" .
                substr(hash("sha256", $club . ":" . $channel), 0, 48);
            if (
                (int) Db::run($this->db, "SELECT GET_LOCK(?,0)", [
                    $lock,
                ])->fetchColumn() !== 1
            ) {
                continue;
            }
            $claim = null;
            try {
                $claim = Db::atomic($this->db, function () use (
                    $club,
                    $channel,
                    $now,
                    $stamp,
                ) {
                    $row = Db::run(
                        $this->db,
                        "SELECT * FROM native_social_check_plans WHERE club_id=? AND channel=? FOR UPDATE",
                        [$club, $channel],
                    )->fetch();
                    if (!$row || !$row["enabled"]) {
                        return null;
                    }
                    $access = Authority::resolve($this->db, [
                        "created_by" => $row["actor_id"],
                        "authority" => $row["authority"],
                        "club_id" => $club,
                    ]);
                    $access->require("social", "manage");
                    if ($access->club() !== $club) {
                        throw new \DomainException(
                            "Vereinszuordnung geändert.",
                        );
                    }
                    $config = CheckSchedule::stored($channel,json_decode($row['config_json'],true,32,JSON_THROW_ON_ERROR));
                    $offsets = json_decode(
                        $row["offsets_json"],
                        true,
                        32,
                        JSON_THROW_ON_ERROR,
                    );
                    if($row['next_at']>$stamp){
                        if($channel!=='stadtlandfunk'||($offsets['hourly']??null)===1)return null;
                        $next=CheckSchedule::next($channel,$config,$now,$offsets,self::lastSlot($channel,$row));
                        $cooldown=$row['state']==='cooldown';$at=$cooldown&&$row['next_at']>$next['at']?$row['next_at']:$next['at'];
                        Db::run($this->db,'UPDATE native_social_check_plans SET next_at=?,offsets_json=?,state=? WHERE club_id=? AND channel=?',[$at,json_encode($next['offsets'],JSON_THROW_ON_ERROR),$cooldown?'cooldown':'scheduled',$club,$channel]);
                        return null;
                    }
                    $completed = null;
                    if ($channel === "stadtlandfunk") {
                        $completed=CheckSchedule::currentSlot($config,$now);
                        if (
                            $completed === null ||
                            $completed === self::lastSlot($channel,$row)
                        ) {
                            $next = CheckSchedule::next(
                                $channel,
                                $config,
                                $now,
                                $offsets,
                                self::lastSlot($channel,$row),
                            );
                            Db::run(
                                $this->db,
                                "UPDATE native_social_check_plans SET next_at=?,offsets_json=?,state='scheduled' WHERE club_id=? AND channel=?",
                                [
                                    $next["at"],
                                    json_encode(
                                        $next["offsets"],
                                        JSON_THROW_ON_ERROR,
                                    ),
                                    $club,
                                    $channel,
                                ],
                            );
                            return null;
                        }
                    }
                    $next = CheckSchedule::next(
                        $channel,
                        $config,
                        $now,
                        $offsets,
                        $completed,
                    );
                    Db::run(
                        $this->db,
                        "UPDATE native_social_check_plans SET next_at=?,offsets_json=?,last_slot=?,state='checking' WHERE club_id=? AND channel=?",
                        [
                            $next["at"],
                            json_encode($next["offsets"], JSON_THROW_ON_ERROR),
                            $completed,
                            $club,
                            $channel,
                        ],
                    );
                    return [
                        "access" => $access,
                        "version" => (int) $row["version"],
                    ];
                });
                if (!$claim) {
                    continue;
                }
                $complete = $check($claim["access"], $channel) !== false;
                $claim["access"]->require("social", "manage");
                $state = $complete ? "checked" : "needs_review";
                Db::run(
                    $this->db,
                    "UPDATE native_social_check_plans SET state=?,last_checked=? WHERE club_id=? AND channel=? AND version=? AND enabled=1",
                    [$state, $stamp, $club, $channel, $claim["version"]],
                );
                $result[$channel] = $state;
            } catch (\Kulturhus\Native\Integration\Cooldown $cooldown) {
                $earliest = $now
                    ->setTimezone(new \DateTimeZone("UTC"))
                    ->modify("+" . $cooldown->seconds . " seconds")
                    ->format("Y-m-d H:i:s");
                Db::run(
                    $this->db,
                    "UPDATE native_social_check_plans SET state='cooldown',next_at=GREATEST(next_at,?) WHERE club_id=? AND channel=? AND version=? AND enabled=1",
                    [$earliest, $club, $channel, $claim["version"] ?? -1],
                );
                $result[$channel] = "cooldown";
            } catch (\Throwable) {
                Db::run(
                    $this->db,
                    $claim
                        ? "UPDATE native_social_check_plans SET state='needs_review' WHERE club_id=? AND channel=? AND enabled=1 AND version=?"
                        : "UPDATE native_social_check_plans SET state='needs_review',enabled=0,next_at=NULL,version=version+1 WHERE club_id=? AND channel=? AND enabled=1 AND next_at<=?",
                    [$club, $channel, $claim["version"] ?? $stamp],
                );
                $result[$channel] = "needs_review";
            } finally {
                Db::run($this->db, "SELECT RELEASE_LOCK(?)", [$lock]);
            }
        }
        return $result;
    }
}
