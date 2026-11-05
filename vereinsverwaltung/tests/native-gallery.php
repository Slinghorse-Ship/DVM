<?php declare(strict_types=1);
/** Local service/rendering regressions. Real HTTP upload and lightbox use separate browser tests. */
require dirname(__DIR__) . '/native/bootstrap.php';

use Kulturhus\Native\Cms\{Conflict, Media, Pages, Schema};
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Web\Gallery;

$checks = 0;
$failures = [];
function check(bool $condition, string $label): void
{
    global $checks, $failures;
    $checks++;
    if (!$condition) $failures[] = $label;
}
function rejects(callable $operation, string $class, string $label): void
{
    try { $operation(); check(false, $label . ' (accepted)'); }
    catch (Throwable $error) { check($error instanceof $class, $label . ' (exception class)'); }
}
function accepted(callable $operation, string $label): bool
{
    try { $operation(); check(true, $label); return true; }
    catch (Throwable) { check(false, $label); return false; }
}
/** Runs another connection's write after Gallery's preflight, before Pages' transaction. */
final class GalleryBeforeTransactionPDO extends PDO
{
    public ?Closure $beforeTransaction = null;
    public function beginTransaction(): bool
    {
        $callback = $this->beforeTransaction;
        $this->beforeTransaction = null;
        if ($callback) $callback();
        return parent::beginTransaction();
    }
}

$db = TestSupport::db();
$seedActors = TestSupport::seed($db);
Schema::install($db);
$run = 'gallery-test-' . bin2hex(random_bytes(6));
$club = $run . '-club';
$actors = [];
foreach ($seedActors as $role => $seedId) {
    $actors[$role] = $run . '-' . $role;
    Db::run($db, 'INSERT INTO native_users(id,club_id,name) VALUES(?,?,?)', [$actors[$role], $role === 'outsider' ? $run . '-foreign' : $club, 'Synthetische Galerie: ' . $role]);
    Db::run($db, 'INSERT INTO native_grants(actor_id,module,capability) SELECT ?,module,capability FROM native_grants WHERE actor_id=?', [$actors[$role], $seedId]);
}
$access = new Access($db, $actors['admin']);
$pages = new Pages($db, $access);
$root = dirname(__DIR__, 2) . '/.local/native-gallery-tests/' . $run;
mkdir($root, 0700, true);
$source = $root . '/synthetic.png';
$png = imagecreatetruecolor(96, 64);
imagefill($png, 0, 0, imagecolorallocate($png, 22, 100, 80));
imagefilledrectangle($png, 20, 15, 76, 49, imagecolorallocate($png, 230, 170, 40));
imagepng($png, $source);
unset($png);
file_put_contents($source, '\nSYNTHETIC_TRAILER_NOT_FOR_PUBLIC_OUTPUT', FILE_APPEND);
$store = $root . '/media';
$media = new Media($db, $access, $store);
$foreignAccess = new Access($db, $actors['outsider']);
$foreignMedia = new Media($db, $foreignAccess, $store);
$admin = new Gallery($db, $access, $store, 'synthetic-csrf');
$editor = new Gallery($db, new Access($db, $actors['editor']), $store);
$reader = new Gallery($db, new Access($db, $actors['reader']), $store);
$outsider = new Gallery($db, $foreignAccess, $store);
$images = [];
foreach (['A', 'B', 'C'] as $label) $images[] = $media->upload($source, 'synthetic-' . $label . '.png', 'Eigenes synthetisches Bild ' . $label);
$foreign = $foreignMedia->upload($source, 'synthetic-foreign.png', 'Fremdes synthetisches Bild');
foreach ($images as $image) {
    $variant = $media->variant($image['id'], 'thumb');
    check($variant['width'] === 96 && $variant['height'] === 64, 'small image is not upscaled');
    check(!str_contains(file_get_contents($variant['path']), 'SYNTHETIC_TRAILER'), 'only decoded/reencoded pixels are retained');
    check($media->publicVariant($image['id'], 'medium') === null, 'unpublished asset is private');
}
$fake = $root . '/synthetic.svg';
file_put_contents($fake, '<svg xmlns="http://www.w3.org/2000/svg"><script>synthetic</script></svg>');
rejects(fn () => $media->upload($fake, 'misleading.png', 'Test'), InvalidArgumentException::class, 'unsupported image type denied');

