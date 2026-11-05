<?php declare(strict_types=1);
/** Adapter/persistence tests in an isolated native fixture. No HTTP or demo-database writes. */
require dirname(__DIR__) . '/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access, Db, RichText};
use Kulturhus\Native\Cms\{Schema, Pages, Media};
use Kulturhus\Native\Communication\{Schema as CommunicationSchema, Workspace};
use Kulturhus\Native\Web\{Manager, Gallery, Site, Cache, Html};

$checks = 0; $failures = [];
function check(bool $condition, string $label): void {
    global $checks, $failures;
    $checks++;
    if (!$condition) $failures[] = $label;
}
function editorValue(string $html): string {
    $dom = new DOMDocument();
    @$dom->loadHTML('<meta charset="UTF-8">' . $html, LIBXML_NONET);
    $input = (new DOMXPath($dom))->query('//input[@name="body_html"]')->item(0);
    if (!$input instanceof DOMElement) throw new RuntimeException('Missing editor body field');
    return $input->getAttribute('value');
}

$db = TestSupport::db(); $seeds = TestSupport::seed($db);
Schema::install($db); CommunicationSchema::install($db);
$run = 'rt-adapter-' . bin2hex(random_bytes(6)); $club = $run . '-club';
foreach (['admin','reader'] as $role) {
    Db::run($db, 'INSERT INTO native_users(id,club_id,name) VALUES(?,?,?)', [$run . '-' . $role, $club, 'Synthetischer Richtexttest ' . $role]);
    Db::run($db, 'INSERT INTO native_grants(actor_id,module,capability) SELECT ?,module,capability FROM native_grants WHERE actor_id=?', [$run . '-' . $role, $seeds[$role]]);
}
$access = new Access($db, $run . '-admin'); $readerAccess = new Access($db, $run . '-reader');
$root = dirname(__DIR__, 2) . '/.local/native-richtext-adapter-tests/' . $run;
$manager = new Manager($db, $access, $root . '/media', 'synthetic-csrf');
$reader = new Manager($db, $readerAccess, $root . '/media', 'synthetic-csrf');
$pages = new Pages($db, $access);
$site = new Site(new Pages($db, $readerAccess), new Media($db, $readerAccess, $root . '/media'), new Cache($root . '/cache'), 'http://127.0.0.1:8109', $club, new Gallery($db, $readerAccess, $root . '/media'));
$legacy = "Älterer Klartext 🎭 & <literal>\nZweite Zeile";
$rich = '<p><strong>Neu 🎶</strong> <span style="color:#123abc;background-color:#fedcba">Farbe</span></p><table><tbody><tr><th>Tag</th><th>Ort</th></tr><tr><td style="background-color:#aabbcc">Freitag</td><td>Testsaal</td></tr></tbody></table>';
$rich .= '<p><a href="mailto:own%23news@example.invalid">Eigene Mail Ä</a> <a href="tel:+49445412345;ext=12">Eigenes Telefon</a></p>';
$safe = RichText::sanitize($rich);
check(str_contains($safe, 'href="mailto:own%23news@example.invalid"') && str_contains($safe, 'href="tel:+49445412345;ext=12"'), 'real shared adapter input retains exact own mail and telephone targets');
check(RichText::plain(Html::content(['body' => $legacy, 'body_html' => ''])) === $legacy, 'empty legacy HTML falls back without duplicate line breaks');
check(RichText::plain(Html::content(['body' => $legacy])) === $legacy, 'missing legacy HTML falls back without duplicate line breaks');

$page = $pages->save(['title' => 'Legacy page', 'slug' => $run, 'body' => $legacy]);
check($page['body_html'] === '' && $page['body'] === $legacy, 'legacy page retains plain body and empty HTML');
$initial = editorValue($manager->main('website', $page['id']));
check(RichText::plain($initial) === $legacy, 'website editor loads legacy body without loss');
check(str_contains($reader->main('website', $page['id']), '&lt;literal&gt;'), 'website reader escapes legacy body');
$post = ['csrf' => 'synthetic-csrf', 'operation' => 'page-save', 'id' => $page['id'], 'version' => $page['version'], 'title' => $page['title'], 'slug' => $page['slug'], 'template' => 'standard', 'body_html' => $initial];
$manager->action('website', $post, []); $page = $pages->get($page['id']);
check($page['body'] === $legacy && $page['version'] === 2, 'untouched legacy website submit retains contents');
$manager->action('website', array_replace($post, ['version' => $page['version'], 'body_html' => $rich]), []); $page = $pages->get($page['id']);
check($page['body_html'] === $safe && $page['body'] === RichText::plain($safe), 'website adapter stores HTML and derived plain body');
check(editorValue($manager->main('website', $page['id'])) === $safe, 'website editor reopens persisted rich content');
check(str_contains($reader->main('website', $page['id']), '<table>'), 'website reader renders table');
$page = $pages->publish($page['id'], $page['version']);
$public = $site->page($page['slug']);
check(str_contains($public, $safe), 'public website renders persisted table and colors');
check(str_contains($public, '/assets/richtext.css'), 'public website includes rich text styling');
$manager->action('website', array_replace($post, ['version' => $page['version'], 'body_html' => '']), []); $page = $pages->get($page['id']);
check($page['body_html'] === '' && $page['body'] === '', 'website editor clear does not resurrect legacy/plain body');
check(str_contains($site->page($page['slug']), $safe), 'clearing draft leaves published rich snapshot unchanged');
$page = $pages->publish($page['id'], $page['version']);
check(!str_contains($site->page($page['slug']), $safe), 'publishing cleared body replaces public cached content');
$page = $pages->restore($page['id'], 1, $page['version']);
check($page['body'] === $legacy && RichText::plain(editorValue($manager->main('website', $page['id']))) === $legacy, 'restoring pre-richtext revision retains fallback');

