<?php
declare(strict_types=1);

namespace Kulturhus\Native\Web\Website;

use Kulturhus\Native\Cms\PathHistory;
use Kulturhus\Native\Web\{Html, Workspace};

final class PathHistoryPanel extends Workspace
{
    public function render(array $page): string
    {
        $e = Html::e(...);
        $publish = $this->can("website", "publish");
        $html =
            '<details class="panel"><summary>Alte Links &amp; Weiterleitungen</summary><p>Beim Veröffentlichen einer neuen URL bleibt die frühere Adresse mit dieser Originalseite verbunden. Weiterleitungen führen direkt zur aktuell veröffentlichten Fassung. Offline-Seiten bleiben auch über alte Links unsichtbar.</p><ul>';
        $rows = (new PathHistory($this->db, $this->access))->listing(
            $page["id"],
        );
        foreach ($rows as $row) {
            $html .= "<li><code>/" . $e($row["slug"]) . "/</code>";
            if ($publish) {
                $html .=
                    $this->start("page-path-remove") .
                    Html::hidden("id", $page["id"]) .
                    Html::hidden("version", $page["version"]) .
                    Html::hidden("history_id", $row["id"]) .
                    '<label class="check"><input class="check" type="checkbox" name="confirmed" value="1" required> Diesen alten Link entfernen</label><button class="btn btn-outline theme-secondary secondary">Weiterleitung entfernen</button></form>';
            }
            $html .= "</li>";
        }
        if (!$rows) {
            $html .= "<li>Noch keine früheren Links gespeichert.</li>";
        }
        $html .= "</ul>";
        if ($publish) {
            $html .=
                $this->start("page-path-add") .
                Html::hidden("id", $page["id"]) .
                Html::hidden("version", $page["version"]) .
                Html::input(
                    "slug",
                    "Früherer Inhaltspfad ohne Domain",
                    "",
                    "text",
                    'required maxlength="180" placeholder="verein/ueber-uns"',
                ) .
                '<button class="btn btn-solid theme-primary">Alten Link übernehmen</button></form>';
        }
        return $html . "</details>";
    }
}