$id = $editor->action(['operation' => 'album-save', 'title' => 'Eigenes Testalbum', 'slug' => $run, 'excerpt' => 'Nur synthetische Inhalte'], []);
$page = $pages->get($id);
check($page['version'] === 1 && $page['template'] === 'gallery', 'new album has first version and gallery template');
check($page['slug'] === 'galerie/' . $run && !$page['nav_visible'], 'new album route and hidden navigation default');
check($page['published_revision'] === null, 'new album is a draft');
check(count(array_filter($reader->albums(), fn ($p) => $p['id'] === $id)) === 1, 'reader can see own album');
check(count(array_filter($outsider->albums(), fn ($p) => $p['id'] === $id)) === 0, 'other club cannot list album');
rejects(fn () => $outsider->manager($id), RuntimeException::class, 'other club cannot view manager');
rejects(fn () => $outsider->action(['operation' => 'album-image', 'id' => $id, 'version' => 1], []), RuntimeException::class, 'other club cannot mutate album');
rejects(fn () => $reader->action(['operation' => 'album-save', 'title' => 'Denied', 'slug' => $run . '-denied'], []), DomainException::class, 'reader cannot create album');
foreach (['', '../escape', 'UPPER', 'bad?query'] as $slug) {
    rejects(fn () => $admin->action(['operation' => 'album-save', 'title' => 'Invalid', 'slug' => $slug], []), InvalidArgumentException::class, 'invalid album path rejected');
}
rejects(fn () => $admin->action(['operation' => 'album-save', 'title' => '', 'slug' => $run . '-invalid'], []), InvalidArgumentException::class, 'empty album title rejected');
$standard = $pages->save(['title' => 'Ordinary page', 'slug' => $run . '-standard']);
rejects(fn () => $admin->action(['operation' => 'album-order', 'id' => $standard['id'], 'version' => 1], []), DomainException::class, 'gallery action rejects ordinary pages');

