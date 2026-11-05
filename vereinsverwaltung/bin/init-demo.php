<?php declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Kulturhus\Verein\{Database,Documents,Finance,Inventory,Registry,ClubChat,Minutes};

$mode = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || !in_array($mode, ['--synthetic', '--demo'], true)) {
    fwrite(STDERR, "Nur lokal: php bin/init-demo.php --synthetic|--demo\n");
    exit(1);
}
$isDemo = $mode === '--demo';
$dir = dirname(__DIR__, 2) . '/.local/vereinsverwaltung';
if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Privater Demoordner konnte nicht angelegt werden.');
$dsn = trim((string)getenv('VEREIN_DATABASE_DSN'));
if(getenv('VEREIN_MODE')!=='synthetic'||!str_starts_with(strtolower($dsn),'mysql:'))throw new RuntimeException('Explizite synthetische MySQL-Datenbank erforderlich.');
$path=$dsn;
$databaseHasDemoData = false;
if ($dsn !== '') {
    $existing = Database::connect($dsn);
    $databaseHasDemoData = Database::tableExists($existing, 'clubs') && (int)$existing->query('SELECT COUNT(*) FROM clubs')->fetchColumn() > 0;
}
if ($databaseHasDemoData) {
    fwrite(STDERR, "Datenbank existiert bereits; keine Daten überschrieben.\n");
    exit(0);
}

$db = Database::connect($path);
Database::migrate($db);
$club = 'kulturhus-demo';
$db->exec("INSERT INTO clubs VALUES ('kulturhus-demo','Kulturhus · Übungsverein'),('other-demo','Zweiter Testverein')");
Database::ensureContributionRates($db);
$db->exec("INSERT INTO actors (id,club_id,role) VALUES ('demo-manager','kulturhus-demo','members'),('demo-editor','kulturhus-demo','editor'),('demo-finance','kulturhus-demo','treasurer'),('other-manager','other-demo','members')");
$db->exec("UPDATE actors SET is_superuser=1 WHERE id='demo-manager'");
$db->exec("INSERT INTO admission_board (club_id,actor_id,office,enabled,version) VALUES ('kulturhus-demo','demo-finance','Kassenwart',1,1)");
$db->exec("INSERT INTO admission_board_checks (club_id,actor_id,board_version,office,checked_by,checked_at) VALUES ('kulturhus-demo','demo-finance',1,'Kassenwart','demo-manager',UTC_TIMESTAMP())");
$key = static fn(): string => bin2hex(random_bytes(16));

// The small --synthetic fixture is retained for the automated test harness.
$registry = new Registry($db, 'demo-manager');
$member = $registry->saveContact(null, 0, 'person', 'Mara Muster · Testdaten', 'mara@example.invalid', "Musterweg 1\n00000 Musterort", ['phone' => '0000 123456', 'birthday' => '1980-04-12']);
$registry->addMembership($member, 'DEMO-001', 'aktiv', '2026-01-01');
$supplier = $registry->saveContact(null, 0, 'organisation', 'Kulturfreunde Muster · Testdaten', 'kontakt@example.invalid', "Beispielallee 2\n00000 Musterort", ['phone' => '0000 654321']);
$db->prepare("INSERT INTO actors (id,club_id,role,contact_id) VALUES ('demo-member',?,?,?)")->execute([$club, 'member', $member]);
$other = new Registry($db, 'other-manager');
$other->saveContact(null, 0, 'person', 'Fremder Verein · Testdaten', '', '');

if (!$isDemo) {
    echo "Synthetische Datenbank angelegt. Keine echte Anmeldung, kein Versand, kein Banking.\n";
    exit(0);
}

// More people make the role, recipient and member workflows useful in training.
$second = $registry->saveContact(null, 0, 'person', 'Nina Beispiel · Testdaten', 'nina@example.invalid', "Beispielweg 3\n00000 Musterort", ['birthday' => '1975-11-08']);
$registry->addMembership($second, 'DEMO-002', 'aktiv', '2024-05-01');
$passive = $registry->saveContact(null, 0, 'person', 'Otto Übung · Testdaten', '', "Übungsstraße 4\n00000 Musterort");
$registry->addMembership($passive, 'DEMO-003', 'passiv', '2019-01-01');
$db->prepare("INSERT INTO actors (id,club_id,role,contact_id) VALUES ('demo-member-2',?,?,?)")->execute([$club, 'member', $second]);

