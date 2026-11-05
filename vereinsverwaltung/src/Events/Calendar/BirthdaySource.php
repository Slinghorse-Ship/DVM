<?php declare(strict_types=1);
namespace Kulturhus\Verein\Events\Calendar;

use Kulturhus\Verein\Registry;

/** Existing profile occasions, with the same current profile permissions and leap-day rules. */
final class BirthdaySource
{
    public function __construct(private Registry $registry) {}

    public function available(): bool
    {
        return $this->registry->canReadProfiles();
    }

    public function rows(string $from, string $to): array
    {
        if (!$this->available()) {
            return [];
        }
        $rows = [];
        // Split by year so a permitted one-year range may include two annual occasions.
        $cursor = $from;
        while ($cursor < $to) {
            $next = (int) substr($cursor, 0, 4) + 1 . "-01-01";
            $end = min($next, $to);
            $days = (int) (new \DateTimeImmutable($cursor))->diff(
                new \DateTimeImmutable($end),
            )->days;
            foreach (
                $this->registry->birthdayAgenda($cursor, $days)
                as $profile
            ) {
                if ($profile["occasion_on"] >= $end) {
                    continue;
                }
                $rows[] = Entry::dated(
                    "birthday:" .
                        $profile["id"] .
                        ":" .
                        $profile["occasion_on"],
                    "Geburtstag: " . $profile["name"],
                    $profile["occasion_on"],
                    "birthdays",
                    ["view" => "members", "id" => $profile["id"]],
                    true,
                );
            }
            $cursor = $end;
        }
        return $rows;
    }
}
