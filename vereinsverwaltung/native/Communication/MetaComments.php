<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

/** Complete root/reply reads; provider identity distinguishes our answers from incoming questions. */
final class MetaComments
{
    public static function read(
        array $object,
        array $account,
        callable $pages,
        callable $call,
        callable $time,
        array $ownReplies = [],
    ): array {
        $instagram = $object["channel"] === "instagram";
        $username = "";
        if ($instagram) {
            $identity = $call(
                $account,
                "GET",
                $account["instagram_id"] . "?fields=id,username",
            );
            if (($identity["id"] ?? null) !== $account["instagram_id"]) {
                throw new \DomainException(
                    "Instagram-Kommentaridentität nicht bestätigt.",
                );
            }
            $username = strtolower(
                Content::text($identity["username"] ?? "", 200, true),
            );
        }
        $fields = $instagram
            ? "id,text,timestamp,username,hidden"
            : "id,message,created_time,from,is_hidden";
        $parameters =
            ["fields" => $fields] +
            ($instagram ? [] : ["filter" => "toplevel"]);
        $roots = $pages(
            $account,
            $object["remote_id"] . "/comments",
            $parameters,
        );
        $ownReplies = array_fill_keys($ownReplies, true);
        $normalized = [];
        $seen = [];
        $append = static function (array $entry, string $parent) use (
            &$normalized,
            &$seen,
            $instagram,
            $account,
            $username,
            $time,
            $ownReplies,
        ): void {
            $id = SocialHub::remoteId($entry["id"] ?? null);
            if (isset($seen[$id])) {
                throw new \DomainException(
                    "Kommentarstränge überlappen. Erneut vollständig abgleichen.",
                );
            }
            if (count($seen) >= 10000) {
                throw new \DomainException(
                    "Kommentar-Abgleich zu groß. Kein unvollständiges Ergebnis speichern.",
                );
            }
            $seen[$id] = true;
            $name = Content::text(
                $entry["username"] ?? ($entry["from"]["name"] ?? ""),
                200,
            );
            $own =
                isset($ownReplies[$id]) ||
                ($instagram
                    ? strtolower($name) === $username
                    : ($entry["from"]["id"] ?? null) === $account["page_id"]);
            $hidden=array_key_exists('hidden',$entry)?$entry['hidden']:(array_key_exists('is_hidden',$entry)?$entry['is_hidden']:false);
            if(!is_bool($hidden))throw new \DomainException('Moderationsstand des Kommentars nicht bestätigt.');
            $normalized[] = [
                "id" => $id,
                "text" => Content::text(
                    $entry["text"] ?? ($entry["message"] ?? ""),
                    10000,
                ),
                "name" => $name,
                "created_at" => $time(
                    $entry["timestamp"] ?? ($entry["created_time"] ?? null),
                ),
                "hidden" => $hidden,
                "incoming" => !$own,
                "reply_to" => $parent,
            ];
        };
        foreach ($roots as $root) {
            $append($root, "");
            $parent = SocialHub::remoteId($root["id"]);
            $children = $pages(
                $account,
                $parent . ($instagram ? "/replies" : "/comments"),
                $parameters,
            );
            foreach ($children as $child) {
                $append($child, $parent);
            }
        }
        return $normalized;
    }
}