$finance = new Finance($db, 'demo-manager');
$bank = $finance->createAccount('Vereinskonto · Demodaten', 'offline', '2500,00', '2026-01-01');
$cash = $finance->createAccount('Barkasse · Demodaten', 'cash', '180,00', '2026-01-01');
$demoIban = 'DE02120300000000202051';
$demoBic = 'GENODEF1VAR';
$bankPayload = json_encode(['account_id' => $bank, 'holder' => 'Kulturhus · Übungsverein', 'creditor' => 'DEMO-CREDITOR-001', 'iban' => $demoIban, 'bic' => $demoBic, 'paypal' => ''], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
$db->prepare('INSERT INTO club_settings VALUES(?,?,?,?,?)')->execute([$club, 'bank', $bankPayload, 1, gmdate('Y-m-d\TH:i:s\Z')]);
$db->prepare('INSERT INTO settings_revisions VALUES(?,?,?,?,?,?)')->execute([$club, 'bank', 1, $bankPayload, 'demo-manager', gmdate('Y-m-d\TH:i:s\Z')]);
$db->prepare('INSERT INTO finance_account_settings(club_id,account_id,active,tax_account,version,fints_read,fints_write) VALUES(?,?,1,\'\',1,1,1) ON DUPLICATE KEY UPDATE fints_read=1,fints_write=1')->execute([$club, $bank]);
$entries = [];
$makeEntry = static function (Finance $f, array $data, ?string $account, string $date) use (&$entries, $key): string {
    $id = $f->createEntry($data, $key());
    if ($account !== null) $f->recordPayment($id, 1, $account, $date);
    $entries[] = $id;
    return $id;
};
$contribution = $makeEntry($finance, ['kind' => 'contribution', 'description' => 'Jahresbeitrag 2026 · Testdaten', 'counterparty' => 'Mara Muster · Testdaten', 'amount' => '60,00', 'document_date' => '2026-02-01', 'due_date' => '2026-02-15', 'category' => 'membership'], $bank, '2026-02-03');
$expense = $makeEntry($finance, ['kind' => 'expense', 'description' => 'Bühnenpodest · Testdaten', 'counterparty' => 'Kulturtechnik Muster GmbH · Testdaten', 'amount' => '299,00', 'document_date' => '2026-03-10', 'due_date' => '2026-03-24', 'category' => 'inventory'], $bank, '2026-03-12');
$donation = $makeEntry($finance, ['kind' => 'donation', 'description' => 'Förderung Kulturprogramm · Testdaten', 'counterparty' => 'Synthetische Förderperson', 'amount' => '350,00', 'document_date' => '2026-04-05', 'due_date' => '2026-04-05', 'category' => 'donations'], $bank, '2026-04-05');
$makeEntry($finance, ['kind' => 'expense', 'description' => 'Büromaterial · Testdaten', 'counterparty' => 'Musterbüro', 'amount' => '38,90', 'document_date' => '2026-05-15', 'due_date' => '2026-05-29', 'category' => 'office'], $cash, '2026-05-17');
$open = $finance->createEntry(['kind' => 'expense', 'description' => 'Offene Raummiete · Testdaten', 'counterparty' => 'Mustervermietung', 'amount' => '240,00', 'document_date' => '2026-08-01', 'due_date' => '2026-08-15', 'category' => 'rooms'], $key());
$db->prepare('INSERT INTO finance_supplier_contacts VALUES(?,?,?,?,?,?,?)')->execute([$club, $open, $supplier, 1, 'Kulturfreunde Muster · Testdaten', 'demo-manager', gmdate('Y-m-d\TH:i:s\Z')]);
$db->prepare('INSERT INTO supplier_bank_details VALUES(?,?,?,?,?,?)')->execute([$club, $supplier, 'DE89370400440532013000', 'COBADEFFXXX', 'demo-manager', gmdate('Y-m-d\TH:i:s\Z')]);

// A bank row without a document is intentionally left for the “Umsatz prüfen” exercise.
$tx = bin2hex(random_bytes(16));
$db->prepare('INSERT INTO finance_transactions(id,club_id,account_id,booked_on,counterparty,description,cents,source,reversal_of,request_key,fingerprint,version) VALUES(?,?,?,?,?,?,?,?,?,?,?,1)')
    ->execute([$tx, $club, $bank, '2026-08-03', 'Musterveranstaltung', 'Eingang Eintritt · Zuordnung üben', 12500, 'manual', null, $key(), hash('sha256', 'demo-bank-1')]);

// Documents use the normal upload path, so folder placement and later linking can be practised.
$pdf = static function (string $line): string {
    $stream = "BT /F1 16 Tf 72 760 Td (" . addcslashes($line, "()\\") . ") Tj ET";
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
        "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    $body = "%PDF-1.4\n"; $offsets = [0];
    foreach ($objects as $n => $object) { $offsets[] = strlen($body); $body .= ($n + 1) . " 0 obj\n" . $object . "\nendobj\n"; }
    $xref = strlen($body); $body .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) $body .= sprintf("%010d 00000 n \n", $offsets[$i]);
    return $body . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
};
$documents = new Documents($db, 'demo-manager');
$documents->upload('demo-rechnung.pdf', $pdf('Demorechnung Buehnenpodest 299,00 EUR'), ['title' => 'Rechnung Bühnenpodest · Demodaten', 'kind' => 'invoice', 'links' => ['entry:' . $expense], 'document_date' => '2026-03-10'], $key());
$documents->upload('demo-offene-rechnung.pdf', $pdf('Offene Demorechnung Raummiete 240,00 EUR'), ['title' => 'Offene Rechnung Raummiete · Demodaten', 'kind' => 'invoice', 'links' => ['entry:' . $open], 'document_date' => '2026-08-01'], $key());
$documents->upload('demo-spendenbeleg.xml', "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Invoice><ID>DEMO-2026-0003</ID><Payee>Synthetische Förderperson</Payee><Amount>350.00</Amount></Invoice>", ['title' => 'E-Rechnung Spende · Demodaten', 'kind' => 'receipt', 'links' => ['entry:' . $donation], 'document_date' => '2026-04-05'], $key());

