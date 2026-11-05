<?php declare(strict_types=1);
namespace Kulturhus\Native\Events;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{ClubPublicationLock,EventIndex,Pages};

/** One guarded import contract for current external event sources. */
final class SourceImporter
{
    use ClubPublicationLock;
    public function __construct(private PDO $db, private Access $access, private string $provider, private array $metadata=[])
    {
        if(!in_array($provider,['eventbrite','facebook'],true))throw new \DomainException('Veranstaltungsquelle prüfen.');
    }
    public function import(array $events,?string $expectedClub=null,?int $workflowVersion=null, ?callable $assertAccount=null): array
    {
        if($this->provider==='facebook'&&($workflowVersion===null||$assertAccount===null||!is_string($this->metadata['facebook_account_binding']??null)||!preg_match('/^[a-f0-9]{64}$/D',$this->metadata['facebook_account_binding'])))throw new \DomainException('Facebook-Abgleich benötigt die aktuelle Vereins- und Kontozuordnung.');
        $this->access->require("website", "edit");
        if($expectedClub!==null&&$this->access->club()!==$expectedClub)throw new \DomainException('Vereinszuordnung vor dem Eventbrite-Abgleich geändert.');
        if (!array_is_list($events) || count($events) > 5000) {
            throw new \DomainException("Ungültige Veranstaltungsliste.");
        }
        $seen = [];
        // Reject the complete batch before changing any page.
        foreach ($events as &$event) {
            if (!is_array($event)) {
                throw new \DomainException("Ungültiger Veranstaltungseintrag.");
            }
            $missingEnd=$this->provider==='facebook'&&($event["ends_at"]??null)==='';
            $event = Eventbrite::normalize([
                "id" => $event["external_id"] ?? null,
                "status" => $event["status"] ?? null,
                "name" => ["text" => $event["title"] ?? null],
                "description" => ["text" => $event["body"] ?? ""],
                "start" => [
                    "utc" => $event["starts_at"] ?? null,
                    "timezone" => $event["event_timezone"] ?? null,
                ],
                "end" => ["utc" => $missingEnd ? ($event["starts_at"]??null) : ($event["ends_at"] ?? null)],
                "venue" => [
                    "name" => $event["event_location"] ?? "",
                    "address" => [
                        "localized_address_display" =>
                            $event["event_address"] ?? "",
                    ],
                ],
                "is_free" => ($event["event_price"] ?? "") === "0.00",
                "url" => $event["ticket_url"] ?? "",
                "logo" => ["url" => $event["image_url"] ?? ""],
            ],$this->provider==='facebook');
            if($missingEnd)$event["ends_at"]='';
            if (isset($seen[$event["external_id"]])) {
                throw new \DomainException("Doppelte Eventbrite-Identität.");
            }
            $seen[$event["external_id"]] = true;
        }
        unset($event);
        $club = $this->access->club();
        $lock = "event-source:" . substr(hash("sha256", $club), 0, 50);
        if (
            (int) Db::run($this->db, "SELECT GET_LOCK(?,0)", [
                $lock,
            ])->fetchColumn() !== 1
        ) {
            throw new \DomainException("Synchronisierung läuft bereits.");
        }
        try {
            return Db::atomic($this->db, function () use (
                $events,
                $club,
                $workflowVersion,
                $assertAccount,
            ): array {
                $this->lockClub();
                if($workflowVersion!==null){$state=(new Workflow($this->db,$this->access))->requireSource($this->provider);if($state['version']!==$workflowVersion)throw new \DomainException('Veranstaltungsablauf während der Abfrage geändert. Neu abgleichen.');}
                if($this->access->require('website','edit')['club_id']!==$club)throw new \DomainException('Vereinszuordnung während des Eventbrite-Abgleichs geändert.');
                if($assertAccount!==null)$assertAccount();
                $result = [];
                $pages = new Pages($this->db, $this->access);
                foreach ($events as $event) {
                    if($this->access->require('website','edit')['club_id']!==$club)throw new \DomainException('Vereinszuordnung während des Eventbrite-Abgleichs geändert.');
                    $id = $event["external_id"];
                    $hash = hash(
                        "sha256",
                        json_encode($event, JSON_THROW_ON_ERROR),
                    );
                    $source = Db::run(
                        $this->db,
                        "SELECT * FROM native_event_sources WHERE club_id=? AND source='".$this->provider."' AND external_id=? FOR UPDATE",
                        [$club, $id],
                    )->fetch();
                    if(!$source){
                        $alias=Db::run($this->db,"SELECT page_id FROM native_event_source_aliases WHERE club_id=? AND source=? AND external_id=? FOR UPDATE",[$club,$this->provider,$id])->fetchColumn();
                        if(is_string($alias))$source=Db::run($this->db,"SELECT * FROM native_event_sources WHERE club_id=? AND page_id=? FOR UPDATE",[$club,$alias])->fetch();
                    }
                    if($source&&isset($this->metadata['facebook_account_binding'])){
                        $prior=json_decode($source['metadata_json']??'{}',true,32,JSON_THROW_ON_ERROR);
                        if(isset($prior['facebook_account_binding'])&&!hash_equals($prior['facebook_account_binding'],$this->metadata['facebook_account_binding']))throw new \DomainException('Facebook-Zuordnung gehört zu einer anderen Verbindung. Bitte bewusst neu zuordnen.');
                    }
                    if ($source && $source['source']===$this->provider && $source['external_id']===$id && hash_equals($source["source_hash"], $hash)) {
                        $result[] = [
                            "id" => $source["page_id"],
                            "status" => "unchanged",
                        ];
                        continue;
                    }
                    $old = $source ? $pages->get($source["page_id"]) : null;
                    $cancelled = in_array(
                        $event["status"],
                        ["canceled", "cancelled"],
                        true,
                    );
                    $snapshot =
                        $old && $cancelled
                            ? EventIndex::capture($this->db, $club, $old["id"])
                            : null;
                    if (
                        $old &&
                        $cancelled &&
                        $old["published_revision"] !== null
                    ) {
                        $old = $pages->unpublish($old["id"], $old["version"]);
                    }
                    if (
                        $old &&
                        !$cancelled &&
                        ($source["state"] ?? "") === "cancelled"
                    ) {
                        Db::run(
                            $this->db,
                            "UPDATE native_event_sources SET state='active' WHERE club_id=? AND source='".$this->provider."' AND external_id=?",
                            [$club, $id],
                        );
                        EventIndex::clearNotice($this->db, $club, $old["id"]);
                        Db::run(
                            $this->db,
                            "UPDATE khcms_state SET generation=generation+1 WHERE club_id=?",
                            [$club],
                        );
                    }
                    if ($old) {
                        // Publishing changes the version but does not edit the imported draft.
                        $imported = Db::run(
                            $this->db,
                            "SELECT payload_json FROM khcms_revisions WHERE club_id=? AND page_id=? AND revision=?",
                            [$club, $old["id"], $source["imported_version"]],
                        )->fetchColumn();
                        $current = Db::run(
                            $this->db,
                            "SELECT draft_json FROM khcms_pages WHERE club_id=? AND id=? FOR UPDATE",
                            [$club, $old["id"]],
                        )->fetchColumn();
                        if (
                            !$imported ||
                            json_decode(
                                $imported,
                                true,
                                512,
                                JSON_THROW_ON_ERROR,
                            ) !==
                                json_decode(
                                    $current,
                                    true,
                                    512,
                                    JSON_THROW_ON_ERROR,
                                )
                        ) {
                            if ($cancelled) {
                                Db::run(
                                    $this->db,
                                    "UPDATE native_event_sources SET state='cancelled' WHERE club_id=? AND source='".$this->provider."' AND external_id=?",
                                    [$club, $id],
                                );
                                EventIndex::cancellation(
                                    $this->db,
                                    $club,
                                    $snapshot,
                                    $old["version"],
                                );
                            }
                            $result[] = [
                                "id" => $old["id"],
                                "status" => $cancelled
                                    ? "cancelled-conflict"
                                    : "conflict",
                            ];
                            continue;
                        }
                    }
                    $data = array_replace($old ?? [], [
                        "title" => $event["title"],
                        "slug" => $old["slug"] ?? ("veranstaltungen/".$this->provider."-" . $id),
                        "template" => "event",
                        "body" => $event["body"],
                        "event_start" => $event["event_start"],
                        "event_timezone" => $event["event_timezone"],
                        "event_start_utc" => $event["starts_at"],
                        "event_end" => $event["ends_at"],
                        "event_location" => $event["event_location"],
                        "event_address" => $event["event_address"],
                        "event_status_mode" => "automatic",
                        "event_status" => match ($event["status"]) {
                            "canceled", "cancelled" => "cancelled",
                            "started" => "started",
                            "ended", "completed" => "ended",
                            default => "scheduled",
                        },
                        "event_ticket_url" => $event["ticket_url"],
                        "event_reference_url" => $this->provider==='facebook'?'https://www.facebook.com/events/'.$id.'/':($old['event_reference_url']??''),
                        "event_price" => $event["event_price"],
                    ]);
                    unset($data["body_html"]);
                    $page = $pages->save($data, $old["version"] ?? 0);
                    if($source){
                        self::alias($this->db,$club,$source['source'],$source['external_id'],$page['id']);
                        if($source['source']!==$this->provider||$source['external_id']!==$id)Db::run($this->db,'DELETE FROM native_event_sources WHERE club_id=? AND page_id=?',[$club,$page['id']]);
                    }
                    self::alias($this->db,$club,$this->provider,$id,$page['id']);
                    Db::run(
                        $this->db,
                        "INSERT INTO native_event_sources(club_id,source,external_id,page_id,source_hash,state,imported_version,metadata_json) VALUES(?,'".$this->provider."',?,?,?,?,?,?) ON DUPLICATE KEY UPDATE source_hash=VALUES(source_hash),state=VALUES(state),imported_version=VALUES(imported_version),metadata_json=VALUES(metadata_json)",
                        [
                            $club,
                            $id,
                            $page["id"],
                            $hash,
                            $cancelled ? "cancelled" : "active",
                            $page["version"],
                            json_encode(
                                [
                                    ...($source && is_string($source['metadata_json']) ? (json_decode($source['metadata_json'],true,32,JSON_THROW_ON_ERROR) ?? []) : []),
                                    ...$this->metadata,
                                    "ticket_url" => $event["ticket_url"],
                                    "image_url" => $event["image_url"],
                                ],
                                JSON_THROW_ON_ERROR,
                            ),
                        ],
                    );
                    if ($cancelled) {
                        EventIndex::cancellation(
                            $this->db,
                            $club,
                            $snapshot,
                            $page["version"],
                        );
                    }
                    $result[] = [
                        "id" => $page["id"],
                        "status" => $cancelled ? "cancelled" : "draft",
                    ];
                }
                return $workflowVersion===null?$result:(new Workflow($this->db,$this->access))->finishImport($result,$this->provider,$workflowVersion,$club);
            });
        } finally {
            Db::run($this->db, "SELECT RELEASE_LOCK(?)", [$lock]);
        }
    }

    public static function alias(PDO $db,string $club,string $source,string $external,string $page):void
    {
        if(!$db->inTransaction()||!in_array($source,['eventbrite','facebook'],true)||!preg_match('/^[0-9]{1,40}$/D',$external)||!preg_match('/^[a-f0-9]{32}$/D',$page))throw new \DomainException('Veranstaltungszuordnung im Abgleich prüfen.');
        $row=Db::run($db,'SELECT page_id FROM native_event_source_aliases WHERE club_id=? AND source=? AND external_id=? FOR UPDATE',[$club,$source,$external])->fetchColumn();
        if(is_string($row)&&$row!==$page)throw new \DomainException('Diese externe Veranstaltung ist bereits einem anderen Termin zugeordnet.');
        Db::run($db,'INSERT IGNORE INTO native_event_source_aliases(club_id,source,external_id,page_id) VALUES(?,?,?,?)',[$club,$source,$external,$page]);
    }
}
