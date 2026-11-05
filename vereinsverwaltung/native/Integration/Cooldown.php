<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration;

/** Provider-requested cooldown takes precedence over any local checking cadence. */
final class Cooldown extends \DomainException
{
    public function __construct(public readonly int $seconds)
    {
        parent::__construct(
            "Anbieter verlangt eine Abrufpause. Nächsten Abruf später ausführen.",
        );
    }

    public static function forResponse(array $response): ?self
    {
        if (!in_array($response["status"] ?? 0, [429, 503], true)) {
            return null;
        }
        $header = $response["headers"]["retry-after"] ?? "";
        $seconds = ctype_digit((string) $header)
            ? (int) $header
            : (strtotime((string) $header) ?: 0) - time();
        return new self(
            max(60, min(PHP_INT_MAX - time(), $seconds > 0 ? $seconds : 900)),
        );
    }
}
