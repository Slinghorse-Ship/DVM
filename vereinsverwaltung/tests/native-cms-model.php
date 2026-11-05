<?php
declare(strict_types=1);
require dirname(__DIR__) . '/native/bootstrap.php';
require_once dirname(__DIR__) . '/native/TestSupport.php';

use Kulturhus\Native\Cms\{Conflict, CustomFields, DataModel, Media, Pages, Schema, Templates};
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Native\TestSupport;

$checks = 0;
function modelCheck(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    $checks++;
}
function modelReject(callable $callback, string $class, string $label): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        modelCheck($error instanceof $class, $label . ' (' . get_class($error) . ')');
        return;
    }
    throw new RuntimeException('FAIL: expected rejection: ' . $label);
}

$db = TestSupport::db();
$actors = TestSupport::seed($db);
Schema::install($db);
$adminAccess = new Access($db, $actors['admin']);
$model = new DataModel($db, $adminAccess);
$editorAccess = new Access($db, $actors['editor']);
$editorModel = new DataModel($db, $editorAccess);
$readerModel = new DataModel($db, new Access($db, $actors['reader']));
$outsideAccess = new Access($db, $actors['outsider']);
$outsideModel = new DataModel($db, $outsideAccess);
$pages = new Pages($db, $adminAccess);
$editor = new Pages($db, $editorAccess);
$outsidePages = new Pages($db, $outsideAccess);
$prefix = 'm' . bin2hex(random_bytes(5));
$slug = 'model-' . $prefix;
$templateKey = $prefix . '_article';
$key = static fn (string $suffix): string => $prefix . '_' . $suffix;
$fieldInputs = [
    'headline' => ['type' => 'text', 'required' => true, 'min' => 3, 'max' => 40],
    'notes' => ['type' => 'textarea', 'max' => 400],
    'seats' => ['type' => 'integer', 'min' => 0, 'max' => 500],
    'cost' => ['type' => 'decimal', 'min' => '0', 'max' => '999999999999999999.999999'],
    'enabled' => ['type' => 'boolean', 'required' => true],
    'date' => ['type' => 'date', 'min' => '2026-01-01', 'max' => '2030-12-31'],
    'url' => ['type' => 'url'],
    'email' => ['type' => 'email'],
    'category' => ['type' => 'select', 'options' => ['Musik', 'Theater']],
    'related' => ['type' => 'page-reference'],
    'poster' => ['type' => 'media-reference'],
];
modelCheck(count(array_filter($model->templates(), fn ($t) => in_array($t['key'], ['standard', 'event', 'gallery'], true))) === 3, 'builtin templates retained');
modelReject(fn () => $editorModel->saveField(['key' => $key('denied'), 'label' => 'Denied', 'type' => 'text']), DomainException::class, 'editor cannot manage schema');
modelReject(fn () => $readerModel->saveTemplate(['key' => $key('denied'), 'label' => 'Denied']), DomainException::class, 'reader cannot manage templates');
$definitions = [];
foreach ($fieldInputs as $suffix => $definition) {
    $definitions[$suffix] = $model->saveField($definition + ['key' => $key($suffix), 'label' => ucfirst($suffix)]);
    modelCheck($definitions[$suffix]['version'] === 1, 'versioned field ' . $suffix);
}
$template = $model->saveTemplate(['key' => $templateKey, 'label' => 'Redaktionelle Veranstaltung', 'renderer' => 'event', 'fields' => array_map($key, array_keys($fieldInputs))]);
modelCheck($template['version'] === 1 && $readerModel->template($templateKey)['renderer'] === 'event', 'readable declarative custom template');
modelReject(fn () => $outsideModel->field($key('headline')), RuntimeException::class, 'cross club field hidden');
modelReject(fn () => $outsideModel->template($templateKey), RuntimeException::class, 'cross club template hidden');
modelCheck($outsideModel->fieldRevisions($key('headline')) === [], 'cross club schema history empty');
$foreignField = $outsideModel->saveField(['key' => $key('foreign'), 'label' => 'Foreign', 'type' => 'text']);
modelReject(fn () => $model->saveTemplate(['key' => $key('foreign_template'), 'label' => 'Foreign', 'fields' => [$foreignField['key']]]), InvalidArgumentException::class, 'template cannot bind foreign field');
modelReject(fn () => $model->saveField($definitions['headline'], 0), Conflict::class, 'stale field update denied');
modelReject(fn () => $model->saveTemplate($template, 0), Conflict::class, 'stale template update denied');
foreach ([['key' => '../code'], ['type' => 'php'], ['required' => 'false'], ['min' => 20, 'max' => 2], ['options' => ['unused']], ['code' => 'system("x")']] as $bad) {
    modelReject(fn () => $model->saveField(array_replace(['key' => $key('bad'), 'label' => 'Bad', 'type' => 'text'], $bad)), InvalidArgumentException::class, 'unsafe field definition rejected');
}
foreach ([['renderer' => '../../evil.php'], ['php' => '<?php echo 1;'], ['fields' => [$key('notes'), $key('notes')]], ['fields' => [$key('absent')]]] as $bad) {
    modelReject(fn () => $model->saveTemplate(array_replace(['key' => $key('bad_template'), 'label' => 'Bad', 'renderer' => 'standard'], $bad)), InvalidArgumentException::class, 'unsafe template rejected');
}

