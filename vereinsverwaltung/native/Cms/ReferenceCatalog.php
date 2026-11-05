<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use InvalidArgumentException;
use Kulturhus\Native\Shared\{Access, Db};
use PDO;

/** Private reference labels, bounded in SQL; never loads drafts, files or provider content. */
final class ReferenceCatalog
{
    public function __construct(private PDO $db, private Access $access) {}

    public function query(array $query): array
    {
        if (
            array_diff(array_keys($query), [
                "native_endpoint",
                "kind",
                "q",
                "page",
                "selected",
                "exclude",
            ])
        ) {
            throw new InvalidArgumentException("Unbekannte Verknüpfungssuche.");
        }
        foreach (["kind", "q", "page", "selected", "exclude"] as $key) {
            if (array_key_exists($key, $query) && !is_string($query[$key])) {
                throw new InvalidArgumentException(
                    "Verknüpfungssuche als Text angeben.",
                );
            }
        }
        $page = $query["page"] ?? "1";
        if (!preg_match("/\A[1-9][0-9]{0,5}\z/D", $page)) {
            throw new InvalidArgumentException("Gültige Trefferseite angeben.");
        }
        return $this->listing(
            $query["kind"] ?? "",
            $query["q"] ?? "",
            (int) $page,
            $query["selected"] ?? "",
            $query["exclude"] ?? "",
        );
    }

    public function listing(
        string $kind,
        string $query = "",
        int $page = 1,
        string $selected = "",
        string $exclude = "",
    ): array {
        $this->access->require("website", "read");
        if (
            !in_array($kind, ["page", "media", "file"], true) ||
            $page < 1 ||
            $page > 999999 ||
            !preg_match("//u", $query) ||
            preg_match('/[\x00-\x1f\x7f]/', $query) ||
            mb_strlen($query) > 120 ||
            ($kind !== "page" && $exclude !== "") ||
            ($selected !== "" &&
                !preg_match("/\A[a-f0-9]{32}\z/D", $selected)) ||
            ($exclude !== "" && !preg_match("/\A[a-f0-9]{32}\z/D", $exclude))
        ) {
            throw new InvalidArgumentException(
                "Art, Suchtext und aktuelle Verknüpfung prüfen.",
            );
        }
        if($kind==='file'&&\Kulturhus\Native\Files\FileReferences::installed($this->db))return(new \Kulturhus\Native\Files\Picker($this->db,$this->access))->listing($query,$page,$selected);
        return Db::atomic($this->db, function () use (
            $kind,
            $query,
            $page,
            $selected,
            $exclude,
        ): array {
            $owner = $this->access->require("website", "read")["club_id"];
            if ($kind === "page") {
                $from = "khcms_pages p";
                $columns =
                    "p.id,p.slug,JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$.title')) AS title";
                $text =
                    "CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$.title')),''),' ',p.slug)";
                $order =
                    "LOWER(title) COLLATE utf8mb4_bin,p.slug COLLATE utf8mb4_bin,p.id";
            } elseif($kind==='file') {
                $from='khcms_documents p';$columns='p.id,p.original_name';$text='p.original_name';$order='LOWER(p.original_name) COLLATE utf8mb4_bin,p.id';
            } else {
                $from = "khcms_media p";
                $columns = "p.id,p.alt,p.original_name";
                $text = "CONCAT(p.alt,' ',p.original_name)";
                $order =
                    "LOWER(p.alt) COLLATE utf8mb4_bin,LOWER(p.original_name) COLLATE utf8mb4_bin,p.id";
            }
            $base = "p.club_id=?" . ($kind === "page" ? " AND ".PageTrash::activeSql() : "");
            if($kind==='file'&&Db::run($this->db,"SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='native_communication_documents'")->fetchColumn())$base.=' AND NOT EXISTS(SELECT 1 FROM native_communication_documents cd WHERE cd.club_id=p.club_id AND cd.document_id=p.id)';
            $parameters = [$owner];
            if($kind==='page'){[$policy,$policyParameters]=TemplatePolicy::readFilter($this->db,$this->access);$base.=' AND ('.$policy.')';array_push($parameters,...$policyParameters);}
            if ($exclude !== "") {
                $base .= " AND p.id<>?";
                $parameters[] = $exclude;
            }
            $where = $base;
            $searchParameters = $parameters;
            if ($query !== "") {
                $where .=
                    " AND LOWER(" .
                    $text .
                    ") COLLATE utf8mb4_bin LIKE LOWER(?) COLLATE utf8mb4_bin ESCAPE '!'";
                $searchParameters[] =
                    "%" .
                    strtr($query, ["!" => "!!", "%" => "!%", "_" => "!_"]) .
                    "%";
            }
            $total = (int) Db::run(
                $this->db,
                "SELECT COUNT(*) FROM " . $from . " WHERE " . $where,
                $searchParameters,
            )->fetchColumn();
            $last = max(1, (int) ceil($total / 25));
            $page = min($page, $last);
            $rows = Db::run(
                $this->db,
                "SELECT " .
                    $columns .
                    " FROM " .
                    $from .
                    " WHERE " .
                    $where .
                    " ORDER BY " .
                    $order .
                    " LIMIT 25 OFFSET " .
                    ($page - 1) * 25,
                $searchParameters,
            )->fetchAll(PDO::FETCH_ASSOC);
            $original =
                $selected === ""
                    ? false
                    : Db::run(
                        $this->db,
                        "SELECT " .
                            $columns .
                            " FROM " .
                            $from .
                            " WHERE " .
                            $base .
                            " AND p.id=?",
                        [...$parameters, $selected],
                    )->fetch(PDO::FETCH_ASSOC);
            $label = static fn(array $row): array => [
                "id" => $row["id"],
                "label" =>
                    $kind==='file'?$row['original_name']:($kind === "page"
                        ? $row["title"] . " · /" . $row["slug"]
                        : $row["alt"] .
                            ($row["original_name"] !== ""
                                ? " · " . $row["original_name"]
                                : "")),
            ];
            return [
                "rows" => array_map($label, $rows),
                "total" => $total,
                "page" => $page,
                "last" => $last,
                "selected" => $original ? $label($original) : null,
            ];
        });
    }
}
