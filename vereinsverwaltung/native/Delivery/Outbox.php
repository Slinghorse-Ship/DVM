<?php declare(strict_types=1);
namespace Kulturhus\Native\Delivery;
use PDO;
use Kulturhus\Native\Shared\{Access, Authority, Db, SecretBox};
use Kulturhus\Native\Integration\ManagerAccess;
use Kulturhus\Native\Communication\Confirmations\Preparation;
use Kulturhus\Native\Communication\Digests\{
    Announcements,
    Expired,
    NotDue,
    Paused,
    Plans,
};
/** Durable delivery claims. Interrupted or uncertain attempts are never automatically replayed. */
final class Outbox
{
    private ?\Closure $clock;
    public function __construct(
        private PDO $db,
        private SecretBox $box,
        ?callable $clock = null,
    ) {
        $this->clock = $clock === null ? null : \Closure::fromCallable($clock);
    }
    public function enqueue(
        string $club,
        string $kind,
        string $dedupe,
        array $message,
        string $due,
        ?Access $actor = null,
        string $capability = "publish",
    ): string {
        if (
            !in_array(
                $kind,
                ["confirm", "reminder", "renewal", "campaign", "test", "press", "meta", "stadtlandfunk"],
                true,
            )
        ) {
            throw new \DomainException("Unbekannter Auftragszweck.");
        }
        if($kind==='press'&&$capability!=='publish')throw new \DomainException('Presseversand benötigt aktuelle Veröffentlichungsrechte.');
        if ($actor) {
            $u = $actor->require(
                JobPurpose::module($kind),
                $capability,
            );
            if ($u["club_id"] !== $club) {
                throw new \DomainException("Falscher Verein.");
            }
        } elseif ($kind !== "confirm") {
            throw new \DomainException("Auftrag benötigt aktuelle Autorität.");
        }
        $hash = hash("sha256", $kind . ":" . $dedupe);
        $id = bin2hex(random_bytes(16));
        return Db::atomic($this->db, function () use (
            $club,
            $kind,
            $hash,
            $id,
            $message,
            $due,
            $actor,
            $capability,
        ): string {
            if (
                $actor &&
                $actor->require(
                    JobPurpose::module($kind),
                    $capability,
                )["club_id"] !== $club
            ) {
                throw new \DomainException("Falscher Verein.");
            }
            $insert = Db::run(
                $this->db,
                "INSERT IGNORE INTO native_delivery_jobs(id,club_id,dedupe,kind,actor_id,authority,capability,due_at,status,ciphertext) VALUES(?,?,?,?,?,?,?,?,'pending',?)",
                [
                    $id,
                    $club,
                    $hash,
                    $kind,
                    $actor ? $actor->user()["id"] : "",
                    $actor instanceof ManagerAccess ? "manager" : "native",
                    $capability,
                    $due,
                    $this->box->seal("job:" . $club . ":" . $id, $message),
                ],
            );
            if ($insert->rowCount() === 1) {
                Source::record($this->db, $club, $id, $kind, $message);
            }
            return Db::run(
                $this->db,
                "SELECT id FROM native_delivery_jobs WHERE club_id=? AND dedupe=?",
                [$club, $hash],
            )->fetchColumn();
        });
    }
    public function run(
        callable $transport,
        int $limit = 50,
        array $kinds = ["confirm", "reminder", "renewal", "campaign", "test", "press"],
        ?string $club = null,
        ?array $onlyJobs = null,
    ): array {
        if ($this->db->inTransaction() || $limit < 1 || $limit > 200) {
            throw new \DomainException(
                "Eigene Worker-Verbindung und gültiges Limit erforderlich.",
            );
        }
        $results = [];
        if($onlyJobs!==null){
            if(!array_is_list($onlyJobs)||count($onlyJobs)>200)throw new \DomainException('Begrenzte Veröffentlichungsaufträge auswählen.');
            foreach($onlyJobs as$id)if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new \DomainException('Gültige Veröffentlichungsaufträge auswählen.');
            $onlyJobs=array_values(array_unique($onlyJobs));
        }
        if (
            !$kinds ||
            array_diff($kinds, [
                "confirm",
                "reminder",
                "renewal",
                "campaign",
                "test",
                "press",
                "meta",
                "stadtlandfunk",
            ])
        ) {
            throw new \DomainException("Ungültige Worker-Zwecke.");
        }
        if($onlyJobs===[])return [];
        $mailKinds=array_values(array_intersect($kinds,['press','campaign','test','confirm','reminder','renewal']));
        if($mailKinds){
            // SMTP is bounded to seconds. An old durable claim remains uncertain, never pending again.
            Db::atomic($this->db,function()use($club,$mailKinds):void{
                $placeholders=implode(',',array_fill(0,count($mailKinds),'?'));
                $rows=Db::run($this->db,"SELECT id,kind FROM native_delivery_jobs WHERE kind IN ($placeholders) AND status='processing' AND started_at<=UTC_TIMESTAMP()-INTERVAL 10 MINUTE".($club!==null?' AND club_id=?':'')." ORDER BY started_at,id LIMIT 200 FOR UPDATE SKIP LOCKED",$club!==null?[...$mailKinds,$club]:$mailKinds)->fetchAll(PDO::FETCH_ASSOC);
                foreach($rows as$row)Db::run($this->db,"UPDATE native_delivery_jobs SET status='uncertain',reason=?,completed_at=UTC_TIMESTAMP() WHERE id=? AND status='processing'",[$row['kind']==='press'?'press_interrupted_review':'newsletter_interrupted_review',$row['id']]);
            });
        }
        $renewals = 0;
        for ($i = 0; $i < $limit; $i++) {
            $activeKinds = $renewals < 20 ? $kinds : array_values(array_diff($kinds, ['renewal']));
            if (!$activeKinds) break;
            $kindSql = implode(",", array_fill(0, count($activeKinds), "?"));
            $job = Db::atomic($this->db, function () use ($activeKinds, $kindSql, $club, $onlyJobs) {
                $args=$club!==null?array_merge($activeKinds,[$club]):$activeKinds;
                $selection='';
                if($onlyJobs!==null){$selection=' AND id IN ('.implode(',',array_fill(0,count($onlyJobs),'?')).')';$args=array_merge($args,$onlyJobs);}
                $r = Db::run(
                    $this->db,
                    "SELECT * FROM native_delivery_jobs WHERE status='pending' AND kind IN ($kindSql) AND due_at<=UTC_TIMESTAMP()".($club!==null?' AND club_id=?':'').$selection." ORDER BY due_at,id LIMIT 1 FOR UPDATE SKIP LOCKED",
                    $args,
                )->fetch();
                if (!$r) {
                    return null;
                }
                Db::run(
                    $this->db,
                    "UPDATE native_delivery_jobs SET status='processing',attempts=attempts+1,started_at=UTC_TIMESTAMP() WHERE id=?",
                    [$r["id"]],
                );
                return $r;
            });
            if (!$job) {
                break;
            }
            if ($job['kind'] === 'renewal') ++$renewals;
            $started = false;
            $state = "cancelled";
            $reason = "authority_or_consent_changed";
            $external = null;
            $payload = [];
            try {
                $payload = $this->box->open(
                    "job:" . $job["club_id"] . ":" . $job["id"],
                    $job["ciphertext"],
                );
                if($job['kind']==='press')$payload=\Kulturhus\Native\Communication\PressCampaigns::prepare($this->db,$this->box,$job,$payload);
                if ($job['kind'] === 'renewal') {
                    $payload = Db::atomic($this->db, function () use ($job, $payload): array {
                        $this->authorize($job, $payload, true);
                        return Preparation::prepare($this->db, $this->box, $job, $payload,
                            $this->clock ? ($this->clock)() : new \DateTimeImmutable());
                    });
                }
                // Keep live authority and consent locks until the bounded transport returns.
                Db::atomic($this->db, function () use (
                    $job,
                    $payload,
                    $transport,
                    &$started,
                    &$external,
                ) {
                    $this->authorize($job, $payload);
                    $started = true;
                    $external = $transport(
                        $job["club_id"],
                        $payload,
                        $job["kind"],
                        $job,
                    );
                    if (
                        !is_string($external) ||
                        strlen($external) > 200 ||
                        preg_match('/[\x00-\x1f]/', $external)
                    ) {
                        throw new \RuntimeException(
                            "Ungültiges Transportergebnis.",
                        );
                    }
                    if (
                        $job["kind"] === "campaign" &&
                        isset($payload["digest"])
                    ) {
                        Announcements::record(
                            $this->db,
                            $job["club_id"],
                            $payload["digest"],
                            $job["id"],
                        );
                    }
                    Db::run(
                        $this->db,
                        "UPDATE native_delivery_jobs SET status='sent',reason=NULL,external_id=?,completed_at=UTC_TIMESTAMP() WHERE id=? AND status='processing'",
                        [$external, $job["id"]],
                    );
                });
                $state = "sent";
                $reason = null;
            } catch (\Throwable $e) {
                // A committed sent row is authoritative even when COMMIT's acknowledgement was lost.
                // Read only this exact attempt; never retry transport to discover its outcome.
                if($started&&is_string($external)&&!$this->db->inTransaction()){
                    $completed=null;try{$completed=Db::run($this->db,"SELECT status,external_id FROM native_delivery_jobs WHERE club_id=? AND id=? AND kind=? AND dedupe=? AND attempts=?",[$job['club_id'],$job['id'],$job['kind'],$job['dedupe'],(int)$job['attempts']+1])->fetch();}catch(\Throwable){}
                    if($completed&&$completed['status']==='sent'&&is_string($completed['external_id'])&&hash_equals($external,$completed['external_id'])){$results[]=['id'=>$job['id'],'status'=>'sent','reason'=>null];continue;}
                }
                if ($started) {
                    $state = "uncertain";
                    $reason = "transport_result_unknown";
                }
                if (
                    $e instanceof UncertainDelivery ||
                    $e instanceof DeferredDelivery ||
                    $e instanceof \Kulturhus\Native\Communication\CarouselProgress
                ) {
                    $external = $e->reference;
                    $reason = $e->stage;
                }
                if ($e instanceof DeferredDelivery || $e instanceof \Kulturhus\Native\Communication\CarouselProgress) {
                    $state = "pending";
                }
                if($e instanceof DeliveryNotStarted){$state=$e->retryable?'pending':'cancelled';$reason=$e->stage;$external=null;}
                if (!$started && $e instanceof Paused) {
                    $state = "pending";
                    $reason = "digest_period_paused";
                } elseif (!$started && $e instanceof NotDue) {
                    $state = "pending";
                    $reason = "digest_send_window_not_started";
                } elseif (!$started && $e instanceof Expired) {
                    $reason = "digest_send_window_expired";
                }
                Db::atomic($this->db,function()use($e,$job,$payload,$state,$reason,$external):void{
                if($e instanceof \Kulturhus\Native\Communication\CarouselProgress)$e->persist($this->db,$job,$payload);
                Db::run(
                    $this->db,
                    'UPDATE native_delivery_jobs SET status=?,reason=?,external_id=COALESCE(?,external_id),due_at=IF(?=\'pending\',DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),due_at),completed_at=IF(?=\'pending\',NULL,UTC_TIMESTAMP()) WHERE id=? AND status=\'processing\'',
                    [$state, $reason, $external, $state, $e instanceof \Kulturhus\Native\Communication\CarouselProgress?0:60, $state, $job["id"]],
                );
                });
            }
            $results[] = [
                "id" => $job["id"],
                "status" => $state,
                "reason" => $reason,
            ];
        }
        return $results;
    }
    private function authorize(array $job, array $payload, bool $preparing = false): void
    {
        if(isset($payload['auto_story']))\Kulturhus\Native\Communication\StoryAutomation::authorize($this->db,$job,$payload);
        if(isset($payload['application_confirmation'])){
            $manager=\Kulturhus\Native\Integration\ManagerClub::id($this->db,$job['club_id']);
            if($job['kind']!=='confirm'||!$manager||!Db::run($this->db,'SELECT 1 FROM application_confirmations c JOIN membership_applications a ON a.club_id=c.club_id AND a.id=c.application_id WHERE c.club_id=? AND c.id=? AND c.email=? AND c.confirmed_at IS NOT NULL AND c.consent=1 AND c.newsletter=1 FOR SHARE',[$manager,$payload['application_confirmation'],$payload['to']])->fetchColumn())throw new \DomainException('Originaler bestätigter Antrag oder Newsletter-Einwilligung geändert.');
        }
        if ($job["actor_id"] !== "") {
            $actor = Authority::resolve($this->db, [
                "created_by" => $job["actor_id"],
                "authority" => $job["authority"],
                "club_id" => $job["club_id"],
            ]);
            $u = $actor->require(
                JobPurpose::module($job['kind']),
                $job["capability"],
            );
            if ($u["club_id"] !== $job["club_id"]) {
                throw new \DomainException("Vereinszuordnung geändert.");
            }
        } elseif ($job["kind"] !== "confirm") {
            throw new \DomainException("Autorität fehlt.");
        }
        if ($job['kind'] === 'renewal') {
            if ($job['capability'] !== 'manage') throw new \DomainException('Erneuerung benötigt aktuelle Verwaltungserlaubnis.');
            Preparation::guard($this->db, $this->box, $job, $payload);
        }
        if (isset($payload["digest"])) {
            if ($job["kind"] !== "campaign") {
                throw new \DomainException(
                    "Periodenauftrag gehört zu einer Newsletterausgabe.",
                );
            }
            $edition = Plans::edition(
                $this->db,
                $job["club_id"],
                $payload["digest"]["item_id"],
            );
            if (
                !$edition ||
                $edition["period_key"] !== $payload["digest"]["period_key"]
            ) {
                throw new \DomainException(
                    "Originale Newsletterperiode fehlt.",
                );
            }
            $now = $this->clock ? ($this->clock)() : new \DateTimeImmutable();
            Plans::guard($this->db, $job["club_id"], $edition, $now);
            if (
                $now < new \DateTimeImmutable($edition["schedule"]["send_at"])
            ) {
                throw new NotDue(
                    "Das Versandfenster beginnt erst zum geplanten Monatsbeginn.",
                );
            }
        }
        if (isset($payload["approval"])) {
            $a = $payload["approval"];
            $actor = Authority::resolve($this->db, [
                "created_by" => $a["actor"],
                "authority" => $a["authority"],
                "club_id" => $job["club_id"],
            ]);
            $actor->require(
                JobPurpose::module($job['kind']),
                "publish",
            );
            $table =
                in_array($job["kind"],["meta","stadtlandfunk"],true)
                    ? "khn_social_items"
                    : ($job['kind']==='press'?'khn_press_items':'khn_newsletter_items');
            $item = Db::run(
                $this->db,
                "SELECT version,status FROM $table WHERE club_id=? AND id=? FOR SHARE",
                [$job["club_id"], $a["item"]],
            )->fetch();
            if (
                !$item ||
                (int) $item["version"] !== $a["version"] ||
                !in_array($item["status"], ["approved", "scheduled"], true)
            ) {
                throw new \DomainException("Freigabe nicht mehr aktuell.");
            }
        }
        if($job['kind']==='meta'&&($payload['kind']??null)==='reel')\Kulturhus\Native\Communication\VideoPublication::guard($this->db,Authority::resolve($this->db,['created_by'=>$job['actor_id'],'authority'=>$job['authority'],'club_id'=>$job['club_id']]),$payload);
        elseif($job['kind']==='meta'&&($payload['kind']??null)==='carousel')\Kulturhus\Native\Communication\CarouselPublication::guard($this->db,Authority::resolve($this->db,['created_by'=>$job['actor_id'],'authority'=>$job['authority'],'club_id'=>$job['club_id']]),$payload);
        elseif (in_array($job['kind'],['meta','stadtlandfunk'],true)) \Kulturhus\Native\Communication\ImageUsage::storedImage($this->db,$job['club_id'],$payload['image_id']);
        if (in_array($job['kind'],['campaign','test'],true)) \Kulturhus\Native\Communication\ImageUsage::message($this->db,$job['club_id'],$payload);
        if($job['kind']==='press'){
            if($job['capability']!=='publish')throw new \DomainException('Presseversand benötigt aktuelle Veröffentlichungsrechte.');
            if(!isset($payload['approval'],$payload['press_contact'],$payload['media_usage']))throw new \DomainException('Originaler Presseauftrag fehlt.');
            \Kulturhus\Native\Communication\ImageUsage::guard($this->db,$job['club_id'],$payload['media_usage'],'press');
            $contact=$payload['press_contact'];\Kulturhus\Native\Communication\Content::id($contact['id']??null);
            $row=Db::run($this->db,'SELECT email,active,version FROM khn_press_contacts WHERE club_id=? AND id=? FOR SHARE',[$job['club_id'],$contact['id']])->fetch();
            if(!$row||(int)$row['active']!==1||(int)$row['version']!==($contact['version']??null)||!hash_equals($row['email'],$payload['to']??''))throw new \DomainException('Ursprünglicher Presseempfänger geändert oder deaktiviert.');
        }
        if ($job["kind"] === "stadtlandfunk") \Kulturhus\Native\Communication\PersonalPublication::guardSource($this->db,Authority::resolve($this->db,['created_by'=>$job['actor_id'],'authority'=>$job['authority'],'club_id'=>$job['club_id']]),$payload);
        if (isset($payload["subscriber"])) {
            $r = Db::run(
                $this->db,
                "SELECT email,status,purposes FROM khn_newsletter_subscribers WHERE club_id=? AND id=? FOR SHARE",
                [$job["club_id"], $payload["subscriber"]],
            )->fetch();
            if (!$r || !hash_equals($r["email"], $payload["to"])) {
                throw new \DomainException("Empfänger geändert.");
            }
            if (in_array($job["kind"], ["confirm", "reminder", "renewal"], true)) {
                if ($r["status"] !== "pending") {
                    throw new \DomainException(
                        "Bestätigung nicht mehr ausstehend.",
                    );
                }
                if ($job['kind'] === 'renewal' && $preparing) return;
                $token = Db::run(
                    $this->db,
                    "SELECT 1 FROM khn_newsletter_tokens WHERE club_id=? AND subscriber_id=? AND token_hash=? AND kind='confirm' AND consumed_at IS NULL AND expires_at>UTC_TIMESTAMP() FOR SHARE",
                    [
                        $job["club_id"],
                        $payload["subscriber"],
                        $payload["confirmation_hash"],
                    ],
                )->fetchColumn();
                if (!$token) {
                    throw new \DomainException(
                        "Bestätigungsschlüssel nicht mehr gültig.",
                    );
                }
            } else {
                if ($r["status"] !== "confirmed") {
                    throw new \DomainException("Einwilligung widerrufen.");
                }
                $purposes = json_decode(
                    $r["purposes"],
                    true,
                    16,
                    JSON_THROW_ON_ERROR,
                );
                $required =
                    $payload["audience"] === "all"
                        ? ["events", "news"]
                        : [$payload["audience"]];
                if (array_diff($required, $purposes)) {
                    throw new \DomainException(
                        "Zwecke nicht mehr freigegeben.",
                    );
                }
            }
        }
    }
    public function list(Access $access, string $module): array
    {
        $access->require($module, "read");
        $kind = match($module){'social'=>"kind IN ('meta','stadtlandfunk')",'press'=>"kind='press'",'newsletter'=>"kind IN ('confirm','reminder','renewal','campaign','test')",default=>throw new \DomainException('Zustellbereich prüfen.')};
        return Db::run(
            $this->db,
            "SELECT id,kind,status,attempts,external_id,reason,due_at,started_at,completed_at FROM native_delivery_jobs WHERE club_id=? AND $kind ORDER BY due_at DESC LIMIT 200",
            [$access->club()],
        )->fetchAll();
    }
}
