<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use PDO;
use Kulturhus\Native\Shared\{Access, Db, SecretBox};

/** One private human review list. A reaction is evidence of activity, never proof of expected reply. */
final class Attention
{
    public function __construct(private PDO $db, private SecretBox $box) {}

    public function observe(
        Access $access,
        string $channel,
        string $kind,
        string $parent,
        array $entries,
        bool $completeSnapshot = false,
    ): int {
        $access->require("social", "manage");
        if (
            !in_array(
                $channel,
                ["facebook", "instagram", "stadtlandfunk"],
                true,
            ) ||
            !in_array($kind, ["comment", "message", "reaction"], true) ||
            !array_is_list($entries) ||
            count($entries) > 10000
        ) {
            throw new \DomainException("Aktivitätsquelle prüfen.");
        }
        SocialHub::remoteId($parent);
        return Db::atomic($this->db, function () use (
            $access,
            $channel,
            $kind,
            $parent,
            $entries,
            $completeSnapshot,
        ) {
            $access->require("social", "manage");
            $club = $access->club();
            $changed = 0;
            $ids = [];
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    throw new \DomainException("Aktivität nicht bestätigt.");
                }
                $remote = SocialHub::remoteId($entry["id"] ?? null);
                $data = [
                    "remote_id" => $remote,
                    "text" => $kind === 'message' ? Content::originalText($entry["text"] ?? "", 20000) : Content::text($entry["text"] ?? "", 20000),
                    "name" => Content::text(
                        $entry["name"] ?? ($entry["from_name"] ?? ""),
                        200,
                    ),
                    "created_at" => Content::text(
                        $entry["created_at"] ?? "",
                        40,
                    ),
                    "title" => Content::text($entry["title"] ?? "", 300),
                ];
                if ($kind === "reaction") {
                    if (
                        !is_int($entry["count"] ?? null) ||
                        $entry["count"] < 0
                    ) {
                        throw new \DomainException(
                            "Reaktionszahl nicht bestätigt.",
                        );
                    }
                    $data["count"] = $entry["count"];
                }
                if ($channel === 'stadtlandfunk' && $kind === 'comment' && isset($entry['creator_id'])) {
                    $data['creator_id'] = SocialHub::remoteId($entry['creator_id']);
                }
                if ($kind === 'message' && isset($entry['from_id'])) $data['sender_id'] = SocialHub::remoteId($entry['from_id']);
                if ($kind === 'comment' && in_array($channel,['facebook','instagram'],true) && array_key_exists('hidden',$entry)) {
                    if (!is_bool($entry['hidden'])) throw new \DomainException('Moderationsstand des Kommentars nicht bestätigt.');
                    $data['hidden']=$entry['hidden'];
                }
                $id = substr(
                    hash(
                        "sha256",
                        $club .
                            ":" .
                            $channel .
                            ":" .
                            $kind .
                            ":" .
                            $parent .
                            ":" .
                            $remote,
                    ),
                    0,
                    32,
                );
                $ids[] = $id;
                $hash = $this->box->fingerprint(
                    $club . ":" . json_encode($data, JSON_THROW_ON_ERROR),
                );
                $old = Db::run(
                    $this->db,
                    "SELECT * FROM native_social_attention WHERE club_id=? AND id=? FOR UPDATE",
                    [$club, $id],
                )->fetch();
                if (!$old && $kind === "reaction" && $data["count"] === 0) {
                    continue;
                }
                if ($old && hash_equals($old["fingerprint"], $hash)) {
                    Db::run(
                        $this->db,
                        "UPDATE native_social_attention SET observed_at=UTC_TIMESTAMP() WHERE club_id=? AND id=?",
                        [$club, $id],
                    );
                    continue;
                }
                if($old)(new OriginalArchive($this->db,$this->box))->retain($club,'attention',$old,'changed');
                Db::run(
                    $this->db,
                    "INSERT INTO native_social_attention(club_id,id,channel,kind,parent_id,state,version,fingerprint,ciphertext,observed_at) VALUES(?,?,?,?,?,'new',?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE state='new',version=VALUES(version),fingerprint=VALUES(fingerprint),ciphertext=VALUES(ciphertext),observed_at=VALUES(observed_at),updated_by=NULL",
                    [
                        $club,
                        $id,
                        $channel,
                        $kind,
                        $parent,
                        $old ? (int) $old["version"] + 1 : 1,
                        $hash,
                        $this->box->seal(
                            "social-attention:" . $club . ":" . $id,
                            $data,
                        ),
                    ],
                );
                $changed++;
            }
            if ($completeSnapshot) {
                $missing="channel=? AND kind=? AND parent_id=?".($ids?" AND id NOT IN (".implode(',',array_fill(0,count($ids),'?')).")":"");
                (new OriginalArchive($this->db,$this->box))->retainWhere($club,'attention',$missing,[$channel,$kind,$parent,...$ids],'removed');
                Db::run(
                    $this->db,
                    "DELETE FROM native_social_attention WHERE club_id=? AND channel=? AND kind=? AND parent_id=?" .
                        ($ids
                            ? " AND id NOT IN (" .
                                implode(",", array_fill(0, count($ids), "?")) .
                                ")"
                            : ""),
                    [$club, $channel, $kind, $parent, ...$ids],
                );
            }
            (new OriginalArchive($this->db,$this->box))->retainWhere($club,'attention',"kind='message' AND observed_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)",[],'expired');
            Db::run(
                $this->db,
                "DELETE FROM native_social_attention WHERE club_id=? AND kind='message' AND observed_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)",
                [$club],
            );
            return $changed;
        });
    }

    public function listing(Access $access, string $state = "open", ?array $kinds = null): array
    {
        $access->require("social", "manage");
        if (
            !in_array(
                $state,
                ["open", "new", "reply_needed", "done", "all"],
                true,
            )
        ) {
            throw new \DomainException("Antwortfilter prüfen.");
        }
        $where =
            $state === "open"
                ? " AND state IN ('new','reply_needed')"
                : ($state === "all"
                    ? ""
                    : " AND state=?");
        $params = [$access->club()];
        if (!in_array($state, ["open", "all"], true)) {
            $params[] = $state;
        }
        if($kinds!==null){
            if(!array_is_list($kinds)||!$kinds||count($kinds)>3||array_diff($kinds,['comment','message','reaction']))throw new \DomainException('Aktivitätsart prüfen.');
            $where.=' AND kind IN ('.implode(',',array_fill(0,count($kinds),'?')).')';
            array_push($params,...$kinds);
        }
        $rows = Db::run(
            $this->db,
            "SELECT * FROM native_social_attention WHERE club_id=?" .
                $where .
                " ORDER BY observed_at DESC,id LIMIT 200",
            $params,
        )->fetchAll();
        foreach ($rows as &$row) {
            $row["payload"] = $this->box->open(
                "social-attention:" . $access->club() . ":" . $row["id"],
                $row["ciphertext"],
            );
            unset($row["ciphertext"], $row["fingerprint"]);
        }
        return $rows;
    }

    public function setState(
        Access $access,
        string $id,
        string $state,
        int $version,
    ): void {
        $access->require("social", "manage");
        Content::id($id);
        if (!in_array($state, ["new", "reply_needed", "done"], true)) {
            throw new \DomainException("Antwortstatus prüfen.");
        }
        Db::atomic($this->db, function () use ($access, $id, $state, $version) {
            $u = $access->require("social", "manage");
            $r = Db::run(
                $this->db,
                "SELECT * FROM native_social_attention WHERE club_id=? AND id=? FOR UPDATE",
                [$u["club_id"], $id],
            )->fetch();
            if (!$r || (int) $r['version'] !== $version) {
                throw new \DomainException(
                    "Aktivität inzwischen geändert. Bitte neu laden.",
                );
            }
            (new OriginalArchive($this->db,$this->box))->retain($u['club_id'],'attention',$r,'reviewed');
            Db::run(
                $this->db,
                "UPDATE native_social_attention SET state=?,version=version+1,updated_by=? WHERE club_id=? AND id=?",
                [$state, $u["id"], $u["club_id"], $id],
            );
        });
    }
}
