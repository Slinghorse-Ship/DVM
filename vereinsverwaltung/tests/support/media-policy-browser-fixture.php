<?php declare(strict_types=1);
require_once __DIR__.'/k-browser-fixture.php';
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';require __DIR__.'/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Cms\{MediaPolicy,Media,MediaMetadata};
use Kulturhus\Native\Shared\{Access,Db,SecretBox};
use Kulturhus\Native\Integration\ManagerBridge;
$k=ownKBrowserFixture();$c=TestSupport::config();$standalone=$k['standalone'];

$db=TestSupport::db();$a=$standalone?new Access($db,'admin'):(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access();$club=$a->club();
$runtime=$k['runtime'];$file=$k['directory'].'/media-policy-'.($standalone?'standalone':'integrated').'.json';
$tables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME<>'khcms_state' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
$pixels=static function()use($runtime):array{$result=[];foreach([$runtime.'/media',$runtime.'/communication-images']as$directory)if(is_dir($directory))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS))as$entry)if($entry->isFile())$result[$entry->getPathname()]=hash_file('sha256',$entry->getPathname());ksort($result);return $result;};
$hash=static function(string $prefix)use($db,$tables,$club):string{return ownOriginalDatabaseHash($db,$tables,static function($table)use($prefix,$club):array{if(in_array($table,['khcms_media_policy','khcms_media_policy_history'],true))return [' WHERE club_id<>?',[$club]];if($table==='khcms_media')return [' WHERE original_name NOT LIKE ?',[$prefix.'%']];return ['',[]];});};
if(($argv[1]??'prepare')==='check'){
    $f=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);if($hash($f['prefix'])!==$f['outside_originals'])throw new RuntimeException('Outside database originals changed.');
    foreach($f['pixels']as$path=>$value)if(hash_file('sha256',$path)!==$value)throw new RuntimeException('Original pixels changed.');
    $policy=(new MediaPolicy($db,$a))->get();if($policy['check_rights']||$policy['require_details'])throw new RuntimeException('Optional policy was not restored.');
    $rows=Db::run($db,'SELECT id,alt FROM khcms_media WHERE club_id=? AND original_name LIKE ?',[$club,$f['prefix'].'%'])->fetchAll();if(count($rows)!==9)throw new RuntimeException('Expected exactly nine new own browser uploads.');
    foreach($rows as$row){$m=(new MediaMetadata($db,$a))->get($row['id']);if($m['version']!==0||$m['rights_state']!=='unknown'||$m['has_proof'])throw new RuntimeException('Upload manufactured rights or proof.');}
    echo "Nine own browser uploads; all outside database and pixel originals unchanged; optional policy restored, no transport.\n";exit;
}
\Kulturhus\Native\Installer\SchemaRegistry::assertCurrent($db);
$policy=(new MediaPolicy($db,$a))->get();if($policy['check_rights']||$policy['require_details'])throw new RuntimeException('Default optional policy required.');
$tables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME<>'khcms_state' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
$prefix='own-upload-'.bin2hex(random_bytes(8)).'-';$source=$runtime.'/'.$prefix.'source.png';$image=imagecreatetruecolor(80,60);imagefill($image,0,0,imagecolorallocate($image,80,140,180));imagepng($image,$source);unset($image);
$f=['database'=>$c['database'],'prefix'=>$prefix,'source'=>$source,'outside_originals'=>$hash($prefix),'pixels'=>$pixels()];file_put_contents($file,json_encode($f,JSON_THROW_ON_ERROR));chmod($file,0600);echo "Own current declared CMS; originals recorded before browser uploads, no transport.\n";
