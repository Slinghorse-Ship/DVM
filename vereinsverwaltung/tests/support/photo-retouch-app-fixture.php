<?php declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
umask(0077);
require dirname(__DIR__, 2).'/native/bootstrap.php';
require dirname(__DIR__, 2).'/src/bootstrap.php';
require __DIR__.'/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Native\Cms\{Media, MediaMetadata};
use Kulturhus\Native\Integration\ManagerBridge;
$c = TestSupport::config();
$standalone = ($c['label'] ?? '') === 'demo';
if (($c['owner'] ?? '') !== 'cloud-onboarding-synthetic' || !in_array($c['label'] ?? '', ['demo', 'integrated-manager'], true)) throw new RuntimeException('Owned synthetic configuration required.');
$db = TestSupport::db();
$access = $standalone ? new Access($db, 'admin') : (new ManagerBridge($db, 'manager-admin', 'native', static fn() => null))->access();
$runtime = dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/'.($standalone ? 'runtime-'.$c['database'] : 'integrated-manager-runtime');
$file = dirname(__DIR__, 3).'/.local/browser-tests/photo-retouch-'.($standalone ? 'standalone' : 'integrated').'.json';
$filter = static function (string $table, array $f): array {
    $entry = $f['original_keys'][$table];
    if (!$entry['values']) return [' WHERE 0=1', []];
    $args = [];
    foreach ($entry['values'] as $row) array_push($args, ...$row);
    return [' WHERE (`'.implode('`,`', $entry['columns']).'`) IN ('.implode(',', array_fill(0, count($entry['values']), '('.implode(',', array_fill(0, count($entry['columns']), '?')).')')).')', $args];
};
if (($argv[1] ?? 'prepare') === 'check') {
    $f = json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
    if (ownOriginalDatabaseHash($db, array_keys($f['original_keys']), fn($t) => $filter($t, $f)) !== $f['original_hash']) throw new RuntimeException('An original database row changed.');
    foreach ($f['pixels'] as $path => $hash) if (!is_file($path) || hash_file('sha256', $path) !== $hash) throw new RuntimeException('An original stored file changed.');
    foreach ($f['sources'] as $source) if (hash_file('sha256', $source['path']) !== $source['sha256']) throw new RuntimeException('An own selected source file changed.');
    $rows = Db::run($db, 'SELECT id, original_name FROM khcms_media WHERE club_id=? AND original_name LIKE ?', [$access->club(), $f['prefix'].'-%'])->fetchAll();
    if (count($rows) !== 3) throw new RuntimeException('Exactly three new own retouched photos required.');
    $media = new Media($db, $access, $runtime.'/media');
    foreach ($rows as $row) {
        $tool = null;
        foreach (['blur', 'pixelate', 'redeye'] as $candidate) if ($row['original_name'] === $f['prefix'].'-'.$candidate.'-bearbeitet.png') $tool = $candidate;
        if ($tool === null) throw new RuntimeException('Exact new owned retouch names required.');
        $metadata = (new MediaMetadata($db, $access))->get($row['id']);
        if ($metadata['version'] !== 0 || $metadata['rights_state'] !== 'unknown' || $metadata['has_proof'] || $media->publicVariant($row['id'], 'thumb') !== null) throw new RuntimeException('New retouch must stay private without invented rights.');
        $asset = $media->variant($row['id'], 'original');
        $size = getimagesize($asset['path']);
        if ([$size[0], $size[1], $size['mime']] !== [240, 160, 'image/png']) throw new RuntimeException('Actual stored retouch PNG dimensions required.');
        $image = imagecreatefrompng($asset['path']);
        $at = static fn($x, $y) => array_values(array_intersect_key(imagecolorsforindex($image, imagecolorat($image, $x, $y)), array_flip(['red', 'green', 'blue', 'alpha'])));
        if ($at(10, 10) !== [237, 37, 37, 0]) throw new RuntimeException('Unpainted original source pixels changed.');
        if ($tool === 'blur') { $p = $at(119, 80); if ($p[0] <= 65 || $p[0] >= 220 || $p[2] <= 65 || $p[2] >= 220 || $p[3] !== 0) throw new RuntimeException('Actual blurred boundary pixels required.'); }
        elseif ($tool === 'pixelate' && ($at(120, 80) !== [137, 61, 137, 0] || $at(121, 80) !== [137, 61, 137, 0])) throw new RuntimeException('Actual stored checkerboard block averages required.');
        elseif ($tool === 'redeye' && ($at(60, 80) !== [37, 37, 37, 0] || $at(180, 80) !== [37, 85, 237, 0])) throw new RuntimeException('Actual stored selected red-eye correction required.');
        unset($image);
    }
    echo "Three actual private retouch PNGs verified; every previous row/publication/file and own selected source preserved.\n";
    exit;
}
if (($argv[1] ?? 'prepare') !== 'prepare') throw new RuntimeException('Owned fixture mode required.');
$prefix = 'own-retouch-'.bin2hex(random_bytes(7));
$f = ['prefix' => $prefix, 'sources' => [], 'original_keys' => [], 'pixels' => []];
foreach (['blur', 'pixelate', 'redeye'] as $tool) {
    $image = imagecreatetruecolor(240, 160);
    $red = imagecolorallocate($image, 237, 37, 37);
    $blue = imagecolorallocate($image, 37, 85, 237);
    if ($tool === 'pixelate') { for ($y = 0; $y < 160; $y++) for ($x = 0; $x < 240; $x++) imagesetpixel($image, $x, $y, ($x + $y) % 2 === 0 ? $red : $blue); }
    else { imagefilledrectangle($image, 0, 0, 119, 159, $red); imagefilledrectangle($image, 120, 0, 239, 159, $blue); }
    $path = $runtime.'/'.$prefix.'-'.$tool.'.png';
    imagepng($image, $path);
    unset($image);
    $f['sources'][$tool] = ['path' => $path, 'name' => basename($path), 'sha256' => hash_file('sha256', $path)];
}
$tables = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME<>'khcms_state' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $table) {
    $keys = Db::run($db, "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION", [$table])->fetchAll(PDO::FETCH_COLUMN);
    if (!$keys) throw new RuntimeException('Stable original keys required.');
    $f['original_keys'][$table] = ['columns' => $keys, 'values' => Db::run($db, 'SELECT `'.implode('`,`', $keys).'` FROM `'.$table.'` ORDER BY `'.implode('`,`', $keys).'`')->fetchAll(PDO::FETCH_NUM)];
}
foreach ([$runtime.'/media', $runtime.'/communication-images', $runtime.'/website-documents'] as $directory) if (is_dir($directory)) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $entry) if ($entry->isFile()) $f['pixels'][$entry->getPathname()] = hash_file('sha256', $entry->getPathname());
$f['original_hash'] = ownOriginalDatabaseHash($db, $tables, fn($t) => $filter($t, $f));
file_put_contents($file, json_encode($f, JSON_THROW_ON_ERROR));
chmod($file, 0600);
echo "Three new own PNG sources prepared; all original rows/publications/files recorded without exclusions.\n";
