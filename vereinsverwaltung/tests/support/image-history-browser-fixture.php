<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';require __DIR__.'/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Native\Communication\{Images,Workspace};
$c=TestSupport::config();$standalone=($c['label']??'')==='demo';
if(($c['owner']??'')!=='cloud-onboarding-synthetic'||!in_array($c['label']??'',['demo','integrated-manager'],true))throw new RuntimeException('Owned synthetic development configuration required.');
$db=TestSupport::db();$a=$standalone?new Access($db,'admin'):(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access();$club=$a->club();
$runtime=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/'.($standalone?'runtime-'.$c['database']:'integrated-manager-runtime');
$file=dirname(__DIR__,3).'/.local/browser-tests/image-history-'.($standalone?'standalone':'integrated').'.json';
$tables=$db->query("SELECT t.TABLE_NAME FROM information_schema.TABLES t WHERE t.TABLE_SCHEMA=DATABASE() AND t.TABLE_TYPE='BASE TABLE' AND t.TABLE_NAME<>'khcms_state' AND EXISTS (SELECT 1 FROM information_schema.KEY_COLUMN_USAGE k WHERE k.TABLE_SCHEMA=t.TABLE_SCHEMA AND k.TABLE_NAME=t.TABLE_NAME AND k.CONSTRAINT_NAME='PRIMARY') ORDER BY t.TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
$outside=static function(array$f)use($db,$tables):string{return ownOriginalDatabaseHash($db,$tables,static function($table)use($f):array{$ids=array_column($f['items']??[],'id');$marks=static fn($s)=>implode(',',array_fill(0,count($s),'?'));if($ids&&$table==='khn_social_items')return [' WHERE id NOT IN ('.$marks($ids).')',$ids];if($ids&&in_array($table,['khn_social_revisions','native_social_images'],true))return [' WHERE item_id NOT IN ('.$marks($ids).')',$ids];return ['',[]];});};
$pixelHashes=static function()use($runtime):array{$hashes=[];foreach([$runtime.'/media',$runtime.'/communication-images']as$root){if(!is_dir($root))continue;foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS))as$entry){if($entry->isLink())throw new RuntimeException('Regular private originals required.');if($entry->isFile())$hashes[$entry->getPathname()]=hash_file('sha256',$entry->getPathname());}}ksort($hashes);return $hashes;};
$workspace=new Workspace($db,$a,'social');$images=new Images($db,$a,$runtime.'/media');
if(($argv[1]??'prepare')==='check'){
 $f=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);if($f['database']!==$c['database']||$outside($f)!==$f['outside_originals'])throw new RuntimeException('Outside original, account, rule or job changed.');
 foreach($f['pixels']as$path=>$hash)if(is_link($path)||!is_file($path)||hash_file('sha256',$path)!==$hash)throw new RuntimeException('Earlier original pixel changed.');
 foreach($f['items']as$item){if(hash('sha256',serialize(Db::run($db,'SELECT * FROM khn_social_items WHERE id=?',[$item['id']])->fetch()))!==$item['original'])throw new RuntimeException('Saved text original changed.');if((int)Db::run($db,'SELECT COUNT(*) FROM native_social_images WHERE item_id=?',[$item['id']])->fetchColumn()!==32)throw new RuntimeException('One actual new GD frame required.');foreach($item['rows']as$id=>$hash)if(hash('sha256',serialize(Db::run($db,'SELECT * FROM native_social_images WHERE id=?',[$id])->fetch()))!==$hash)throw new RuntimeException('Historical original metadata changed.');}
 echo "All 124 previous image originals, text, source photos, accounts, jobs and every outside database original remain unchanged. Four actual new GD frames, no publication.\n";exit;
}
$f=['database'=>$c['database'],'items'=>[]];$before=$outside($f);
foreach([[390,true],[320,true],[820,true],[320,false]]as$i=>[$width,$js]){
 $item=$workspace->save(['title'=>'Bildverlauf '.$i,'summary'=>'Eigene unveränderte Fassung','body'=>'Eigener Text','channels'=>['instagram']]);$first=$images->render($item['id'])[0];$source=Db::run($db,'SELECT * FROM native_social_images WHERE id=?',[$first])->fetch();$meta=json_decode($source['metadata_json'],true,32,JSON_THROW_ON_ERROR);$ids=[$first];
 for($n=1;$n<=30;++$n){$id=bin2hex(random_bytes(16));$created=sprintf('2030-01-01 12:00:%02d',$n);$copy=$meta;$copy['generated_at']=$created.'.000000';foreach(['png','jpg']as$ext){$target=$runtime.'/communication-images/'.$id.'.'.$ext;if(!copy($runtime.'/communication-images/'.$first.'.'.$ext,$target))throw new RuntimeException('Own fixture image copy failed.');chmod($target,0600);}Db::run($db,'INSERT INTO native_social_images(id,club_id,item_id,item_version,content_hash,slide,kind,sha256,metadata_json,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[$id,$club,$item['id'],1,$source['content_hash'],0,'post',$source['sha256'],json_encode($copy,JSON_THROW_ON_ERROR),$created]);$ids[]=$id;}
 $rows=[];foreach($ids as$id)$rows[$id]=hash('sha256',serialize(Db::run($db,'SELECT * FROM native_social_images WHERE id=?',[$id])->fetch()));
 $f['items'][]=['id'=>$item['id'],'width'=>$width,'js'=>$js,'ids'=>array_reverse($ids),'original'=>hash('sha256',serialize(Db::run($db,'SELECT * FROM khn_social_items WHERE id=?',[$item['id']])->fetch())),'rows'=>$rows];
}
if($outside($f)!==$before)throw new RuntimeException('Preparation changed an outside original.');$f['outside_originals']=$outside($f);$f['pixels']=$pixelHashes();file_put_contents($file,json_encode($f,JSON_THROW_ON_ERROR));chmod($file,0600);echo "Four own 31-frame histories prepared, all existing originals retained; synthetic fixture copies, no provider transport.\n";
