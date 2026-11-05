<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use PDO;
use Kulturhus\Native\Shared\{Access, Db, Identity, SecretBox};
use Kulturhus\Native\Delivery\{Outbox, UncertainDelivery, DeferredDelivery};
use Kulturhus\Native\Integration\{Accounts, JsonHttp};
/** Approved text/image/channel snapshots. Never creates Facebook events. */
final class Meta
{
    private \Closure $http;
    private \Closure $uploadHttp;
    public function __construct(
        private PDO $db,
        private SecretBox $box,
        ?callable $http = null,
        private ?string $mediaRoot = null,
        ?callable $uploadHttp = null,
    ) {
        $this->uploadHttp=$uploadHttp?\Closure::fromCallable($uploadHttp):static fn($m,$p,$h,$b)=>JsonHttp::request('meta-upload',$m,$p,$h,$b);
        $this->http = $http
            ? \Closure::fromCallable($http)
            : static fn($m, $p, $h, $b) => JsonHttp::request(
                "meta",
                $m,
                $p,
                $h,
                $b,
            );
    }
    public function queue(
        Access $access,
        string $itemId,
        string $due,
        string $mediaRoot,
        array $automaticStory = [],
    ): array {
        return Db::atomic($this->db, function () use (
            $access,
            $itemId,
            $due,
            $mediaRoot,
            $automaticStory,
        ) {
            $publication = PublicationSnapshot::social(
                $this->db,
                $access,
                $itemId,
                $mediaRoot,
            );
            $item = $publication["item"];
            if($automaticStory!==[]&&($item['kind']!=='story'||array_diff($item['channels'],['facebook','instagram'])))throw new \DomainException('Die Story-Automatik gilt für Facebook und Instagram.');
            if (!empty($item["story_link_requested"])) {
                throw new \DomainException(
                    "Link-Story manuell veröffentlichen: Datei herunterladen und Link-Sticker vor der Veröffentlichung in der App einsetzen. Der API-Weg fügt keinen klickbaren Link hinzu.",
                );
            }
            $club = $access->club();
            $identity = Identity::forClub($this->db, $club);
            if ($identity["origin"] === "") {
                throw new \DomainException("Vereinsdomain fehlt.");
            }
            $account = (new Accounts($this->db, $this->box))->get(
                $club,
                "meta",
            );
            $earlier=Db::run($this->db,"SELECT j.id,j.ciphertext FROM native_delivery_jobs j JOIN native_delivery_sources s ON s.job_id=j.id AND s.club_id=j.club_id WHERE s.club_id=? AND s.module='social' AND s.item_id=? AND j.kind='meta' AND j.status IN ('processing','uncertain') FOR SHARE",[$club,$item['id']])->fetchAll();
            foreach($earlier as$job){$original=$this->box->open('job:'.$club.':'.$job['id'],$job['ciphertext']);if(($original['approval']['version']??null)!==$item['version']&&in_array($original['channel']??null,$item['channels'],true))throw new \DomainException('Früheres Veröffentlichungsergebnis für diesen Kanal zuerst prüfen.');}
            if($item['kind']==='reel')return VideoPublication::queue($this->db,$this->box,$access,$publication,$account,$due,$mediaRoot);
            if($item['kind']==='carousel')return CarouselPublication::queue($this->db,$this->box,$access,$publication,$account,$due,$mediaRoot);
            $images = $publication["images"];
            $jobs = [];
            foreach ($item["channels"] as $channel) {
                if (
                    $channel === "instagram" &&
                    $account["instagram_id"] === ""
                ) {
                    throw new \DomainException(
                        "Instagram-Konto nicht eingerichtet.",
                    );
                }
                foreach ($images as $slide => $image) {
                    (new Images($this->db, $access, $mediaRoot))->file(
                        $image["id"],
                    );
                    $token = bin2hex(random_bytes(32));
                    $expiry = (new \DateTimeImmutable(
                        $due,
                        new \DateTimeZone("UTC"),
                    ))
                        ->modify("+7 days")
                        ->format("Y-m-d H:i:s");
                    Db::run(
                        $this->db,
                        'INSERT INTO native_image_capabilities(token_hash,club_id,image_id,expires_at,format) VALUES(?,?,?,?,\'jpeg\')',
                        [hash("sha256", $token), $club, $image["id"], $expiry],
                    );
                    $imageMeta = json_decode(
                        $image["metadata_json"],
                        true,
                        32,
                        JSON_THROW_ON_ERROR,
                    );
                    if (empty($imageMeta["jpeg_sha256"])) {
                        throw new \DomainException(
                            "JPEG-Ausgabe fehlt. Bilder erneut erzeugen und freigeben.",
                        );
                    }
                    $p = [
                        "channel" => $channel,
                        "kind" => $item["kind"],
                        "image_id" => $image["id"],
                        "image_hash" => $imageMeta["jpeg_sha256"],
                        "image_url" =>
                            $identity["origin"] .
                            "/publication-image/" .
                            $token,
                        "caption" =>
                            $item["title"] .
                            "\n\n" .
                            $item["body"] .
                            (($item["destination_url"] ?? "") !== ""
                                ? "\n" . $item["destination_url"]
                                : ""),
                        "page_id" => $account["page_id"],
                        "instagram_id" => $account["instagram_id"],
                        "connection_id" => Accounts::binding($account),
                        "approval" => [
                            "actor" => $item["approved_by"],
                            "authority" =>
                                $item["approved_authority"] ?? "native",
                            "item" => $itemId,
                            "version" => $item["version"],
                        ],
                    ];
                    if($automaticStory!==[]){
                        $p['auto_story']=$automaticStory;
                        StoryAutomation::authorize($this->db,['club_id'=>$club,'kind'=>'meta','actor_id'=>$access->user()['id'],'due_at'=>$due],$p);
                    }
                    $jobs[] = (new Outbox($this->db, $this->box))->enqueue(
                        $club,
                        "meta",
                        $itemId .
                            ":" .
                            $item["version"] .
                            ":" .
                            $channel .
                            ":" .
                            $slide,
                        $p,
                        $due,
                        $access,
                    );
                }
            }
            return $jobs;
        });
    }
    private function call(
        array $account,
        string $method,
        string $path,
        ?array $body = null,
    ): array {
        $r = ($this->http)(
            $method,
            "/" . $account["api_version"] . "/" . $path,
            ["Authorization: Bearer " . $account["token"]],
            $body,
        );
        if (
            ($r["status"] ?? 0) < 200 ||
            ($r["status"] ?? 0) >= 300 ||
            !is_array($r["body"] ?? null) ||
            isset($r["body"]["error"])
        ) {
            throw new \RuntimeException("Meta-Aufruf nicht bestätigt.");
        }
        return $r["body"];
    }
    private function id(array $response): string
    {
        $id = $response["id"] ?? null;
        if (!is_string($id) || !preg_match('/^[0-9_]{1,100}$/D', $id)) {
            throw new \RuntimeException("Meta-Kennung nicht bestätigt.");
        }
        return $id;
    }
    public function send(string $club, array $p, array $job = []): string
    {
        if(($p['kind']??null)==='carousel'){
            if($this->mediaRoot===null)throw new \DomainException('Privaten Karussell-Bildspeicher prüfen.');
            $access=\Kulturhus\Native\Shared\Authority::resolve($this->db,['created_by'=>$p['approval']['actor']??'','authority'=>$p['approval']['authority']??'native','club_id'=>$club]);
            CarouselPublication::guard($this->db,$access,$p,$this->mediaRoot);
            $stored=Db::run($this->db,"SELECT ciphertext FROM native_delivery_jobs WHERE id=? AND club_id=? AND kind='meta'",[Content::id($job['id']??''),$club])->fetchColumn();
            if(!is_string($stored)||CarouselPublication::fingerprint($this->box->open('job:'.$club.':'.$job['id'],$stored))!==CarouselPublication::fingerprint($p))throw new \DomainException('Originalen ganzen Karussell-Auftrag verwenden.');
            $account=(new Accounts($this->db,$this->box))->get($club,'meta');
            if($account['page_id']!==$p['page_id']||$account['instagram_id']!==$p['instagram_id']||!hash_equals(Accounts::binding($account),$p['connection_id']))throw new \DomainException('Meta-Zielkonto oder Verbindung geändert.');
            return (new MetaCarousels($this->db,fn($a,$m,$path,$body)=>$this->call($a,$m,$path,$body)))->send($club,$account,$p,$job);
        }
        if(($p['kind']??null)==='reel'){
            if($this->mediaRoot===null)throw new \DomainException('Privaten Videospeicher prüfen.');
            $access=\Kulturhus\Native\Shared\Authority::resolve($this->db,['created_by'=>$p['approval']['actor']??'','authority'=>$p['approval']['authority']??'native','club_id'=>$club]);
            VideoPublication::guard($this->db,$access,$p,$this->mediaRoot);
            $account=(new Accounts($this->db,$this->box))->get($club,'meta');
            if($account['page_id']!==$p['page_id']||$account['instagram_id']!==$p['instagram_id']||!hash_equals(Accounts::binding($account),$p['connection_id']))throw new \DomainException('Meta-Zielkonto oder Verbindung geändert.');
            return (new MetaReels(fn($a,$m,$path,$body)=>$this->call($a,$m,$path,$body),$this->uploadHttp))->send($account,$p,$job);
        }
        ImageUsage::storedImage($this->db, $club, $p["image_id"]);
        $a = (new Accounts($this->db, $this->box))->get($club, "meta");
        if (
            $a["page_id"] !== $p["page_id"] ||
            $a["instagram_id"] !== $p["instagram_id"] ||
            (isset($p["connection_id"]) &&
                !hash_equals($p["connection_id"], Accounts::binding($a)))
        ) {
            throw new \DomainException(
                "Meta-Zielkonto oder Verbindung geändert.",
            );
        }
        $row = Db::run(
            $this->db,
            "SELECT metadata_json FROM native_social_images WHERE club_id=? AND id=? FOR SHARE",
            [$club, $p["image_id"]],
        )->fetch();
        if (
            !$row ||
            !hash_equals(
                json_decode(
                    $row["metadata_json"],
                    true,
                    32,
                    JSON_THROW_ON_ERROR,
                )["jpeg_sha256"] ?? "",
                $p["image_hash"],
            )
        ) {
            throw new \DomainException("Freigegebenes Bild fehlt.");
        }
        if ($p["channel"] === "facebook") {
            if ($p["kind"] !== "story") {
                $photo = $this->id(
                    $this->call($a, "POST", $a["page_id"] . "/photos", [
                        "url" => $p["image_url"],
                        "caption" => $p["caption"],
                        "published" => true,
                    ]),
                );
                return "facebook:photo:" . $photo;
            }
            if (
                !preg_match(
                    '/^facebook:upload:([0-9_]{1,100})$/D',
                    $job["external_id"] ?? "",
                    $known,
                )
            ) {
                $photo = $this->id(
                    $this->call($a, "POST", $a["page_id"] . "/photos", [
                        "url" => $p["image_url"],
                        "published" => false,
                    ]),
                );
                throw new DeferredDelivery(
                    "facebook:upload:" . $photo,
                    "story_upload_prepared",
                );
            }
            $photo = $known[1];
            try {
                $result = $this->call(
                    $a,
                    "POST",
                    $a["page_id"] . "/photo_stories",
                    ["photo_id" => $photo],
                );
                $story = $result["post_id"] ?? ($result["id"] ?? null);
                if (
                    !is_string($story) ||
                    !preg_match('/^[0-9_]{1,100}$/D', $story)
                ) {
                    throw new \RuntimeException("Story-Ergebnis fehlt.");
                }
                return "facebook:story:" . $story;
            } catch (\Throwable) {
                throw new UncertainDelivery(
                    "facebook:upload:" . $photo,
                    "story_publish_unknown",
                );
            }
        }
        if ($p["channel"] !== "instagram" || $a["instagram_id"] === "") {
            throw new \DomainException("Instagram-Ziel fehlt.");
        }
        $body = ["image_url" => $p["image_url"]];
        if ($p["kind"] === "story") {
            $body["media_type"] = "STORIES";
        } else {
            $body["caption"] = $p["caption"];
        }
        if (
            !preg_match(
                '/^instagram:container:([0-9_]{1,100})$/D',
                $job["external_id"] ?? "",
                $known,
            )
        ) {
            $container = $this->id(
                $this->call($a, "POST", $a["instagram_id"] . "/media", $body),
            );
            throw new DeferredDelivery(
                "instagram:container:" . $container,
                "container_waiting",
            );
        }
        $container = $known[1];
        try {
            $state = $this->call($a, "GET", $container . "?fields=status_code");
            if (($state["status_code"] ?? "") === "PUBLISHED") {
                return "instagram:container:" . $container;
            }
            if (($state["status_code"] ?? "") !== "FINISHED") {
                if (
                    ($state["status_code"] ?? "") === "IN_PROGRESS" &&
                    (int) ($job["attempts"] ?? 0) < 5
                ) {
                    throw new DeferredDelivery(
                        "instagram:container:" . $container,
                        "container_waiting",
                    );
                }
                throw new UncertainDelivery(
                    "instagram:container:" . $container,
                    "container_not_ready",
                );
            }
            ImageUsage::storedImage($this->db, $club, $p["image_id"]);
            $media = $this->id(
                $this->call($a, "POST", $a["instagram_id"] . "/media_publish", [
                    "creation_id" => $container,
                ]),
            );
            return "instagram:media:" . $media;
        } catch (DeferredDelivery | UncertainDelivery $e) {
            throw $e;
        } catch (\Throwable) {
            throw new UncertainDelivery(
                "instagram:container:" . $container,
                "publish_unknown",
            );
        }
    }
    /** Read only. An unpublished ready container is never assumed to be a published post. */
    public function reconcile(Access $access, string $jobId): array
    {
        $access->require("social", "publish");
        Content::id($jobId);
        $job = Db::run(
            $this->db,
            "SELECT * FROM native_delivery_jobs WHERE club_id=? AND id=? AND kind='meta'",
            [$access->club(), $jobId],
        )->fetch();
        if($job&&in_array($job['status'],['sent','uncertain','processing','pending'],true)){
            $carouselFrame=$this->box->open('job:'.$access->club().':'.$jobId,$job['ciphertext']);
            if(($carouselFrame['kind']??null)==='carousel')return $this->reconcileCarousel($access,$job,$carouselFrame);
        }
        if (
            !$job ||
            !in_array(
                $job["status"],
                ["sent", "uncertain", "processing"],
                true,
            ) ||
            !preg_match(
                '/^(facebook|instagram):(photo|story|upload|container|media|reel-start|reel-upload|reel-publishing|reel):([0-9_]{1,100})$/D',
                $job["external_id"] ?? "",
                $match,
            )
        ) {
            throw new \DomainException(
                "Keine unabhängig prüfbare Anbieterkennung vorhanden. Nicht erneut veröffentlichen.",
            );
        }
        $p = $this->box->open(
            "job:" . $access->club() . ":" . $jobId,
            $job["ciphertext"],
        );
        $a = (new Accounts($this->db, $this->box))->get(
            $access->club(),
            "meta",
        );
        if (
            $a["page_id"] !== $p["page_id"] ||
            $a["instagram_id"] !== $p["instagram_id"] ||
            (isset($p["connection_id"]) &&
                !hash_equals($p["connection_id"], Accounts::binding($a)))
        ) {
            throw new \DomainException("Meta-Ziel oder Verbindung geändert.");
        }
        $result = $this->call(
            $a,
            "GET",
            $match[3] .
                "?fields=" .
                ($match[2] === "container" ? "id,status_code" : (str_starts_with($match[2],"reel") ? "id,status" : "id")),
        );
        if(($p['kind']??null)==='reel'&&($result['id']??null)!==$match[3])throw new \DomainException('Anbieterkennung passt nicht zum originalen Reel.');
        $published =
            $match[2] === "container" &&
            ($result["status_code"] ?? "") === "PUBLISHED";
        if($match[1]==='facebook'&&str_starts_with($match[2],'reel'))$published=MetaReels::published($result['status']??[]);
        if ($published) {
            Db::run(
                $this->db,
                "UPDATE native_delivery_jobs SET status='sent',reason='provider_reconciled',completed_at=UTC_TIMESTAMP() WHERE club_id=? AND id=? AND status IN ('uncertain','processing')",
                [$access->club(), $jobId],
            );
        }
        return [
            "reference" => $job["external_id"],
            "provider_status" => $result["status_code"] ?? ($result["status"]["video_status"]??"exists"),
            "publication_confirmed" => $job["status"] === "sent" || $published,
        ];
    }

