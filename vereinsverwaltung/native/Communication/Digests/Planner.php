<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication\Digests;

use PDO;
use Kulturhus\Native\Shared\{Authority, Db};
use Kulturhus\Native\Communication\{Content, Workspace};
use Kulturhus\Native\Cms\PublishedEvents;

/** Prepares one unapproved draft from published originals. Never queues or sends mail. */
final class Planner
{
    public static function run(PDO $db, ?\DateTimeImmutable $now = null, ?string $club = null): array
    {
        $now ??= new \DateTimeImmutable();
        $result = [];
        foreach (
            Db::run(
                $db,
                "SELECT * FROM native_digest_plans WHERE paused=0".($club!==null?' AND club_id=?':'')." ORDER BY club_id,period",
                $club!==null?[$club]:[],
            )->fetchAll()
            as $plan
        ) {
            $lock =
                "digest:" .
                substr(
                    hash("sha256", $plan["club_id"] . ":" . $plan["period"]),
                    0,
                    48,
                );
            if (
                (int) Db::run($db, "SELECT GET_LOCK(?,0)", [
                    $lock,
                ])->fetchColumn() !== 1
            ) {
                continue;
            }
            try {
                $created = Db::atomic($db, function () use (
                    $db,
                    $plan,
                    $now,
                ): ?string {
                    $current = Db::run(
                        $db,
                        "SELECT * FROM native_digest_plans WHERE club_id=? AND period=? FOR UPDATE",
                        [$plan["club_id"], $plan["period"]],
                    )->fetch();
                    if (
                        !$current ||
                        $current["paused"] ||
                        (int) $current["version"] !== (int) $plan["version"]
                    ) {
                        return null;
                    }
                    $access = Authority::resolve($db, [
                        "created_by" => $current["actor_id"],
                        "authority" => $current["authority"],
                        "club_id" => $current["club_id"],
                    ]);
                    $access->require("newsletter", "manage");
                    $access->require("newsletter", "edit");
                    $access->require("website", "read");
                    $schedule = Schedule::at(
                        $current["period"],
                        $now,
                        (int) $current["prepare_days"],
                        (int) $current["send_hour"],
                    );
                    // Preserve existing explicit month/quarter edition keys across the schema upgrade.
                    $key =
                        $current["period"] === "calendar"
                            ? $schedule["period_key"]
                            : preg_replace(
                                "/^(month|quarter)-/",
                                "",
                                $schedule["period_key"],
                            );
                    if (
                        !$schedule["ready"] ||
                        Plans::paused(
                            $db,
                            $current["club_id"],
                            $current["period"],
                            $schedule["period_key"],
                        )
                    ) {
                        return null;
                    }
                    if (
                        Db::run(
                            $db,
                            "SELECT 1 FROM native_digest_editions WHERE club_id=? AND period=? AND period_key=?",
                            [$current["club_id"], $current["period"], $key],
                        )->fetchColumn()
                    ) {
                        return null;
                    }
                    $events = (new PublishedEvents(
                        $db,
                        $current["club_id"],
                    ))->between($schedule["start"], $schedule["end"]);
                    if (
                        $current["period"] === "calendar" &&
                        !$schedule["quarter"]
                    ) {
                        $start = new \DateTimeImmutable($schedule["start"]);
                        $quarter =
                            "quarter-" .
                            $start->format("Y") .
                            "-Q" .
                            (intdiv((int) $start->format("n") - 1, 3) + 1);
                        $events = Announcements::remaining(
                            $db,
                            $current["club_id"],
                            $events,
                            $quarter,
                        );
                    }
                    if (!$events) {
                        return null;
                    }
                    $blocks = [];
                    $refs = [];
                    foreach ($events as $event) {
                        $local =
                            (new \DateTimeImmutable($event["starts_at"]))
                                ->setTimezone(
                                    new \DateTimeZone("Europe/Berlin"),
                                )
                                ->format("d.m.Y H:i") . " Uhr";
                        $block = Content::blocks([
                            [
                                "type" => "event",
                                "title" => $event["title"],
                                "date" => $local,
                                "venue" => $event["location"],
                                "url" => rtrim(\Kulturhus\Native\Shared\Identity::forClub($db,$current["club_id"])["origin"],"/")."/".rawurlencode($event["slug"])."/",
                                "text" => mb_strcut(
                                    $event["description"],
                                    0,
                                    5000,
                                    "UTF-8",
                                ),
                            ],
                        ])[0];
                        if(!empty($event['media_ids']))$blocks[]=Content::blocks([['type'=>'image','media_ref'=>$event['media_ids'][0],'alt'=>$event['title']]])[0];
                        $blocks[] = $block;
                        $refs[] = [
                            "id" => $event["id"],
                            "revision" => $event["revision"],
                            "starts_at" => $event["starts_at"],
                            "slug" => $event["slug"],
                            "block_hash" => Announcements::blockHash($block),
                        ];
                    }
                    $item = (new Workspace($db, $access, "newsletter"))->save([
                        "title" =>
                            ($schedule["quarter"]
                                ? "Quartalsausgabe "
                                : "Monatsausgabe ") . $key,
                        "body" => "Die veröffentlichten Termine im Überblick.",
                        "blocks" => $blocks,
                        "audience" => "events",
                    ]);
                    Db::run(
                        $db,
                        "INSERT INTO native_digest_editions(club_id,period,period_key,item_id,schedule_json,events_json) VALUES(?,?,?,?,?,?)",
                        [
                            $current["club_id"],
                            $current["period"],
                            $key,
                            $item["id"],
                            $current["period"] === "calendar"
                                ? json_encode($schedule, JSON_THROW_ON_ERROR)
                                : null,
                            $current["period"] === "calendar"
                                ? json_encode($refs, JSON_THROW_ON_ERROR)
                                : null,
                        ],
                    );
                    return $item["id"];
                });
                if ($created !== null) {
                    $result[] = $created;
                }
            } catch (\DomainException) {
                /* Live revocation leaves the original plan unexecuted. */
            } finally {
                Db::run($db, "SELECT RELEASE_LOCK(?)", [$lock]);
            }
        }
        return $result;
    }
}
