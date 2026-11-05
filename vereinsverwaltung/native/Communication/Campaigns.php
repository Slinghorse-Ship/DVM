<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use PDO;
use Kulturhus\Native\Shared\{Access, Db, Identity, SecretBox, MailDesign};
use Kulturhus\Native\Delivery\Outbox;
final class Campaigns
{
    public function __construct(
        private PDO $db,
        private Access $access,
        private SecretBox $box,
        private ?string $mediaRoot = null,
    ) {}
    public function viewLink(string $id, bool $test = false): string
    {
        $item = (new Workspace($this->db, $this->access, "newsletter"))->get(
            $id,
        );
        $this->access->require("newsletter", $test ? "edit" : "publish");
        if (
            !$test &&
            !in_array($item["status"], ["approved", "scheduled"], true)
        ) {
            throw new \DomainException(
                "Browseransicht benötigt eine Freigabe.",
            );
        }
        $origin = Identity::forClub($this->db, $this->access->club())["origin"];
        if ($origin === "") {
            throw new \DomainException("Vereinsdomain fehlt.");
        }
        $token = bin2hex(random_bytes(32));
        $item["_media_usage"] = ImageUsage::item($this->db, $this->access->club(), $item, "newsletter");
        $item["_identity"] = Identity::forClub(
            $this->db,
            $this->access->club(),
        );
        if ($this->mediaRoot !== null) {
            $item = (new Assets(
                $this->db,
                $this->access,
                $this->mediaRoot,
            ))->freeze($item, $test);
        } elseif (
            $item["_identity"]["logo_id"] !== "" ||
            array_filter(
                $item["blocks"],
                static fn($b) => $b["type"] === "image",
            )
        ) {
            throw new \DomainException(
                "Bildspeicher für diese Ausgabe erforderlich.",
            );
        }
        $brand=$item['_identity'];if($brand['logo_id']!=='')$brand['logo_url']=$item['_image_urls'][$brand['logo_id']]??'';
        $item['_mail_design']=MailDesign::brand($brand);
        Db::run(
            $this->db,
            "INSERT INTO native_campaign_views(token_hash,club_id,item_id,item_version,snapshot_json,expires_at,is_test,created_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP())",
            [
                hash("sha256", $token),
                $this->access->club(),
                $id,
                $item["version"],
                json_encode($item, JSON_THROW_ON_ERROR),
                $test ? gmdate("Y-m-d H:i:s", time() + 7 * 86400) : null,
                (int) $test,
            ],
        );
        return $origin . "/newsletter/ausgabe/?token=" . $token;
    }
    public static function publicView(
        PDO $db,
        string $club,
        string $token,
    ): ?array {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return null;
        }
        $r = Db::run(
            $db,
            "SELECT snapshot_json,is_test FROM native_campaign_views WHERE club_id=? AND token_hash=? AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP())",
            [$club, hash("sha256", $token)],
        )->fetch();
        return $r
            ? [
                "item" => json_decode(
                    $r["snapshot_json"],
                    true,
                    64,
                    JSON_THROW_ON_ERROR,
                ),
                "test" => (bool) $r["is_test"],
            ]
            : null;
    }
    public function queue(
        string $id,
        string $due,
        ?string $testEmail = null,
    ): array {
        $item = (new Workspace($this->db, $this->access, "newsletter"))->get(
            $id,
        );
        $this->access->require(
            "newsletter",
            $testEmail === null ? "publish" : "edit",
        );
        if (
            $testEmail === null &&
            (!in_array($item["status"], ["approved", "scheduled"], true) ||
                empty($item["approved_by"]))
        ) {
            throw new \DomainException("Aktuelle Ausgabe benötigt Freigabe.");
        }
        $club = $this->access->club();
        $digest = Digests\Plans::edition($this->db, $club, $id);
        if ($testEmail === null && $digest) {
            Digests\Plans::guard($this->db, $club, $digest);
            $at = \DateTimeImmutable::createFromFormat(
                "!Y-m-d H:i:s",
                $due,
                new \DateTimeZone("UTC"),
            );
            if (
                !$at ||
                $at->format("Y-m-d H:i:s") !== $due ||
                $at < new \DateTimeImmutable($digest["schedule"]["send_at"]) ||
                $at > new \DateTimeImmutable($digest["schedule"]["deadline"])
            ) {
                throw new \DomainException(
                    "Diese Periodenausgabe nur im angezeigten Versandfenster einplanen.",
                );
            }
        }
        $identity = Identity::forClub($this->db, $club);
        $origin = $identity["origin"];
        if ($origin === "") {
            throw new \DomainException("Vereinsdomain fehlt.");
        }
        $link = $this->viewLink($id, $testEmail !== null);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        $snapshot = self::publicView($this->db, $club, $query["token"])["item"];
        if(($snapshot['id']??null)!==$item['id']||(int)($snapshot['version']??0)!==(int)$item['version'])throw new \DomainException('Newsletterausgabe inzwischen geändert. Erneut öffnen.');
        $viewHash=hash('sha256',$query['token']);
        $snapshotHash=Db::run($this->db,'SELECT SHA2(snapshot_json,256) FROM native_campaign_views WHERE club_id=? AND token_hash=?',[$club,$viewHash])->fetchColumn();
        if(!is_string($snapshotHash)||!preg_match('/\A[a-f0-9]{64}\z/D',$snapshotHash))throw new \DomainException('Ursprüngliche Newsletterfassung prüfen.');
        $snapshot["browser_url"] = $link;
        $recipients =
            $testEmail !== null
                ? [["id" => null, "email" => Content::email($testEmail)]]
                : array_map(
                    fn($r) => (new Subscribers($this->db, $this->access))->get(
                        $r["id"],
                    ),
                    (new Subscribers($this->db, $this->access))->eligible(
                        $item["audience"],
                    ),
                );
        $documents=($item['document_attachments']??[])?(new DocumentAttachments($this->db,$this->access,$this->mediaRoot??throw new \DomainException('PDF-Speicher fehlt.')))->freeze($item['document_attachments'],$testEmail!==null):[];
        $ids = [];
        foreach ($recipients as $recipient) {
            $unsubscribe =
                $testEmail !== null
                    ? ""
                    : $origin .
                        "/newsletter/abmelden/?token=" .
                        (new NewsletterGateway(
                            $this->db,
                            $club,
                            $this->box,
                        ))->unsubscribeToken($recipient["id"]);
            $body = Content::preview($snapshot);
            $body .=
                '<p><a href="' .
                htmlspecialchars($link, ENT_QUOTES) .
                '">Im Browser lesen</a></p>';
            if ($unsubscribe !== "") {
                $body .=
                    '<p><a href="' .
                    htmlspecialchars($unsubscribe, ENT_QUOTES) .
                    '">Newsletter abmelden</a></p>';
            } else {
                $body =
                    "<p>TESTVORSCHAU · keine wirksame Abmeldung</p>" . $body;
            }
            $plainBody=$body;
            if(isset($snapshot['_mail_design']))$body=MailDesign::newsletter($snapshot,Content::preview($snapshot,null,false,false),$link,$unsubscribe,$testEmail!==null);
            $p = [
                'newsletter_original'=>['item'=>$id,'version'=>(int)$item['version'],'view_hash'=>$viewHash,'snapshot_sha256'=>$snapshotHash],
                "to" => $recipient["email"],
                "subject" =>
                    ($testEmail !== null ? "[Test] " : "") . $item["title"],
                "html" => $body,
                "text" => strip_tags(
                    str_replace(["</p>", "<br>"], "\n", $plainBody),
                ),
                "media_usage" => $snapshot["_media_usage"],
                "headers" =>
                    $unsubscribe !== ""
                        ? [
                            "List-Unsubscribe" => "<" . $unsubscribe . ">",
                            "List-Unsubscribe-Post" =>
                                "List-Unsubscribe=One-Click",
                        ]
                        : [],
            ];
            if(!empty($snapshot['_mail_design']['logo_png']))$p['logo_png']=$snapshot['_mail_design']['logo_png'];
            if($documents)$p['document_attachments']=$documents;
            if ($testEmail === null && $digest) {
                $p["digest"] = [
                    "item_id" => $id,
                    "period_key" => $digest["period_key"],
                    "events" => Digests\Announcements::selected(
                        $digest,
                        $snapshot["blocks"],
                    ),
                ];
            }
            if ($testEmail === null) {
                $p += [
                    "subscriber" => $recipient["id"],
                    "audience" => $item["audience"],
                    "approval" => [
                        "actor" => $item["approved_by"],
                        "authority" => $item["approved_authority"] ?? "native",
                        "item" => $id,
                        "version" => $item["version"],
                    ],
                ];
            }
            $ids[] = (new Outbox($this->db, $this->box))->enqueue(
                $club,
                $testEmail === null ? "campaign" : "test",
                $id .
                    ":" .
                    $item["version"] .
                    ":" .
                    ($recipient["id"] ?? $recipient["email"]),
                $p,
                $due,
                $this->access,
                $testEmail === null ? "publish" : "edit",
            );
        }
        return $ids;
    }
    public function remind(string $subscriber): string
    {
        return (new ConfirmationReminders(
            $this->db,
            $this->access,
            $this->box,
        ))->queue($subscriber);
    }
    public function planDigest(
        string $period,
        bool $paused = false,
        int $version = 0,
        int $prepareDays = 7,
        int $sendHour = 10,
    ): void {
        (new Digests\Plans($this->db, $this->access))->save(
            $period,
            $paused,
            $version,
            $prepareDays,
            $sendHour,
        );
    }
    public static function runDigests(
        PDO $db,
        SecretBox $box,
        ?\DateTimeImmutable $now = null,
        ?string $club = null,
    ): array {
        return Digests\Planner::run($db, $now, $club);
    }
}
