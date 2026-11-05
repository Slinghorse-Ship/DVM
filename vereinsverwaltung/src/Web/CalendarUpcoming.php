<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Kulturhus\Verein\CalendarFeed;

/** A bounded, read-only club calendar list independent of the visible day/week/month grid. */
final class CalendarUpcoming
{
    public const MONTHS = 6;
    public const PAGE_SIZE = 25;
    public const MAX_MONTH_OFFSET = 120;

    public static function window(DateTimeImmutable $day, string $mode, array $query): array
    {
        $offset = self::number($query['upcoming_months'] ?? '0', 0, self::MAX_MONTH_OFFSET);
        if ($offset % self::MONTHS !== 0) throw new DomainException('Den Zeitraum der Terminliste bitte erneut auswählen.', 422);
        $page = self::number($query['upcoming_page'] ?? '1', 1, 1000000);
        $term = $query['q'] ?? '';
        if (!is_string($term) || strlen($term) > 600 || !mb_check_encoding($term, 'UTF-8') || mb_strlen($term, 'UTF-8') > 150 || preg_match('/[\x00-\x1f\x7f]/', $term)) throw new DomainException('Bitte einen gültigen Suchtext mit höchstens 150 Zeichen eingeben.', 422);
        $anchor = $day->modify('first day of this month')->modify('+'.$offset.' months');
        $from = $offset === 0 ? CalendarFeed::range($mode, $day)['start'] : $anchor;
        $to = $anchor->modify('+'.self::MONTHS.' months');
        return ['from'=>$from->format('Y-m-d'), 'to'=>$to->format('Y-m-d'), 'last'=>$to->modify('-1 day')->format('Y-m-d'), 'offset'=>$offset, 'page'=>$page, 'term'=>$term];
    }

    public static function read(CalendarFeed $feed, DateTimeImmutable $day, string $mode, string $filter, array $query): array
    {
        $window = self::window($day, $mode, $query);
        return self::project($feed->between($window['from'], $window['to'], $filter, clubOnly:true), $window);
    }

    /** Also used by the plain renderer; source records are never changed or copied into storage. */
    public static function project(array $rows, array $window): array
    {
        $rows = array_values(array_filter($rows, static function(array $row) use ($window): bool {
            if (!in_array($row['source_kind'] ?? 'internal', ['internal','board','events','external'], true)) return false;
            $start = self::localDay($row['starts_at']);
            $end = self::localDay($row['ends_at']);
            return $start < $window['to'] && $end >= $window['from'] && ($window['term'] === '' || mb_stripos($row['title'].' '.$row['location'], $window['term'], 0, 'UTF-8') !== false);
        }));
        usort($rows, static fn(array $a,array $b):int=>[$a['starts_at'],$a['id']]<=>[$b['starts_at'],$b['id']]);
        $pages = max(1, (int)ceil(count($rows) / self::PAGE_SIZE));
        if ($window['page'] > $pages) throw new DomainException('Diese Seite der Terminliste ist nicht mehr verfügbar. Bitte die erste Seite öffnen.', 422);
        $groups = [];
        foreach (array_slice($rows, ($window['page']-1)*self::PAGE_SIZE, self::PAGE_SIZE) as $row) {
            $month = substr(self::localDay($row['starts_at']),0,7);
            $groups[$month][] = $row;
        }
        return $window + ['total'=>count($rows), 'pages'=>$pages, 'groups'=>$groups];
    }

    private static function localDay(string $utc): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d');
    }

    private static function number(mixed $value, int $min, int $max): int
    {
        if (!is_string($value) || !preg_match('/\A(?:0|[1-9][0-9]{0,6})\z/D', $value) || (int)$value < $min || (int)$value > $max) throw new DomainException('Seite oder Zeitraum der Terminliste bitte erneut auswählen.', 422);
        return (int)$value;
    }
}