$admin->action(['operation' => 'album-attach', 'id' => $id, 'version' => 1, 'images' => [$images[0]['id'], $images[1]['id'], $images[0]['id']]], []);
$page = $pages->get($id);
check($page['version'] === 2 && $page['media_ids'] === [$images[0]['id'], $images[1]['id']], 'attach preserves order and deduplicates');
check($page['gallery_items'][0]['caption'] === $images[0]['alt'], 'attach starts caption from alt text');
$before = $page;
rejects(fn () => $admin->action(['operation' => 'album-attach', 'id' => $id, 'version' => 2, 'images' => [$images[2]['id'], $foreign['id']]], []), RuntimeException::class, 'cross club attachment denied');
check($pages->get($id) === $before, 'failed mixed attachment has no partial save');
foreach (['album-image', 'album-remove', 'album-move'] as $operation) {
    rejects(fn () => $admin->action(['operation' => $operation, 'id' => $id, 'version' => 2, 'image_id' => $images[2]['id'], 'caption' => 'No member'], []), DomainException::class, 'image operation requires album membership: ' . $operation);
}
$caption = 'Öffentliche Beschreibung <script>synthetic</script> " &';
$admin->action(['operation' => 'album-image', 'id' => $id, 'version' => 2, 'image_id' => $images[0]['id'], 'caption' => $caption, 'tags' => ' Musik, Ort, Musik, '], []);
$page = $pages->get($id);
check($page['version'] === 3 && $page['gallery_items'][0]['caption'] === $caption, 'caption is versioned as text');
check($page['gallery_items'][0]['tags'] === ['Musik', 'Ort'], 'tags trimmed and deduplicated');
check($pages->revisions($id)[0]['payload']['gallery_items'][0]['caption'] === $caption, 'caption included in immutable revision');
foreach ([['caption' => str_repeat('ä', 501)], ['caption' => "bad\x00caption"], ['caption' => "\xC3\x28"], ['tags' => str_repeat('x', 51)], ['tags' => implode(',', range(1, 21))]] as $bad) {
    rejects(fn () => $admin->action(array_replace(['operation' => 'album-image', 'id' => $id, 'version' => 3, 'image_id' => $images[0]['id'], 'caption' => 'Valid', 'tags' => 'Valid'], $bad), []), InvalidArgumentException::class, 'invalid caption/tag rejected');
}
check($pages->get($id)['version'] === 3, 'invalid metadata does not advance version');
$admin->action(['operation' => 'album-image', 'id' => $id, 'version' => 3, 'image_id' => $images[1]['id'], 'caption' => str_repeat('ä', 500), 'tags' => '0,2026'], []);
$page = $pages->get($id);
check($page['gallery_items'][1]['caption'] === str_repeat('ä', 500), '500 Unicode characters accepted');
check($page['gallery_items'][1]['tags'] === ['0', '2026'], 'numeric zero tag is preserved');
$version = $page['version'];
rejects(fn () => $reader->action(['operation' => 'album-image', 'id' => $id, 'version' => $version, 'image_id' => $images[0]['id']], []), DomainException::class, 'reader cannot edit image');
rejects(fn () => $editor->action(['operation' => 'album-publish', 'id' => $id, 'version' => $version], []), DomainException::class, 'editor without publish cannot publish');
foreach (['album-image', 'album-remove', 'album-move', 'album-attach', 'album-order', 'album-publish', 'album-unpublish'] as $operation) {
    rejects(fn () => $admin->action(['operation' => $operation, 'id' => $id, 'version' => $version - 1, 'image_id' => $images[0]['id']], []), Conflict::class, 'stale operation rejected: ' . $operation);
}
$beforeCount = count($media->list());
rejects(fn () => $admin->action(['operation' => 'album-upload', 'id' => $id, 'version' => $version], ['image' => ['error' => UPLOAD_ERR_OK, 'tmp_name' => $source, 'name' => 'forged.png']]), DomainException::class, 'CLI file cannot impersonate HTTP upload');
rejects(fn () => $admin->action(['operation' => 'album-upload', 'id' => $id, 'version' => $version], ['image' => ['error' => UPLOAD_ERR_INI_SIZE]]), DomainException::class, 'HTTP upload error denied');
check(count($media->list()) === $beforeCount && $pages->get($id)['version'] === $version, 'rejected upload creates no asset or revision');
rejects(fn () => $admin->action(['operation' => 'album-save', 'id' => $id, 'version' => $version - 1, 'title' => 'Stale', 'slug' => $run], []), Conflict::class, 'stale album metadata rejected');
rejects(fn () => $admin->action(['operation' => 'album-unknown', 'id' => $id, 'version' => $version], []), DomainException::class, 'unknown album action rejected');
$admin->action(['operation' => 'album-save', 'id' => $id, 'version' => $version, 'title' => 'Updated <svg onload="synthetic">', 'slug' => 'galerie/' . $run, 'excerpt' => 'Synthetischer Kurztext', 'body' => 'Synthetische Beschreibung', 'nav_order' => 10], []);
$page = $pages->get($id);
check($page['version'] === $version + 1 && $page['slug'] === 'galerie/' . $run, 'album-save versions changes without duplicating route prefix');
check($page['media_ids'] === [$images[0]['id'], $images[1]['id']] && $page['gallery_items'][0]['caption'] === $caption, 'album-save preserves images and image metadata');
check($page['excerpt'] === 'Synthetischer Kurztext' && $page['body'] === 'Synthetische Beschreibung' && $page['nav_order'] === 10, 'album-save updates description and position');
$version = $page['version'];

