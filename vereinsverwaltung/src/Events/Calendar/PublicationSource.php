<?php declare(strict_types=1);
namespace Kulturhus\Verein\Events\Calendar;

use DomainException;
use Kulturhus\Native\Communication\{Schema, Workspace};
use Kulturhus\Native\Shared\{Access, Db};
use PDO;

/** Original editorial planning and non-private delivery references; no payload decryption. */
final class PublicationSource
{
    private const STATES = [
        "pending" => "Geplant",
        "processing" => "In Bearbeitung",
        "sent" => "Zugestellt",
        "uncertain" => "Ergebnis unklar",
        "cancelled" => "Abgebrochen",
        "local_ready" => "Lokal vorbereitet",
    ];
    public function __construct(private PDO $db, private Access $access) {}

    public function rows(
        string $module,
        string $label,
        string $start,
        string $end,
    ): array {
        $this->access->require($module, "read");
        $club = $this->access->club();
        $kinds = match($module){'social'=>['meta','stadtlandfunk'],'press'=>['press'],'newsletter'=>['campaign'],default=>throw new DomainException('Kommunikationsquelle prüfen.')};
        $kindSql=implode(',',array_fill(0,count($kinds),'?'));
        $query = Db::run(
            $this->db,
            "SELECT s.item_id,s.item_version,j.kind,j.status,j.due_at,COUNT(*) quantity
            FROM native_delivery_jobs j LEFT JOIN native_delivery_sources s ON s.job_id=j.id AND s.club_id=j.club_id AND s.module=?
            WHERE j.club_id=? AND j.kind IN ($kindSql) AND j.due_at>=? AND j.due_at<?
            GROUP BY s.item_id,s.item_version,j.kind,j.status,j.due_at ORDER BY j.due_at,s.item_id,j.status",
            [$module, $club, ...$kinds, $start, $end],
        );
        $groups = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $job) {
            // Identified posts combine recipient/channel states into one original planning entry.
            $key =
                ($job['kind']==='stadtlandfunk'?'stadtlandfunk:':'') .
                ($job["item_id"] ?? "legacy:" . $job["status"]) .
                ":" .
                ($job["item_version"] ?? 0) .
                ":" .
                $job["due_at"];
            $groups[$key] ??= ["job" => $job, "states" => [], "quantity" => 0];
            $groups[$key]["states"][] =
                ($module==='press'&&$job['status']==='sent'?'Versandt':self::state($job["status"])) . " (" . $job["quantity"] . ")";
            $groups[$key]["quantity"] += (int) $job["quantity"];
        }
        $workspace = new Workspace($this->db, $this->access, $module);
        $rows = [];
        $identified = [];
        foreach ($groups as $key => $group) {
            $job = $group["job"];
            $targetLabel=$job['kind']==='stadtlandfunk'?'StadtLand.Funk':$label;
            $title = $targetLabel . ": " . $group["quantity"] . " Zustellaufträge";
            $route = ["view" => $module, "section" => "planning"];
            $state = implode(" / ", $group["states"]);
            if ($job["item_id"] !== null) {
                try {
                    $item = $workspace->get($job["item_id"]);
                    $title = $targetLabel . ": " . $item["title"];
                    $route = ["view" => $module, "native_item" => $item["id"]];
                    $identified[$item["id"] . ":" . $job["due_at"]] = true;
                    if ($item["version"] !== (int) $job["item_version"]) {
                        $state .= " · Frühere Fassung";
                    }
                    if($module==='press'){
                        $revision=Db::run($this->db,'SELECT payload FROM khn_press_revisions WHERE club_id=? AND item_id=? AND version=?',[$club,$job['item_id'],$job['item_version']])->fetchColumn();
                        if(is_string($revision)){$original=json_decode($revision,true,64,JSON_THROW_ON_ERROR);$title=$targetLabel.': '.($original['title']??$item['title']);}
                        $route=['view'=>'press','section'=>'planning','press_item'=>$job['item_id']];
                    }
                } catch (DomainException) {
                    // Removed originals stay honest historical metadata; revocation still stops the feed.
                    $this->access->require($module, "read");
                }
            }
            $rows[] = Entry::dated(
                "delivery:" . hash("sha256", $module . ":" . $key),
                $title,
                $job["due_at"],
                $module,
                $route,
                false,
                $state,
            );
        }
        $table = Schema::table($module, "jobs");
        $query = Db::run(
            $this->db,
            "SELECT item_id,item_version,due_at,status FROM $table WHERE club_id=? AND due_at>=? AND due_at<? ORDER BY due_at,id",
            [$club, $start, $end],
        );
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $job) {
            if (isset($identified[$job["item_id"] . ":" . $job["due_at"]])) {
                continue;
            }
            $item = $workspace->get($job["item_id"]);
            $state = self::state($job["status"]);
            if ($item["version"] !== (int) $job["item_version"]) {
                $state .= " · Frühere Fassung";
            }
            $rows[] = Entry::dated(
                "publication:" .
                    $module .
                    ":" .
                    $job["item_id"] .
                    ":" .
                    $job["item_version"],
                $label . ": " . $item["title"],
                $job["due_at"],
                $module,
                ["view" => $module, "native_item" => $item["id"]],
                false,
                $state,
            );
        }
        return $rows;
    }

    private static function state(string $status): string
    {
        return self::STATES[$status] ?? $status;
    }
}
