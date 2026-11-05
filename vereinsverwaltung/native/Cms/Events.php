<?php
declare(strict_types=1);

namespace Kulturhus\Native\Cms;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Kulturhus\Native\Shared\{Access, Db};
use PDO;

/** Public event catalogue. Filters/counts use MySQL; only the requested originals are decoded. */
final class Events
{
    private const FROM = " FROM khcms_events i JOIN khcms_pages p ON p.id=i.page_id AND p.club_id=i.club_id JOIN khcms_revisions r ON r.page_id=i.page_id AND r.club_id=i.club_id AND r.revision=i.revision LEFT JOIN khcms_archives a ON a.page_id=p.id AND a.club_id=p.club_id LEFT JOIN native_event_sources s ON s.page_id=p.id AND s.club_id=p.club_id ";
    private const PUBLIC = "i.club_id=? AND r.action='publish' AND a.page_id IS NULL AND ((i.is_notice=0 AND p.published_revision=i.revision AND p.published_slug=i.slug) OR (i.is_notice=1 AND p.published_revision IS NULL AND s.state='cancelled'))";
    private const SELECT = "SELECT i.*,r.payload_json,s.source,s.external_id,CASE WHEN s.state='cancelled' THEN 'cancelled' ELSE i.status END AS current_status";

    /** Access is mandatory for editor/UI callers; trusted workers bind their already checked club. */
    public function __construct(
        private PDO $db,
        private string $club,
        private ?Access $access = null,
    ) {}

    private function authorize(): void
    {
        if ($this->access) {
            $this->access->require("website", "read");
            if (!hash_equals($this->club, $this->access->club())) {
                throw new \DomainException("Veranstaltungen nicht verfügbar.");
            }
        }
    }

    private function publicWhere():array
    {
        [$policy,$parameters]=TemplatePolicy::publicFilter($this->db,$this->club);
        return [self::PUBLIC.' AND ('.$policy.')',[$this->club,...$parameters]];
    }
    public function ticketSales(string $id):array
    {
        $this->authorize();$state=\Kulturhus\Native\Events\Workflow::forClub($this->db,$this->club);
        return ['provider'=>$state['tickets'],'offer'=>$state['tickets']==='native'?\Kulturhus\Native\Events\Tickets\Offers::publicOffer($this->db,$this->club,$id):null];
    }

