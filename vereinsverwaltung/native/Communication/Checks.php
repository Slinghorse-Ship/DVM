<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use PDO;
use Kulturhus\Native\Shared\{Access, Db, SecretBox};

/** Provider reads only. Account passwords/tokens, comment text and messages never enter run logs. */
final class Checks
{
    public static function run(PDO $db, SecretBox $box, string $club): array
    {
        return (new CheckPlans($db))->run($club, static function (
            Access $access,
            string $channel,
        ) use ($db, $box): bool {
            if ($channel === "stadtlandfunk") {
                (new PersonalActivity($db, $box))->sync($access);
                return true;
            }
            $hub = new SocialHub($db, $box);
            $result = $hub->sync($access);
            $complete = !array_filter(
                $result,
                static fn($r) => $r["status"] !== "available",
            );
            $account = (new \Kulturhus\Native\Integration\Accounts(
                $db,
                $box,
            ))->get($access->club(), "meta");
            foreach (
                $account["instagram_id"] !== ""
                    ? ["facebook", "instagram"]
                    : ["facebook"]
                as $source
            ) {
                try {
                    $hub->syncInbox($access, $source);
                } catch (\Kulturhus\Native\Integration\Cooldown $pause) {
                    throw $pause;
                } catch (\DomainException) {
                    $complete = false;
                }
            }
            $objects = Db::run(
                $db,
                "SELECT id FROM native_social_objects WHERE club_id=? AND channel IN ('facebook','instagram') AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.connection_id'))=? ORDER BY last_seen DESC,id LIMIT 200",
                [
                    $access->club(),
                    \Kulturhus\Native\Integration\Accounts::binding($account),
                ],
            )->fetchAll(PDO::FETCH_COLUMN);
            foreach ($objects as $id) {
                try {
                    $hub->comments($access, $id);
                } catch (\Kulturhus\Native\Integration\Cooldown $pause) {
                    throw $pause;
                } catch (\DomainException) {
                    $complete = false;
                }
            }
            if (
                (int) Db::run(
                    $db,
                    "SELECT COUNT(*) FROM native_social_objects WHERE club_id=? AND channel IN ('facebook','instagram') AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.connection_id'))=?",
                    [
                        $access->club(),
                        \Kulturhus\Native\Integration\Accounts::binding(
                            $account,
                        ),
                    ],
                )->fetchColumn() > 200
            ) {
                $complete = false;
            }
            return $complete;
        });
    }
}
