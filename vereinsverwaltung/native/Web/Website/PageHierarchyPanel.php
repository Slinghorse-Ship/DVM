<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;

use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Web\{Html, ReferencePicker};
use PDO;

/** One accessible placement form for every content editor, including custom templates. */
final class PageHierarchyPanel
{
    use \Kulturhus\Native\Web\ContentInterfaceText;
    public function __construct(private PDO $db, private Access $access, private string $csrf) {}

    public function render(array $page): string
    {
        if (empty($page['id']) || $page['archived']) return '';
        try { $this->access->require('website', 'edit'); }
        catch (\DomainException) { return ''; }
        $parent = $page['parent_id'] ?? '';
        $position = $page['nav_order'];
        $version = $page['version'];
        $submitted = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
            && ($_POST['operation'] ?? '') === 'page-place' && ($_POST['id'] ?? '') === $page['id'];
        if ($submitted) {
            if (is_string($_POST['hierarchy_parent'] ?? null)
                && preg_match('/\A(?:[a-f0-9]{32})?\z/D', $_POST['hierarchy_parent'])) $parent = $_POST['hierarchy_parent'];
            if (is_string($_POST['hierarchy_position'] ?? null)
                && preg_match('/\A-?[0-9]{1,7}\z/D', $_POST['hierarchy_position'])) $position = $_POST['hierarchy_position'];
            // Keep the original version after a conflict: retrying cannot overwrite newer work.
            if (is_string($_POST['version'] ?? null)
                && preg_match('/\A[1-9][0-9]{0,9}\z/D', $_POST['version'])) $version = $_POST['version'];
        }
        return '<details class="panel page-hierarchy-panel" id="page-hierarchy"' . ($submitted ? ' open' : '') . ('><summary>'.Html::e($this->contentText('Seite verschieben und einordnen')).'</summary>')
            . ('<p>'.Html::e($this->contentText('Ungespeicherte Inhalte vorher sichern. Die zuletzt gespeicherte Seite und ihre Unterseiten behalten Kennungen, Adressen, Bilder und Inhalte. Diese Änderung bleibt privat bis zur bewussten Veröffentlichung.')).'</p>')
            . '<form method="post">' . Html::hidden('csrf', $this->csrf) . Html::hidden('operation', 'page-place')
            . Html::hidden('id', $page['id']) . Html::hidden('version', $version)
            . ('<label for="hierarchy-parent">'.Html::e($this->contentText('Neue übergeordnete Seite')).'</label>')
            . (new ReferencePicker($this->db, $this->access))->render('page', 'hierarchy_parent', 'hierarchy-parent', $parent, false, '', $page['id'], $this->contentText('Oberste Ebene'))
            . Html::input('hierarchy_position', $this->contentText('Reihenfolge unter den Unterseiten'), $position, 'number', 'required min="-100000" max="100000" step="1"')
            . ('<p class="hint">'.Html::e($this->contentText('Kleinere Zahlen stehen zuerst; gleiche Zahlen werden nach Titel und Kennung sortiert. Bis zu 500 Seiten pro verschobenem Teilbaum und 100 Ebenen. Die Reihenfolge wird auch als gespeicherte Menüposition verwendet; eigene Menüeinstellungen bleiben vorrangig.')).'</p>')
            . ('<button class="btn btn-solid theme-primary">'.Html::e($this->contentText('Anordnung als Entwurf speichern')).'</button></form></details>');
    }
}
