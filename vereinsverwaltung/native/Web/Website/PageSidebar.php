<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;
use InvalidArgumentException;
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Native\Web\Html;
use Kulturhus\Native\Cms\PageTrash;
use PDO;

/** Complete paged standalone directory using only editor metadata, never every content body. */
final class PageSidebar
{
    public function __construct(private PDO $db, private Access $access) {}
    public function render(string $selected = ""): string
    {
        $requested = array_key_exists("sidebar_page", $_GET)
            ? $_GET["sidebar_page"]
            : "1";
        if (
            !is_string($requested) ||
            !preg_match("/\A[1-9][0-9]{0,5}\z/D", $requested)
        ) {
            throw new InvalidArgumentException("Seitenverzeichnis prüfen.");
        }
        return Db::atomic($this->db, function () use (
            $requested,
            $selected,
        ): string {
            $owner = $this->access->require("website", "read")["club_id"];
            $total = (int) Db::run(
                $this->db,
                "SELECT COUNT(*) FROM khcms_pages p WHERE p.club_id=? AND ".PageTrash::activeSql(),
                [$owner],
            )->fetchColumn();
            $last = max(1, (int) ceil($total / 25));
            $page = min((int) $requested, $last);
            $rows = Db::run(
                $this->db,
                "SELECT id,slug,version,published_revision,JSON_UNQUOTE(JSON_EXTRACT(draft_json,'$.title')) AS title FROM khcms_pages p WHERE p.club_id=? AND ".PageTrash::activeSql()." ORDER BY updated_at DESC,id LIMIT 25 OFFSET " .
                    ($page - 1) * 25,
                [$owner],
            )->fetchAll(PDO::FETCH_ASSOC);
            $html =
                '<aside class="panel page-sidebar"><details class="page-sidebar-picker"'.(array_key_exists('sidebar_page',$_GET)?' open':'').'><summary>Seiten auswählen</summary><h2>Deine Seiten</h2><p class="hint page-sidebar-count">' .
                $total .
                " Inhalte · Seite " .
                $page .
                " von " .
                $last .
                '</p><a data-nav class="btn btn-solid theme-secondary button secondary" href="/?view=website">Neue Seite</a>';
            foreach ($rows as $row) {
                $html .=
                    '<a class="item" data-page-directory="' .
                    Html::e($row["id"]) .
                    '" data-nav href="' .
                    Html::e(
                        "/?" .
                            http_build_query([
                                "view" => "website",
                                "item" => $row["id"],
                                "sidebar_page" => $page,
                            ]),
                    ) .
                    '"' .
                    ($row["id"] === $selected ? ' aria-current="page"' : "") .
                    "><strong>" .
                    Html::e($row["title"]) .
                    "</strong><small>/" .
                    Html::e($row["slug"]) .
                    " · Version " .
                    Html::e($row["version"]) .
                    ($row["published_revision"] !== null
                        ? " · veröffentlicht"
                        : " · Entwurf") .
                    "</small></a>";
            }
            if ($last > 1) {
                $html .=
                    '<nav class="actions" aria-label="Seitenverzeichnis blättern">';
                foreach (
                    [
                        "Vorherige Seiten" => $page - 1,
                        "Weitere Seiten" => $page + 1,
                    ]
                    as $label => $target
                ) {
                    if ($target > 0 && $target <= $last) {
                        $html .=
                            '<a class="btn btn-solid theme-secondary" data-nav href="' .
                            Html::e(
                                "/?" .
                                    http_build_query([
                                        "view" => "website",
                                        "item" => $selected,
                                        "sidebar_page" => $target,
                                    ]),
                            ) .
                            '">' .
                            Html::e($label) .
                            "</a>";
                    }
                }
                $html .= "</nav>";
            }
            return $html . "</details></aside>";
        });
    }
}