    public function listing(
        array $query = [],
        ?DateTimeImmutable $now = null,
        int $pageSize = 20,
        ?\Closure $localizedSearch = null,
    ): array {
        if(!in_array($pageSize,[6,20],true))throw new InvalidArgumentException('Programmseitengröße prüfen.');
        $this->authorize();
        $values = [];
        foreach (["archiv", "jahr", "q", "sort", "seite"] as $key) {
            $value = $query[$key] ?? "";
            if (!is_string($value) && !is_int($value)) {
                throw new InvalidArgumentException(
                    "Programmfilter müssen einzelne Werte sein.",
                );
            }
            $values[$key] = (string) $value;
        }
        $archive = $values["archiv"] === "1";
        $year =
            ctype_digit($values["jahr"]) &&
            (int) $values["jahr"] >= 1900 &&
            (int) $values["jahr"] <= 2100
                ? (int) $values["jahr"]
                : 0;
        $search = trim($values["q"]);
        if (
            preg_match("//u", $search) !== 1 ||
            preg_match('/[\x00-\x1f\x7f]/', $search) ||
            preg_match_all("/./us", $search) > 150
        ) {
            throw new InvalidArgumentException(
                "Suche auf 150 Zeichen ohne Steuerzeichen begrenzen.",
            );
        }
        $descending =
            $values["sort"] === "desc" ||
            ($values["sort"] !== "asc" && $archive);
        $number =
            $values["seite"] === ""
                ? 1
                : (ctype_digit($values["seite"]) &&
                strlen($values["seite"]) <= 9
                    ? max(1, (int) $values["seite"])
                    : throw new InvalidArgumentException(
                        "Ungültige Programmseite.",
                    ));
        $zone = new DateTimeZone("Europe/Berlin");
        $today = ($now ?? new DateTimeImmutable("now", $zone))
            ->setTimezone($zone)
            ->setTime(0, 0);
        [$baseWhere,$baseParameters]=$this->publicWhere();
        $where =
            $baseWhere . " AND i.starts_at " . ($archive ? "<" : ">=") . " ?";
        $params = [
            ...$baseParameters,
            $today->setTimezone(new DateTimeZone("UTC"))->format("Y-m-d H:i:s"),
        ];
        if ($year) {
            $where .= " AND i.starts_at>=? AND i.starts_at<?";
            foreach ([$year, $year + 1] as $y) {
                $params[] = (new DateTimeImmutable($y . "-01-01", $zone))
                    ->setTimezone(new DateTimeZone("UTC"))
                    ->format("Y-m-d H:i:s");
            }
        }
        if ($search !== "") {
            $localized=$localizedSearch!==null?$localizedSearch($search):null;
            if($localized!==null){
                [$predicate,$searchParameters]=$localized;
                $where.=' AND ('.$predicate.')';array_push($params,...$searchParameters);
            }else{
            // LOCATE treats %, _, regex and quotes literally; published JSON is never an SQL selector.
            $where .=
                " AND LOCATE(?,CONCAT_WS(' ',JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.title')),JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.excerpt')),JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.body')),JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.event_location')),JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.event_address'))) COLLATE utf8mb4_unicode_ci)>0";
            $params[] = $search;
            }
        }
        return Db::atomic($this->db, function () use (
            $where,
            $params,
            $number,
            $archive,
            $year,
            $search,
            $descending,
            $today,
            $pageSize,
            $baseWhere,
            $baseParameters,
        ): array {
            $this->authorize();
            $total = (int) Db::run(
                $this->db,
                "SELECT COUNT(*)" . self::FROM . "WHERE " . $where,
                $params,
            )->fetchColumn();
            $pages = max(1, (int) ceil($total / $pageSize));
            $number = min($number, $pages);
            // Materialize only the requested identities before loading large original bodies.
            // Sorting JSON payloads can exhaust MySQL sort memory even with LIMIT six.
            $picked="SELECT i.page_id".self::FROM."WHERE ".$where." ORDER BY i.starts_at ".($descending?'DESC':'ASC').",i.page_id LIMIT ".$pageSize." OFFSET ".(($number-1)*$pageSize);
            $rows=Db::run($this->db,self::SELECT.self::FROM." JOIN (".$picked.") picked ON picked.page_id=i.page_id WHERE ".$baseWhere,[...$params,...$baseParameters])->fetchAll();
            usort($rows,static function(array $a,array $b)use($descending):int{
                $time=strcmp($a['starts_at'],$b['starts_at']);
                return ($descending?-$time:$time)?:strcmp($a['page_id'],$b['page_id']);
            });
            $years = Db::run(
                $this->db,
                "SELECT DISTINCT i.local_year" .
                    self::FROM .
                    "WHERE " .
                    $baseWhere .
                    " AND i.local_year IS NOT NULL ORDER BY i.local_year DESC",
                $baseParameters,
            )->fetchAll(PDO::FETCH_COLUMN);
            return [
                "rows" => array_map($this->present(...), $rows),
                "total" => $total,
                "page" => $number,
                "pages" => $pages,
                "years" => array_map(intval(...), $years),
                "archive" => $archive,
                "year" => $year,
                "query" => $search,
                "sort" => $descending ? "desc" : "asc",
                "today" => $today->format("Y-m-d"),
            ];
        });
    }

    public function publicPage(string $slug): ?array
    {
        $this->authorize();
        [$where,$parameters]=$this->publicWhere();
        $row = Db::run(
            $this->db,
            self::SELECT .
                self::FROM .
                "WHERE " .
                $where .
                " AND i.slug=?",
            [...$parameters, $slug],
        )->fetch();
        return $row ? $this->present($row) : null;
    }

    public function publicId(string $id,bool $current=false): ?array
    {
        $this->authorize();
        [$where,$parameters]=$this->publicWhere();
        $row = Db::run(
            $this->db,
            self::SELECT .
                self::FROM .
                "WHERE " .
                $where .
                " AND i.page_id=?" . ($current&&$this->db->inTransaction()?" FOR SHARE":""),
            [...$parameters, $id],
        )->fetch();
        return $row ? $this->present($row) : null;
    }