$generation = $pages->generation();
$admin->action(['operation' => 'album-publish', 'id' => $id, 'version' => $version], []);
$page = $pages->get($id);
$publishedVersion = $page['version'];
check($page['published_revision'] === $publishedVersion && $publishedVersion === $version + 1, 'publish creates an album revision');
check($pages->generation() === $generation + 1, 'publish invalidates public cache');
check($media->publicVariant($images[0]['id'], 'large') !== null, 'published album grants public image access');
$published = $pages->publicPage($page['slug']);
$publicHtml = $admin->publicContent($published);
check(str_contains($publicHtml, '&lt;script&gt;synthetic&lt;/script&gt;') && !str_contains($publicHtml, '<script>synthetic</script>'), 'public caption escaped');
check(!str_contains($publicHtml, $root) && !str_contains($publicHtml, $foreign['id']), 'public markup has no local path or foreign asset');
check(!str_contains($publicHtml, '/thumb 320w'), 'responsive thumbnail uses real dimensions');
$managerHtml = $admin->manager($id);
check(str_contains($managerHtml, '&lt;script&gt;synthetic&lt;/script&gt;') && !str_contains($managerHtml, '<script>synthetic</script>'), 'manager caption escaped');
check(str_contains($managerHtml, '&lt;svg onload=') && !str_contains($managerHtml, '<svg onload='), 'album title is escaped in manager');
check(str_contains($publicHtml, '&lt;svg onload=') && !str_contains($publicHtml, '<svg onload='), 'album title is escaped in public markup');
check(str_contains($managerHtml, 'multipart/form-data'), 'upload form supports multipart fallback');
check(!str_contains($reader->manager($id), 'value="album-image"') && !str_contains($reader->manager($id), 'value="album-publish"'), 'reader rendering omits mutation controls');
$admin->action(['operation' => 'album-image', 'id' => $id, 'version' => $publishedVersion, 'image_id' => $images[0]['id'], 'caption' => 'UNPUBLISHED_CAPTION_' . $run, 'tags' => 'UNPUBLISHED_TAG'], []);
$page = $pages->get($id);
check($pages->publicPage($page['slug'])['gallery_items'][0]['caption'] === $caption, 'draft caption is not public');
check($pages->publicPage($page['slug'])['gallery_items'][0]['tags'] === ['Musik', 'Ort'], 'draft tags are not public');
check(!str_contains($admin->publicContent(), 'UNPUBLISHED_CAPTION_' . $run), 'overview uses published captions');
check($pages->generation() === $generation + 1, 'draft metadata does not invalidate public cache');
$admin->action(['operation' => 'album-move', 'id' => $id, 'version' => $page['version'], 'image_id' => $images[1]['id'], 'direction' => 'up'], []);
$page = $pages->get($id);
check($page['media_ids'] === [$images[1]['id'], $images[0]['id']], 'move changes draft order');
check($pages->publicPage($page['slug'])['media_ids'] === [$images[0]['id'], $images[1]['id']], 'draft movement leaves public order');
check($page['gallery_items'][1]['caption'] === 'UNPUBLISHED_CAPTION_' . $run, 'metadata follows moved image');
$admin->action(['operation' => 'album-remove', 'id' => $id, 'version' => $page['version'], 'image_id' => $images[1]['id']], []);
$page = $pages->get($id);
check($page['media_ids'] === [$images[0]['id']] && count($page['gallery_items']) === 1, 'remove updates membership and metadata');
check($media->publicVariant($images[1]['id'], 'large') !== null, 'draft removal keeps published media visible');
check($media->variant($images[1]['id'], 'large')['width'] === 96, 'removed image remains in private media library');
$admin->action(['operation' => 'album-publish', 'id' => $id, 'version' => $page['version']], []);
$page = $pages->get($id);
check($media->publicVariant($images[1]['id'], 'large') === null, 'publishing removal revokes last public reference');
check($pages->publicPage($page['slug'])['gallery_items'][0]['caption'] === 'UNPUBLISHED_CAPTION_' . $run, 'republish installs new captions');

// Published selection and sorting must not consult mutable draft title/order/template/slug.
$alphaId = $admin->action(['operation' => 'album-save', 'title' => 'ALPHA_' . $run, 'slug' => $run . '-alpha', 'nav_order' => 100], []);
$betaId = $admin->action(['operation' => 'album-save', 'title' => 'BETA_' . $run, 'slug' => $run . '-beta', 'nav_order' => 100], []);
$admin->action(['operation' => 'album-publish', 'id' => $alphaId, 'version' => 1], []);
$admin->action(['operation' => 'album-publish', 'id' => $betaId, 'version' => 1], []);
$alpha = $pages->get($alphaId);
$beta = $pages->get($betaId);
$alpha = $pages->save(array_replace($alpha, ['title' => 'ZULU_' . $run, 'nav_order' => 999]), $alpha['version']);
$overview = $admin->publicContent();
check(strpos($overview, 'ALPHA_' . $run) < strpos($overview, 'BETA_' . $run), 'draft title/order cannot reorder public albums');
check(!str_contains($overview, 'ZULU_' . $run), 'draft title does not render publicly');
$alpha = $pages->save(array_replace($alpha, ['template' => 'standard']), $alpha['version']);
check(str_contains($admin->publicContent(), 'ALPHA_' . $run), 'draft template cannot hide published gallery');
$beta = $pages->save(array_replace($beta, ['slug' => 'galerie']), $beta['version']);
check(str_contains($admin->publicContent(), 'BETA_' . $run), 'draft overview slug cannot hide published album');
$standard = $pages->publish($standard['id'], $standard['version']);
$standard = $pages->save(array_replace($standard, ['template' => 'gallery']), $standard['version']);
check(!str_contains($admin->publicContent(), 'Ordinary page'), 'draft gallery template cannot add published ordinary page');

