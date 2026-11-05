<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration\StadtLandFunk;

/** The current app uses PagedModel; retain the previously observed Spring Page. */
final class OwnPostsPage
{
    public static function read(array $data, int $number): array
    {
        $rows = $data['content'] ?? null;
        if ($number < 0 || $number > 3 || !is_array($rows) || !array_is_list($rows) || count($rows) > 50) {
            throw new \DomainException('Eigene Veranstaltungsliste nicht vollständig bestätigt.');
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \DomainException('Eigener Veranstaltungseintrag nicht bestätigt.');
            }
        }
        $nested = array_key_exists('page', $data);
        $meta = $nested ? $data['page'] : $data;
        if (!is_array($meta) || !is_int($meta['totalPages'] ?? null) || $meta['totalPages'] < 0 || $meta['totalPages'] > 4) {
            throw new \DomainException('Eigene Veranstaltungsliste unvollständig oder größer als 200 Termine.');
        }
        $pages = $meta['totalPages'];
        if (($pages === 0 && ($number !== 0 || $rows !== [])) || ($pages > 0 && $number >= $pages)) {
            throw new \DomainException('Eigene Veranstaltungsseite nicht bestätigt.');
        }
        $total = null;
        if ($nested) {
            if (!is_int($meta['number'] ?? null) || $meta['number'] !== $number || ($meta['size'] ?? null) !== 50 || !is_int($meta['totalElements'] ?? null) || $meta['totalElements'] < 0 || $meta['totalElements'] > 200) {
                throw new \DomainException('Eigene Veranstaltungsseitenangaben nicht bestätigt.');
            }
            $total = $meta['totalElements'];
            if ($pages !== intdiv($total + 49, 50) || count($rows) !== min(50, max(0, $total - $number * 50))) {
                throw new \DomainException('Eigene Veranstaltungsliste nicht vollständig bestätigt.');
            }
            foreach (['number', 'size', 'totalElements', 'totalPages'] as $key) {
                if (array_key_exists($key, $data) && $data[$key] !== $meta[$key]) {
                    throw new \DomainException('Eigene Veranstaltungsseitenangaben widersprechen sich.');
                }
            }
        }
        return ['content' => $rows, 'total_pages' => $pages, 'total_elements' => $total, 'format' => $nested ? 'paged-model' : 'spring-page'];
    }

    public static function sequence(array $page): array
    {
        return [$page['total_pages'], $page['total_elements'], $page['format']];
    }
}
