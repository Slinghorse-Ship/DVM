<?php
declare(strict_types=1);

namespace Kulturhus\Native\Integration;

use DomainException;
use Kulturhus\Native\Auth\{Runtime, Service};
use Kulturhus\Verein\{ContactArchive, MemberBankStepUp};
use PDO;

/** Native account proof for the existing bank vault, with its original grant contract. */
final class MemberBankAuthentication
{
    public static function identity(
        PDO $db,
        string $actor,
        Service $auth,
        #[\SensitiveParameter] string $token,
    ): void {
        if (
            ManagerBridge::fromNativeSession(
                $db,
                $auth,
                $token,
            )->managerActor() !== $actor
        ) {
            throw new DomainException(
                "Anmeldung und Vereinszugang stimmen nicht überein.",
            );
        }
    }

    public static function create(PDO $db, string $actor): MemberBankStepUp
    {
        if (session_status() !== PHP_SESSION_ACTIVE || session_id() === "") {
            throw new DomainException(
                "Aktive Vereinsmanager-Sitzung erforderlich.",
            );
        }
        $token = $_SESSION["auth_token"] ?? "";
        if (!is_string($token)) {
            throw new DomainException("Aktive Kontoanmeldung erforderlich.");
        }
        $auth = Runtime::service($db);
        $security = static function () use ($db, $actor, $auth, $token): array {
            self::identity($db, $actor, $auth, $token);
            return $auth->reauthenticationState($token);
        };
        $security();
        return new MemberBankStepUp(
            $db,
            $actor,
            $_SESSION,
            hash("sha256", session_id()),
            static fn(string $password): bool => $auth->reauthenticatePassword(
                $token,
                \Kulturhus\Native\Demo\Runtime::confirmationPassword($password,$actor),
            ),
            $security,
            static fn(string $email, string $code): bool => false,
            static function (string $phase): void {},
            hash_hkdf(
                "sha256",
                ContactArchive::serverKey(),
                32,
                "member-bank-stepup-v1",
            ),
            null,
            static fn(
                string $code,
                string $stamp,
            ): bool => $auth->reauthenticateFactor($token, $code, $stamp),
        );
    }
}
