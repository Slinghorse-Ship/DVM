<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;
use InvalidArgumentException;
use Kulturhus\Native\Cms\Pages;
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Native\Web\Html;
use PDO;

/** Same club-bound, paged hierarchy links in every native content editor. */
final class PageChildrenPanel
{
    use \Kulturhus\Native\Web\ContentInterfaceText;
    public function __construct(private PDO $db, private Access $access) {}
    public function render(array $parent): string
    {
        if (empty($parent["id"])) {
            return "";
        }
        $requested = $_GET["children_page"] ?? "1";
        if (
            !is_string($requested) ||
            !preg_match("/\A[1-9][0-9]{0,5}\z/D", $requested)
        ) {
            throw new InvalidArgumentException("Unterseitenzahl prüfen.");
        }
        return Db::atomic($this->db, function () use (
            $parent,
            $requested,
        ): string {
            $pages = new Pages($this->db, $this->access);
            $filter = [["field" => "parent_id", "value" => $parent["id"]]];
            $total = $pages->count($filter);
            if (!$total) {
                return "";
            }
            $last = (int) ceil($total / 25);
            $page = min((int) $requested, $last);
            $children = $pages->search($filter, 25, ($page - 1) * 25, [
                ["field" => "nav_order"],
                ["field" => "title", "case_fold" => true],
            ]);
            $html =
                ('<section class="panel page-children-panel"><h2>'.Html::e($this->contentText('Unterseiten')).'</h2><p>'.Html::e($this->contentText('Die zuletzt gespeicherte Entwurfshierarchie. Inhalte hier öffnen und prüfen.')).'</p><p class="page-children-count" role="status">') .
                Html::e($this->contentFormat($last>1?'{count} Unterseiten · Seite {page} von {pages}':'{count} Unterseiten',['count'=>$total,'page'=>$page,'pages'=>$last])) .
                ('</p><p class="hint">'.Html::e($this->contentText('Nach gespeicherter Reihenfolge, dann Titel. Zum Einordnen die Unterseite öffnen und „Seite verschieben und einordnen“ wählen.')).'</p><ul class="nav page-children-list">');
            foreach ($children as $child) {
                $state = $child["archived"]
                    ? "Archiviert"
                    : ($child["published_revision"] === null
                        ? "Unveröffentlicht"
                        : ($child["version"] === $child["published_revision"]
                            ? "Veröffentlicht"
                            : "Entwurf mit Änderungen"));
                $html .=
                    '<li data-child-page="' .
                    Html::e($child["id"]) .
                    '"><a class="nav-link" data-nav href="' .
                    Html::e(
                        "/?" .
                            http_build_query([
                                "view" => self::view($child),
                                "item" => $child["id"],
                            ]),
                    ) .
                    '">' .
                    Html::e($child["title"]) .
                    '</a><span class="badge">' .
                    Html::e($this->contentText($state)) .
                    '</span><span class="badge">' . Html::e($this->contentFormat('Position {position}',['position'=>(int)$child['nav_order']])) . "</span></li>";
            }
            $html .= "</ul>";
            if ($last > 1) {
                $html .=
                    ('<nav class="website-pagination" aria-label="'.Html::e($this->contentText('Unterseiten blättern')).'">');
                foreach (
                    [
                        "Vorherige Unterseiten" => $page - 1,
                        "Nächste Unterseiten" => $page + 1,
                    ]
                    as $label => $target
                ) {
                    if ($target > 0 && $target <= $last) {
                        $html .=
                            '<a class="btn btn-solid theme-secondary" data-nav href="' .
                            Html::e(
                                "/?" .
                                    http_build_query([
                                        "view" => self::view($parent),
                                        "item" => $parent["id"],
                                        "children_page" => $target,
                                    ]),
                            ) .
                            '">' .
                            Html::e($this->contentText($label)) .
                            "</a>";
                    }
                }
                $html .= "</nav>";
            }
            return $html . "</section>";
        });
    }
    private static function view(array $page): string
    {
        return match ($page["template"]) {
            "news" => "news",
            "gallery" => "media",
            "event" => "events",
            default => "website",
        };
    }
}
