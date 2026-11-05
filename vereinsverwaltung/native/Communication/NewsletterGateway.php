<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use PDO;
use Kulturhus\Native\Shared\{Db, SecretBox, Identity};
use Kulturhus\Native\Delivery\Outbox;
/** Anonymous capabilities are bounded tokens; no operator grants are exposed publicly. */
final class NewsletterGateway
{
    public function __construct(
        private PDO $db,
        private string $club,
        private SecretBox $box,
        private ?string $mediaRoot = null,
    ) {}
    /** Pure input validation; no database, brand, token or transport is accessed. */
    public static function signupInput(string $email, string $name, array $purposes, bool $consent): array
    {
        $email = Content::email($email);
        $name = Content::text($name, 200);
        if (!$consent) {
            throw new \DomainException("Bitte Einwilligung bestätigen.");
        }
        if (
            !$purposes ||
            !array_is_list($purposes) ||
            array_filter($purposes, static fn($purpose) => !is_string($purpose)) ||
            array_diff($purposes, ["events", "news"])
        ) {
            throw new \DomainException("Mindestens ein gültiges Thema wählen.");
        }
        $purposes = array_values(array_unique($purposes));
        sort($purposes);
        return ['email'=>$email, 'name'=>$name, 'purposes'=>$purposes];
    }
    public function signup(
        string $email,
        string $name,
        array $purposes,
        string $ip,
        bool $consent,
        string $honeypot = "",
    ): void {
        $input = self::signupInput($email, $name, $purposes, $consent);
        $email = $input['email'];
        $name = $input['name'];
        $purposes = $input['purposes'];
        if (
            $honeypot !== "" ||
            !$this->rate("ip:" . $ip, 8, 3600) ||
            !$this->rate("email:" . $email, 3, 86400)
        ) {
            return;
        }
        $origin = Identity::forClub($this->db, $this->club)["origin"];
        if ($origin === "") {
            throw new \DomainException(
                "Newsletter ist noch nicht eingerichtet.",
            );
        }
        Db::atomic(
            $this->db,
            fn() => $this->prepare($email, $name, $purposes, $origin),
        );
    }
    /** Internal original-application bridge; validates opt-in again before creating any token. */
    public function applicationRequest(string $request): array
    {
        $managerClub = \Kulturhus\Native\Integration\ManagerClub::id(
            $this->db,
            $this->club,
        );
        if (!$managerClub || !preg_match('/^[a-f0-9]{32}$/D', $request)) {
            throw new \DomainException("Bestätigten eigenen Antrag angeben.");
        }
        return Db::atomic($this->db, function () use (
            $managerClub,
            $request,
        ): array {
            $original = Db::run(
                $this->db,
                "SELECT c.* FROM application_confirmations c JOIN membership_applications a ON a.club_id=c.club_id AND a.id=c.application_id WHERE c.club_id=? AND c.id=? AND c.confirmed_at IS NOT NULL AND c.consent=1 AND c.newsletter=1 FOR UPDATE",
                [$managerClub, $request],
            )->fetch();
            if (!$original) {
                throw new \DomainException(
                    "Bestätigter Antrag mit ausdrücklicher Newsletter-Einwilligung fehlt.",
                );
            }
            $prior = Db::run(
                $this->db,
                "SELECT l.*,j.status delivery_status FROM native_application_newsletters l LEFT JOIN native_delivery_jobs j ON j.id=l.job_id AND j.club_id=l.club_id WHERE l.club_id=? AND l.request_id=?",
                [$this->club, $request],
            )->fetch();
            if ($prior) {
                return [
                    "status" => $prior["delivery_status"] ?? $prior["status"],
                    "created" => false,
                ];
            }
            $email = Content::email($original["email"]);
            $answers = json_decode(
                $original["payload"],
                true,
                32,
                JSON_THROW_ON_ERROR,
            );
            $name = Content::text($answers["first_name"] ?? "", 200);
            $origin = Identity::forClub($this->db, $this->club)["origin"];
            if ($origin === "") {
                throw new \DomainException(
                    "Newsletter ist noch nicht eingerichtet.",
                );
            }
            $record = [
                "subscriber" => null,
                "job" => null,
                "status" => "blocked",
            ];
            $job = false;
            if (
                !Db::run(
                    $this->db,
                    "SELECT 1 FROM native_newsletter_blocks WHERE club_id=? AND email_hash=?",
                    [$this->club, $this->box->fingerprint("email:" . $email)],
                )->fetchColumn()
            ) {
                $existing = Db::run(
                    $this->db,
                    "SELECT id,status FROM khn_newsletter_subscribers WHERE club_id=? AND email=? FOR UPDATE",
                    [$this->club, $email],
                )->fetch();
                if ($existing && $existing["status"] === "confirmed") {
                    $record = [
                        "subscriber" => $existing["id"],
                        "job" => null,
                        "status" => "confirmed",
                    ];
                } else {
                    $job = $existing
                        ? Db::run(
                            $this->db,
                            "SELECT j.id FROM khn_newsletter_tokens t JOIN native_delivery_jobs j ON j.club_id=t.club_id AND j.dedupe=SHA2(CONCAT('confirm:',t.token_hash),256) AND j.kind='confirm' WHERE t.club_id=? AND t.subscriber_id=? AND t.kind='confirm' AND t.consumed_at IS NULL AND t.expires_at>UTC_TIMESTAMP() AND j.status IN ('pending','processing','sent','uncertain') ORDER BY t.created_at DESC LIMIT 1",
                            [$this->club, $existing["id"]],
                        )->fetchColumn()
                        : false;
                    $record = $job
                        ? [
                            "subscriber" => $existing["id"],
                            "job" => $job,
                            "status" => "queued",
                        ]
                        : $this->prepare(
                            $email,
                            $name,
                            ["events", "news"],
                            $origin,
                            "membership-confirmed:" .
                                $original["application_id"],
                            $request,
                        );
                }
            }
            Db::run(
                $this->db,
                "INSERT INTO native_application_newsletters(club_id,request_id,application_id,subscriber_id,job_id,status,created_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP())",
                [
                    $this->club,
                    $request,
                    $original["application_id"],
                    $record["subscriber"],
                    $record["job"],
                    $record["status"],
                ],
            );
            $state = $record["job"]
                ? Db::run(
                    $this->db,
                    "SELECT status FROM native_delivery_jobs WHERE club_id=? AND id=?",
                    [$this->club, $record["job"]],
                )->fetchColumn()
                : $record["status"];
            return [
                "status" => $state,
                "created" => (bool) $record["job"] && !$job,
            ];
        });
    }
    private function prepare(
        string $email,
        string $name,
        array $purposes,
        string $origin,
        string $evidence = "public-doi-request",
        ?string $applicationRequest = null,
    ): array {
        $old = Db::run(
            $this->db,
            "SELECT id,status FROM khn_newsletter_subscribers WHERE club_id=? AND email=? FOR UPDATE",
            [$this->club, $email],
        )->fetch();
        if ($old && $old["status"] === "confirmed") {
            return [
                "subscriber" => $old["id"],
                "job" => null,
                "status" => "confirmed",
            ];
        }
        $id = $old["id"] ?? bin2hex(random_bytes(16));
        $now = gmdate("Y-m-d H:i:s");
        Db::run(
            $this->db,
            "INSERT INTO khn_newsletter_subscribers(club_id,id,email,name,status,purposes,consent_evidence,version,created_at,updated_at) VALUES(?,?,?,?,'pending',?,?,1,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),status='pending',purposes=VALUES(purposes),consent_evidence=VALUES(consent_evidence),version=version+1,confirmed_at=NULL,unsubscribed_at=NULL,updated_at=VALUES(updated_at)",
            [
                $this->club,
                $id,
                $email,
                $name,
                json_encode($purposes),
                $evidence,
                $now,
                $now,
            ],
        );
        Db::run(
            $this->db,
            "DELETE FROM khn_newsletter_tokens WHERE club_id=? AND subscriber_id=? AND kind='confirm'",
            [$this->club, $id],
        );
        $token = bin2hex(random_bytes(32));
        $hash = hash("sha256", $token);
        Db::run(
            $this->db,
            "INSERT INTO khn_newsletter_tokens(token_hash,club_id,subscriber_id,kind,expires_at,created_at) VALUES(?,?,?,'confirm',?,?)",
            [
                $hash,
                $this->club,
                $id,
                gmdate("Y-m-d H:i:s", time() + 172800),
                $now,
            ],
        );
        $url = $origin . "/newsletter/bestaetigen/?token=" . $token;
        $unsubscribe =
            $origin .
            "/newsletter/abmelden/?token=" .
            $this->unsubscribeToken($id);
        $brand = Confirmations\Message::brand(
            $this->db,
            $this->club,
            $this->mediaRoot ??
                (getenv("KH_NATIVE_MANAGER_MEDIA_ROOT") ?:
                getenv("KH_NATIVE_MEDIA_ROOT") ?:
                    null),
        );
        $message = Confirmations\Message::compose(
            $brand,
            $url,
            $unsubscribe,
            false,
        );
        $source =
            $applicationRequest !== null
                ? ["application_confirmation" => $applicationRequest]
                : [];
        $job = (new Outbox($this->db, $this->box))->enqueue(
            $this->club,
            "confirm",
            $hash,
            [
                "subscriber" => $id,
                "confirmation_hash" => $hash,
                "to" => $email,
            ] +
                $source +
                $message,
            $now,
        );
        return ["subscriber" => $id, "job" => $job, "status" => "queued"];
    }
    private function rate(string $value, int $limit, int $seconds): bool
    {
        return Db::atomic($this->db, function () use (
            $value,
            $limit,
            $seconds,
        ) {
            $hash = $this->box->fingerprint($value);
            $bucket = intdiv(time(), $seconds);
            Db::run(
                $this->db,
                "INSERT INTO native_newsletter_rates(club_id,fingerprint,bucket,count) VALUES(?,?,?,1) ON DUPLICATE KEY UPDATE count=count+1",
                [$this->club, $hash, $bucket],
            );
            return (int) Db::run(
                $this->db,
                "SELECT count FROM native_newsletter_rates WHERE club_id=? AND fingerprint=? AND bucket=? FOR UPDATE",
                [$this->club, $hash, $bucket],
            )->fetchColumn() <= $limit;
        });
    }
    public function redeem(
        #[\SensitiveParameter] string $token,
        string $kind,
    ): bool {
        if (
            !in_array($kind, ["confirm", "unsubscribe"], true) ||
            !preg_match('/^[a-f0-9]{64}$/D', $token)
        ) {
            return false;
        }
        $hash = hash("sha256", $token);
        $record = Db::run(
            $this->db,
            "SELECT subscriber_id FROM khn_newsletter_tokens WHERE club_id=? AND token_hash=? AND kind=?",
            [$this->club, $hash, $kind],
        )->fetch();
        if (!$record) {
            return false;
        }
        return Db::atomic($this->db, function () use ($hash, $kind, $record) {
            $s = Db::run(
                $this->db,
                "SELECT * FROM khn_newsletter_subscribers WHERE club_id=? AND id=? FOR UPDATE",
                [$this->club, $record["subscriber_id"]],
            )->fetch();
            $t = Db::run(
                $this->db,
                "SELECT * FROM khn_newsletter_tokens WHERE club_id=? AND token_hash=? FOR UPDATE",
                [$this->club, $hash],
            )->fetch();
            if (
                !$s ||
                !$t ||
                ($t["expires_at"] !== null &&
                    $t["expires_at"] <= gmdate("Y-m-d H:i:s"))
            ) {
                return false;
            }
            if ($kind === "confirm") {
                if ($t["consumed_at"] !== null || $s["status"] !== "pending") {
                    return false;
                }
                Db::run(
                    $this->db,
                    "UPDATE khn_newsletter_subscribers SET status='confirmed',confirmed_at=UTC_TIMESTAMP(),unsubscribed_at=NULL,version=version+1,updated_at=UTC_TIMESTAMP() WHERE club_id=? AND id=?",
                    [$this->club, $s["id"]],
                );
                Db::run(
                    $this->db,
                    "DELETE FROM native_newsletter_blocks WHERE club_id=? AND email_hash=?",
                    [
                        $this->club,
                        $this->box->fingerprint("email:" . $s["email"]),
                    ],
                );
            } else {
                if ($s["status"] === "unsubscribed") {
                    return true;
                }
                $fingerprint = $this->box->fingerprint("email:" . $s["email"]);
                Db::run(
                    $this->db,
                    "INSERT IGNORE INTO native_newsletter_blocks(club_id,email_hash,created_at) VALUES(?,?,UTC_TIMESTAMP())",
                    [$this->club, $fingerprint],
                );
                Db::run(
                    $this->db,
                    "UPDATE khn_newsletter_subscribers SET email=?,name='',status='unsubscribed',purposes='[]',consent_evidence='',unsubscribed_at=UTC_TIMESTAMP(),version=version+1,updated_at=UTC_TIMESTAMP() WHERE club_id=? AND id=?",
                    [$s["id"] . "@removed.invalid", $this->club, $s["id"]],
                );
                Db::run(
                    $this->db,
                    "UPDATE khn_newsletter_consent_events SET evidence='',actor_id='' WHERE club_id=? AND subscriber_id=?",
                    [$this->club, $s["id"]],
                );
                Db::run(
                    $this->db,
                    "DELETE FROM khn_newsletter_tokens WHERE club_id=? AND subscriber_id=? AND kind='confirm'",
                    [$this->club, $s["id"]],
                );
                // Private pending payloads also contain addresses and token links. Remove them on withdrawal.
                $jobs = Db::run(
                    $this->db,
                    "SELECT id,ciphertext FROM native_delivery_jobs WHERE club_id=? FOR UPDATE",
                    [$this->club],
                )->fetchAll();
                foreach ($jobs as $job) {
                    $p = $this->box->open(
                        "job:" . $this->club . ":" . $job["id"],
                        $job["ciphertext"],
                    );
                    if (($p["subscriber"] ?? null) === $s["id"]) {
                        Db::run(
                            $this->db,
                            "UPDATE native_delivery_jobs SET status=IF(status='pending','cancelled',status),reason=IF(status='pending','consent_withdrawn',reason),ciphertext=? WHERE id=?",
                            [
                                $this->box->seal(
                                    "job:" . $this->club . ":" . $job["id"],
                                    [],
                                ),
                                $job["id"],
                            ],
                        );
                    }
                }
            }
            Db::run(
                $this->db,
                "UPDATE khn_newsletter_tokens SET consumed_at=UTC_TIMESTAMP() WHERE club_id=? AND token_hash=?",
                [$this->club, $hash],
            );
            return true;
        });
    }
    public function unsubscribeToken(string $subscriber): string
    {
        $token = bin2hex(random_bytes(32));
        Db::run(
            $this->db,
            "INSERT INTO khn_newsletter_tokens(token_hash,club_id,subscriber_id,kind,created_at) VALUES(?,?,?,'unsubscribe',UTC_TIMESTAMP())",
            [hash("sha256", $token), $this->club, $subscriber],
        );
        return $token;
    }
}