$target = $pages->save(['title' => 'Referenzseite', 'slug' => $slug . '-target']);
$target = $pages->publish($target['id'], 1);
$foreignPage = $outsidePages->save(['title' => 'Fremde Referenz', 'slug' => $slug]);
$root = dirname(__DIR__, 2) . '/.local/native-cms-model-tests/' . $prefix;
mkdir($root, 0700, true);
$image = imagecreatetruecolor(32, 16);
imagepng($image, $root . '/source.png');
unset($image);
$media = new Media($db, $adminAccess, $root . '/media');
$asset = $media->upload($root . '/source.png', 'model.png', 'Synthetisches Modellbild');
$foreignAsset = (new Media($db, $outsideAccess, $root . '/media'))->upload($root . '/source.png', 'foreign.png', 'Fremdes Modellbild');
$values = [$key('headline') => 'Kultur & Begegnung', $key('notes') => '<script>escaped by renderer</script>', $key('seats') => '25', $key('cost') => '0012.500', $key('enabled') => '0', $key('date') => '2026-11-14', $key('url') => 'https://example.invalid/kultur', $key('email') => 'editor@example.invalid', $key('category') => 'Musik', $key('related') => $target['id'], $key('poster') => $asset['id']];
$page = $editor->save(['title' => 'Custom 100%_ exact', 'slug' => $slug, 'template' => $templateKey, 'body' => 'Own typed model', 'custom_fields' => $values, 'parent_id' => $target['id'], 'event_start' => '2026-11-14T19:00']);
modelCheck($page['custom_fields'][$key('seats')] === 25, 'integer normalized');
modelCheck($page['custom_fields'][$key('cost')] === '12.5', 'decimal exact canonical string');
modelCheck($page['custom_fields'][$key('enabled')] === false, 'required false boolean retained');
modelCheck($page['template_definition']['renderer'] === 'event' && $page['field_definitions'][$key('headline')]['version'] === 1, 'page captures definition snapshots');
modelCheck($pages->publicPage($slug) === null, 'custom draft remains private');
$page = $pages->publish($page['id'], 1);
modelCheck($pages->publicPage($slug)['custom_fields'][$key('headline')] === 'Kultur & Begegnung', 'custom published snapshot');
modelCheck($media->publicVariant($asset['id'], 'thumb') !== null, 'custom media reference grants published asset');
$generation = $pages->generation();
$changedField = $model->saveField(array_replace($definitions['headline'], ['label' => 'Neue Bezeichnung', 'max' => 80]), 1);
modelCheck($changedField['version'] === 2 && count($model->fieldRevisions($key('headline'))) === 2, 'field history immutable');
modelCheck($model->fieldRevisions($key('headline'))[1]['payload']['label'] === 'Headline', 'old field schema retained');
modelCheck($pages->generation() === $generation, 'schema edits do not invalidate public snapshot');
modelCheck($pages->publicPage($slug)['field_definitions'][$key('headline')]['label'] === 'Headline', 'published field definitions frozen');
modelReject(fn () => $pages->publish($page['id'], 2), Conflict::class, 'changed field schema requires deliberate draft resave');
$page = $editor->save(array_replace($page, ['custom_fields' => array_replace($page['custom_fields'], [$key('headline') => 'Neue Redaktion'])]), 2);
modelCheck($page['field_definitions'][$key('headline')]['version'] === 2 && $pages->publicPage($slug)['custom_fields'][$key('headline')] === 'Kultur & Begegnung', 'new model only changes draft');
$template = $model->saveTemplate(array_replace($template, ['label' => 'Neue Template-Bezeichnung']), 1);
modelReject(fn () => $pages->publish($page['id'], 3), Conflict::class, 'changed template schema requires resave');
modelCheck(count($model->templateRevisions($templateKey)) === 2, 'template history retained');
$page = $editor->save($page, 3);
$page = $pages->publish($page['id'], 4);
modelCheck($pages->publicPage($slug)['template_definition']['version'] === 2, 'resaved model can publish');
modelCheck($pages->revisions($page['id'])[4]['payload']['field_definitions'][$key('headline')]['version'] === 1, 'page revision carries historical model');

