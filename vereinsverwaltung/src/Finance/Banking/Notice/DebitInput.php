<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Banking\Notice;

use DateTimeImmutable;
use DomainException;
use Kulturhus\Verein\SettingsSchema;

final class DebitInput
{
    public const TEXT_FIELDS = ['subject', 'message', 'intro', 'closing'];

    public static function initial(array $context): array
    {
        return self::capture(['entry'=>$context['claim']['entry']['id'], 'day'=>$context['day'], 'collection'=>$context['collection'],
            'text'=>array_intersect_key($context['template'], array_flip(self::TEXT_FIELDS)), 'reason'=>'']);
    }

    public static function capture(array $input): array
    {
        $keys = array_keys($input);
        sort($keys);
        if ($keys !== ['collection', 'day', 'entry', 'reason', 'text'] || !is_array($input['text'])) {
            throw new DomainException('Ganze ursprüngliche Angaben zur Vorankündigung prüfen.', 422);
        }
        $keys = array_keys($input['text']);
        sort($keys);
        if ($keys !== ['closing', 'intro', 'message', 'subject']) {
            throw new DomainException('Alle ursprünglichen Brieftextfelder prüfen.', 422);
        }
        foreach ([$input['entry'], $input['day'], $input['collection'], $input['reason'], ...array_values($input['text'])] as $value) {
            if (!is_string($value) || strlen($value) > 40000 || preg_match('//u', $value) !== 1
                || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
                throw new DomainException('Ganze ursprüngliche Angaben zur Vorankündigung prüfen.', 422);
            }
        }
        $text = [];
        foreach (self::TEXT_FIELDS as $field) $text[$field] = $input['text'][$field];
        return ['entry'=>$input['entry'], 'day'=>$input['day'], 'collection'=>$input['collection'], 'text'=>$text, 'reason'=>$input['reason']];
    }

    public static function normalize(array $input): array
    {
        $input = self::capture($input);
        if (!preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/D', $input['entry'])) {
            throw new DomainException('Eigene ausgegebene Originalrechnung auswählen.', 404);
        }
        foreach (['day', 'collection'] as $field) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $input[$field]);
            if (!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $input[$field]) || !$date || $date->format('Y-m-d') !== $input[$field]) {
                throw new DomainException('Ganzes Briefdatum und Einzugsdatum prüfen.', 422);
            }
        }
        if (trim($input['reason']) === '' || mb_strlen($input['reason']) > 2000) {
            throw new DomainException('Eigenen ganzen Grund mit höchstens 2.000 Zeichen angeben.', 422);
        }
        foreach (self::TEXT_FIELDS as $field) {
            $value = $input['text'][$field];
            if (mb_strlen($value) > ($field === 'subject' ? 200 : 6000)
                || (in_array($field, ['subject', 'message'], true) && trim($value) === '')
                || ($field === 'subject' && preg_match('/[\r\n]/', $value))) {
                throw new DomainException('Ganzen Betreff und Brieftext innerhalb der angezeigten Grenzen prüfen.', 422);
            }
            // Validate variables and formatting without replacing the complete human input.
            SettingsSchema::formatted($value, 40000);
        }
        return $input;
    }
}
