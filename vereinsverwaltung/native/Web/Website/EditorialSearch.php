<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;
use InvalidArgumentException;
use Kulturhus\Native\Cms\Pages;
use Kulturhus\Native\Shared\{Access, Db};
use PDO;

/** Shared validation, literal content/status filtering and consistent SQL page boundaries. */
final class EditorialSearch
{
    public function __construct(private PDO $db, private Access $access) {}
    public function validate(array $filters, int $size): void
    {
        if (
            $size < 1 ||
            $size > 100 ||
            !is_int($filters["page"] ?? null) ||
            $filters["page"] < 1 ||
            !is_string($filters["q"] ?? null) ||
            !is_string($filters["sort"] ?? null) ||
            !preg_match("//u", $filters["q"]) ||
            preg_match('/[\x00-\x1f\x7f]/', $filters["q"]) ||
            mb_strlen($filters["q"]) > 120 ||
            !in_array(
                $filters["status"] ?? null,
                ["all", "draft", "published", "changed"],
                true,
            )
        ) {
            throw new InvalidArgumentException(
                "Suchtext, Seitenzahl, Status und Sortierung prüfen.",
            );
        }
    }
    public function filters(
        array $filters,
        string $textField = "search_text",
    ): array {
        if (!in_array($textField, ["search_text", "event_search_text"], true)) {
            throw new InvalidArgumentException(
                "Unbekannter redaktioneller Suchtext.",
            );
        }
        $result = [];
        if ($filters["q"] !== "") {
            $result[] = [
                "field" => $textField,
                "op" => "contains_ci",
                "value" => $filters["q"],
            ];
        }
        if ($filters["status"] === "published") {
            $result[] = ["field" => "published", "value" => true];
        } elseif ($filters["status"] !== "all") {
            $result[] = [
                "field" => "status",
                "value" =>
                    $filters["status"] === "changed"
                        ? "unpublished_changes"
                        : "draft",
            ];
        }
        return $result;
    }
    public function listing(
        array $selectors,
        int $page,
        int $size,
        array $order,
    ): array {
        if ($page < 1 || $size < 1 || $size > 100) {
            throw new InvalidArgumentException(
                "Gültige redaktionelle Seitenzahl und Größe erforderlich.",
            );
        }
        return Db::atomic($this->db, function () use (
            $selectors,
            $page,
            $size,
            $order,
        ): array {
            $pages = new Pages($this->db, $this->access);
            $count = $pages->count($selectors);
            $last = max(1, (int) ceil($count / $size));
            $page = min($page, $last);
            return [
                "rows" => $pages->search(
                    $selectors,
                    $size,
                    ($page - 1) * $size,
                    $order,
                ),
                "total" => $count,
                "page" => $page,
                "last" => $last,
            ];
        });
    }
}
