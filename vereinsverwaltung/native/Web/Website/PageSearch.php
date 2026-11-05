<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;

use InvalidArgumentException;
use Kulturhus\Native\Cms\DataModel;
use Kulturhus\Native\Shared\{Access, Db};
use PDO;

/** Database-bounded editorial pages. List/search/count share one typed filter/order contract. */
final class PageSearch
{
    private const ORDERS = [
        "title_asc" => ["title", "asc", true],
        "title_desc" => ["title", "desc", true],
        "updated_desc" => ["updated_at", "desc", false],
    ];
    public function __construct(private PDO $db, private Access $access) {}

    public function choices(?callable $interfaceText=null): array
    {
        $t=$interfaceText??static fn(string $source):string=>$source;
        $choices = [
            "title_asc" => "Titel A–Z",
            "title_desc" => "Titel Z–A",
            "updated_desc" => "Zuletzt bearbeitet",
        ];
        $choices=array_map($t,$choices);
        foreach (
            (new DataModel($this->db, $this->access))->fields()
            as $field
        ) {
            foreach (
                ["asc" => "aufsteigend", "desc" => "absteigend"]
                as $direction => $label
            ) {
                $choices["custom." . $field["key"] . ":" . $direction] =
                    $field["label"] . " (" . $field["key"] . ") · " . $t($label);
            }
        }
        return $choices;
    }

    public function listing(array $filters, int $size = 25): array
    {
        $search = new EditorialSearch($this->db, $this->access);
        $search->validate($filters, $size);
        return Db::atomic($this->db, function () use (
            $filters,
            $size,
            $search,
        ): array {
            $selectors = $search->filters($filters);
            foreach (["event", "news", "gallery"] as $renderer) {
                $selectors[] = [
                    "field" => "renderer",
                    "op" => "ne",
                    "value" => $renderer,
                ];
            }
            $sort = $filters["sort"];
            if (isset(self::ORDERS[$sort])) {
                [$field, $direction, $fold] = self::ORDERS[$sort];
            } else {
                if (
                    !preg_match(
                        "/\Acustom\.([a-z][a-z0-9_]{0,63}):(asc|desc)\z/D",
                        $sort,
                        $match,
                    )
                ) {
                    throw new InvalidArgumentException(
                        "Unbekannte Seitensortierung.",
                    );
                }
                $definition = (new DataModel($this->db, $this->access))->field(
                    $match[1],
                );
                $field = "custom." . $match[1];
                $direction = $match[2];
                $fold = in_array(
                    $definition["type"],
                    ["text", "textarea", "richtext", "select", "url", "email"],
                    true,
                );
            }
            return $search->listing($selectors, $filters["page"], $size, [
                [
                    "field" => $field,
                    "direction" => $direction,
                    "case_fold" => $fold,
                ],
            ]);
        });
    }
}