$album = $pages->save(['title' => 'Legacy album', 'slug' => 'galerie/' . $run, 'template' => 'gallery', 'body' => $legacy]);
$initial = editorValue($manager->main('media', $album['id']));
check(RichText::plain($initial) === $legacy, 'album editor loads legacy plain description');
$albumPost = ['csrf' => 'synthetic-csrf', 'operation' => 'album-save', 'id' => $album['id'], 'version' => 1, 'title' => $album['title'], 'slug' => $album['slug'], 'body_html' => $initial];
$manager->action('media', $albumPost, []); $album = $pages->get($album['id']);
check($album['body'] === $legacy, 'untouched album editor retains legacy body');
$manager->action('media', array_replace($albumPost, ['version' => $album['version'], 'body_html' => $rich]), []); $album = $pages->get($album['id']);
check($album['body_html'] === $safe && $album['body'] === RichText::plain($safe), 'album adapter stores rich description');
check(editorValue($manager->main('media', $album['id'])) === $safe, 'album rich description reopens');
$album = $pages->publish($album['id'], $album['version']);
check(str_contains($site->page($album['slug']), $safe), 'public album renders formatted description before photos');
$manager->action('media', array_replace($albumPost, ['version' => $album['version'], 'body_html' => '']), []); $album = $pages->get($album['id']);
check($album['body'] === '' && $album['body_html'] === '', 'album editor clear removes derived body');
check(str_contains($site->page($album['slug']), $safe), 'album draft clear does not alter public snapshot');

foreach (['newsletter' => ['edition','newsletter-standard'], 'social' => ['post','social-post'], 'press' => ['release','press-release']] as $module => [$kind,$template]) {
    $workspace = new Workspace($db, $access, $module);
    $document = $workspace->save(['title' => 'Legacy ' . $module, 'body' => $legacy, 'kind' => $kind, 'template' => $template]);
    $initial = editorValue($manager->main($module, $document['id']));
    check(RichText::plain($initial) === $legacy, $module . ': editor loads legacy plain body');
    $post = ['csrf' => 'synthetic-csrf', 'operation' => 'communication-save', 'id' => $document['id'], 'version' => 1, 'title' => $document['title'], 'kind' => $kind, 'template' => $template, 'body_html' => $initial, 'channels' => ['facebook']];
    $manager->action($module, $post, []); $document = $workspace->get($document['id']);
    check($document['body'] === $legacy, $module . ': untouched editor retains legacy body');
    $manager->action($module, array_replace($post, ['version' => $document['version'], 'body_html' => $rich]), []); $document = $workspace->get($document['id']);
    check($document['body_html'] === $safe && $document['body'] === RichText::plain($safe), $module . ': adapter persists formatted and plain content');
    check(editorValue($manager->main($module, $document['id'])) === $safe, $module . ': editor reopens rich body');
    check(str_contains($workspace->preview($document['id']), $safe), $module . ': preview renders persisted table and colors');
    check(str_contains($reader->main($module, $document['id']), $safe), $module . ': read-only manager renders same table and colors');
    $manager->action($module, array_replace($post, ['version' => $document['version'], 'body_html' => '']), []); $document = $workspace->get($document['id']);
    check($document['body'] === '' && ($document['body_html'] ?? '') === '', $module . ': explicit editor clear removes previous rich/plain body');
    check(editorValue($manager->main($module, $document['id'])) === '', $module . ': cleared editor remains empty on reopen');
}

foreach ($failures as $failure) fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
echo 'Native RichText adapters: ' . ($checks - count($failures)) . '/' . $checks . ' checks passed; ' . count($failures) . ' failures.' . PHP_EOL;
exit($failures ? 1 : 0);
