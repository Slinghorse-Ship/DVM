<?php declare(strict_types=1);
namespace Kulturhus\Banking;

use DomainException;

/** Shared presentation and server-side checks; never trusts browser bank results. */
final class PaymentRules
{
    public static function transferMode(string $mode): string
    {
        if (!in_array($mode, ['standard', 'instant'], true)) {
            throw new DomainException('Normale Überweisung oder Echtzeitüberweisung auswählen.');
        }
        return $mode;
    }

    public static function payee(array $state): array
    {
        $result = (string)($state['vop_result'] ?? '');
        $label = match ($result) {
            'CompletedFullMatch' => 'Name und IBAN stimmen überein.',
            'CompletedCloseMatch' => 'Der Name weicht leicht ab.',
            'CompletedNoMatch' => 'Name und IBAN stimmen nicht überein.',
            'CompletedPartialMatch' => 'Nicht alle Empfänger stimmen überein.',
            default => 'Die Bank konnte den Empfänger nicht bestätigen.',
        };
        $expiry = (string)($state['vop_expires'] ?? '');
        $timestamp = $expiry !== '' ? strtotime($expiry) : null;
        return [
            'label' => $label,
            'warning' => $result !== 'CompletedFullMatch',
            'expired' => $timestamp !== null && ($timestamp === false || $timestamp <= time()),
        ];
    }

    public static function confirmPayee(array $state, array $input): void
    {
        $result = self::payee($state);
        if ($result['expired']) {
            throw new DomainException('Die Empfängerprüfung ist abgelaufen. Bankdialog beenden und neu starten.');
        }
        if (($input['confirm_vop'] ?? '') !== '1') {
            throw new DomainException('Empfängerprüfung zuerst bestätigen.');
        }
        if ($result['warning'] && ($input['acknowledge_vop_warning'] ?? '') !== '1') {
            throw new DomainException('Empfänger und IBAN prüfen und die Warnung ausdrücklich bestätigen – oder abbrechen.');
        }
    }
}