    public function next(?DateTimeImmutable $today = null): ?array
    {
        $this->authorize();
        [$where,$parameters]=$this->publicWhere();
        $today = (
            $today ??
            new DateTimeImmutable("today", new DateTimeZone("Europe/Berlin"))
        )->setTimezone(new DateTimeZone("UTC"));
        $row = Db::run(
            $this->db,
            self::SELECT .
                self::FROM .
                "WHERE " .
                $where .
                " AND i.starts_at>=? AND i.status<>'cancelled' AND (s.state IS NULL OR s.state<>'cancelled') ORDER BY i.starts_at,i.page_id LIMIT 1",
            [...$parameters, $today->format("Y-m-d H:i:s")],
        )->fetch();
        return $row ? $this->present($row) : null;
    }

    /** Lifecycle/delivery metadata from the same public original, without loading its private draft or body. */
    public function publicationState(string $id): ?array
    {
        $this->authorize();
        [$where,$parameters]=$this->publicWhere();
        $row = Db::run(
            $this->db,
            "SELECT JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.title')) AS title,r.payload_json,i.starts_at,i.is_notice,s.source,s.external_id,CASE WHEN s.state='cancelled' THEN 'cancelled' ELSE i.status END AS status" .
                self::FROM .
                " WHERE " .
                $where .
                " AND i.page_id=?",
            [...$parameters, $id],
        )->fetch();
        if (!$row) {
            return null;
        }
        $start =
            $row["starts_at"] === null
                ? ""
                : (new DateTimeImmutable(
                    $row["starts_at"],
                    new DateTimeZone("UTC"),
                ))->format("Y-m-d\TH:i:s\Z");
        return [
            "title" => $row["title"] ?? "",
            "event_start" => $start,
            "event_start_utc" => $start,
            "event_timezone" => "UTC",
            "event_status" => EventDetails::status(array_replace(json_decode($row['payload_json'],true,512,JSON_THROW_ON_ERROR),['event_status'=>$row['status']])) ,
            "cancellation_notice" => (bool) $row["is_notice"],
            "event_source" => $row["source"] ?? "local",
            "event_external_id" => $row["external_id"],
        ];
    }

    public function between(
        string $from,
        string $until,
        bool $includeCancelled = false,
    ): array {
        $this->authorize();
        $zone = new DateTimeZone("Europe/Berlin");
        $start = new DateTimeImmutable($from, $zone);
        $end = new DateTimeImmutable($until, $zone);
        if ($end <= $start) {
            throw new InvalidArgumentException("Ungültiger Terminzeitraum.");
        }
        [$where,$parameters]=$this->publicWhere();
        $where .= " AND i.starts_at>=? AND i.starts_at<?";
        if (!$includeCancelled) {
            $where .=
                " AND i.status<>'cancelled' AND (s.state IS NULL OR s.state<>'cancelled')";
        }
        $rows = Db::run(
            $this->db,
            self::SELECT .
                self::FROM .
                "WHERE " .
                $where .
                " ORDER BY i.starts_at,i.page_id",
            [
                ...$parameters,
                $start
                    ->setTimezone(new DateTimeZone("UTC"))
                    ->format("Y-m-d H:i:s"),
                $end
                    ->setTimezone(new DateTimeZone("UTC"))
                    ->format("Y-m-d H:i:s"),
            ],
        )->fetchAll();
        return array_map($this->present(...), $rows);
    }

