<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;
use Kulturhus\Native\Shared\{Access, Db};
use PDO;

/** Distinct draft calendar years; no content bodies or publication side effects. */
final class EventYears
{
    public function __construct(private PDO $db, private Access $access) {}
    public function values(): array
    {
        $this->access->require("website", "read");
        $fields = new QueryField(new DataModel($this->db, $this->access));
        [$day, $parameters] = $fields->resolve("event_day");
        [$renderer, $rendererParameters] = $fields->resolve("renderer");
        $rows = Db::run(
            $this->db,
            "SELECT DISTINCT LEFT(" .
                $day .
                ",4) AS year FROM khcms_pages p WHERE ".PageTrash::activeSql()." AND p.club_id=? AND " .
                $renderer .
                "=? AND " .
                $day .
                " IS NOT NULL ORDER BY year DESC LIMIT 10000",
            [
                ...$parameters,
                $this->access->club(),
                ...$rendererParameters,
                "event",
                ...$parameters,
            ],
        )->fetchAll(PDO::FETCH_COLUMN);
        return array_map(static fn($year) => (string) $year, $rows);
    }
}
