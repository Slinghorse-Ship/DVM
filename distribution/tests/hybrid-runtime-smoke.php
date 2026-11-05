<?php declare(strict_types=1);
/** Native, installed PDF/CII/validator acceptance. Synthetic SQLite :memory: only; no mail or bank calls. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc !== 2) { fwrite(STDERR, "Usage: php hybrid-runtime-smoke.php /absolute/install-root\n"); exit(2); }
ini_set('display_errors', '0');
ini_set('log_errors', '0');
putenv('KULTURHUS_DISABLE_MAIL=1');
$stage = 'installation';
$started = microtime(true);
try {
    $base = realpath($argv[1]);
    if (!$base || $base === '/' || !is_file($base . '/installation.json')) throw new RuntimeException('Completed installation required.');
    $app = $base . '/app/vereinsverwaltung';
    // Only component paths, never the business runtime or a hosting database.
    require $base . '/private/runtime-components.php';
    require_once dirname(__DIR__) . '/src/RuntimeInstaller.php';
    $versions = RuntimeInstaller::preflight($base . '/runtime');
    require_once $app . '/src/bootstrap.php';
    require_once $app . '/src/Finance/Invoices/OutgoingEInvoice.php';
    require_once $app . '/src/Finance/Invoices/CiiInvoice.php';
    require_once $app . '/src/Finance/Invoices/HybridEInvoice.php';
    $stage = 'synthetic in-memory invoice';
    $db = \Kulturhus\Verein\Database::connect(':memory:');
    \Kulturhus\Verein\Database::migrate($db);
    $db->exec("INSERT INTO clubs VALUES ('synthetic','FIKTIV'); INSERT INTO actors(id,club_id,role,is_superuser) VALUES ('synthetic-admin','synthetic','member',1)");
    $finance = new \Kulturhus\Verein\Finance($db, 'synthetic-admin');
    $account = $finance->createAccount('FIKTIV – Prüfbank', 'offline', '0', '2026-01-01');
    $sections = [
        'club' => ['name'=>'FIKTIVER Prüfverein','street'=>'Musterstraße 1','postcode'=>'12345','city'=>'Musterstadt','email'=>'synthetic@example.invalid','phone'=>'+49 123 456789','register'=>'VR 12345 Musterstadt'],
        'tax' => ['number'=>'123/456/78901'],
        'bank' => ['iban'=>'DE89370400440532013000','holder'=>'FIKTIVER Prüfverein','account_id'=>$account],
    ];
    $q = $db->prepare('INSERT INTO club_settings(club_id,section,version,payload,updated_at) VALUES(?,?,1,?,?)');
    foreach ($sections as $section => $values) $q->execute(['synthetic',$section,json_encode(array_replace(\Kulturhus\Verein\SettingsSchema::defaults($section),$values),JSON_THROW_ON_ERROR),gmdate('c')]);
    $invoices = new \Kulturhus\Verein\OutgoingInvoices($db, 'synthetic-admin');
    $exports = new \Kulturhus\Verein\OutgoingEInvoice($db, 'synthetic-admin');
    $data = ['buyer_name'=>'FIKTIVE Kundengesellschaft','buyer_street'=>'Prüfweg 2','buyer_postcode'=>'23456','buyer_city'=>'Beispielstadt','buyer_email'=>'buyer@example.invalid','buyer_reference'=>'FIKTIV-100','description'=>'FIKTIVE Prüfleistung','note'=>'FIKTIV – Nur technische Formatprüfung, keine echte Rechnung','vat_reason'=>'','document_date'=>'2026-09-08','delivery_date'=>'2026-09-07','due_date'=>'2026-09-22','category'=>'general','contact_id'=>'','payment_account'=>$account,'positions'=>[
        ['description'=>'Fiktive Raumnutzung & Bühnentechnik – Prüfung €','quantity'=>'3','unit'=>'DAY','unit_gross'=>'119','tax_rate'=>'19','sphere'=>'3','account'=>''],
        ['description'=>'Fiktive ermäßigte Leistung','quantity'=>'1.5','unit'=>'HUR','unit_gross'=>'107','tax_rate'=>'7','sphere'=>'3','account'=>''],
    ]];
    $id = $invoices->save('',0,$data,bin2hex(random_bytes(16)));
    $invoices->review($id,1);
    $entry = $invoices->issue($id,2);
    $source = $exports->hybridSource($entry,1);
    $confirmation = ['domestic_country'=>'DE','source_hash'=>$source['source_hash']];
    $xml = \Kulturhus\Verein\CiiInvoice::build($source['invoice'],$confirmation,$source['original']['sha256']);
    $stage = 'real PDF/A-3b and EN16931 validation';
    $document = $exports->saveHybrid($entry,1,$confirmation);
    $file = (new \Kulturhus\Verein\Documents($db,'synthetic-admin'))->file($document,1);
    $snapshot = json_decode($file['snapshot'],true,512,JSON_THROW_ON_ERROR);
    $validation = $snapshot['validation'];
    if ($validation['state'] !== 'valid' || $validation['pdfa'] !== '3b' || $validation['pdf_assertions'] < 100) throw new RuntimeException('Native validation did not prove conformance.');
    if ($validation['source_sha256'] !== $source['original']['sha256'] || $validation['xml_sha256'] !== hash('sha256',$xml)) throw new RuntimeException('Original and XML evidence mismatch.');
    if ($exports->hybridSource($entry,1)['original']['sha256'] !== $source['original']['sha256']) throw new RuntimeException('Original was changed.');
    if ($exports->saveHybrid($entry,1,$confirmation) !== $document) throw new RuntimeException('Repeated export was not idempotent.');
    $stage = 'real rejection of inconsistent tax total';
    $invalidXml = preg_replace_callback('/<ram:TaxTotalAmount currencyID="EUR">([^<]+)/',static fn(array $m): string => '<ram:TaxTotalAmount currencyID="EUR">' . number_format((float)$m[1]+1,2,'.',''),$xml,1,$count);
    if ($count !== 1) throw new RuntimeException('Negative fixture preparation failed.');
    $rejected = false;
    try { \Kulturhus\Verein\HybridEInvoice::build($source['original']['bytes'],$invalidXml); }
    catch (DomainException) { $rejected = true; }
    if (!$rejected) throw new RuntimeException('Inconsistent tax total was accepted.');
    if ((int)$db->query('SELECT COUNT(*) FROM outgoing_invoice_deliveries')->fetchColumn() !== 0) throw new RuntimeException('Unexpected delivery record.');
    echo json_encode(['passed'=>true,'synthetic_memory_database'=>true,'reportlab'=>$versions['reportlab'],'pdfa'=>'3b','profile'=>'EN16931','pdf_assertions'=>$validation['pdf_assertions'],'original_unchanged'=>true,'idempotent'=>true,'incorrect_tax_rejected'=>true,'mail_deliveries'=>0,'seconds'=>round(microtime(true)-$started,3)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    // A native command may carry local paths. Do not expose private runtime contents or secrets.
    fwrite(STDERR, "Hybrid runtime smoke failed at: " . $stage . "\n");
    exit(1);
}
