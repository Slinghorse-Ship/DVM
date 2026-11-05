<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Settings;

use PDO;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Integration\Jobs\RunCatalogue;
use Kulturhus\Native\Web\Html;

final class WorkerPanel
{
    public static function render(PDO $db, Access $access): string
    {
        $catalogue=RunCatalogue::page($db,$access,$_GET);$rows=$catalogue['rows'];
        $h =
            '<section id="worker-history" aria-labelledby="worker-heading"><h2 id="worker-heading">Hintergrundaufgaben</h2><p>Eigene Hintergrundläufe. Versandresultate stehen im jeweiligen Fachbereich.</p>';
        if (!$catalogue['all_total']) {
            return $h .
                "<p>Noch kein Hintergrundlauf aufgezeichnet.</p></section>";
        }
        $h.='<form method="get" action="/?view=settings#worker-heading" class="worker-history-filter">'.Html::hidden('view','settings').Html::select('worker_state','Läufe anzeigen',RunCatalogue::STATES,$catalogue['state']).'<button class="btn btn-outline theme-secondary">Anzeigen</button></form><p data-worker-count>'.Html::e($catalogue['total']).($catalogue['total']===1?' Lauf · Seite ':' Läufe · Seite ').Html::e($catalogue['page']).' von '.Html::e($catalogue['pages']).'</p>';
        if(!$rows)return $h.'<p>Keine passenden Hintergrundläufe.</p></section>';
        $h .=
            '<div class="table-wrap worker-table" tabindex="0" role="region" aria-label="Eigene Hintergrundläufe"><table class="table"><caption>Letzte Hintergrundläufe · Zeiten in UTC</caption><thead><tr><th scope="col">Beginn</th><th scope="col">Aufgaben</th><th scope="col">Status</th><th scope="col">Abschluss</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $modes = json_decode(
                $row["modes_json"],
                true,
                16,
                JSON_THROW_ON_ERROR,
            );
            $labels = [];
            foreach (
                [
                    "send" => "E-Mail",
                    "receive" => "Rechnungspostfach",
                    "push" => "Push",
                    "meta" => "Facebook / Instagram veröffentlichen",
                    "stories" => "Veranstaltungsstorys automatisch veröffentlichen",
                    "stadtlandfunk" => "StadtLand.Funk veröffentlichen",
                    "publish" => "Website veröffentlichen",
                    "social-check" =>
                        "Social-Kommentare und Nachrichten prüfen",
                    "mail-replies" => "Mailantworten abrufen",
                    "events" => "Veranstaltungen abgleichen",
                    "press" => "Presseversand",
                    "catalog-billing" => "Tarifabrechnung",
                    "fints" => "Bankumsatzabruf",
                ]
                as $key => $label
            ) {
                if (($modes[$key] ?? false) && !($key==='send'&&(($modes['stadtlandfunk']??false)||($modes['meta']??false)||($modes['stories']??false)||($modes['press']??false)))) {
                    $labels[] = $label;
                }
            }
            $status = match ($row["status"]) {
                "completed" => "Abgeschlossen",
                "failed" => "Fehlgeschlagen – prüfen",
                "needs_review"
                    => "Abgeschlossen – Veröffentlichungen oder Zustellungen prüfen",
                default => "Läuft",
            };
            if (
                ($modes["social-check"] ?? false) &&
                $row["status"] === "needs_review"
            ) {
                $status =
                    "Abgeschlossen – Social-Verbindung oder Anbieterpause prüfen";
            }
            if (
                $row["status"] === "running" &&
                strtotime($row["started_at"] . " UTC") < time() - 900
            ) {
                $status = "Länger laufend oder unterbrochen – prüfen";
            }
            $badge=match($row['status']){'completed'=>'Abgeschlossen','failed'=>'Fehlgeschlagen','needs_review'=>'Prüfen',default=>$status==='Läuft'?'Läuft':'Prüfen'};
            $tone=$badge==='Prüfen'?'needs_review':$row['status'];
            $h .=
                '<tr data-worker-run="'.Html::e($row['id']).'"><td data-label="Beginn (UTC)"><time datetime="' .
                Html::e(str_replace(" ", "T", $row["started_at"]) . "Z") .
                '">' .
                Html::e($row["started_at"]) .
                '</time></td><td data-label="Aufgaben">' .
                Html::e(
                    $labels ? implode(", ", $labels) : "Planung ohne Versand",
                ) .
                '</td><td data-label="Status" aria-label="'.Html::e($status).'"><span class="worker-status worker-status-'.Html::e($tone).'">' .
                Html::e($badge) .
                '</span>' .
                '</td><td data-label="Abschluss (UTC)">' .
                Html::e($row["finished_at"] ?? "Offen") .
                "</td></tr>";
        }
        $h.='</tbody></table></div>';if($catalogue['pages']>1){$h.='<nav class="worker-history-pages" aria-label="Hintergrundläufe blättern">';foreach([-1=>'Vorherige',1=>'Weitere']as$delta=>$label){$page=$catalogue['page']+$delta;if($page>=1&&$page<=$catalogue['pages'])$h.='<a class="btn btn-outline theme-secondary" data-nav href="'.Html::e('/?'.http_build_query(['view'=>'settings','worker_state'=>$catalogue['state'],'worker_page'=>$page]).'#worker-heading').'">'.$label.'</a>';}$h.='</nav>';}return $h.'</section>';
    }
}