$invalidValues = [
    ['headline', 'x'], ['headline', null], ['headline', str_repeat('x', 81)], ['notes', ['array']],
    ['seats', '1.2'], ['seats', '501'], ['cost', 1.25], ['cost', '1e3'], ['cost', '-0.01'], ['cost', '1.0000001'],
    ['enabled', 'false'], ['date', '2026-02-30'], ['date', '2025-01-01'], ['url', 'javascript:alert(1)'],
    ['url', 'https://user:secret@example.invalid'], ['email', 'invalid'], ['category', 'Opera'],
    ['related', $foreignPage['id']], ['poster', $foreignAsset['id']], ['related', str_repeat('a', 32)],
];
foreach ($invalidValues as [$suffix, $value]) {
    modelReject(fn () => $editor->save(array_replace($page, ['custom_fields' => array_replace($page['custom_fields'], [$key($suffix) => $value])]), 5), InvalidArgumentException::class, 'invalid custom value ' . $suffix);
}
modelReject(fn () => $editor->save(array_replace($page, ['custom_fields' => $page['custom_fields'] + ['unknown' => 'x']]), 5), InvalidArgumentException::class, 'unknown custom field rejected');
modelCheck($pages->get($page['id'])['version'] === 5, 'invalid values produce no revision');
modelReject(fn () => $outsidePages->save(['title' => 'Foreign model', 'slug' => $slug . '-foreign', 'template' => $templateKey]), RuntimeException::class, 'foreign model cannot be used');

$find = static fn (array $selectors): array => $pages->search([['field' => 'slug', 'op' => 'eq', 'value' => $slug], ...$selectors]);
foreach ([
    ['title', 'contains', '100%_'], ['template', 'eq', $templateKey], ['parent_id', 'eq', $target['id']], ['status', 'eq', 'published'],
    ['custom.' . $key('headline'), 'contains', 'Redaktion'], ['custom.' . $key('seats'), 'gte', 25], ['custom.' . $key('cost'), 'eq', '12.500'],
    ['custom.' . $key('enabled'), 'eq', false], ['custom.' . $key('date'), 'lt', '2027-01-01'], ['custom.' . $key('category'), 'in', ['Musik', 'Theater']],
    ['custom.' . $key('url'), 'eq', 'https://example.invalid/kultur'], ['custom.' . $key('email'), 'eq', 'editor@example.invalid'],
    ['custom.' . $key('related'), 'eq', $target['id']], ['custom.' . $key('poster'), 'eq', $asset['id']],
] as [$field, $op, $value]) {
    modelCheck(count($find([compact('field', 'op', 'value')])) === 1, 'typed selector ' . $field . '/' . $op);
}
modelCheck($find([['field' => 'title', 'op' => 'contains', 'value' => "%' OR 1=1 --"]]) === [], 'bound SQL injection value stays text');
modelCheck($outsidePages->search([['field' => 'template', 'op' => 'eq', 'value' => $templateKey]]) === [], 'selector tenant scope');
foreach ([
    [['field' => 'custom.x) OR 1=1', 'op' => 'eq', 'value' => 1]],
    [['field' => 'title', 'op' => 'raw', 'value' => '1=1']],
    [['field' => 'custom.' . $key('seats'), 'op' => 'contains', 'value' => '2']],
    [['field' => 'status', 'op' => 'eq', 'value' => 'everything']],
    [['field' => 'title', 'op' => 'in', 'value' => []]],
] as $bad) {
    modelReject(fn () => $pages->search($bad), InvalidArgumentException::class, 'unsafe selector rejected');
}
modelReject(fn () => $pages->search([], 501), InvalidArgumentException::class, 'search result bound');
modelReject(fn () => $pages->search([], 50, -1), InvalidArgumentException::class, 'negative selector offset');
modelCheck(count(array_filter($pages->children($target['id']), fn ($p) => $p['id'] === $page['id'])) === 1, 'children API');
modelReject(fn () => $pages->children($foreignPage['id']), RuntimeException::class, 'children foreign parent denied');
$tree = $pages->tree();
$branch = array_values(array_filter($tree, fn ($p) => $p['id'] === $target['id']));
modelCheck(count($branch) === 1 && $branch[0]['children'][0]['id'] === $page['id'], 'draft tree hierarchy');

