<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;
use Kulturhus\Native\Cms\{Pages, PageSummary, Navigation};
use Kulturhus\Native\Shared\{Access, Db};
use PDO;

/** One consistent metadata summary plus exactly eight recent saved pages. */
final class PageOverview
{
    public function __construct(private PDO $db, private Access $access) {}
    public function read(): array
    {
        return Db::atomic(
            $this->db,
            fn() => [
                "counts" => (new PageSummary($this->db, $this->access))->read(),
                "menu" => (new Navigation($this->db, $this->access))->count(
                    "start",
                ),
                "recent" => (new Pages($this->db, $this->access))->search(
                    [],
                    8,
                    0,
                    [["field" => "updated_at", "direction" => "desc"]],
                ),
            ],
        );
    }
}
