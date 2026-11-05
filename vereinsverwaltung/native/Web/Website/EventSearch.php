<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Kulturhus\Native\Cms\EventYears;
use Kulturhus\Native\Shared\{Access, Db};
use PDO;

/** Current saved event drafts, with the same private list semantics as the editor. */
final class EventSearch
{
    public function __construct(private PDO $db, private Access $access) {}
    public function listing(array $filters, int $size = 25): array
    {
        $search = new EditorialSearch($this->db, $this->access);
        $search->validate($filters, $size);
        if (
            !in_array(
                $filters["period"] ?? null,
                ["all", "archive", "upcoming"],
                true,
            ) ||
            !is_string($filters["year"] ?? null) ||
            ($filters["year"] !== "" &&
                !preg_match("/\A[0-9]{4}\z/D", $filters["year"])) ||
            !in_array(
                $filters["sort"],
                ["date_asc", "date_desc", "title_asc"],
                true,
            )
        ) {
            throw new InvalidArgumentException(
                "Veranstaltungszeitraum, Jahr und Sortierung prüfen.",
            );
        }
        return Db::atomic($this->db, function () use (
            $filters,
            $size,
            $search,
        ): array {
            $selectors = [
                ["field" => "renderer", "value" => "event"],
                ...$search->filters($filters, "event_search_text"),
            ];
            if ($filters["year"] !== "") {
                $selectors[] = [
                    "field" => "event_day",
                    "op" => "gte",
                    "value" => $filters["year"] . "-01-01",
                ];
                $selectors[] = [
                    "field" => "event_day",
                    "op" => "lte",
                    "value" => $filters["year"] . "-12-31",
                ];
            }
            $today = (new DateTimeImmutable(
                "today",
                new DateTimeZone("Europe/Berlin"),
            ))->format("Y-m-d");
            if ($filters["period"] === "archive") {
                $selectors[] = [
                    "field" => "event_day",
                    "op" => "lt",
                    "value" => $today,
                ];
            } elseif ($filters["period"] === "upcoming") {
                $selectors[] = [
                    "any" => [
                        ["field" => "event_day", "value" => null],
                        [
                            "field" => "event_day",
                            "op" => "gte",
                            "value" => $today,
                        ],
                    ],
                ];
            }
            $title = $filters["sort"] === "title_asc";
            $result = $search->listing($selectors, $filters["page"], $size, [
                [
                    "field" => $title ? "title" : "event_start",
                    "direction" =>
                        $filters["sort"] === "date_desc" ? "desc" : "asc",
                    "case_fold" => $title,
                ],
            ]);
            $result["years"] = (new EventYears(
                $this->db,
                $this->access,
            ))->values();
            return $result;
        });
    }
}