$page = $editor->save(array_replace($page, ['custom_fields' => array_replace($page['custom_fields'], [$key('cost') => '999999999999999999.999998', $key('notes') => null, $key('poster') => null])]), 5);
modelCheck(count($find([['field' => 'custom.' . $key('cost'), 'op' => 'lt', 'value' => '999999999999999999.999999']])) === 1, 'decimal comparison retains exact final digit');
modelCheck($find([['field' => 'custom.' . $key('cost'), 'op' => 'eq', 'value' => '999999999999999999.999999']]) === [], 'decimal comparison does not round to false equality');
modelCheck(count($find([['field' => 'custom.' . $key('notes'), 'op' => 'eq', 'value' => null]])) === 1, 'optional field SQL null');
modelCheck(count($find([['field' => 'status', 'op' => 'eq', 'value' => 'unpublished_changes']])) === 1, 'draft versus published status selector');
$page = $pages->publish($page['id'], 6);
modelCheck($media->publicVariant($asset['id'], 'thumb') === null, 'published custom media removal revokes access');
$page = $editor->restore($page['id'], 1, 7);
modelCheck($page['custom_fields'][$key('headline')] === 'Kultur & Begegnung' && $page['field_definitions'][$key('headline')]['version'] === 1, 'restore retains historical typed values and original declarative model');
modelCheck($pages->publicPage($slug)['custom_fields'][$key('cost')] === '999999999999999999.999998', 'restore does not change public custom content');
$builtin = $model->template('gallery');
$builtin = $model->saveTemplate(array_replace($builtin, ['fields' => [$key('notes')]]), $builtin['version']);
$builtinPage = $editor->save(['title' => 'Extended built-in', 'slug' => $slug . '-builtin', 'template' => 'gallery', 'custom_fields' => [$key('notes') => 'Built-in extension']]);
modelCheck($builtinPage['template_definition']['renderer'] === 'gallery' && $builtinPage['custom_fields'][$key('notes')] === 'Built-in extension', 'builtin can gain custom fields without replacing renderer');

$album = $editor->save(['title' => 'Galerie mit Metadaten', 'slug' => $slug . '-album', 'template' => 'gallery', 'media_ids' => [$asset['id']], 'gallery_items' => [['id' => $asset['id'], 'caption' => 'Erste Beschriftung', 'tags' => ['Kultur', ' Musik ']]]]);
modelCheck($album['gallery_items'][0]['tags'] === ['Kultur', 'Musik'], 'gallery metadata UTF-8 normalization');
$album = $pages->publish($album['id'], 1);
$album = $editor->save(array_replace($album, ['gallery_items' => [['id' => $asset['id'], 'caption' => 'Unveröffentlichte Beschriftung', 'tags' => ['Entwurf']]]]), 2);
modelCheck($pages->publicPage($album['slug'])['gallery_items'][0]['caption'] === 'Erste Beschriftung', 'album draft captions stay private');
modelCheck($pages->publicPage($album['slug'])['gallery_items'][0]['tags'] === ['Kultur', 'Musik'], 'album draft tags stay private');
$album = $pages->publish($album['id'], 3);
modelCheck($pages->publicPage($album['slug'])['gallery_items'][0]['caption'] === 'Unveröffentlichte Beschriftung', 'album metadata publishes with revision');
modelCheck($pages->revisions($album['id'])[2]['payload']['gallery_items'][0]['caption'] === 'Erste Beschriftung', 'album historical metadata retained');
$baseGallery = ['title' => 'Gallery validation', 'slug' => 'gallery-validation', 'media_ids' => [$asset['id']]];
modelCheck(Templates::normalize($baseGallery)['gallery_items'] === [['id' => $asset['id'], 'caption' => '', 'tags' => []]], 'legacy media_ids create gallery metadata defaults');
foreach ([
    [['id' => $asset['id']], ['id' => $asset['id']]],
    [['id' => $asset['id'], 'caption' => str_repeat('x', 501), 'tags' => []]],
    [['id' => $asset['id'], 'caption' => '', 'tags' => array_fill(0, 21, 'Tag')]],
    [['id' => $asset['id'], 'caption' => '', 'tags' => [str_repeat('x', 51)]]],
    [['id' => $asset['id'], 'caption' => "bad\x00", 'tags' => []]],
    [['id' => $asset['id'], 'caption' => '', 'tags' => ['']]],
] as $items) {
    modelReject(fn () => Templates::normalize($baseGallery + ['gallery_items' => $items]), InvalidArgumentException::class, 'invalid gallery metadata rejected');
}
modelCheck(Templates::normalize(array_replace($baseGallery, ['media_ids' => [], 'gallery_items' => [['id' => $asset['id'], 'caption' => 'Removed']]]))['gallery_items'] === [], 'removed image metadata is removed compatibly');
$reordered = Templates::normalize(array_replace($baseGallery, ['media_ids' => [$foreignAsset['id'], $asset['id']], 'gallery_items' => [['id' => $asset['id'], 'caption' => 'Keep me']]]));
modelCheck($reordered['gallery_items'][0]['caption'] === '' && $reordered['gallery_items'][1]['caption'] === 'Keep me', 'gallery metadata follows media order with new-image defaults');