// Publisher-only is a supported independent capability in Pages and in Gallery's UI.
$publisherId = $run . '-publisher';
Db::run($db, 'INSERT INTO native_users(id,club_id,name) VALUES(?,?,?)', [$publisherId, $club, 'Synthetische Freigabe']);
foreach (['read', 'publish'] as $cap) Db::run($db, 'INSERT INTO native_grants(actor_id,module,capability) VALUES(?,?,?)', [$publisherId, 'website', $cap]);
$publisher = new Gallery($db, new Access($db, $publisherId), $store);
check(str_contains($publisher->manager($id), 'value="album-publish"'), 'publisher-only manager offers publication');
$v = $pages->get($id)['version'];
accepted(fn () => $publisher->action(['operation' => 'album-publish', 'id' => $id, 'version' => $v], []), 'publisher-only can publish');
$v = $pages->get($id)['version'];
accepted(fn () => $publisher->action(['operation' => 'album-unpublish', 'id' => $id, 'version' => $v], []), 'publisher-only can unpublish');
rejects(fn () => $publisher->action(['operation' => 'album-image', 'id' => $id, 'version' => $pages->get($id)['version'], 'image_id' => $images[0]['id'], 'caption' => 'Forbidden'], []), DomainException::class, 'publisher-only cannot edit captions');

// Deterministic interleaving closes the gap between Gallery preflight and atomic Pages save.
$config = TestSupport::config();
$raceDb = new GalleryBeforeTransactionPDO('mysql:host=127.0.0.1;port=13371;dbname=' . $config['database'] . ';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$raceGallery = new Gallery($raceDb, new Access($raceDb, $actors['admin']), $store);
$page = $pages->get($id);
$v = $page['version'];
$raceDb->beforeTransaction = function () use ($admin, $id, $v, $images): void {
    $admin->action(['operation' => 'album-image', 'id' => $id, 'version' => $v, 'image_id' => $images[0]['id'], 'caption' => 'WINNING_CAPTION'], []);
};
rejects(fn () => $raceGallery->action(['operation' => 'album-image', 'id' => $id, 'version' => $v, 'image_id' => $images[0]['id'], 'caption' => 'LOSING_CAPTION'], []), Conflict::class, 'interleaved stale caption save rejected');
check($pages->get($id)['version'] === $v + 1 && $pages->get($id)['gallery_items'][0]['caption'] === 'WINNING_CAPTION', 'interleaved winner is not overwritten');
$raceActor = $run . '-revoked';
Db::run($db, 'INSERT INTO native_users(id,club_id,name) VALUES(?,?,?)', [$raceActor, $club, 'Synthetischer Rechtewechsel']);
foreach (['read', 'edit'] as $cap) Db::run($db, 'INSERT INTO native_grants(actor_id,module,capability) VALUES(?,?,?)', [$raceActor, 'website', $cap]);
$revoked = new Gallery($raceDb, new Access($raceDb, $raceActor), $store);
$raceDb->beforeTransaction = fn () => Db::run($db, 'DELETE FROM native_grants WHERE actor_id=? AND module=? AND capability=?', [$raceActor, 'website', 'edit']);
$v = $pages->get($id)['version'];
rejects(fn () => $revoked->action(['operation' => 'album-image', 'id' => $id, 'version' => $v, 'image_id' => $images[0]['id'], 'caption' => 'REVOKED_CAPTION'], []), DomainException::class, 'edit capability rechecked inside transaction');
check($pages->get($id)['version'] === $v, 'revoked edit leaves version unchanged');

// An asset shared with another published album remains public until its last reference ends.
$sharedId = $admin->action(['operation' => 'album-save', 'title' => 'Shared synthetic album', 'slug' => $run . '-shared'], []);
$admin->action(['operation' => 'album-attach', 'id' => $sharedId, 'version' => 1, 'images' => [$images[0]['id']]], []);
$admin->action(['operation' => 'album-publish', 'id' => $sharedId, 'version' => 2], []);
$v = $pages->get($id)['version'];
$admin->action(['operation' => 'album-unpublish', 'id' => $id, 'version' => $v], []);
check($pages->publicPage('galerie/' . $run) === null, 'unpublish removes album route');
check($media->publicVariant($images[0]['id'], 'large') !== null, 'shared published reference keeps asset public');
$admin->action(['operation' => 'album-unpublish', 'id' => $sharedId, 'version' => 3], []);
check($media->publicVariant($images[0]['id'], 'large') === null, 'last unpublish revokes public asset');
check($media->variant($images[0]['id'], 'large')['width'] === 96, 'unpublish preserves private media');

foreach ($failures as $failure) fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
echo 'Native gallery: ' . ($checks - count($failures)) . '/' . $checks . ' checks passed; ' . count($failures) . ' failures.' . PHP_EOL;
exit($failures ? 1 : 0);
