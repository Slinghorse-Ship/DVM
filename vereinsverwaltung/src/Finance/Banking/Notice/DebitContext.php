<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Banking\Notice;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{FinancePaymentAuthority, Iban, MemberBankVault, SettingsSchema};

final class DebitContext
{
    public static function read(PDO $db, string $actorId, string $entry, string $day, string $collection, MemberBankVault $vault): array
    {
        foreach ([$day, $collection] as $value) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $value) || !$date || $date->format('Y-m-d') !== $value) throw new DomainException('Ganzes Briefdatum und Einzugsdatum prüfen.', 422);
        }
        $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin'));
        if ($day > $today->format('Y-m-d') || $collection < $today->modify('+14 days')->format('Y-m-d')) {
            throw new DomainException('Briefdatum höchstens heute und Einzugsdatum mindestens 14 Tage nach heute wählen. Tatsächliche rechtzeitige Vorabinformation bleibt erforderlich.', 422);
        }
        $claim = DebitSource::read($db, $actorId, $entry, $vault);
        if ($claim['entry']['due_date'] !== '' && $collection < $claim['entry']['due_date']) throw new DomainException('Einzug nicht vor der ursprünglichen Rechnungsfälligkeit ankündigen.', 422);
        $club = $claim['invoice']['club_id'];
        $group = $claim['template'];
        $rows = Db::run($db, 'SELECT section,payload,version FROM club_settings WHERE club_id=? AND section IN (?,?,?) ORDER BY section FOR SHARE NOWAIT', [$club, $group, 'bank', 'account:'.$actorId])->fetchAll();
        $states = array_column($rows, null, 'section');
        $read = static function (string $section, ?string $key = null) use ($states): array {
            $row = $states[$key ?? $section] ?? null;
            $data = $row ? json_decode($row['payload'], true, 64, JSON_THROW_ON_ERROR) : [];
            return array_replace(SettingsSchema::defaults($section), $data);
        };
        $templates = SettingsSchema::normalize($group, $read($group));
        $bank = $read('bank');
        if (($bank['account_id'] ?? '') !== $claim['account']['id'] || Iban::normalize($bank['iban']) !== Iban::normalize($claim['original_iban'])
            || ($bank['creditor'] ?? '') !== $claim['mandate']['creditor_id']) {
            throw new DomainException('Aktuelle Gläubigerangaben, Mandat und ursprüngliches Rechnungskonto stimmen nicht überein. Keine Ersatzbank verwenden.', 409);
        }
        $profile = $read('account', 'account:'.$actorId);
        $snapshot = $claim['snapshot'];
        $originalValues = $snapshot['values'];
        $format = static fn(string $value): string => (new DateTimeImmutable($value))->format('d.m.Y');
        $values = ['VEREINSNAME'=>$claim['mandate']['creditor_name'], 'BRIEFANREDE'=>'Guten Tag '.preg_split('/\r\n|\r|\n/', $claim['recipient'])[0].',',
            'ZAHLBETRAG'=>number_format($claim['amount_cents'] / 100, 2, ',', '.').' €', 'DATUM'=>$format($day),
            'RECHNUNGSNUMMER'=>$claim['entry']['display_number'], 'VERWENDUNGSZWECK'=>$claim['entry']['display_number'],
            'RECHNUNGSDATUM'=>$format($claim['entry']['document_date']), 'FAELLIGKEIT'=>$format($claim['entry']['due_date']),
            'BEZEICHNUNG'=>$claim['entry']['description'], 'ZAHLUNG_FUER'=>$claim['entry']['description'],
            'VEREINS_IBAN'=>$claim['original_iban'], 'VEREINS_BIC'=>$bank['bic'], 'KONTOINHABER'=>$bank['holder'],
            'GLAEUBIGER_ID'=>$claim['mandate']['creditor_id'], 'MANDATSREFERENZ'=>$claim['mandate']['reference'],
            'LASTSCHRIFT_DATUM'=>$format($collection), 'ZAHLUNGSART'=>'SEPA-Basislastschrift', 'BEARBEITER'=>$profile['display_name']];
        foreach (['MITGLIEDSNUMMER', 'ABRECHNUNGSZEITRAUM', 'LIEFERDATUM'] as $key) if (isset($originalValues[$key]) && $originalValues[$key] !== '') $values[$key] = $originalValues[$key];
        $paper = $snapshot['paper'];
        $paper['signature'] = '';
        $assets = array_intersect_key($snapshot['assets'] ?? [], ['logo'=>true]);
        $settings = [];
        foreach (['template'=>$group, 'bank'=>'bank', 'profile'=>'account:'.$actorId] as $label=>$section) {
            $row = $states[$section] ?? null;
            $settings[$label] = ['version'=>(int)($row['version'] ?? 0), 'payload_sha256'=>$row ? hash('sha256', $row['payload']) : null];
        }
        return ['claim'=>$claim, 'authorization'=>FinancePaymentAuthority::require($db, $actorId), 'template'=>$templates['debit'], 'values'=>$values, 'paper'=>$paper, 'assets'=>$assets,
            'settings'=>$settings, 'day'=>$day, 'collection'=>$collection, 'actor_id'=>$actorId, 'club_id'=>$club];
    }
}
