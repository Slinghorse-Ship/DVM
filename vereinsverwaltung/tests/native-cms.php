<?php
declare(strict_types=1);

require dirname(__DIR__) . '/native/bootstrap.php';
require_once dirname(__DIR__) . '/native/TestSupport.php';

use Kulturhus\Native\Cms\{Conflict, Media, Pages, Schema, Templates};
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Native\TestSupport;

$checks = 0;
function check(bool $value, string $label): void
{
    global $checks;
    if (!$value) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    $checks++;
}
function rejects(callable $callback, string $class, string $label): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        check($error instanceof $class, $label . ' (' . get_class($error) . ')');
        return;
    }
    throw new RuntimeException('FAIL: expected rejection: ' . $label);
}

/** Deterministically commits a second connection's change between preflight and transaction. */
final class BeforeTransactionPDO extends PDO
{
    public ?Closure $beforeTransaction = null;

    public function beginTransaction(): bool
    {
        $callback = $this->beforeTransaction;
        $this->beforeTransaction = null;
        if ($callback !== null) {
            $callback();
        }
        return parent::beginTransaction();
    }
}

$db = TestSupport::db();
$actors = TestSupport::seed($db);
Schema::install($db);
Schema::install($db);
$adminAccess = new Access($db, $actors['admin']);
$admin = new Pages($db, $adminAccess);
$editor = new Pages($db, new Access($db, $actors['editor']));
$reader = new Pages($db, new Access($db, $actors['reader']));
$outsider = new Pages($db, new Access($db, $actors['outsider']));
$prefix = 'cms-test-' . bin2hex(random_bytes(6));
$generation = $admin->generation();
$page = $editor->save(['title' => 'Kultur im Haus', 'slug' => $prefix, 'body' => 'Synthetischer Inhalt', 'seo_title' => 'Kulturhus SEO', 'nav_label' => 'Start', 'nav_order' => 3]);
check($page['version'] === 1, 'new version');
check($page['published_revision'] === null, 'new unpublished');
check($reader->get($page['id'])['body'] === 'Synthetischer Inhalt', 'read allowed');
check($admin->publicPage($prefix) === null, 'draft private');
check($admin->generation() === $generation, 'draft does not invalidate public cache');
check(count(array_filter($admin->list(), fn ($p) => $p['id'] === $page['id'])) === 1, 'list own page');
check(count(array_filter($outsider->list(), fn ($p) => $p['id'] === $page['id'])) === 0, 'list tenant scope');
rejects(fn () => $outsider->get($page['id']), RuntimeException::class, 'tenant get denied');
rejects(fn () => $outsider->revisions($page['id']), RuntimeException::class, 'tenant revisions denied');
rejects(fn () => $reader->save($page, 1), DomainException::class, 'reader cannot edit');
rejects(fn () => $editor->publish($page['id'], 1), DomainException::class, 'editor cannot publish');
rejects(fn () => $admin->publish($page['id'], 0), Conflict::class, 'publish expected version');
rejects(fn () => $editor->save(['title' => 'Konflikt', 'slug' => $prefix]), Conflict::class, 'duplicate slug');
rejects(fn () => $editor->save(['title' => 'Falsche Version', 'slug' => $prefix . '-new'], 4), Conflict::class, 'new page stale expected version');
$page = $admin->publish($page['id'], 1);
check($page['version'] === 2 && $page['published_revision'] === 2, 'publish creates revision');
check($admin->generation() === $generation + 1, 'publish invalidates cache');
check($admin->publicPage($prefix)['seo_title'] === 'Kulturhus SEO', 'published SEO');
check(!$page['has_unpublished_changes'], 'published equals draft');
check(count(array_filter($admin->navigation(), fn ($n) => $n['id'] === $page['id'] && $n['label'] === 'Start')) === 1, 'published navigation');
$page = $editor->save(array_replace($page, ['slug' => $prefix . '-changed', 'body' => '<script>draft</script>', 'nav_label' => 'Entwurf', 'seo_title' => 'Entwurf SEO']), 2);
check($page['version'] === 3 && $page['has_unpublished_changes'], 'draft advances independently');
check($admin->generation() === $generation + 1, 'draft after publish keeps cache generation');
check($admin->publicPage($prefix)['body'] === 'Synthetischer Inhalt', 'published body unchanged');
check($admin->publicPage($prefix . '-changed') === null, 'draft slug private');
check(count(array_filter($admin->navigation(), fn ($n) => $n['id'] === $page['id'] && $n['label'] === 'Start')) === 1, 'draft menu private');
rejects(fn () => $editor->save($page, 2), Conflict::class, 'stale writer rejected');
rejects(fn () => $admin->publish($page['id'], 2), Conflict::class, 'stale publisher rejected');
rejects(fn () => $editor->save(['title' => 'Alte URL', 'slug' => $prefix]), Conflict::class, 'published old slug reserved');
$page = $editor->restore($page['id'], 1, 3);
check($page['version'] === 4 && $page['body'] === 'Synthetischer Inhalt', 'restore creates draft');
check($admin->publicPage($prefix)['version'] === 2, 'restore leaves publication');
check($admin->generation() === $generation + 1, 'restore does not invalidate');
$revisions = $reader->revisions($page['id']);
check(count($revisions) === 4 && $revisions[0]['action'] === 'restore', 'history complete');
check($revisions[1]['payload']['body'] === '<script>draft</script>', 'immutable snapshot');
rejects(fn () => $editor->restore($page['id'], 99999, 4), RuntimeException::class, 'unknown revision');
check($admin->get($page['id'])['version'] === 4, 'failed restore atomic');
rejects(fn () => $editor->save(array_replace($page, ['parent_id' => $page['id']]), 4), InvalidArgumentException::class, 'self cycle');
$foreign = $outsider->save(['title' => 'Fremder Verein', 'slug' => $prefix]);
check($foreign['id'] !== $page['id'], 'same slug other tenant allowed');
rejects(fn () => $editor->save(array_replace($page, ['parent_id' => $foreign['id']]), 4), RuntimeException::class, 'cross tenant parent');
$child = $editor->save(['title' => 'Unterseite', 'slug' => $prefix . '-child', 'parent_id' => $page['id']]);
rejects(fn () => $editor->save(array_replace($page, ['parent_id' => $child['id']]), 4), InvalidArgumentException::class, 'indirect hierarchy cycle');
$child = $admin->publish($child['id'], 1);
rejects(fn () => $admin->unpublish($page['id'], 4), Conflict::class, 'published children protect parent');
$child = $admin->unpublish($child['id'], 2);
check($admin->publicPage($child['slug']) === null, 'unpublish hides child');
$page = $admin->unpublish($page['id'], 4);
check($page['version'] === 5 && $admin->publicPage($prefix) === null, 'unpublish revision and removal');
check(count(array_filter($admin->navigation(), fn ($n) => $n['id'] === $page['id'])) === 0, 'unpublish invalidates menu');
$pendingParent = $editor->save(['title' => 'Entwurf-Elternseite', 'slug' => $prefix . '-pending']);
$pendingChild = $editor->save(['title' => 'Entwurf-Unterseite', 'slug' => $prefix . '-pending-child', 'parent_id' => $pendingParent['id']]);
rejects(fn () => $admin->publish($pendingChild['id'], 1), Conflict::class, 'parent publication required');
foreach ([['slug' => '../bad'], ['slug' => 'UPPER'], ['slug' => []], ['template' => 'php'], ['template' => []], ['media_ids' => ['../file']], ['nav_order' => '1.5'], ['noindex' => 'false'], ['title' => ''], ['body' => "bad\x00text"], ['event_start' => '2026-02-30T12:00'], ['event_start' => []]] as $bad) {
    rejects(fn () => Templates::normalize(array_replace(['title' => 'Test', 'slug' => 'safe'], $bad)), InvalidArgumentException::class, 'content validation');
}
check(Templates::normalize(['title' => 'Veranstaltung', 'slug' => 'event', 'template' => 'event', 'event_start' => '2026-10-17T18:00'])['event_timezone'] === 'Europe/Berlin', 'event timezone explicit');

