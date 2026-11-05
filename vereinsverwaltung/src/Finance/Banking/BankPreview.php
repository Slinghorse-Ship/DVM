<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;

/** Network-free preview. Never accepts credentials or represents bank authorisation. */
final class BankPreview
{
    public const BANKS = [
        'vr' => ['name' => 'Volksbank Jade-Weser', 'app' => 'VR SecureGo plus'],
        'dkb' => ['name' => 'DKB', 'app' => 'DKB-App'],
    ];

    public static function visible(?array $state, string $actor, bool $allowed, int $now): ?array
    {
        if (!$allowed || !$state || ($state['actor'] ?? '') !== $actor) return null;
        if ($now >= $state['expires']) {
            $state['status'] = 'expired';
            $state['rows'] = [];
        }
        return $state;
    }

    public static function apply(?array $state, string $actor, bool $allowed, string $action, array $input, int $now): ?array
    {
        if (getenv('VEREIN_MODE') !== 'synthetic' || !$allowed || $actor === '') {
            throw new DomainException('Finanzberechtigung in der lokalen Testumgebung erforderlich.');
        }
        if (array_diff(array_keys($input), ['bank', 'id', 'version'])) {
            throw new DomainException('Hier werden ausschließlich Testdaten verwendet.');
        }
        $state = self::visible($state, $actor, $allowed, $now);
        if ($action === 'start') {
            if ($state && $state['status'] === 'waiting') throw new DomainException('Ein Testabruf wartet bereits. Bitte zuerst beenden.');
            $bank = $input['bank'] ?? '';
            if (!is_string($bank) || !isset(self::BANKS[$bank])) throw new DomainException('Bitte eine unterstützte Bank auswählen.');
            return ['id' => bin2hex(random_bytes(16)), 'version' => 1, 'actor' => $actor,
                'bank' => $bank, 'status' => 'waiting', 'expires' => $now + 300, 'rows' => []];
        }
        if (!$state || !is_string($input['id'] ?? null) || !hash_equals($state['id'], $input['id'])
            || (string)$state['version'] !== ($input['version'] ?? '')) {
            throw new DomainException('Der Testvorgang wurde geändert. Bitte die Seite neu laden.');
        }
        if ($action === 'clear') return null;
        if ($state['status'] !== 'waiting') throw new DomainException('Dieser Testvorgang ist bereits beendet oder abgelaufen.');
        if (!in_array($action, ['approve', 'reject', 'cancel'], true)) throw new DomainException('Unbekannte Testaktion.');
        $state['version']++;
        $state['status'] = ['approve' => 'complete', 'reject' => 'rejected', 'cancel' => 'cancelled'][$action];
        if ($action === 'approve') {
            $state['rows'] = [
                ['date' => date('Y-m-d', $now), 'name' => 'Testmitglied A', 'purpose' => 'Beitrag · Beispiel', 'cents' => 2500],
                ['date' => date('Y-m-d', $now - 86400), 'name' => 'Testlieferant B', 'purpose' => 'Veranstaltungsmaterial · Beispiel', 'cents' => -1490],
            ];
        }
        return $state;
    }
}