final class BeforeModelTransactionPDO extends PDO
{
    public ?Closure $beforeTransaction = null;
    public function beginTransaction(): bool
    {
        $callback = $this->beforeTransaction;
        $this->beforeTransaction = null;
        if ($callback) {
            $callback();
        }
        return parent::beginTransaction();
    }
}
$config = TestSupport::config();
$raceDb = new BeforeModelTransactionPDO('mysql:host=127.0.0.1;port=13371;dbname=' . $config['database'] . ';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$raceActor = $key('schema_race');
Db::run($db, 'INSERT INTO native_users (id,club_id,name) VALUES (?,?,?)', [$raceActor, 'synthetic-a', 'Synthetischer Schema-Rechtewechsel']);
Db::run($db, 'INSERT INTO native_grants (actor_id,module,capability) VALUES (?,\'website\',\'manage\')', [$raceActor]);
$raceDb->beforeTransaction = static function () use ($db, $raceActor): void {
    Db::run($db, 'DELETE FROM native_grants WHERE actor_id=? AND module=\'website\' AND capability=\'manage\'', [$raceActor]);
};
modelReject(fn () => (new DataModel($raceDb, new Access($raceDb, $raceActor)))->saveField(['key' => $key('revoked'), 'label' => 'Revoked schema', 'type' => 'text']), DomainException::class, 'schema manage revoked between preflight and transaction');
modelCheck(!Db::run($db, 'SELECT 1 FROM khcms_definitions WHERE definition_key=?', [$key('revoked')])->fetchColumn(), 'revoked schema management leaves no definition');

$largeKeys = [];
$largeValues = [];
for ($index = 0; $index < 6; $index++) {
    $largeKey = $key('large_' . $index);
    $model->saveField(['key' => $largeKey, 'label' => 'Large ' . $index, 'type' => 'textarea']);
    $largeKeys[] = $largeKey;
    $largeValues[$largeKey] = str_repeat('🎵', 100000);
}
$model->saveTemplate(['key' => $key('large'), 'label' => 'Bounded content', 'renderer' => 'standard', 'fields' => $largeKeys]);
modelReject(fn () => $editor->save(['title' => 'Too much content', 'slug' => $slug . '-large', 'template' => $key('large'), 'custom_fields' => $largeValues]), InvalidArgumentException::class, 'aggregate typed page size bound');
modelCheck(!Db::run($db, 'SELECT 1 FROM khcms_pages WHERE slug=?', [$slug . '-large'])->fetchColumn(), 'oversized page inserts nothing');
$simple = $editor->save(['title' => 'Authoritative model', 'slug' => $slug . '-snapshot', 'template' => $templateKey, 'custom_fields' => $values, 'template_definition' => ['renderer' => '../../arbitrary.php'], 'field_definitions' => []]);
modelCheck($simple['template_definition']['renderer'] === 'event' && count($simple['field_definitions']) === 11, 'client cannot forge authoritative model snapshots');
$selector = [['field' => 'slug', 'op' => 'starts_with', 'value' => $slug]];
$ordered = $pages->search($selector, 500);
modelCheck($pages->search($selector, 1, 1)[0]['id'] === $ordered[1]['id'], 'deterministic paginated selector order');

echo json_encode(['suite' => 'native-cms-model', 'checks' => $checks, 'status' => 'passed', 'driver' => 'mysql'], JSON_THROW_ON_ERROR) . PHP_EOL;
