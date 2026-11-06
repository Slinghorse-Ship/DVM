<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use PDO;
use Kulturhus\Native\Shared\{Access, Db, SecretBox};
use Kulturhus\Native\Integration\StadtLandFunk\{Connection, OwnPostsPage, Sender};

/** Reads the personal app's own events/comments, without a paid partner key or browser. */
final class PersonalActivity
{
    public function __construct(
        private PDO $db,
        private SecretBox $box,
        private ?Connection $connection = null,
    ) {
        $this->connection ??= new Connection($db, $box);
    }

    private static function stamp(mixed $millis): ?string
    {
        if ($millis === null) {
            return null;
        }
        if (!is_int($millis) || $millis < 0 || $millis > 4102444800000) {
            throw new \DomainException(
                "Persönliche Beitragszeit nicht bestätigt.",
            );
        }
        return gmdate("Y-m-d H:i:s", intdiv($millis, 1000));
    }

    public function sync(Access $access): array
    {
        $access->require("social", "manage");
        $club = $access->club();
        $identity = $this->connection->identity($access);
        $events = [];
        $seen = [];
        $pages = 1;
        $sequence = null;
        for ($page = 0; $page < $pages; $page++) {
            $data = $this->connection->read(
                $access,
                Sender::postsPath($identity, $page),
            );
            $data = OwnPostsPage::read($data, $page);
            if ($page === 0) {
                $pages = max(1, $data['total_pages']);
                $sequence = OwnPostsPage::sequence($data);
            } elseif (OwnPostsPage::sequence($data) !== $sequence) {
                throw new \DomainException(
                    "Persönliche Seitenfolge inzwischen geändert.",
                );
            }
            foreach ($data["content"] as $row) {
                $remote = SocialHub::remoteId($row["id"] ?? null);
                $type = $row['type'] ?? 'HAPPENING';
                $event = match ($type) { 'HAPPENING'=>$row['happening']??null, 'GOSSIP'=>$row['gossip']??null, 'NEWS_ITEM'=>$row['newsItem']??null, default=>null };
                if (
                    isset($seen[$remote]) ||
                    !is_array($event) ||
                    !Sender::owns($event, $identity)
                ) {
                    throw new \DomainException(
                        "Eigene Veranstaltung oder Seitenfolge nicht bestätigt.",
                    );
                }
                $seen[$remote] = true;
                $title = Content::text($event["title"] ?? ($type==='HAPPENING'?'':'Beitrag'), 300, true);
                $events[] = [
                    "id" => substr(
                        hash("sha256", $club . ":stadtlandfunk:" . $remote),
                        0,
                        32,
                    ),
                    "remote_id" => $remote,
                    "kind" => $type==='HAPPENING' ? 'event' : 'post',
                    "caption" =>
                        $title .
                        "\n\n" .
                        Content::text($event["text"] ?? "", 100000),
                    "title" => $title,
                    "created_at" => self::stamp($event["created"] ?? null),
                    "starts_at" => self::stamp($event["startTime"] ?? null),
                    "ends_at" => self::stamp($event["endTime"] ?? null),
                    "permalink" =>
                        "https://www.stadtlandfunk.app/post-detail?" .
                        http_build_query(["id" => $remote]),
                    "thumbnail" => "",
                    "like_count" => is_int($event["likeCount"] ?? null)
                        ? $event["likeCount"]
                        : null,
                    "comments_count" => is_int($event["commentCount"] ?? null)
                        ? $event["commentCount"]
                        : null,
                    "connection_id" => $identity["connection_id"],
                    "account_id" => $identity["account_id"],
                    "home_area_id" => $identity["home_area_id"],
                ] + (!empty($identity['organization_id']) ? ['organization_id'=>$identity['organization_id']] : []);
            }
        }
        $comments = [];
        foreach ($events as $event) {
            $rows = $this->connection->read(
                $access,
                "/grapevine/comment?" .
                    http_build_query(["postId" => $event["remote_id"]]),
            );
            if (!array_is_list($rows) || count($rows) > 10000) {
                throw new \DomainException(
                    "Persönliche Kommentarliste nicht bestätigt.",
                );
            }
            $normalized = [];
            foreach ($rows as $comment) {
                if (
                    !is_array($comment) ||
                    ($comment["postId"] ?? $event["remote_id"]) !==
                        $event["remote_id"]
                ) {
                    throw new \DomainException(
                        "Kommentar gehört nicht zum ausgewählten Original.",
                    );
                }
                if (
                    !empty($comment["deleted"]) ||
                    ($comment["creator"]["id"] ?? null) ===
                        $identity["account_id"] ||
                    (!empty($identity['organization_id']) && ($comment['organizationId']??'')===$identity['organization_id'])
                ) {
                    continue;
                }
                $normalized[] = [
                    "id" => SocialHub::remoteId($comment["id"] ?? null),
                    "creator_id" => SocialHub::remoteId($comment["creator"]["id"] ?? null),
                    "text" => Content::text($comment["text"] ?? "", 10000),
                    "name" => Content::text(
                        trim(
                            ($comment["creator"]["firstName"] ?? "") .
                                " " .
                                ($comment["creator"]["lastName"] ?? ""),
                        ),
                        200,
                    ),
                    "created_at" =>
                        self::stamp($comment["created"] ?? null) ?? "",
                    "hidden" => false,
                    "title" => $event["title"],
                ];
            }
            $comments[$event["id"]] = $normalized;
        }
        return Db::atomic($this->db, function () use (
            $access,
            $club,
            $identity,
            $events,
            $comments,
        ) {
            $access->require("social", "manage");
            if (
                $access->club() !== $club ||
                $this->connection->identity($access) !== $identity
            ) {
                throw new \DomainException(
                    "Persönliches Kontoziel während Abruf geändert.",
                );
            }
            $attention = new Attention($this->db, $this->box);
            $changed = 0;
            foreach ($events as $event) {
                $id = $event["id"];
                $old=Db::run($this->db,'SELECT * FROM native_social_objects WHERE club_id=? AND id=? FOR UPDATE',[$club,$id])->fetch();
                $sameComments=$old&&$old['comments_cipher']!==null&&($this->box->open('social-comments:'.$club.':'.$id,$old['comments_cipher'])['comments']??null)===$comments[$id];
                if($old&&(!$sameComments||$old['caption']!==$event['caption']||!OriginalArchive::sameData(json_decode($old['payload_json'],true,64,JSON_THROW_ON_ERROR),$event)))(new OriginalArchive($this->db,$this->box))->retain($club,'object',$old,'changed');
                Db::run(
                    $this->db,
                    "INSERT INTO native_social_objects(club_id,id,channel,remote_id,kind,caption,created_at,payload_json,last_seen,comments_cipher,comments_at) VALUES(?,?,'stadtlandfunk',?,?,?,?,?,UTC_TIMESTAMP(),?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE kind=VALUES(kind),caption=VALUES(caption),payload_json=VALUES(payload_json),last_seen=VALUES(last_seen),comments_cipher=VALUES(comments_cipher),comments_at=VALUES(comments_at)",
                    [
                        $club,
                        $id,
                        $event["remote_id"],
                        $event['kind'],
                        $event["caption"],
                        $event["created_at"],
                        json_encode($event, JSON_THROW_ON_ERROR),
                        $sameComments?$old['comments_cipher']:$this->box->seal(
                            "social-comments:" . $club . ":" . $id,
                            ["comments" => $comments[$id]],
                        ),
                    ],
                );
                $changed += $attention->observe(
                    $access,
                    "stadtlandfunk",
                    "comment",
                    $event["remote_id"],
                    $comments[$id],
                    true,
                );
                if ($event["like_count"] !== null) {
                    $changed += $attention->observe(
                        $access,
                        "stadtlandfunk",
                        "reaction",
                        $event["remote_id"],
                        [
                            [
                                "id" => "reaction-summary",
                                "text" => "Reaktionen: " . $event["like_count"],
                                "count" => $event["like_count"],
                                "title" => $event["title"],
                            ],
                        ],
                    );
                }
            }
            Db::run(
                $this->db,
                "INSERT INTO native_social_checks(club_id,feature,state,checked_at) VALUES(?,'stadtlandfunk-events','available',UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE state=VALUES(state),checked_at=VALUES(checked_at)",
                [$club],
            );
            return ["events" => count($events), "changed_activity" => $changed];
        });
    }
}
