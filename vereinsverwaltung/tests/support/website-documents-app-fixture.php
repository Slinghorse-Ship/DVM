<?php declare(strict_types=1);
require_once __DIR__.'/k-browser-fixture.php';
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';require __DIR__.'/original-database-hash.php';require __DIR__.'/own-website-pdf.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{WebsiteDocuments,Pages};
use Kulturhus\Native\Integration\ManagerBridge;
$k=ownKBrowserFixture();$c=TestSupport::config();$standalone=$k['standalone'];

$db=TestSupport::db();$access=$standalone?new Access($db,'admin'):(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access();
$runtime=$k['runtime'];
$file=$k['directory'].'/website-documents-'.($standalone?'standalone':'integrated').'.json';
$filter=static function(string $table,array $f):array{$entry=$f['original_keys'][$table];if(!$entry['values'])return [' WHERE 0=1',[]];$args=[];foreach($entry['values']as$row)array_push($args,...$row);return [' WHERE (`'.implode('`,`',$entry['columns']).'`) IN ('.implode(',',array_fill(0,count($entry['values']),'('.implode(',',array_fill(0,count($entry['columns']),'?')).')')).')',$args];};
if(($argv[1]??'prepare')==='check'){
    $f=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
    if(ownOriginalDatabaseHash($db,array_keys($f['original_keys']),fn($t)=>$filter($t,$f))!==$f['original_hash'])throw new RuntimeException('An original database row changed.');
    foreach($f['pixels']as$path=>$hash)if(!is_file($path)||hash_file('sha256',$path)!==$hash)throw new RuntimeException('An original stored file changed.');
    $pages=new Pages($db,$access);$docs=new WebsiteDocuments($db,$access,$runtime.'/website-documents');$count=0;
    foreach($f['pages']as$case=>$original){
        $p=$pages->get($original['id']);
        if($p['version']!==7||$p['published_revision']!==null||$p['title']!==$f['prefix'].' edited '.$case||$p['excerpt']!=='Own unsaved input '.$case||count($p['document_links'])!==1||$p['document_links'][0]['label']!=='Own readable PDF '.$case)throw new RuntimeException('Exact final saved page required.');
        $revisions=Db::run($db,'SELECT payload_json FROM khcms_revisions WHERE page_id=? AND revision=4',[$p['id']])->fetchColumn();$published=json_decode($revisions,true,64,JSON_THROW_ON_ERROR);
        if(count($published['document_links'])!==2)throw new RuntimeException('Exact two-document original publication required.');
        foreach($published['document_links']as$i=>$link){$asset=$docs->file($link['id']);if(file_get_contents($asset['path'])!==file_get_contents($f['files'][$i])||$link['sha256']!==hash_file('sha256',$f['files'][$i])||$docs->publicFile($link['id'])!==null)throw new RuntimeException('Original PDF bytes or final withdrawal mismatch.');$count++;}
    }
    if($count!==8||Db::run($db,'SELECT COUNT(*) FROM khcms_documents WHERE original_name LIKE ?',[$f['prefix'].'%'])->fetchColumn()!=8)throw new RuntimeException('Exactly eight own new PDF files required.');
    echo "Eight own uploaded PDFs and four exact private final pages verified; all previous database originals, revisions and files unchanged.\n";exit;
}
if(($argv[1]??'prepare')!=='prepare')throw new RuntimeException('Owned fixture mode required.');
$prefix='own-website-documents-'.bin2hex(random_bytes(7));$f=['prefix'=>$prefix,'pages'=>[],'files'=>[],'original_keys'=>[],'pixels'=>[]];
foreach(['A','B']as$letter){$path=$runtime.'/'.$prefix.'-'.$letter.'.pdf';file_put_contents($path,ownWebsitePdf('Own new PDF '.$prefix.' '.$letter));$f['files'][]=$path;}
$f['bad']=$runtime.'/'.$prefix.'-invalid.pdf';file_put_contents($f['bad'],'Own invalid new PDF fixture');
$pages=new Pages($db,$access);
foreach(['390-js','320-js','820-js','390-nojs']as$case){$p=$pages->save(['title'=>$prefix.' original '.$case,'slug'=>$prefix.'-'.$case,'excerpt'=>'Own original excerpt','body_html'=>'<h2>Own original heading</h2><ul><li>Own original list</li></ul>']);$p=$pages->publish($p['id'],$p['version']);$f['pages'][$case]=['id'=>$p['id'],'slug'=>$p['slug']];}
$tables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME<>'khcms_state' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
foreach($tables as$table){$keys=Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);if(!$keys)throw new RuntimeException('Stable original keys required.');$values=Db::run($db,'SELECT `'.implode('`,`',$keys).'` FROM `'.$table.'` ORDER BY `'.implode('`,`',$keys).'`')->fetchAll(PDO::FETCH_NUM);if($table==='khcms_pages')$values=array_values(array_filter($values,fn($row)=>!in_array($row[0],array_column($f['pages'],'id'),true)));$f['original_keys'][$table]=['columns'=>$keys,'values'=>$values];}
foreach([$runtime.'/media',$runtime.'/communication-images',$runtime.'/website-documents']as$directory)if(is_dir($directory))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS))as$entry)if($entry->isFile())$f['pixels'][$entry->getPathname()]=hash_file('sha256',$entry->getPathname());
$f['original_hash']=ownOriginalDatabaseHash($db,$tables,fn($t)=>$filter($t,$f));file_put_contents($file,json_encode($f,JSON_THROW_ON_ERROR));chmod($file,0600);
echo "Four own published pages without documents and two new PDF fixtures prepared; all previous original rows, revisions and files recorded.\n";