$root = dirname(__DIR__, 2) . '/.local/native-cms-tests/' . $prefix;
mkdir($root, 0700, true);
$source = $root . '/source.png';
$image = imagecreatetruecolor(1200, 800);
imagefill($image, 0, 0, imagecolorallocate($image, 30, 110, 70));
imagepng($image, $source);
unset($image);
file_put_contents($source, '<?php synthetic_payload(); ?>', FILE_APPEND);
$media = new Media($db, $adminAccess, $root . '/media');
$readerMedia = new Media($db, new Access($db, $actors['reader']), $root . '/media');
$foreignMedia = new Media($db, new Access($db, $actors['outsider']), $root . '/media');
rejects(fn () => $readerMedia->upload($source, 'image.png', 'Leserbild'), DomainException::class, 'media upload permission');
check($media->upload($source, 'image.png', '')['alt'] === 'image', 'image uploads work without required metadata and retain a filename label');
$asset = $media->upload($source, '../../unsafe.php.png', 'Synthetische grüne Bildfläche');
check($asset['mime'] === 'image/png' && count($asset['variants']) === 4, 'image reencoding variants');
check($asset['original_name'] === 'unsafe.php.png', 'filename only metadata');
check($media->variant($asset['id'], 'thumb')['width'] === 320, 'thumbnail bounded');
check($media->variant($asset['id'], 'large')['width'] === 1200, 'no upscaling');
check(!str_contains(file_get_contents($media->variant($asset['id'], 'original')['path']), 'synthetic_payload'), 'trailing payload stripped');
check($media->publicVariant($asset['id'], 'thumb') === null, 'draft media private');
rejects(fn () => $foreignMedia->variant($asset['id'], 'thumb'), RuntimeException::class, 'media tenant scope');
rejects(fn () => $media->variant($asset['id'], '../original'), InvalidArgumentException::class, 'media variant traversal');
rejects(fn () => $media->variant('../' . $asset['id'], 'thumb'), InvalidArgumentException::class, 'media id traversal');
file_put_contents($root . '/script.jpg', '<?php echo "not an image";');
rejects(fn () => $media->upload($root . '/script.jpg', 'photo.jpg', 'Falsches Bild'), InvalidArgumentException::class, 'fake extension rejected');
file_put_contents($root . '/image.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
rejects(fn () => $media->upload($root . '/image.svg', 'image.svg', 'SVG-Bild'), InvalidArgumentException::class, 'SVG rejected');
rejects(fn () => new Media($db, $adminAccess, dirname(__DIR__) . '/public'), InvalidArgumentException::class, 'webroot rejected');
rejects(fn () => $outsider->save(['title' => 'Fremdes Bild', 'slug' => $prefix . '-foreign-media', 'media_ids' => [$asset['id']]]), InvalidArgumentException::class, 'cross tenant media reference');
$gallery = $editor->save(['title' => 'Galerie', 'slug' => $prefix . '-gallery', 'template' => 'gallery', 'media_ids' => [$asset['id']]]);
$gallery = $admin->publish($gallery['id'], 1);
check($media->publicVariant($asset['id'], 'medium')['mime'] === 'image/png', 'published media accessible');
$gallery = $editor->save(array_replace($gallery, ['media_ids' => []]), 2);
check($media->publicVariant($asset['id'], 'medium') !== null, 'draft media removal does not change public asset');
$gallery = $admin->publish($gallery['id'], 3);
check($media->publicVariant($asset['id'], 'medium') === null, 'publication removes old public asset');
check(count($foreignMedia->list()) === 0, 'foreign media list isolated');
$inactive = 'inactive-' . bin2hex(random_bytes(8));
Db::run($db, 'INSERT INTO native_users (id,club_id,name,active) VALUES (?,?,?,0)', [$inactive, 'synthetic-a', 'Inaktiver Testnutzer']);
rejects(fn () => (new Pages($db, new Access($db, $inactive)))->list(), DomainException::class, 'inactive actor rejected');

$config = TestSupport::config();
$raceDb = new BeforeTransactionPDO('mysql:host=127.0.0.1;port=13371;dbname=' . $config['database'] . ';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$raceActor = 'race-' . bin2hex(random_bytes(8));
Db::run($db, 'INSERT INTO native_users (id,club_id,name) VALUES (?,?,?)', [$raceActor, 'synthetic-a', 'Synthetischer Rechtewechsel']);
foreach (['read', 'edit'] as $capability) {
    Db::run($db, 'INSERT INTO native_grants (actor_id,module,capability) VALUES (?,\'website\',?)', [$raceActor, $capability]);
}
$raceAccess = new Access($raceDb, $raceActor);
$revoke = static function () use ($db, $raceActor): void {
    Db::run($db, 'DELETE FROM native_grants WHERE actor_id=? AND module=\'website\' AND capability=\'edit\'', [$raceActor]);
};
$raceDb->beforeTransaction = $revoke;
rejects(fn () => (new Pages($raceDb, $raceAccess))->save(['title' => 'Widerrufen', 'slug' => $prefix . '-revoked']), DomainException::class, 'edit revocation between preflight and transaction');
check(!Db::run($db, 'SELECT 1 FROM khcms_pages WHERE slug=?', [$prefix . '-revoked'])->fetchColumn(), 'revoked save inserts nothing');
Db::run($db, 'INSERT INTO native_grants (actor_id,module,capability) VALUES (?,\'website\',\'edit\')', [$raceActor]);
$raceMedia = new Media($raceDb, $raceAccess, $root . '/race-media');
$raceDb->beforeTransaction = $revoke;
rejects(fn () => $raceMedia->upload($source, 'revoked.png', 'Widerrufenes Bild'), DomainException::class, 'upload rechecks permission after processing');
check(!Db::run($db, 'SELECT 1 FROM khcms_media WHERE created_by=?', [$raceActor])->fetchColumn(), 'revoked media inserts nothing');
check(count(glob($root . '/race-media/' . hash('sha256', 'synthetic-a') . '/*')) === 0, 'revoked media files cleaned');
Db::run($db, 'INSERT INTO native_grants (actor_id,module,capability) VALUES (?,\'website\',\'edit\')', [$raceActor]);
$raceDb->beforeTransaction = static function () use ($db, $raceActor): void {
    Db::run($db, 'UPDATE native_users SET club_id=? WHERE id=?', ['synthetic-b', $raceActor]);
};
rejects(fn () => $raceMedia->upload($source, 'moved.png', 'Vereinswechsel beim Upload'), Conflict::class, 'upload rejects changed club during processing');
check(!Db::run($db, 'SELECT 1 FROM khcms_media WHERE created_by=?', [$raceActor])->fetchColumn(), 'club change inserts no media');
check(count(glob($root . '/race-media/' . hash('sha256', 'synthetic-a') . '/*')) === 0, 'club change media files cleaned');

echo json_encode(['suite' => 'native-cms', 'checks' => $checks, 'status' => 'passed', 'storage' => 'isolated local MySQL and synthetic image files'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
