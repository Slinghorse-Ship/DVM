<?php declare(strict_types=1);
namespace Kulturhus\Verein\DocumentWorkflow;

/** Pinned field types give dates meaning; narrative text is never interpreted. */
final class WorkflowDeadlines
{
    public static function validDate(mixed $value): bool
    {
        if (!is_string($value) || !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $parts)) return false;
        return checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]);
    }

    public static function dates(array $document, string $from, string $to): array
    {
        $dates = [];
        foreach ($document['fields'] as $field) {
            if ($field['type'] === 'risk_register') {
                foreach (($document['values'][$field['key']] ?? []) as $index => $row) {
                    if (!is_array($row) || self::validDate($row['action_completed_on'] ?? null)) continue;
                    $value = $row['action_deadline'] ?? '';
                    if (!self::validDate($value) || $value < $from || $value >= $to) continue;
                    $dates[$value][] = 'Maßnahmenfrist · ' . $field['label'] . ' · Zeile ' . ($index + 1);
                }
                continue;
            }
            if ($field['type'] !== 'date' || !in_array($field['key'], ['review_date', 'deadline'], true)) continue;
            $value = $document['values'][$field['key']] ?? '';
            if (!self::validDate($value) || $value < $from || $value >= $to) continue;
            $dates[$value][] = $field['label'];
        }
        ksort($dates);
        return $dates;
    }
}
