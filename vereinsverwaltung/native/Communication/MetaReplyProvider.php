<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use PDO;
use DomainException;
use Kulturhus\Native\Shared\{Access, Db, SecretBox};
use Kulturhus\Native\Integration\{Accounts, JsonHttp, MetaRejected};

/** Meta comment contracts, independent of durable reply state. */
final class MetaReplyProvider implements ReplyProvider
{
    private \Closure $http;
    public function __construct(
        private PDO $db,
        private SecretBox $box,
        ?callable $http = null,
    ) {
        $this->http = $http
            ? \Closure::fromCallable($http)
            : static fn($m, $p, $h, $b) => JsonHttp::request(
                "meta",
                $m,
                $p,
                $h,
                $b,
            );
    }

    public function account(
        Access $access,
        array $source,
        ?string $binding = null,
    ): array {
        $account = (new Accounts($this->db, $this->box))->get(
            $access->club(),
            "meta",
        );
        if (
            $binding !== null &&
            !hash_equals($binding, Accounts::binding($account))
        ) {
            throw new DomainException(
                "Antwort gehört zu einer früheren Meta-Verbindung.",
            );
        }
        $object = Db::run(
            $this->db,
            "SELECT payload_json FROM native_social_objects WHERE club_id=? AND channel=? AND remote_id=?" .
                ($this->db->inTransaction() ? " FOR SHARE" : ""),
            [$access->club(), $source["channel"], $source["parent_id"]],
        )->fetchColumn();
        $payload = $object
            ? json_decode($object, true, 32, JSON_THROW_ON_ERROR)
            : [];
        if (
            !is_string($payload["connection_id"] ?? null) ||
            !hash_equals(Accounts::binding($account), $payload["connection_id"])
        ) {
            throw new DomainException(
                "Originalbeitrag zuerst mit der aktuellen Meta-Verbindung abgleichen.",
            );
        }
        if (
            $source["channel"] === "instagram" &&
            $account["instagram_id"] === ""
        ) {
            throw new DomainException("Instagram-Konto fehlt.");
        }
        $account["_reply_binding"] = Accounts::binding($account);
        return $account;
    }

    private function call(
        array $account,
        string $method,
        string $path,
        ?array $body = null,
    ): array {
        $result = ($this->http)(
            $method,
            "/" . $account["api_version"] . "/" . $path,
            ["Authorization: Bearer " . $account["token"]],
            $body,
        );
        if (
            $method === "POST" &&
            ($result["status"] ?? 0) >= 400 &&
            ($result["status"] ?? 0) < 500 &&
            is_array($result["body"]["error"] ?? null)
        ) {
            throw new MetaRejected(
                "Meta hat die Antwort abgelehnt. Rechte und Verbindung prüfen.",
            );
        }
        if (
            ($result["status"] ?? 0) < 200 ||
            ($result["status"] ?? 0) >= 300 ||
            !is_array($result["body"] ?? null) ||
            isset($result["body"]["error"])
        ) {
            throw new DomainException(
                "Meta-Aktion nicht bestätigt. Rechte und Verbindung beim Anbieter prüfen.",
            );
        }
        return $result["body"];
    }

    private static function fields(string $channel): string
    {
        return $channel === "facebook"
            ? "id,message,can_comment"
            : "id,text,hidden";
    }

    public function proof(Access $access, array $account, array $row, string $remote): bool
    {
        $result = $this->call(
            $account,
            "GET",
            SocialHub::remoteId($remote) .
                "?fields=" .
                self::fields($row["channel"]),
        );
        return ($result["id"] ?? null) === $remote &&
            ($result[$row["channel"] === "facebook" ? "message" : "text"] ??
                null) ===
                $row["payload"]["text"];
    }

    public function current(Access $access, array $account, array $source, array $row): void
    {
        $remote = SocialHub::remoteId($source["payload"]["remote_id"]);
        $current = $this->call(
            $account,
            "GET",
            $remote . "?fields=" . self::fields($row["channel"]),
        );
        if (
            ($current["id"] ?? null) !== $remote ||
            ($current[$row["channel"] === "facebook" ? "message" : "text"] ??
                null) !==
                $row["payload"]["original"]["text"]
        ) {
            throw new DomainException(
                "Kommentar beim Anbieter geändert oder entfernt. Neu abgleichen; nichts versendet.",
            );
        }
        if (
            ($row["channel"] === "facebook" &&
                ($current["can_comment"] ?? null) === false) ||
            ($row["channel"] === "instagram" &&
                ($current["hidden"] ?? null) === true)
        ) {
            throw new DomainException(
                "Dieser Kommentar erlaubt derzeit keine Antwort. Original und Moderationsstand beim Anbieter prüfen.",
            );
        }
    }

    public function publish(Access $access, array $account, array $source, array $row): string
    {
        $remote = SocialHub::remoteId($source["payload"]["remote_id"]);
        $result = $this->call($account, "POST", $remote . ($row["channel"] === "facebook" ? "/comments" : "/replies"), ["message" => $row["payload"]["text"]]);
        return SocialHub::remoteId($result["id"] ?? null);
    }
}