    /** Published title/date metadata only, using the same current publication policy. */
    public function choiceIndex(string $from,string $until,bool $current=false,bool $includeLocation=false):array
    {
        $this->authorize();$zone=new DateTimeZone('Europe/Berlin');$start=new DateTimeImmutable($from,$zone);$end=new DateTimeImmutable($until,$zone);
        if($end<=$start)throw new InvalidArgumentException('Ungültiger Terminzeitraum.');
        [$where,$parameters]=$this->publicWhere();
        $location=$includeLocation?",TRIM(CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.event_location')),''),' ',COALESCE(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.event_address')),''))) AS location":'';
        $rows=Db::run($this->db,"SELECT i.page_id AS id,JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.title')) AS title,JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.event_status_mode')) AS status_mode,JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.event_timezone')) AS timezone,i.starts_at,i.ends_at,CASE WHEN s.state='cancelled' THEN 'cancelled' ELSE i.status END AS status".$location.self::FROM.' WHERE '.$where.' AND i.starts_at>=? AND i.starts_at<? ORDER BY i.starts_at,i.page_id'.($current&&$this->db->inTransaction()?' FOR SHARE NOWAIT':''),[...$parameters,$start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),$end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')])->fetchAll();
        foreach($rows as &$row){if(!is_string($row['title']))throw new \DomainException('Veranstaltungsmetadaten prüfen.');$statusPage=['event_start'=>$row['starts_at'],'event_start_utc'=>$row['starts_at'].'Z','event_end'=>$row['ends_at']!==null?$row['ends_at'].'Z':'','event_timezone'=>$row['timezone']?:'Europe/Berlin','event_status'=>$row['status']];if(in_array($row['status_mode'],['automatic','manual'],true))$statusPage['event_status_mode']=$row['status_mode'];$row['status']=EventDetails::status($statusPage);unset($row['status_mode'],$row['timezone']);$row['starts_at']=(new DateTimeImmutable($row['starts_at'],new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');$row['ends_at']=$row['ends_at']===null?$row['starts_at']:(new DateTimeImmutable($row['ends_at'],new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');}unset($row);
        return $rows;
    }

    /** Bounded input batches for feeds; includes undated public originals and cancellation notices. */
    public function all(): \Generator
    {
        $after = "";
        do {
            $this->authorize();
            [$where,$parameters]=$this->publicWhere();
            $rows = Db::run(
                $this->db,
                self::SELECT .
                    self::FROM .
                    "WHERE " .
                    $where .
                    " AND i.page_id>? ORDER BY i.page_id LIMIT 200",
                [...$parameters, $after],
            )->fetchAll();
            foreach ($rows as $row) {
                $after = $row["page_id"];
                yield $this->present($row);
            }
        } while (count($rows) === 200);
    }

    /** Chronological feed input: metadata batches, then one currently public original at a time. */
    public function ordered():\Generator
    {
        $cursor=null;
        do{
            $this->authorize();[$where,$args]=$this->publicWhere();$after='';
            if($cursor!==null){
                if($cursor['starts_at']===null){$after=' AND i.starts_at IS NULL AND i.page_id COLLATE utf8mb4_bin>?';$args[]=$cursor['page_id'];}
                else{$after=' AND (i.starts_at>? OR (i.starts_at=? AND i.page_id COLLATE utf8mb4_bin>?) OR i.starts_at IS NULL)';array_push($args,$cursor['starts_at'],$cursor['starts_at'],$cursor['page_id']);}
            }
            $rows=Db::run($this->db,'SELECT i.page_id,i.starts_at'.self::FROM.' WHERE '.$where.$after.' ORDER BY (i.starts_at IS NULL),i.starts_at,i.page_id COLLATE utf8mb4_bin LIMIT 100',$args)->fetchAll(PDO::FETCH_ASSOC);
            foreach($rows as$meta){$page=$this->publicId($meta['page_id']);if($page!==null)yield $page;unset($page);}
            if($rows)$cursor=$rows[array_key_last($rows)];
        }while(count($rows)===100);
        $this->authorize();
    }

    private function present(array $row): array
    {
        $page = json_decode(
            $row["payload_json"],
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $current=EventDetails::status(array_replace($page,['event_status'=>$row['current_status']]));
        return array_replace(FieldPolicy::publicSource($this->db,$this->club,$page), [
            "id" => $row["page_id"],
            "slug" => $row["slug"],
            "version" => (int) $row["revision"],
            "published_revision" => (int) $row["revision"],
            "published_version" => (int) $row["revision"],
            "published_at" => $row["published_at"],
            "event_status" => $current,
            "event_sequence" => (int) $row["sequence"],
            "event_changed_at" => $row["changed_at"],
            "cancellation_notice" => (bool) $row["is_notice"],
            "event_source" => $row["source"] ?? "local",
            "event_external_id" => $row["external_id"],
        ]);
    }
}