    private function reconcileCarousel(Access $access,array $job,array $frame):array
    {
        if($job['status']==='processing')$job=Db::atomic($this->db,function()use($access,$job):array{
            $access->require('social','publish');$current=Db::run($this->db,"SELECT * FROM native_delivery_jobs WHERE id=? AND club_id=? AND kind='meta' FOR UPDATE",[$job['id'],$access->club()])->fetch();
            if(!$current)throw new \DomainException('Eigenen Karussell-Auftrag auswählen.');
            if($current['status']==='processing'&&$current['started_at']!==null&&strtotime($current['started_at'].' UTC')<=time()-600){Db::run($this->db,"UPDATE native_delivery_jobs SET status='uncertain',reason='carousel_interrupted_review' WHERE id=? AND status='processing'",[$job['id']]);$current['status']='uncertain';}
            return$current;
        });
        $club=$access->club();$account=(new Accounts($this->db,$this->box))->get($club,'meta');
        if($account['page_id']!==$frame['page_id']||$account['instagram_id']!==$frame['instagram_id']||!hash_equals(Accounts::binding($account),$frame['connection_id']))throw new \DomainException('Meta-Ziel oder Verbindung geändert.');
        $proof=(new MetaCarousels($this->db,fn($a,$m,$path,$body)=>$this->call($a,$m,$path,$body)))->check($club,$account,$frame,$job);$resumed=false;
        if($proof['publication_confirmed']){
            Db::atomic($this->db,function()use($club,$job,$frame,$proof):void{
                $current=Db::run($this->db,'SELECT status FROM native_delivery_jobs WHERE id=? AND club_id=? FOR UPDATE',[$job['id'],$club])->fetchColumn();
                // A live worker owns its receipt; the read result never steals its claim.
                if(!in_array($current,['pending','uncertain','sent'],true))return;
                if($frame['channel']==='facebook'&&($proof['rows'][10]['state']??null)==='prepared')Db::run($this->db,"UPDATE native_carousel_progress SET state='known',remote_id=?,completed_at=UTC_TIMESTAMP(6) WHERE job_id=? AND club_id=? AND slot=10 AND state='prepared' AND frame_hash=?",[$proof['remote'],$job['id'],$club,CarouselPublication::fingerprint($frame)]);
                Db::run($this->db,"UPDATE native_delivery_jobs SET status='sent',external_id=?,reason='provider_reconciled',completed_at=UTC_TIMESTAMP() WHERE id=? AND club_id=? AND status IN ('pending','uncertain')",[$proof['reference'],$job['id'],$club]);
            });
        }elseif($proof['resume_safe']&&$job['status']==='uncertain'){
            Db::atomic($this->db,function()use($access,$club,$job,$frame,&$resumed):void{
                $current=Db::run($this->db,'SELECT status FROM native_delivery_jobs WHERE id=? AND club_id=? FOR UPDATE',[$job['id'],$club])->fetchColumn();if($current!=='uncertain')return;
                CarouselPublication::guard($this->db,$access,$frame,$this->mediaRoot);
                $rows=CarouselProgress::rows($this->db,$club,$job['id'],$frame);foreach($rows as$row)if($row['state']==='prepared')throw new \DomainException('Unbekanntes Anbieterergebnis zuerst prüfen.');
                Db::run($this->db,"UPDATE native_delivery_jobs SET status='pending',reason='carousel_resume_checked',due_at=UTC_TIMESTAMP(),completed_at=NULL WHERE id=? AND club_id=? AND status='uncertain'",[$job['id'],$club]);$resumed=true;
            });
        }
        return ['reference'=>$proof['reference'],'provider_status'=>$resumed?'resume_ready':$proof['provider_status'],'publication_confirmed'=>$proof['publication_confirmed'],'resumed'=>$resumed];
    }

