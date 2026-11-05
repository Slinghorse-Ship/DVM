<?php declare(strict_types=1);
namespace Kulturhus\Native\Auth;

use PDO;
use DomainException;
use Kulturhus\Native\Shared\Db;

/** Current-device preferences never select an account or renew a revoked session. */
final class Sessions
{
    public const MAX_SECONDS = 34560000; // Finite 400-day browser/session storage.
    public const MODES = [
        "standard" => 28800,
        "day" => 86400,
        "month" => 2592000,
        "logout" => self::MAX_SECONDS,
    ];
    public const LABELS = [
        "standard" => "8 Stunden (Standard)",
        "day" => "24 Stunden",
        "month" => "30 Tage",
        "logout" => "Bis ich mich abmelde",
    ];
    public static function validate(string $mode): void
    {
        if (!isset(self::MODES[$mode])) {
            throw new DomainException(
                "Bitte eine der angebotenen Anmeldedauern auswählen.",
            );
        }
    }
    public static function create(
        PDO $db,
        string $account,
        int $now,
        string $mode = "standard",
    ): array {
        self::validate($mode);
        $token = bin2hex(random_bytes(32));
        $expires = $now + self::MODES[$mode];
        Db::run(
            $db,
            "INSERT INTO native_auth_sessions(token_hash,account_id,expires_at,created_at,duration_mode,last_seen) VALUES(?,?,?,?,?,?)",
            [hash("sha256", $token), $account, $expires, $now, $mode, $now],
        );
        return [
            "status" => "authenticated",
            "session_token" => $token,
            "expires_at" => $expires,
            "duration_mode" => $mode,
        ];
    }
    public static function touch(
        PDO $db,
        string $hash,
        array $row,
        int $now,
    ): int {
        $mode = $row["session_mode"];
        self::validate($mode);
        if (!in_array($mode, ["day", "month"], true)) {
            return (int) $row["session_expires_at"];
        }
        $expires = min(
            (int) $row["session_created_at"] + self::MAX_SECONDS,
            $now + self::MODES[$mode],
        );
        if ($expires <= $now) {
            throw new DomainException(
                "Sitzung abgelaufen. Bitte erneut anmelden.",
            );
        }
        Db::run(
            $db,
            "UPDATE native_auth_sessions SET expires_at=?,last_seen=? WHERE token_hash=? AND revoked_at IS NULL AND expires_at>?",
            [$expires, $now, $hash, $now],
        );
        return $expires;
    }
}
