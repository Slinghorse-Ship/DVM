<?php
declare(strict_types=1);
namespace Kulturhus\Native\Web\Settings;

use Kulturhus\Native\Cms\WebsiteSettings;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Web\Html;
use PDO;

final class IndexingPanel
{
    public static function version(array $post): int
    {
        $value = $post["version"] ?? null;
        if (
            (!is_string($value) && !is_int($value)) ||
            !preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', (string) $value) ||
            (int) $value >= 4294967295
        ) {
            throw new \DomainException(
                "Website-Fassung ungültig. Bitte Formular neu öffnen.",
            );
        }
        return (int) $value;
    }

    public static function choice(array $post): bool
    {
        if (
            ($post["website_indexing_present"] ?? null) !== "1" ||
            (array_key_exists("website_indexing", $post) &&
                $post["website_indexing"] !== "1")
        ) {
            throw new \DomainException(
                "Suchmaschinenwahl ungültig. Bitte Formular neu öffnen.",
            );
        }
        return array_key_exists("website_indexing", $post);
    }

    public static function render(PDO $db, Access $access, string $csrf): string
    {
        $access->require("website", "manage");
        $store = new WebsiteSettings($db, $access);
        $state = $store->get();
        $html =
            '<section class="panel" aria-labelledby="website-indexing-heading"><h2 id="website-indexing-heading">Website in Suchmaschinen</h2><p>Erlaube Suchmaschinen, veröffentlichte Seiten deiner Website zu indexieren. Die Einstellung „Nicht in Suchmaschinen indexieren“ auf einer Seite gilt weiterhin. Entwicklungsseiten, Vorschauen und der Vereinsmanager bleiben gesperrt.</p><form method="post">' .
            Html::hidden("csrf", $csrf) .
            Html::hidden("operation", "website-indexing") .
            Html::hidden("version", $state["version"]) .
            Html::hidden("website_indexing_present", "1") .
            '<label class="check"><input class="check" type="checkbox" name="website_indexing" value="1"' .
            ($state["enabled"] ? " checked" : "") .
            '> Öffentliche Website in Suchmaschinen anzeigen</label><button class="btn btn-solid theme-primary">Suchmaschinenwahl speichern</button></form><p><a href="/sitemap/" target="_blank" rel="noopener">Sitemap ansehen ↗</a></p>';
        if ($history = $store->history()) {
            $html .= "<details><summary>Letzte Änderungen</summary><ol>";
            foreach ($history as $entry) {
                $html .=
                    "<li>Fassung " .
                    Html::e($entry["version"]) .
                    ": " .
                    ((int) $entry["indexing_enabled"] === 1
                        ? "Indexierung erlaubt"
                        : "Indexierung gesperrt") .
                    " · " .
                    Html::e($entry["created_at"]) .
                    " UTC</li>";
            }
            $html .= "</ol></details>";
        }
        return $html . "</section>";
    }
}
