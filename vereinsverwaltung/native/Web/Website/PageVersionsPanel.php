<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;
use InvalidArgumentException;
use Kulturhus\Native\Cms\PageVersions;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Web\Html;
use PDO;

/** Same original history, compare form and deliberate restore controls in every content editor. */
final class PageVersionsPanel
{
    use \Kulturhus\Native\Web\ContentInterfaceText;
    private const ACTIONS = [
        "create" => "Angelegt",
        "save" => "Entwurf gespeichert",
        "publish" => "Veröffentlicht",
        "unpublish" => "Offline genommen",
        "restore" => "Als Entwurf wiederhergestellt",
        "archive" => "Archiviert",
        "unarchive" => "Aus Archiv zurückgeholt",
        "news-review" => "Zur Prüfung gegeben",
        "news-approve" => "Redaktionell freigegeben",
        "news-draft" => "Zurück zum Entwurf",
    ];
    public function __construct(
        private PDO $db,
        private Access $access,
        private string $csrf,
    ) {}
    public function render(
        array $page,
        string $view,
        string $restoreOperation = "",
        string $restoreLabel = "Als Entwurf laden",
    ): string {
        $versions = new PageVersions($this->db, $this->access);
        $requested = self::number(
            $_GET["versions_page"] ?? "1",
            "Versionsseite",
        );
        $history = $versions->overview($page["id"], $requested);
        $defaultFrom =
            $history["published"] !== null &&
            $history["published"] !== $history["current"]
                ? $history["published"]
                : $history["previous"] ?? $history["current"];
        $from = self::number(
            $_GET["compare_from"] ?? (string) $defaultFrom,
            "Ausgangsversion",
        );
        $to = self::number(
            $_GET["compare_to"] ?? (string) $history["current"],
            "Vergleichsversion",
        );
        $selected = $versions->selected($page["id"], [$from, $to]);
        $choices = [];
        foreach ($history["rows"] as $row) {
            $choices[$row["revision"]] = $this->label($row, $history);
        }
        foreach ($selected as $revision => $row) {
            $choices[$revision] = $this->label($row, $history);
        }
        krsort($choices, SORT_NUMERIC);
        $html =
            ('<details class="page-versions" data-page-versions'.(isset($_GET['versions_page'])||isset($_GET['compare_from'])||isset($_GET['compare_to'])?' open':'').'><summary>'.Html::e($this->contentText('Verlauf')).'</summary><p class="hint">'.Html::e($this->contentText('Vergleiche zwei gespeicherte Fassungen. Die ursprünglichen Angaben, Feldtypen und Vorlagen bleiben erkennbar. Veröffentlichungen werden dadurch nicht geändert.')).'</p><form method="get" action="/" data-version-compare-form data-dirty-ignore>') .
            Html::hidden("view", $view) .
            Html::hidden("item", $page["id"]) .
            Html::hidden("versions_page", $history["page"]) .
            '<div class="fields">' .
            Html::select(
                "compare_from",
                $this->contentText("Ausgangsversion"),
                $choices,
                (string) $from,
            ) .
            Html::select(
                "compare_to",
                $this->contentText("Vergleichsversion"),
                $choices,
                (string) $to,
            ) .
            ('</div><button class="btn btn-solid theme-secondary">'.Html::e($this->contentText('Versionen vergleichen')).'</button></form>');
        if (
            array_key_exists("compare_from", $_GET) ||
            array_key_exists("compare_to", $_GET)
        ) {
            $comparison = $versions->compare($page["id"], $from, $to);
            $values = new VersionValues($this->db, $this->access);
            $html .=
                '<section class="version-comparison" data-version-comparison><h4>' .
                Html::e($this->contentFormat('Version {from} → Version {to}',['from'=>$from,'to'=>$to])) .
                '</h4><p role="status">' .
                Html::e($this->contentFormat('{count} geänderte Angaben',['count'=>count($comparison['changes'])])) .
                "</p>";
            if (!$comparison["changes"]) {
                $html .=
                    ('<p>'.Html::e($this->contentText('Die gespeicherten Inhalte stimmen überein. Veröffentlichung, Archivierung und andere Vorgänge sind in der Historie aufgeführt.')).'</p>');
            }
            foreach ($comparison["changes"] as $change) {
                $html .=
                    '<section class="version-change" data-version-change="' .
                    Html::e($change["key"]) .
                    '"><h5>' .
                    Html::e($change["label"]) .
                    '</h5><div class="version-change-columns"><div><h6>' .
                    Html::e($this->contentFormat('Version {version}',['version'=>$from])) .
                    "</h6>" .
                    $values->render(
                        $change["before"],
                        $comparison["before_labels"],
                    ) .
                    "</div><div><h6>" .
                    Html::e($this->contentFormat('Version {version}',['version'=>$to])) .
                    "</h6>" .
                    $values->render(
                        $change["after"],
                        $comparison["after_labels"],
                    ) .
                    "</div></div></section>";
            }
            $html .= "</section>";
        }
        $html .=
            '<p class="hint version-history-count">' .
            Html::e($this->contentFormat('{count} Versionen · Seite {page} von {pages}',['count'=>$history['total'],'page'=>$history['page'],'pages'=>$history['pages']])) .
            '</p><ol class="version-list" data-version-history>';
        $canRestore = false;
        try {
            $this->access->require("website", "edit");
            $canRestore = $restoreOperation !== "";
        } catch (\DomainException) {
        }
        foreach ($history["rows"] as $row) {
            $html .=
                '<li data-saved-version="' .
                $row["revision"] .
                '"><strong>' .
                Html::e($this->label($row, $history)) .
                "</strong><small>" .
                Html::e($row["created_at"]) .
                " UTC · " .
                Html::e($row["actor_name"] ?? $this->contentText("Ehemaliges Konto")) .
                '</small><a data-nav class="btn btn-outline theme-secondary" href="' .
                Html::e(
                    $this->url(
                        $view,
                        $page["id"],
                        $history["page"],
                        $row["revision"],
                        $history["current"],
                    ),
                ) .
                ('">'.Html::e($this->contentText('Mit aktuellem Stand vergleichen')).'</a>');
            if ($canRestore && $row["revision"] !== $page["version"]) {
                $html .=
                    '<form method="post">' .
                    Html::hidden("csrf", $this->csrf) .
                    Html::hidden("operation", $restoreOperation) .
                    Html::hidden("id", $page["id"]) .
                    Html::hidden("revision", $row["revision"]) .
                    Html::hidden("version", $this->contentPosted($restoreOperation,$page["id"])&&($_POST["revision"]??null)===(string)$row["revision"]&&is_string($_POST["version"]??null)?$_POST["version"]:$page["version"]) .
                    '<button class="btn btn-solid theme-secondary secondary">' .
                    Html::e($this->contentText($restoreLabel)) .
                    "</button></form>";
            }
            $html .= "</li>";
        }
        $html .= "</ol>";
        if ($history["pages"] > 1) {
            $html .=
                ('<nav class="actions" aria-label="'.Html::e($this->contentText('Gespeicherte Versionen blättern')).'">');
            foreach (
                [
                    "Neuere Versionen" => $history["page"] - 1,
                    "Ältere Versionen" => $history["page"] + 1,
                ]
                as $label => $target
            ) {
                if ($target > 0 && $target <= $history["pages"]) {
                    $html .=
                        '<a data-nav class="btn btn-outline theme-secondary" href="' .
                        Html::e(
                            $this->url(
                                $view,
                                $page["id"],
                                $target,
                                array_key_exists("compare_from", $_GET)
                                    ? $from
                                    : null,
                                array_key_exists("compare_to", $_GET)
                                    ? $to
                                    : null,
                            ),
                        ) .
                        '">' .
                        Html::e($this->contentText($label)) .
                        "</a>";
                }
            }
            $html .= "</nav>";
        }
        return $html . "</details>";
    }
    private function label(array $row, array $history): string
    {
        return $this->contentFormat('Version {version} · {action}',['version'=>$row['revision'],'action'=>$this->contentText(self::ACTIONS[$row['action']]??'Gespeicherter Vorgang')])
            .($row['revision']===$history['published']?' · '.$this->contentText('aktuell veröffentlicht'):'');
    }
    private function url(
        string $view,
        string $id,
        int $page,
        ?int $from,
        ?int $to,
    ): string {
        return "/?" .
            http_build_query(
                ["view" => $view, "item" => $id, "versions_page" => $page] +
                    ($from === null ? [] : ["compare_from" => $from]) +
                    ($to === null ? [] : ["compare_to" => $to]),
            );
    }
    private static function number(mixed $value, string $label): int
    {
        if (
            !is_string($value) ||
            !preg_match("/\A[1-9][0-9]{0,9}\z/D", $value) ||
            (int) $value > 4294967295
        ) {
            throw new InvalidArgumentException($label . " prüfen.");
        }
        return (int) $value;
    }
}
