<?php declare(strict_types=1);
namespace Kulturhus\Verein\Events\Calendar;

use PDO;
use Kulturhus\Verein\Pagination;

/** Bounded original calendar reads; Registry supplies the current authenticated calendar actor. */
final class AppointmentListing
{
    public static function forActor(PDO $db, array $actor, array $query,int $pageSize=20): array
    {
        if(!in_array($pageSize,[6,20],true))throw new \InvalidArgumentException('Kalenderseitengröße prüfen.');
        foreach (["q", "archiv", "seite"] as $key) {
            if (!is_string($query[$key] ?? "") && !is_int($query[$key] ?? "")) {
                throw new \InvalidArgumentException(
                    "Kalenderfilter müssen einzelne Werte sein.",
                );
            }
        }
        $search = trim((string) ($query["q"] ?? ""));
        if (
            preg_match("//u", $search) !== 1 ||
            preg_match('/[\x00-\x1f\x7f]/', $search) ||
            preg_match_all("/./us", $search) > 150
        ) {
            throw new \InvalidArgumentException(
                "Suche auf 150 Zeichen ohne Steuerzeichen begrenzen.",
            );
        }
        $page = (string) ($query["seite"] ?? "");
        if ($page !== "" && (!ctype_digit($page) || strlen($page) > 9)) {
            throw new \InvalidArgumentException("Ungültige Kalenderseite.");
        }
        $archive = (string) ($query["archiv"] ?? "") === "1";
        $today = (new \DateTimeImmutable(
            "today",
            new \DateTimeZone("Europe/Berlin"),
        ))
            ->setTimezone(new \DateTimeZone("UTC"))
            ->format("Y-m-d\TH:i:s\Z");
        $where =
            "e.club_id=? AND e.starts_at " .
            ($archive ? "<" : ">=") .
            " ? AND (?='members' OR EXISTS (SELECT 1 FROM appointment_attendees t WHERE t.club_id=e.club_id AND t.appointment_id=e.id AND t.contact_id=?))";
        $parameters = [
            $actor["club_id"],
            $today,
            $actor["role"],
            $actor["contact_id"],
        ];
        if ($search !== "") {
            $where .=
                " AND LOCATE(?,CONCAT_WS(' ',e.title,e.location,e.description) COLLATE utf8mb4_unicode_ci)>0";
            $parameters[] = $search;
        }
        $statement = $db->prepare(
            "SELECT COUNT(*) FROM appointments e WHERE " . $where,
        );
        $statement->execute($parameters);
        $window = Pagination::window(
            (int) $statement->fetchColumn(),
            max(1, (int) ($page ?: 1)),
            $pageSize,
        );
        $order = $archive ? "DESC" : "ASC";
        $statement = $db->prepare(
            "SELECT e.id,e.club_id,e.title,e.starts_at,e.ends_at,e.location,e.description,e.category,e.version,e.activity_state,e.activity_kind,e.source_reference FROM appointments e WHERE " .
                $where .
                " ORDER BY e.starts_at " .
                $order .
                ",e.id " .
                $order .
                " LIMIT " . $pageSize . " OFFSET " .
                $window["offset"],
        );
        $statement->execute($parameters);
        unset($window["offset"]);
        return ["rows" => $statement->fetchAll()] + $window;
    }
}
