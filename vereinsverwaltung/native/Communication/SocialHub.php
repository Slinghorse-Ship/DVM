<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use PDO;
use Kulturhus\Verein\Pagination;
require_once dirname(__DIR__, 2) . "/src/Shared/Pagination.php";
use Kulturhus\Native\Shared\{Access, Db, SecretBox};
use Kulturhus\Native\Integration\{Accounts, JsonHttp};
/** Club-pinned Graph reads. Complete bounded pages before mutating a stored snapshot. */
final class SocialHub
{
    private \Closure $http;
    private \Closure $imageFetch;
    private int $remainingCalls = 500;
    public function __construct(
        private PDO $db,
        private SecretBox $box,
        ?callable $http = null,
        ?callable $imageFetch = null,
    ) {
        $this->http = $http
            ? \Closure::fromCallable($http)
            : static fn($m, $p, $h, $b) => JsonHttp::request(
                "meta",
                $m,
                $p,
                $h,
                $b,
            );
        $this->imageFetch = $imageFetch
            ? \Closure::fromCallable($imageFetch)
            : self::fetchImage(...);
    }
    private function call(
        array $a,
        string $method,
        string $path,
        ?array $body = null,
    ): array {
        if (--$this->remainingCalls < 0) {
            throw new \DomainException(
                "Abgleichbudget erreicht; kein unvollständiges Ergebnis speichern.",
            );
        }
        $r = ($this->http)(
            $method,
            "/" . $a["api_version"] . "/" . $path,
            ["Authorization: Bearer " . $a["token"]],
            $body,
        );
        if ($pause = \Kulturhus\Native\Integration\Cooldown::forResponse($r)) {
            throw $pause;
        }
        if (
            ($r["status"] ?? 0) < 200 ||
            ($r["status"] ?? 0) >= 300 ||
            !is_array($r["body"] ?? null) ||
            isset($r["body"]["error"])
        ) {
            throw new \DomainException(
                "Meta-Funktion für dieses Konto nicht bestätigt. Berechtigungen und API-Version prüfen.",
            );
        }
        return $r["body"];
    }
    public static function remoteId(mixed $id): string
    {
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_.$:-]{1,200}$/D', $id) || str_contains($id,'..')) {
            throw new \DomainException("Anbieterkennung ungültig.");
        }
        return $id;
    }
    private function pages(
        array $a,
        string $path,
        array $parameters = [],
        int $maximumPages = 100,
        int $maximumRows = 10000,
        int $pageSize = 100,
    ): array {
        $rows = [];
        $seen = [];
        $cursors = [];
        $after = null;
        for ($page = 0; $page < $maximumPages; $page++) {
            $query = $parameters + ["limit" => $pageSize];
            if ($after !== null) {
                $query["after"] = $after;
            }
            $r = $this->call(
                $a,
                "GET",
                $path .
                    "?" .
                    http_build_query($query, "", "&", PHP_QUERY_RFC3986),
            );
            if (!is_array($r["data"] ?? null) || !array_is_list($r["data"])) {
                throw new \DomainException("Meta-Liste unvollständig.");
            }
            foreach ($r["data"] as $row) {
                if (!is_array($row)) {
                    throw new \DomainException("Meta-Datensatz ungültig.");
                }
                $id = self::remoteId($row["id"] ?? null);
                if (isset($seen[$id])) {
                    throw new \DomainException(
                        "Meta-Seiten überlappen. Erneut vollständig abrufen.",
                    );
                }
                $seen[$id] = true;
                $rows[] = $row;
                if (count($rows) > $maximumRows) {
                    throw new \DomainException(
                        "Meta-Abgleich zu groß; Zeitraum eingrenzen.",
                    );
                }
            }
            if (empty($r["paging"]["next"])) {
                return $rows;
            }
            $after = $r["paging"]["cursors"]["after"] ?? null;
            if (
                !is_string($after) ||
                $after === "" ||
                strlen($after) > 2048 ||
                preg_match('/[\x00-\x20\x7f]/', $after) ||
                isset($cursors[$after])
            ) {
                throw new \DomainException("Meta-Seitenfolge unvollständig.");
            }
            $cursors[$after] = true;
            // paging.next may contain credentials; never follow or store its URL.
        }
        throw new \DomainException(
            "Meta-Seitenlimit erreicht; kein Teilimport.",
        );
    }
    private static function utc(mixed $date): ?string
    {
        if ($date === null || $date === "") {
            return null;
        }
        if (
            !is_string($date) ||
            !preg_match(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:?\d{2})$/D',
                $date,
            )
        ) {
            throw new \DomainException("Meta-Zeitangabe ungültig.");
        }
        return Content::time(
            preg_replace('/([+-]\d{2})(\d{2})$/D', '$1:$2', $date),
        );
    }
    public static function url(mixed $url, bool $asset = false): string
    {
        if ($url === null || $url === "") {
            return "";
        }
        $url = Content::url($url);
        $host = strtolower(parse_url($url, PHP_URL_HOST));
        $allowed = $asset
            ? str_ends_with($host, ".cdninstagram.com") ||
                str_ends_with($host, ".fbcdn.net")
            : in_array(
                $host,
                [
                    "www.instagram.com",
                    "instagram.com",
                    "www.facebook.com",
                    "facebook.com",
                    "m.facebook.com",
                    "www.fb.com",
                ],
                true,
            );
        if (!$allowed || parse_url($url, PHP_URL_PORT) !== null) {
            return "";
        }
        return $url;
    }
    private function observation(
        string $club,
        string $feature,
        string $state,
    ): void {
        Db::run(
            $this->db,
            "INSERT INTO native_social_checks(club_id,feature,state,checked_at) VALUES(?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE state=VALUES(state),checked_at=VALUES(checked_at)",
            [$club, $feature, $state],
        );
    }
    public function sync(Access $access): array
    {
        $access->require("social", "edit");
        $club = $access->club();
        $a = (new Accounts($this->db, $this->box))->get($club, "meta");
        $page = $this->call(
            $a,
            "GET",
            $a["page_id"] . "?fields=id,name,instagram_business_account",
        );
        if (
            ($page["id"] ?? "") !== $a["page_id"] ||
            ($a["instagram_id"] !== "" &&
                ($page["instagram_business_account"]["id"] ?? "") !==
                    $a["instagram_id"])
        ) {
            throw new \DomainException(
                "Facebook-/Instagram-Kontoverbindung passt nicht zur Einrichtung.",
            );
        }
        $sources = [
            "facebook-posts" => [
                $a["page_id"] . "/published_posts",
                "id,message,created_time,permalink_url,full_picture,reactions.limit(0).summary(true)",
                "facebook",
                "post",
            ],
            "facebook-reels" => [
                $a["page_id"] . "/video_reels",
                "id,title,description,created_time,permalink_url,picture",
                "facebook",
                "reel",
            ],
        ];
        if ($a["instagram_id"] !== "") {
            $sources += [
                "instagram-media" => [
                    $a["instagram_id"] . "/media",
                    "id,caption,media_type,media_product_type,media_url,thumbnail_url,permalink,timestamp,like_count,comments_count",
                    "instagram",
                    "post",
                ],
                "instagram-stories" => [
                    $a["instagram_id"] . "/stories",
                    "id,media_type,media_url,thumbnail_url,permalink,timestamp",
                    "instagram",
                    "story",
                ],
            ];
        }
        $result = [];
        foreach ($sources as $feature => [$path, $fields, $channel, $default]) {
            try {
                $rows = $this->pages($a, $path, ["fields" => $fields]);
                $normalized = [];
                foreach ($rows as $r) {
                    $kind = $default;
                    if ($channel === "instagram") {
                        $kind =
                            ($r["media_product_type"] ?? "") === "REELS"
                                ? "reel"
                                : (($r["media_type"] ?? "") === "CAROUSEL_ALBUM"
                                    ? "carousel"
                                    : ($default === "story"
                                        ? "story"
                                        : (($r["media_type"] ?? "") === "VIDEO"
                                            ? "video"
                                            : "post")));
                    }
                    $caption = Content::text(
                        $r["caption"] ??
                            ($r["message"] ??
                                ($r["description"] ?? ($r["title"] ?? ""))),
                        100000,
                    );
                    $media =
                        $r["thumbnail_url"] ??
                        (($r["media_type"] ?? "") === "IMAGE" ||
                        $kind === "post"
                            ? $r["media_url"] ??
                                ($r["full_picture"] ?? ($r["picture"] ?? ""))
                            : $r["picture"] ?? "");
                    if (!is_string($media)) {
                        $media = "";
                    }
                    $normalized[] = [
                        "remote_id" => self::remoteId($r["id"]),
                        "connection_id" => Accounts::binding($a),
                        "kind" => $kind,
                        "caption" => $caption,
                        "created_at" => self::utc(
                            $r["timestamp"] ?? ($r["created_time"] ?? null),
                        ),
                        "permalink" => self::url(
                            $r["permalink"] ?? ($r["permalink_url"] ?? ""),
                        ),
                        "thumbnail" => self::url($media, true),
                        "media_type" => Content::text(
                            $r["media_type"] ?? "",
                            30,
                        ),
                        "reaction_count" => is_int(
                            $r["reactions"]["summary"]["total_count"] ?? null,
                        )
                            ? $r["reactions"]["summary"]["total_count"]
                            : (is_int($r["like_count"] ?? null)
                                ? $r["like_count"]
                                : null),
                        "like_count" =>
                            isset($r["like_count"]) && is_int($r["like_count"])
                                ? $r["like_count"]
                                : null,
                        "comments_count" =>
                            isset($r["comments_count"]) &&
                            is_int($r["comments_count"])
                                ? $r["comments_count"]
                                : null,
                    ];
                }
                Db::atomic($this->db, function () use (
                    $access,
                    $a,
                    $club,
                    $normalized,
                    $feature,
                    $channel,
                ) {
                    $access->require("social", "edit");
                    $this->currentAccount($access, $a);
                    foreach ($normalized as $r) {
                        $id = substr(
                            hash(
                                "sha256",
                                $club . ":" . $channel . ":" . $r["remote_id"],
                            ),
                            0,
                            32,
                        );
                        $old=Db::run($this->db,'SELECT * FROM native_social_objects WHERE club_id=? AND id=? FOR UPDATE',[$club,$id])->fetch();
                        if($old&&($old['kind']!==$r['kind']||$old['caption']!==$r['caption']||$old['created_at']!==$r['created_at']||!OriginalArchive::sameData(json_decode($old['payload_json'],true,64,JSON_THROW_ON_ERROR),$r)))(new OriginalArchive($this->db,$this->box))->retain($club,'object',$old,'changed');
                        Db::run(
                            $this->db,
                            'INSERT INTO native_social_objects(club_id,id,channel,remote_id,kind,caption,created_at,payload_json,last_seen) VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE thumbnail_blob=IF(JSON_UNQUOTE(JSON_EXTRACT(payload_json,\'$.thumbnail\'))<=>JSON_UNQUOTE(JSON_EXTRACT(VALUES(payload_json),\'$.thumbnail\')),thumbnail_blob,NULL),thumbnail_sha256=IF(JSON_UNQUOTE(JSON_EXTRACT(payload_json,\'$.thumbnail\'))<=>JSON_UNQUOTE(JSON_EXTRACT(VALUES(payload_json),\'$.thumbnail\')),thumbnail_sha256,NULL),kind=VALUES(kind),caption=VALUES(caption),created_at=VALUES(created_at),payload_json=VALUES(payload_json),last_seen=VALUES(last_seen)',
                            [
                                $club,
                                $id,
                                $channel,
                                $r["remote_id"],
                                $r["kind"],
                                $r["caption"],
                                $r["created_at"],
                                json_encode($r, JSON_THROW_ON_ERROR),
                            ],
                        );
                        if (
                            $r["reaction_count"] !== null &&
                            $this->canManage($access)
                        ) {
                            (new Attention($this->db, $this->box))->observe(
                                $access,
                                $channel,
                                "reaction",
                                $r["remote_id"],
                                [
                                    [
                                        "id" => "reaction-summary",
                                        "count" => $r["reaction_count"],
                                        "text" =>
                                            "Reaktionen: " .
                                            $r["reaction_count"],
                                    ],
                                ],
                            );
                        }
                    }
                    $this->observation($club, $feature, "available");
                });
                $result[$feature] = [
                    "status" => "available",
                    "count" => count($normalized),
                ];
            } catch (\Kulturhus\Native\Integration\Cooldown $pause) {
                throw $pause;
            } catch (\DomainException) {
                Db::atomic($this->db, function () use (
                    $access,
                    $club,
                    $feature,
                ) {
                    $access->require("social", "edit");
                    $this->observation($club, $feature, "not-confirmed");
                });
                $result[$feature] = ["status" => "not-confirmed"];
            }
        }
        return $result;
    }
    public function listing(
        Access $access,
        string $search = "",
        string $kind = "",
        string $channel = "",
        int $page = 1,
        int $perPage = 24,
    ): array {
        $access->require("social", "read");
        $search = Content::text($search, 120);
        if (
            !in_array(
                $kind,
                ["", "post", "reel", "video", "carousel", "story", "event"],
                true,
            ) ||
            !in_array(
                $channel,
                ["", "facebook", "instagram", "stadtlandfunk"],
                true,
            ) ||
            $page < 1 ||
            $page > 100000 ||
            !in_array($perPage, [6,24], true)
        ) {
            throw new \DomainException("Bibliotheksfilter prüfen.");
        }
        $where = "club_id=?";
        $args = [$access->club()];
        if ($search !== "") {
            $where .= " AND LOCATE(LOWER(?),LOWER(caption))>0";
            $args[] = $search;
        }
        if ($kind !== "") {
            $where .= " AND kind=?";
            $args[] = $kind;
        }
        if ($channel !== "") {
            $where .= " AND channel=?";
            $args[] = $channel;
        }
        $total = (int) Db::run(
            $this->db,
            "SELECT COUNT(*) FROM native_social_objects WHERE " . $where,
            $args,
        )->fetchColumn();
        $window = Pagination::window($total, $page, $perPage);
        $page = $window["page"];
        $pages = $window["pages"];
        $rows = Db::run(
            $this->db,
            "SELECT * FROM native_social_objects WHERE " .
                $where .
                " ORDER BY created_at DESC,id LIMIT " . $perPage . " OFFSET " .
                ($page - 1) * $perPage,
            $args,
        )->fetchAll();
        foreach ($rows as &$r) {
            $r["payload"] = json_decode(
                $r["payload_json"],
                true,
                32,
                JSON_THROW_ON_ERROR,
            );
        }
        return compact("rows", "total", "pages", "page");
    }
    public function checks(Access $access): array
    {
        $access->require("social", "read");
        return Db::run(
            $this->db,
            "SELECT feature,state,checked_at FROM native_social_checks WHERE club_id=? ORDER BY feature",
            [$access->club()],
        )->fetchAll();
    }
    public function object(Access $access, string $id): array
    {
        $access->require("social", "read");
        $r = Db::run(
            $this->db,
            "SELECT * FROM native_social_objects WHERE club_id=? AND id=?",
            [$access->club(), Content::id($id)],
        )->fetch();
        if (!$r) {
            throw new \DomainException("Beitrag nicht verfügbar.");
        }
        return $r;
    }
    /** Refresh actual counters of one already owned original; unavailable fields never become zero. */
    public function refreshCounts(Access $access,string $id):void
    {
        $access->require('social','manage');$object=$this->object($access,$id);$account=(new Accounts($this->db,$this->box))->get($access->club(),'meta');$this->objectAccount($object,$account);$instagram=$object['channel']==='instagram';
        if(!in_array($object['channel'],['facebook','instagram'],true))throw new \DomainException('Meta-Beitrag auswählen.');
        $fields=$instagram?'id,owner,like_count,comments_count':'id,from,reactions.limit(0).summary(true),comments.limit(0).summary(true)';
        $result=$this->call($account,'GET',rawurlencode($object['remote_id']).'?'.http_build_query(['fields'=>$fields],'','&',PHP_QUERY_RFC3986));
        if(($result['id']??null)!==$object['remote_id']||(!$instagram&&($result['from']['id']??null)!==$account['page_id'])||($instagram&&isset($result['owner']['id'])&&$result['owner']['id']!==$account['instagram_id']))throw new \DomainException('Ursprünglichen eigenen Beitrag prüfen.');
        $values=$instagram?['like_count'=>$result['like_count']??null,'comments_count'=>$result['comments_count']??null]:['reaction_count'=>$result['reactions']['summary']['total_count']??null,'comments_count'=>$result['comments']['summary']['total_count']??null];
        $valid=array_filter($values,static fn($value)=>is_int($value)&&$value>=0);if(!$valid)throw new \DomainException('Anbieter hat keine bestätigten Zähler geliefert.');
        Db::atomic($this->db,function()use($access,$id,$account,$valid):void{
            $access->require('social','manage');$this->currentAccount($access,$account);$old=Db::run($this->db,'SELECT * FROM native_social_objects WHERE club_id=? AND id=? FOR UPDATE',[$access->club(),$id])->fetch();if(!$old)throw new \DomainException('Originalbeitrag inzwischen entfernt.');$this->objectAccount($old,$account);$payload=json_decode($old['payload_json'],true,32,JSON_THROW_ON_ERROR);$next=array_replace($payload,$valid);
            if($next!==$payload)(new OriginalArchive($this->db,$this->box))->retain($access->club(),'object',$old,'changed');
            Db::run($this->db,'UPDATE native_social_objects SET payload_json=?,last_seen=UTC_TIMESTAMP() WHERE club_id=? AND id=?',[json_encode($next,JSON_THROW_ON_ERROR),$access->club(),$id]);
        });
    }
    public function insights(Access $access, string $id): array
    {
        $access->require("social", "edit");
        $o = $this->object($access, $id);
        if (!in_array($o["channel"], ["facebook", "instagram"], true)) {
            throw new \DomainException(
                "Für diesen Kanal den eigenen Abgleich verwenden.",
            );
        }
        $a = (new Accounts($this->db, $this->box))->get(
            $access->club(),
            "meta",
        );
        $this->objectAccount($o, $a);
        $metrics =
            $o["channel"] === "instagram"
                ? ["views", "reach", "saved", "shares", "total_interactions"]
                : ["post_media_view", "post_reactions_by_type_total"];
        $result = [];
        foreach ($metrics as $metric) {
            try {
                $r = $this->call(
                    $a,
                    "GET",
                    $o["remote_id"] . "/insights?metric=" . $metric,
                );
                $data = $r["data"] ?? null;
                if (
                    !is_array($data) ||
                    count($data) !== 1 ||
                    ($data[0]["name"] ?? "") !== $metric
                ) {
                    throw new \DomainException("Kennzahl nicht bestätigt.");
                }
                $result[$metric] = ["available" => true, "data" => $data[0]];
            } catch (\Kulturhus\Native\Integration\Cooldown $pause) {
                throw $pause;
            } catch (\DomainException) {
                $result[$metric] = ["available" => false];
            }
        }
        Db::atomic($this->db, function () use ($access, $id, $result, $a) {
            $access->require("social", "edit");
            $this->currentAccount($access, $a);
            $old=Db::run($this->db,'SELECT * FROM native_social_objects WHERE club_id=? AND id=? FOR UPDATE',[$access->club(),$id])->fetch();
            if(!$old)throw new \DomainException('Originalbeitrag inzwischen entfernt.');
            if($old['insights_json']!==null&&!OriginalArchive::sameData(json_decode($old['insights_json'],true,64,JSON_THROW_ON_ERROR),$result))(new OriginalArchive($this->db,$this->box))->retain($access->club(),'object',$old,'changed');
            Db::run(
                $this->db,
                "UPDATE native_social_objects SET insights_json=?,insights_at=UTC_TIMESTAMP() WHERE club_id=? AND id=?",
                [
                    json_encode($result, JSON_THROW_ON_ERROR),
                    $access->club(),
                    $id,
                ],
            );
        });
        return $result;
    }
    public function comments(Access $access, string $id): array
    {
        $access->require("social", "manage");
        $o = $this->object($access, $id);
        if (!in_array($o["channel"], ["facebook", "instagram"], true)) {
            throw new \DomainException(
                "Für diesen Kanal den eigenen Abgleich verwenden.",
            );
        }
        $a = (new Accounts($this->db, $this->box))->get(
            $access->club(),
            "meta",
        );
        $this->objectAccount($o, $a);
        $ownReplies = Db::run(
            $this->db,
            "SELECT remote_id FROM native_social_replies WHERE club_id=? AND channel=? AND parent_id=? AND connection_id=? AND remote_id IS NOT NULL AND status IN ('sent','uncertain','sending')",
            [
                $access->club(),
                $o["channel"],
                $o["remote_id"],
                Accounts::binding($a),
            ],
        )->fetchAll(PDO::FETCH_COLUMN);
        $normalized = MetaComments::read(
            $o,
            $a,
            $this->pages(...),
            $this->call(...),
            self::utc(...),
            $ownReplies,
        );
        Db::atomic($this->db, function () use ($access, $id, $normalized, $a) {
            $access->require("social", "manage");
            $this->currentAccount($access, $a);
            $old=Db::run($this->db,'SELECT * FROM native_social_objects WHERE club_id=? AND id=? FOR UPDATE',[$access->club(),$id])->fetch();
            if(!$old)throw new \DomainException('Originalbeitrag inzwischen entfernt.');
            $unchanged=$old['comments_cipher']!==null&&($this->box->open('social-comments:'.$access->club().':'.$id,$old['comments_cipher'])['comments']??null)===$normalized;
            if(!$unchanged&&$old['comments_cipher']!==null)(new OriginalArchive($this->db,$this->box))->retain($access->club(),'object',$old,'changed');
            Db::run(
                $this->db,
                "UPDATE native_social_objects SET comments_cipher=?,comments_at=UTC_TIMESTAMP() WHERE club_id=? AND id=?",
                [
                    $unchanged?$old['comments_cipher']:$this->box->seal(
                        "social-comments:" . $access->club() . ":" . $id,
                        ["comments" => $normalized],
                    ),
                    $access->club(),
                    $id,
                ],
            );
            $object = $this->object($access, $id);
            (new Attention($this->db, $this->box))->observe(
                $access,
                $object["channel"],
                "comment",
                $object["remote_id"],
                array_values(
                    array_filter(
                        $normalized,
                        static fn($comment) => $comment["incoming"],
                    ),
                ),
                true,
            );
        });
        return $normalized;
    }
    public function privateComments(Access $access, string $id): array
    {
        $access->require("social", "manage");
        $row = $this->object($access, $id);
        return $row["comments_cipher"]
            ? $this->box->open(
                "social-comments:" . $access->club() . ":" . $id,
                $row["comments_cipher"],
            )["comments"]
            : [];
    }
    public function syncInbox(Access $access, string $channel,?string $participant=null,?string $requiredMessage=null,?callable $observed=null): int
    {
        $access->require("social", "manage");
        if (!in_array($channel, ["facebook", "instagram"], true)) {
            throw new \DomainException("Nachrichtenkanal prüfen.");
        }
        $club = $access->club();
        $a = (new Accounts($this->db, $this->box))->get($club, "meta");
        if ($channel === "instagram" && $a["instagram_id"] === "") {
            throw new \DomainException("Instagram-Konto fehlt.");
        }
        $parameters = [
            "fields" => "id,updated_time,participants",
            "platform" => $channel === "instagram" ? "instagram" : "messenger",
        ];
        if($participant!==null)$parameters['user_id']=self::remoteId($participant);
        if($requiredMessage!==null)self::remoteId($requiredMessage);
        $conversations = $this->pages(
            $a,
            $a["page_id"] . "/conversations",
            $parameters,
            $participant===null?100:4,
            $participant===null?10000:5,
            $participant===null?100:5,
        );
        if (count($conversations) > 100) {
            throw new \DomainException(
                "Mehr als 100 Unterhaltungen; Abgleichzeitraum eingrenzen.",
            );
        }
        $rows = [];$requiredFound=$requiredMessage===null;$observedIds=[];
        $messageCount = 0;
        foreach ($conversations as $c) {
            if($participant!==null){$participants=$c['participants']['data']??null;if(!is_array($participants)||!array_is_list($participants)||count($participants)>100)throw new \DomainException('Ursprüngliche Gesprächsteilnehmer prüfen.');$ids=array_map(static fn($p)=>self::remoteId($p['id']??null),$participants);if(!in_array($participant,$ids,true)||!array_intersect($ids,[$a['page_id'],$a['instagram_id']]))throw new \DomainException('Gespräch gehört nicht zu diesem Konto und Teilnehmer.');}
            $remote = self::remoteId($c["id"]);
            $messages = $this->pages($a, rawurlencode($remote) . "/messages", [
                "fields" => "id,message,created_time,from,to",
            ],100,5000);
            $normalized = [];
            $incoming = null;
            $recipient = "";
            foreach ($messages as $m) {
                if (++$messageCount > 5000) {
                    throw new \DomainException(
                        "Mehr als 5000 Nachrichten; kein Teilabgleich.",
                    );
                }
                $date = self::utc($m["created_time"] ?? null);
                if (
                    !$date ||
                    $date < gmdate("Y-m-d H:i:s", time() - 30 * 86400)
                ) {
                    continue;
                }
                $from = self::remoteId($m["from"]["id"] ?? null);
                $text = Content::originalText($m["message"] ?? "", 20000);
                $isIncoming = !in_array(
                    $from,
                    [$a["page_id"], $a["instagram_id"]],
                    true,
                );
                if($participant!==null&&$isIncoming&&$from!==$participant)throw new \DomainException('Gesprächsteilnehmer inzwischen geändert.');
                if(($m['id']??null)===$requiredMessage)$requiredFound=true;
                if ($isIncoming && ($incoming === null || $date > $incoming)) {
                    $incoming = $date;
                    $recipient = $from;
                }
                $normalized[] = [
                    "id" => self::remoteId($m["id"]),
                    "text" => $text,
                    "from_name" => Content::text($m["from"]["name"] ?? "", 200),
                    "from_id" => $from,
                    "incoming" => $isIncoming,
                    "created_at" => $date,
                ];
                $observedIds[]=self::remoteId($m['id']);
            }
            if (!$normalized || $recipient === "") {
                continue;
            }
            usort(
                $normalized,
                static fn($x, $y) => strcmp($x["created_at"], $y["created_at"]),
            );
            $rows[] = [
                "remote" => $remote,
                "last_incoming" => $incoming,
                "recipient" => $recipient,
                "messages" => $normalized,
            ];
        }
        if(!$requiredFound)throw new \DomainException('Gemeldete Nachricht ist bei Meta noch nicht vollständig bestätigt.');
        $saved=Db::atomic($this->db, function () use (
            $access,
            $club,
            $a,
            $channel,
            $rows,
        ) {
            $access->require("social", "manage");
            $this->currentAccount($access, $a);
            foreach ($rows as $r) {
                $id = substr(
                    hash("sha256", $club . ":" . $channel . ":" . $r["remote"]),
                    0,
                    32,
                );
                $p = [
                    "conversation" => $r["remote"],
                    "recipient" => $r["recipient"],
                    "page_id" => $a["page_id"],
                    "instagram_id" => $a["instagram_id"],
                    "connection_id" => Accounts::binding($a),
                    "messages" => $r["messages"],
                ];
                $old=Db::run($this->db,'SELECT ciphertext FROM native_social_inbox WHERE club_id=? AND id=? FOR UPDATE',[$club,$id])->fetchColumn();
                if(is_string($old))$p=InboxArchive::merge($this->box->open('social-inbox:'.$club.':'.$id,$old),$p);
                $lastIncoming=$r['last_incoming'];
                foreach($p['messages']as$message)if($message['incoming'])$lastIncoming=max($lastIncoming,$message['created_at']);
                Db::run(
                    $this->db,
                    "INSERT INTO native_social_inbox(club_id,id,channel,ciphertext,last_incoming,updated_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE ciphertext=VALUES(ciphertext),last_incoming=VALUES(last_incoming),updated_at=VALUES(updated_at)",
                    [
                        $club,
                        $id,
                        $channel,
                        $this->box->seal(
                            "social-inbox:" . $club . ":" . $id,
                            $p,
                        ),
                        $lastIncoming,
                    ],
                );
                (new Attention($this->db, $this->box))->observe(
                    $access,
                    $channel,
                    "message",
                    $r["remote"],
                    array_values(
                        array_filter(
                            $r["messages"],
                            static fn($m) => $m["incoming"],
                        ),
                    ),
                    true,
                );
            }
            // The provider's read window does not erase earlier native originals.
            $this->observation($club, $channel . "-messages", "available");
            return count($rows);
        });
        if($observed!==null)$observed(array_values(array_unique($observedIds)));return$saved;
    }
    public function inbox(Access $access): array
    {
        $access->require("social", "manage");
        $rows = Db::run(
            $this->db,
            "SELECT id,channel,ciphertext,last_incoming,updated_at FROM native_social_inbox WHERE club_id=? AND last_incoming>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY) ORDER BY last_incoming DESC LIMIT 100",
            [$access->club()],
        )->fetchAll();
        foreach ($rows as &$r) {
            $r["messages"] = $this->box->open(
                "social-inbox:" . $access->club() . ":" . $r["id"],
                $r["ciphertext"],
            )["messages"];
            unset($r["ciphertext"]);
        }
        return $rows;
    }
    private function canManage(Access $access): bool
    {
        try {
            $access->require("social", "manage");
            return true;
        } catch (\DomainException) {
            return false;
        }
    }
    private function objectAccount(
        array $object,
        #[\SensitiveParameter] array $account,
    ): void {
        $data = json_decode(
            $object["payload_json"],
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        if (
            !is_string($data["connection_id"] ?? null) ||
            !hash_equals(Accounts::binding($account), $data["connection_id"])
        ) {
            throw new \DomainException(
                "Beitrag gehört zu einer früheren Verbindung. Das aktuelle Konto erneut abgleichen.",
            );
        }
    }
    private function currentAccount(Access $access, array $expected): void
    {
        $current = (new Accounts($this->db, $this->box))->get(
            $access->club(),
            "meta",
        );
        if (
            $current["page_id"] !== $expected["page_id"] ||
            $current["instagram_id"] !== $expected["instagram_id"] ||
            !hash_equals(
                Accounts::binding($current),
                Accounts::binding($expected),
            )
        ) {
            throw new \DomainException(
                "Kontoziel oder Verbindung während Abruf geändert.",
            );
        }
    }
    private static function fetchImage(string $url): string
    {
        \Kulturhus\Native\Demo\Network::assertAllowed();
        if (self::url($url, true) !== $url) {
            throw new \DomainException("Meta-Bildquelle nicht freigegeben.");
        }
        if (
            getenv("KULTURHUS_DISABLE_MAIL") === "1" ||
            (getenv("KULTURHUS_LOCAL_DEMO") !== false &&
                getenv("KULTURHUS_LOCAL_DEMO") !== "")
        ) {
            throw new \DomainException(
                "Externer Abruf in der Testumgebung gesperrt.",
            );
        }
        $bytes = "";
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($curl, $chunk) use (
                &$bytes,
            ) {
                if (strlen($bytes) + strlen($chunk) > 5 * 1024 * 1024) {
                    return 0;
                }
                $bytes .= $chunk;
                return strlen($chunk);
            },
        ]);
        try {
            if (
                curl_exec($curl) === false ||
                curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200
            ) {
                throw new \DomainException(
                    "Anbietervorschau nicht abrufbar. Bibliothek erneut abgleichen.",
                );
            }
        } finally {
            curl_close($curl);
        }
        return $bytes;
    }
    public function thumbnail(Access $access, string $id): string
    {
        $row = $this->object($access, $id);
        if (
            $row["thumbnail_blob"] !== null &&
            $row["thumbnail_sha256"] !== null &&
            hash_equals(
                $row["thumbnail_sha256"],
                hash("sha256", $row["thumbnail_blob"]),
            )
        ) {
            return $row["thumbnail_blob"];
        }
        $payload = json_decode(
            $row["payload_json"],
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        $url = self::url($payload["thumbnail"] ?? "", true);
        if ($url === "") {
            throw new \DomainException(
                "Kein tatsächliches Anbietervorschaubild verfügbar.",
            );
        }
        $bytes = ($this->imageFetch)($url);
        if (!is_string($bytes) || strlen($bytes) > 5 * 1024 * 1024) {
            throw new \DomainException("Vorschaubild zu groß.");
        }
        $size = @getimagesizefromstring($bytes);
        if (
            !$size ||
            !in_array(
                $size["mime"],
                ["image/jpeg", "image/png", "image/webp"],
                true,
            ) ||
            $size[0] < 1 ||
            $size[1] < 1 ||
            $size[0] * $size[1] > 8000000
        ) {
            throw new \DomainException(
                "Anbietervorschau hat kein erlaubtes Bildformat.",
            );
        }
        $source = @imagecreatefromstring($bytes);
        if (!$source) {
            throw new \DomainException("Anbietervorschau nicht lesbar.");
        }
        $factor = min(1, 480 / max($size[0], $size[1]));
        $out = imagecreatetruecolor(
            max(1, (int) ($size[0] * $factor)),
            max(1, (int) ($size[1] * $factor)),
        );
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled(
            $out,
            $source,
            0,
            0,
            0,
            0,
            imagesx($out),
            imagesy($out),
            $size[0],
            $size[1],
        );
        ob_start();
        try {
            if (!imagepng($out, null, 6)) {
                throw new \DomainException("Vorschaubild nicht speicherbar.");
            }
            $png = ob_get_contents();
        } finally {
            ob_end_clean();
            unset($source, $out);
        }
        Db::atomic($this->db, function () use ($access, $id, $url, $png) {
            $access->require('social','read');
            $current=Db::run($this->db,'SELECT * FROM native_social_objects WHERE club_id=? AND id=? FOR UPDATE',[$access->club(),$id])->fetch();
            if(!$current)throw new \DomainException('Originalbeitrag inzwischen entfernt.');
            $data = json_decode(
                $current["payload_json"],
                true,
                32,
                JSON_THROW_ON_ERROR,
            );
            if (($data["thumbnail"] ?? "") !== $url) {
                throw new \DomainException(
                    "Bildquelle während Abruf geändert.",
                );
            }
            if($current['thumbnail_blob']!==null&&$current['thumbnail_blob']!==$png)(new OriginalArchive($this->db,$this->box))->retain($access->club(),'object',$current,'changed');
            Db::run(
                $this->db,
                "UPDATE native_social_objects SET thumbnail_blob=?,thumbnail_sha256=? WHERE club_id=? AND id=?",
                [$png, hash("sha256", $png), $access->club(), $id],
            );
        });
        return $png;
    }
}