    /** Stop only an uncertain preparation whose durable phase history proves no public write was possible. */
    public function stopCarousel(Access $access,string $jobId):void
    {
        $access->require('social','publish');Content::id($jobId);
        Db::atomic($this->db,function()use($access,$jobId):void{
            $club=$access->club();$job=Db::run($this->db,"SELECT * FROM native_delivery_jobs WHERE id=? AND club_id=? AND kind='meta' FOR UPDATE",[$jobId,$club])->fetch();
            if(!$job||!in_array($job['status'],['uncertain','cancelled'],true))throw new \DomainException('Unterbrochene Karussell-Vorbereitung auswählen.');
            $frame=$this->box->open('job:'.$club.':'.$jobId,$job['ciphertext']);if(($frame['kind']??null)!=='carousel')throw new \DomainException('Eigenes Karussell auswählen.');
            $rows=CarouselProgress::rows($this->db,$club,$jobId,$frame);$publicationSlot=$frame['channel']==='instagram'?11:10;
            if(isset($rows[$publicationSlot]))throw new \DomainException('Mögliches Veröffentlichungsergebnis zuerst bei Meta prüfen.');
            if($job['status']==='cancelled')return;
            $access->require('social','publish');Db::run($this->db,"UPDATE native_delivery_jobs SET status='cancelled',reason='carousel_preparation_stopped',completed_at=UTC_TIMESTAMP() WHERE id=? AND club_id=? AND status='uncertain'",[$jobId,$club]);
        });
    }
}
