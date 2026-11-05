<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Donations\Cover;

use DateTimeImmutable;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{Permissions, SettingsSchema};
use Kulturhus\Verein\Settings\PaperAssets;

/** Finance may consume approved letter settings without managing those settings. */
final class CoverContext
{
    public static function read(PDO $db, string $actorId, string $receiptId, string $day): array
    {
        if (!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $day)) throw new DomainException('Ganzes Briefdatum prüfen.', 422);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        if (!$date || $date->format('Y-m-d') !== $day) throw new DomainException('Ganzes Briefdatum prüfen.', 422);
        $own = !$db->inTransaction();
        try {
            if ($own) $db->exec('START TRANSACTION');
            $source = CoverSource::read($db, $actorId, $receiptId);
            $actor = Permissions::finance($db, $actorId);
            $profileKey = 'account:'.$actorId;
            $rows = Db::run($db, 'SELECT section,payload,version FROM club_settings WHERE club_id=? AND section IN (?,?,?,?,?) ORDER BY section FOR SHARE NOWAIT', [$actor['club_id'], 'club', 'bank', 'paper', 'texts_donations', $profileKey])->fetchAll();
            $states = array_column($rows, null, 'section');
            $read = static function (string $section, ?string $key = null) use ($states): array {
                $row = $states[$key ?? $section] ?? null;
                $data = $row ? json_decode($row['payload'], true, 64, JSON_THROW_ON_ERROR) : SettingsSchema::defaults($section);
                if (!is_array($data)) throw new DomainException('Gespeicherte Briefquelle bitte prüfen lassen.', 503);
                return ['data'=>array_replace(SettingsSchema::defaults($section), $data), 'version'=>(int)($row['version'] ?? 0), 'payload_sha256'=>$row ? hash('sha256', $row['payload']) : null];
            };
            $club = $read('club');
            $bank = $read('bank');
            $profile = $read('account', $profileKey);
            $templates = $read('texts_donations');
            $canonical = SettingsSchema::normalize('texts_donations', $templates['data']);
            $template = $canonical[$source['template']];
            $paperState = $read('paper');
            $paper = $paperState['data'];
            if (!isset($states['paper'])) {
                $paper['header_text'] = $club['data']['name'];
                $paper['sender_name'] = $club['data']['name'];
            } else {
                $storedPaper = json_decode($states['paper']['payload'], true, 64, JSON_THROW_ON_ERROR);
                if (!array_key_exists('sender_source', $storedPaper)) {
                    $customAddress = false;
                    foreach (['sender_street', 'sender_address2', 'sender_city'] as $field) {
                        if (trim((string)($storedPaper[$field] ?? '')) !== '') $customAddress = true;
                    }
                    $name = trim((string)($storedPaper['sender_name'] ?? ''));
                    $customName = $name !== '' && !in_array($name, [SettingsSchema::defaults('paper')['sender_name'], 'Kulturhus Jaderberg e. V.'], true);
                    $paper['sender_source'] = $customAddress || $customName ? 'custom' : 'club';
                }
            }
            $paper = SettingsSchema::normalize('paper', $paper);
            if ($paper['sender_source'] === 'club') {
                $paper = array_replace($paper, ['sender_name'=>$club['data']['name'], 'sender_street'=>$club['data']['street'], 'sender_address2'=>$club['data']['address2'], 'sender_city'=>trim($club['data']['postcode'].' '.$club['data']['city'])]);
            }
            // A cover does not sign the underlying tax certificate or load private signatures.
            $paper['signature'] = '';
            $assets = [];
            $assetSources = [];
            if ($paper['logo'] !== '') {
                $logo = PaperAssets::load($db, $actor['club_id'], $paper['logo']);
                $assets['logo'] = ['mime'=>$logo['mime'], 'base64'=>base64_encode($logo['bytes'])];
                $assetSources['logo'] = ['id'=>$paper['logo'], 'mime'=>$logo['mime'], 'sha256'=>$logo['sha256'], 'bytes'=>strlen($logo['bytes'])];
            }
            $donorLines = preg_split('/\r\n|\r|\n/', $source['donor']);
            $values = [
                'VEREINSNAME'=>(string)$club['data']['name'],
                'BRIEFANREDE'=>'Guten Tag '.trim($donorLines[0]).',',
                'ZAHLBETRAG'=>number_format($source['amount_cents'] / 100, 2, ',', '.').' €',
                'VEREINS_IBAN'=>(string)$bank['data']['iban'],
                'VEREINS_BIC'=>(string)$bank['data']['bic'],
                'KONTOINHABER'=>(string)$bank['data']['holder'],
                'GLAEUBIGER_ID'=>(string)$bank['data']['creditor'],
                'PAYPAL_LINK'=>(string)$bank['data']['paypal'],
                'BEARBEITER'=>(string)$profile['data']['display_name'],
                'DATUM'=>$date->format('d.m.Y'),
                'BEZEICHNUNG'=>$source['template'] === 'collective' ? 'Sammelzuwendungsbescheinigung '.$receiptId : 'Zuwendungsbescheinigung '.$receiptId,
            ];
            $settingSources = [];
            foreach (['club'=>$club, 'bank'=>$bank, 'paper'=>$paperState, 'texts_donations'=>$templates, 'profile'=>$profile] as $name=>$state) {
                $settingSources[$name] = array_intersect_key($state, array_flip(['version', 'payload_sha256']));
            }
            $context = ['certificate'=>$source, 'template'=>$template, 'values'=>$values, 'paper'=>$paper, 'assets'=>$assets, 'settings'=>$settingSources, 'asset_sources'=>$assetSources, 'actor_id'=>$actorId, 'club_id'=>$actor['club_id'], 'day'=>$day];
            if ($own) $db->commit();
            return $context;
        } catch (\Throwable $failure) {
            if ($own && $db->inTransaction()) $db->rollBack();
            if (($failure instanceof \PDOException && in_array((int)($failure->errorInfo[1] ?? 0), [1205, 3572], true))
                || ($failure instanceof DomainException && $failure->getMessage() === 'Ein Speichervorgang läuft bereits. Bitte erneut versuchen.')) {
                throw new DomainException('Briefvorlage oder Bescheinigung wird gerade bearbeitet. Erneut prüfen.', 503, $failure);
            }
            throw $failure;
        }
    }
}
