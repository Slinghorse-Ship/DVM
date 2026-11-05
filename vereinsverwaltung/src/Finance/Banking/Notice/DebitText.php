<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Banking\Notice;

use DateTimeImmutable;
use DomainException;
use Kulturhus\Verein\SettingsSchema;

final class DebitText
{
    private static function resolve(string $text, array $values): string
    {
        preg_match_all('/\{\{([A-Z_]+)\}\}/', $text, $matches);
        $missing = array_values(array_unique(array_filter($matches[1], static fn(string $key): bool => !array_key_exists($key, $values) || trim((string)$values[$key]) === '')));
        if ($missing) {
            throw new DomainException('Für diese Originalrechnung fehlen nachweisbare Vorlagenwerte: '.implode(', ', array_map(static fn(string $key): string => SettingsSchema::VARIABLES[$key] ?? $key, $missing)).'. Ganze Brieftexte gezielt berichtigen.', 422);
        }
        return str_replace(["\r\n", "\r"], "\n", SettingsSchema::interpolate($text, $values));
    }

    public static function document(array $context, array $raw): array
    {
        $input = DebitInput::normalize($raw);
        if ($input['entry'] !== $context['claim']['entry']['id'] || $input['day'] !== $context['day'] || $input['collection'] !== $context['collection']) {
            throw new DomainException('Genau diese ursprüngliche Originalrechnung und dieses Briefdatum erneut prüfen.', 409);
        }
        $text = [];
        foreach (DebitInput::TEXT_FIELDS as $field) $text[$field] = self::resolve($input['text'][$field], $context['values']);
        if (trim($text['subject']) === '' || mb_strlen($text['subject']) > 300 || preg_match('/[\r\n]/', $text['subject'])) {
            throw new DomainException('Aufgelösten ganzen Briefbetreff einzeilig mit höchstens 300 Zeichen prüfen.', 422);
        }
        $paper = $context['paper'];
        foreach (['footer_left', 'footer_middle', 'footer_right'] as $field) $paper[$field] = self::resolve($paper[$field], $context['values']);
        $parts = array_values(array_filter([$text['intro'], $text['message'], $text['closing']], static fn(string $part): bool => $part !== ''));
        $parts[] = 'Rechnungsnummer: '.$context['values']['RECHNUNGSNUMMER']."\nEinzugsbetrag: ".$context['values']['ZAHLBETRAG']."\nEinzugsdatum: ".$context['values']['LASTSCHRIFT_DATUM']."\nMandatsreferenz: ".$context['values']['MANDATSREFERENZ']."\nGläubiger-ID: ".$context['values']['GLAEUBIGER_ID'];
        return ['kind'=>'letter', 'recipient'=>$context['claim']['recipient'],
            'date'=>(new DateTimeImmutable($input['day']))->format('d.m.Y'), 'title'=>$text['subject'],
            'text'=>['message'=>implode("\n\n", $parts)], 'values'=>$context['values'],
            'paper'=>$paper, 'assets'=>$context['assets']];
    }
}
