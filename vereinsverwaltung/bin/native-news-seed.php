<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/native/bootstrap.php';

use Kulturhus\Native\Cms\{Articles, Media, Pages, Schema};
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\TestSupport;

// TestSupport rejects every host/database outside the registered local fixture format.
$db = TestSupport::db();
$actors = TestSupport::seed($db);
Schema::install($db);
$access = new Access($db, $actors['admin']);
$pages = new Pages($db, $access);
$articles = new Articles($db, $access);
$runtime = dirname((string) getenv('KH_NATIVE_TEST_CONFIG')) . '/runtime-' . TestSupport::config()['database'];
if (!is_dir($runtime)) mkdir($runtime, 0700, true);
$media = new Media($db, $access, $runtime . '/media');
$existing = array_column($pages->list(), null, 'slug');
$anchors = [
    ['title' => 'Kulturhus Jaderberg e. V.', 'slug' => 'start', 'excerpt' => 'Kultur und mehr am Wattenmeer', 'body' => 'Diese lokale Vorschau enthält ausschließlich synthetische redaktionelle Inhalte. Es handelt sich nicht um eine Veröffentlichung des echten Vereinsprogramms.', 'nav_label' => 'Start', 'nav_order' => 0],
    ['title' => 'Ein Abend voller Musik', 'slug' => 'veranstaltungen', 'template' => 'event', 'excerpt' => 'Eine frei erfundene Veranstaltung für die lokale Vorschau.', 'body' => 'Synthetischer Testtermin ohne Buchung oder Ticketverkauf.', 'event_start' => '2026-11-14T19:30', 'event_location' => 'Synthetischer Kulturraum', 'nav_label' => 'Programm 2026', 'nav_order' => 10],
    ['title' => 'Bildergalerie', 'slug' => 'galerie', 'template' => 'gallery', 'excerpt' => 'Kulturhus-Momente in synthetischen Testbildern.', 'nav_label' => 'Galerie', 'nav_order' => 20],
    ['title' => 'Kultur gemeinsam gestalten', 'slug' => 'verein', 'excerpt' => 'Eine Vorschau auf gemeinsames Vereinsleben.', 'body' => 'Synthetischer Testinhalt. Hier kann die Redaktion später geprüfte Informationen zum Verein pflegen.', 'nav_label' => 'Verein', 'nav_order' => 30],
    ['title' => 'Kontakt', 'slug' => 'kontakt', 'excerpt' => 'Mit dem Kulturhus ins Gespräch kommen.', 'body_html' => '<p>Dies ist eine <strong>synthetische Kontaktseite</strong> für die lokale Vorschau. Es werden keine echten Kontaktdaten angezeigt und keine Nachrichten versendet.</p><p>Später können hier die vom Verein geprüften Kontaktmöglichkeiten redaktionell veröffentlicht werden.</p>', 'nav_label' => 'Kontakt', 'nav_order' => 50],
    ['title' => 'Impressum · Testinhalt', 'slug' => 'impressum', 'excerpt' => 'Platzhalter der lokalen Entwicklungsumgebung.', 'body' => 'Diese Seite ist ein synthetischer Testanker und kein rechtlich gültiges Impressum. Sie enthält keine echten Vertretungs-, Register- oder Kontaktdaten. Vor einem öffentlichen Betrieb müssen die tatsächlich zutreffenden und geprüften Angaben hinterlegt werden.', 'nav_visible' => false],
    ['title' => 'Datenschutz · Testinhalt', 'slug' => 'datenschutz', 'excerpt' => 'Platzhalter der lokalen Entwicklungsumgebung.', 'body' => 'Diese synthetische Seite demonstriert die Verknüpfung zur Datenschutzerklärung. Sie ist keine gültige Datenschutzerklärung. Für einen öffentlichen Betrieb müssen die tatsächlichen Verarbeitungsvorgänge, Verantwortlichkeiten und Kontaktwege geprüft und dokumentiert werden.', 'nav_visible' => false],
    ['title' => 'Satzung · Testinhalt', 'slug' => 'satzung', 'excerpt' => 'Platzhalter der lokalen Entwicklungsumgebung.', 'body' => 'Hier ist bewusst keine echte Vereinssatzung hinterlegt. Dieser synthetische Testanker ist kein verbindliches Vereinsdokument und ersetzt keine Satzung. In einem späteren Betrieb ist ausschließlich die geprüfte, gültige Fassung des Vereins bereitzustellen.', 'nav_visible' => false],
];
foreach ($anchors as $anchor) if (!isset($existing[$anchor['slug']])) { $p = $pages->save($anchor); $pages->publish($p['id'], $p['version']); }
// Normalize only the untouched old synthetic seed label; edited drafts remain untouched.
if (isset($existing['veranstaltungen']) && $existing['veranstaltungen']['nav_label'] === 'Veranstaltungen' && !$existing['veranstaltungen']['has_unpublished_changes']) {
    $p = $existing['veranstaltungen']; $p['nav_label'] = 'Programm 2026'; $p = $pages->save($p, $p['version']); $pages->publish($p['id'], $p['version']);
}
if (!isset($existing['aktuelles'])) {
    $page = $pages->save(['title' => 'Aktuelles', 'slug' => 'aktuelles', 'excerpt' => 'Programm, Einblicke und Geschichten rund um unsere gemeinsame Kulturarbeit.', 'nav_label' => 'Aktuelles', 'nav_order' => 25]);
    $pages->publish($page['id'], $page['version']);
}
$samples = [
    ['slug' => 'aktuelles/demo-ein-abend-der-verbindet', 'title' => 'Ein Abend, der verbindet', 'news_date' => '2026-10-07', 'excerpt' => 'Musik, Gespräche und helfende Hände – ein synthetischer Rückblick auf gemeinsame Kultur.', 'body_html' => '<p>Eine kleine Bühne, viele Ideen und Menschen, die gemeinsam etwas möglich machen: So stellen wir uns einen Kulturabend vor.</p><h2>Gemeinsam wird mehr daraus</h2><p>Vom ersten Aufbau bis zum letzten Gespräch entstehen die schönsten Momente durch das Miteinander. <strong>Danke an alle, die ihre Zeit und ihre Ideen teilen.</strong> 🎶</p><p>Dieser Beitrag ist frei erfunden und dient ausschließlich der lokalen Vorschau. Er beschreibt keine reale Veranstaltung.</p>', 'colors' => [36, 86, 64]],
    ['slug' => 'aktuelles/demo-hinter-den-kulissen', 'title' => 'Hinter den Kulissen: Kultur entsteht gemeinsam', 'news_date' => '2026-10-06', 'excerpt' => 'Wie aus einer ersten Idee ein gemeinsamer Abend werden kann.', 'body_html' => '<p>Wer stellt die Stühle? Wer begrüßt die Gäste? Wer bringt eine neue Idee mit? In unserer synthetischen Geschichte trifft sich ein kleines Team, um einen Kulturabend vorzubereiten.</p><blockquote>Viele kleine Beiträge machen gemeinsam einen großen Unterschied.</blockquote><p>Ob Technik, Gestaltung oder ein offenes Ohr: Engagement hat viele Formen.</p><p>Alle Inhalte und Bilder dieses Beitrags sind synthetische Testdaten.</p>', 'colors' => [113, 78, 44]],
    ['slug' => 'aktuelles/demo-raum-fuer-neue-ideen', 'title' => 'Raum für neue Ideen', 'news_date' => '2026-10-05', 'excerpt' => 'Geschichten erzählen, Menschen zusammenbringen und Kultur vor Ort gestalten.', 'body_html' => '<p>Ein Haus für Kultur lebt davon, dass Menschen ihre Perspektiven teilen. In dieser lokalen Vorschau zeigen wir, wie ein neuer Beitrag aussehen kann.</p><h2>Was uns bewegt</h2><ul><li>Musik und Geschichten aus der Region</li><li>Offene Begegnungen und gemeinsame Projekte</li><li>Freude am ehrenamtlichen Gestalten</li></ul><p>Dieser Text ist keine reale Vereinsmeldung und wird nicht extern veröffentlicht.</p>', 'colors' => [43, 74, 104]],
];
$created = 0;
foreach ($samples as $sample) {
    if (isset($existing[$sample['slug']])) continue;
    $image = imagecreatetruecolor(1200, 760);
    imagefill($image, 0, 0, imagecolorallocate($image, ...$sample['colors']));
    $gold = imagecolorallocate($image, 212, 164, 76); $cream = imagecolorallocate($image, 246, 243, 233);
    imagefilledellipse($image, 880, 280, 390, 390, $gold);
    imagefilledrectangle($image, 100, 480, 1080, 500, $cream);
    imagefilledrectangle($image, 180, 400, 230, 480, $cream);
    imagefilledrectangle($image, 420, 340, 470, 480, $gold);
    imagestring($image, 5, 100, 90, 'KULTURHUS / SYNTHETISCHE GESCHICHTEN', $cream);
    $file = $runtime . '/synthetic-news-' . bin2hex(random_bytes(5)) . '.png';
    imagepng($image, $file); unset($image);
    $asset = $media->upload($file, 'synthetic-news.png', 'Synthetische Illustration: Bühne, Formen und goldener Kreis');
    unlink($file);
    unset($sample['colors']);
    $sample['media_ids'] = [$asset['id']];
    $p = $articles->save($sample); $articles->publish($p['id'], $p['version']); $created++;
}
echo 'Synthetische Beitragsdemo ergänzt: ' . $created . ' neue Beiträge; vorhandene Inhalte unverändert.' . PHP_EOL;