$inventory = new Inventory($db, 'demo-manager');
$location = $inventory->saveLocation('', 0, ['name' => 'Vereinslager · Demodaten', 'notes' => 'Nur Übungsbestand'], $key());
$inventory->saveItem('', 0, ['code' => 'DEMO-BUEHNE-01', 'name' => 'Bühnenpodest · Demodaten', 'category' => 'Bühnentechnik', 'unit' => 'Satz', 'kind' => 'reusable', 'minimum' => '1', 'home_location' => $location, 'initial_quantity' => '4', 'content_quantity' => '4', 'content_unit' => 'Füße', 'source_kind' => 'expense', 'source_id' => $expense, 'unit_value' => '299,00', 'value_note' => 'Demorechnung DEMO-2026-0002'], $key());
$inventory->saveItem('', 0, ['code' => 'DEMO-KABEL-01', 'name' => 'Verlängerungskabel · Demodaten', 'category' => 'Veranstaltung', 'unit' => 'Stück', 'kind' => 'consumable', 'minimum' => '5', 'home_location' => $location, 'initial_quantity' => '20'], $key());

// One upcoming and one archived event demonstrate the calendar, event report and GEMA pages.
$event = $registry->saveAppointment(null, 0, ['title' => 'Sommerfest · Demodaten', 'start' => '2026-10-10T15:00', 'end' => '2026-10-10T22:00', 'location' => 'Musterplatz · Testdaten', 'description' => 'Fiktiver Termin zum Üben.', 'category' => 'kultur', 'activity_state' => 'planned', 'activity_kind' => 'event'], [$member, $second]);
$pastEvent = $registry->saveAppointment(null, 0, ['title' => 'Frühlingsabend · Demodaten', 'start' => '2026-05-16T18:00', 'end' => '2026-05-16T21:00', 'location' => 'Musterhaus · Testdaten', 'description' => 'Fiktiver abgeschlossener Termin.', 'category' => 'kultur', 'activity_state' => 'completed', 'activity_kind' => 'event', 'report_summary' => 'Fiktiver Tätigkeitsbericht für den Übungsablauf.'], [$member, $second]);
$db->prepare('INSERT INTO event_gema(club_id,event_id,event_title,event_date,status,reference,notes,setlist,entry_id,version,details) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([$club, $event, 'Sommerfest · Demodaten', '2026-10-10', 'check', '', 'Demo: Anmeldung auf der GEMA-Seite prüfen.', 'check', null, 1, '{}']);
$db->prepare('INSERT INTO event_gema(club_id,event_id,event_title,event_date,status,reference,notes,setlist,entry_id,version,details) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([$club, $pastEvent, 'Frühlingsabend · Demodaten', '2026-05-16', 'registered', 'DEMO-GEMA-001', 'Fiktiver Vorgang.', 'submitted', null, 1, '{}']);

$minutes = new Minutes($db, 'demo-manager');
$minutes->save('', 0, ['title' => 'Vorstandssitzung · Demodaten', 'meeting_on' => '2026-08-20', 'source_kind' => 'editor', 'body_html' => '<p>Fiktives Protokoll zum Üben.</p><h2>Beschlüsse</h2><ul><li>Sommerfest vorbereiten</li><li>Rechnung prüfen</li></ul>'], $key());
$chat = new ClubChat($db, 'demo-manager');
$chatId = $chat->create('Kassenübung · Demodaten', ['demo-manager', 'demo-finance']);
$chat->send($chatId, 'Willkommen in der Übungsumgebung. Diese Nachricht versendet keine Mail.', $key());

$db->prepare('INSERT INTO document_checks(club_id,id,title,category,responsible,valid_until,review_on,reminder_days,notes,state,version,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$club, bin2hex(random_bytes(16)), 'Erste-Hilfe-Tasche · Demodaten', 'Erste Hilfe', 'Mara Muster · Testdaten', '2027-06-30', '2027-05-30', 30, 'Fiktiver Nachweis für die Fristenansicht.', 'active', 1, gmdate('c')]);
$db->prepare('INSERT INTO tax_account_settings(club_id,year,code,name,category,active,custom,version) VALUES(?,?,?,?,?,?,?,1)')->execute([$club, 2026, '04900', 'Sonstige Vereinsausgaben · Demodaten', 'expense', 1, 1]);

echo "Demo-Datenbank angelegt: konfigurierte synthetische MySQL-Datenbank\n";
echo "Keine echten Konten, Mails, Bankzugriffe oder Veröffentlichungen.\n";
