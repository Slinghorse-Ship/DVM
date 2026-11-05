<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;
use Kulturhus\Native\Shared\{Access, Db};
use PDO;

/** Aggregate current editorial metadata in SQL; do not materialize every draft. */
final class PageSummary
{
    public function __construct(private PDO $db, private Access $access) {}
    public function read(): array
    {
        $this->access->require("website", "read");
        [$renderer, $parameters] = (new QueryField(
            new DataModel($this->db, $this->access),
        ))->resolve("renderer");
        $rows = Db::run(
            $this->db,
            "SELECT " .
                $renderer .
                " AS renderer,COUNT(*) AS total,SUM(p.published_revision IS NOT NULL) AS published,SUM(p.published_revision IS NOT NULL AND p.version>p.published_revision) AS changed,SUM(p.slug=?) AS root_gallery FROM khcms_pages p WHERE ".PageTrash::activeSql()." AND p.club_id=? GROUP BY renderer",
            [...$parameters, "galerie", $this->access->club()],
        )->fetchAll(PDO::FETCH_ASSOC);
        $result = [
            "total" => 0,
            "published" => 0,
            "changed" => 0,
            "website" => 0,
            "events" => 0,
            "news" => 0,
            "media" => 0,
        ];
        foreach ($rows as $row) {
            foreach (["total", "published", "changed"] as $key) {
                $result[$key] += (int) $row[$key];
            }
            $view = match ($row["renderer"]) {
                "event" => "events",
                "news" => "news",
                "gallery" => "media",
                default => "website",
            };
            $result[$view] +=
                (int) $row["total"] -
                ($view === "media" ? (int) $row["root_gallery"] : 0);
        }
        return $result;
    }
}
