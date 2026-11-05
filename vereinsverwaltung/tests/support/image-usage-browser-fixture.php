<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';require __DIR__.'/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db,SecretBox};
use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Native\Cms\{Media,MediaMetadata};
use Kulturhus\Native\Communication\{Workspace,Images,Campaigns};
$c=TestSupport::config();$standalone=($c['label']??'')==='demo';
if(($c['owner']??'')!=='cloud-onboarding-synthetic'||!in_array($c['label']??'',['demo','integrated-manager'],true))throw new RuntimeException('Own synthetic development configuration required.');
$db=TestSupport::db();$a=$standalone?new Access($db,'admin'):(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access();$club=$a->club();$runtime=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/'.($standalone?'runtime-'.$c['database']:'integrated-manager-runtime');$key=$standalone?(getenv('KH_NATIVE_PRIVATE_KEY_FILE')?:dirname(__DIR__,3).'/.local/native-cms-integration/private.key'):$runtime.'/private.key';$box=SecretBox::fromFile($key);
$file=dirname(__DIR__,3).'/.local/browser-tests/image-usage-'.($standalone?'standalone':'integrated').'.json';
$tables=$db->query("SELECT t.TABLE_NAME FROM information_schema.TABLES t WHERE t.TABLE_SCHEMA=DATABASE() AND t.TABLE_TYPE='BASE TABLE' AND t.TABLE_NAME<>'khcms_state' AND EXISTS (SELECT 1 FROM information_schema.KEY_COLUMN_USAGE k WHERE k.TABLE_SCHEMA=t.TABLE_SCHEMA AND k.TABLE_NAME=t.TABLE_NAME AND k.CONSTRAINT_NAME='PRIMARY') ORDER BY t.TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
$outside=static function(array $f)use($db,$tables):string{return ownOriginalDatabaseHash($db,$tables,static function($table)use($f):array{
 $list=[];$column='id';$items=$f['items']??[];
 if($table==='khcms_media')$list=array_column($items,'id');
 elseif(in_array($table,['khcms_media_metadata','khcms_media_metadata_revisions'],true)){$list=array_column($items,'id');$column='media_id';}
 elseif(in_array($table,['khn_social_items','khn_social_revisions','khn_newsletter_items','khn_newsletter_revisions'],true)){$list=array_column($items,str_starts_with($table,'khn_social')?'social':'newsletter');$column=str_ends_with($table,'_items')?'id':'item_id';}
 elseif($table==='native_social_images')$list=$f['images']??[];
 elseif($table==='native_image_capabilities'){$list=$f['capabilities']??[];$column='token_hash';}
 elseif($table==='native_campaign_views'){$list=array_column($items,'view_hash');$column='token_hash';}
 return $list?[' WHERE '.$column.' NOT IN ('.implode(',',array_fill(0,count($list),'?')).')',$list]:['',[]];
});};
$media=new Media($db,$a,$runtime.'/media');$metadata=new MediaMetadata($db,$a,$box);$social=new Workspace($db,$a,'social');$newsletter=new Workspace($db,$a,'newsletter');$images=new Images($db,$a,$runtime.'/media');$campaign=new Campaigns($db,$a,$box,$runtime.'/media');
if(($argv[1]??'prepare')==='check'){
 $f=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);if($f['database']!==$c['database']||$outside($f)!==$f['outside_originals'])throw new RuntimeException('Outside originals, accounts or jobs changed.');
 foreach($f['items']as$item){$current=$metadata->get($item['id']);if($current['version']!==2||$current['rights_state']!=='withdrawn'||$metadata->privateProof($item['id'])!==$item['proof'])throw new RuntimeException('Expected one precise browser withdrawal.');}
 foreach($f['originals']as$o){$record=Db::run($db,'SELECT * FROM `'.$o['table'].'` WHERE `'.$o['column'].'`=?'.(isset($o['original_version'])?' AND version=1':'').' ORDER BY 1',[$o['id']])->fetchAll();if(hash('sha256',serialize($record))!==$o['hash'])throw new RuntimeException('Own original revision or asset changed.');}
 foreach($f['pixels']as$path=>$hash)if(!is_file($path)||hash_file('sha256',$path)!==$hash)throw new RuntimeException('Own pixels changed.');
 echo "Eight channel endpoints are withdrawn; original sources, frozen newsletters, pixels, private proofs, accounts and all other jobs remain unchanged.\n";exit;
}
$f=['database'=>$c['database'],'items'=>[],'images'=>[],'capabilities'=>[],'pixels'=>[],'originals'=>[]];$before=$outside($f);$source=$runtime.'/own-usage-'.bin2hex(random_bytes(8)).'.png';$im=imagecreatetruecolor(80,60);imagefill($im,0,0,imagecolorallocate($im,34,128,77));imagepng($im,$source);unset($im);
try{
 foreach([[390,true],[320,true],[320,false],[820,true]]as$i=>[$width,$js]){
  $id=$media->upload($source,'Own channel photo '.$i.'.png','Original upload '.$i)['id'];$proof='Own private consent '.$id;$alt='Ursprünglicher Bildtext '.$i.' ä🙂';$credit='Ursprünglicher Credit '.$i.' ä🙂';
  $metadata->save($id,['alt'=>$alt,'credit'=>$credit,'source_name'=>'Eigene Quelle','source_url'=>'https://source.example.invalid/'.str_repeat('a',180),'license_name'=>'Eigene Vereinbarung','rights_state'=>'cleared','consent_state'=>'recorded','expires_on'=>'2099-12-31'],0,$proof);
  $post=$social->save(['title'=>'Eigene Kanalprüfung '.$i,'body'=>'Eigener menschlicher Beitrag','channels'=>['facebook'],'blocks'=>[['type'=>'image','media_ref'=>$id,'alt'=>'Lokale Beschreibung']]]);$rendered=$images->render($post['id'])[0];$post=$social->approve($post['id'],$post['version']);$socialToken=bin2hex(random_bytes(32));
  Db::run($db,'INSERT INTO native_image_capabilities(token_hash,club_id,image_id,expires_at,format) VALUES(?,?,?, ?,\'jpeg\')',[hash('sha256',$socialToken),$club,$rendered,'2099-12-31 23:59:59']);
  $edition=$newsletter->save(['title'=>'Eigene Newsletterprüfung '.$i,'body'=>'Eigener menschlicher Newsletter','blocks'=>[['type'=>'image','media_ref'=>$id,'alt'=>'Eigene lokale Bildunterschrift']]]);$url=$campaign->viewLink($edition['id'],true);parse_str(parse_url($url,PHP_URL_QUERY),$q);$view=Campaigns::publicView($db,$club,$q['token']);
  foreach($view['item']['_image_urls']as$imageUrl){$token=basename($imageUrl);$hash=hash('sha256',$token);$f['capabilities'][]=$hash;$imageId=Db::run($db,'SELECT image_id FROM native_image_capabilities WHERE token_hash=?',[$hash])->fetchColumn();$f['images'][]=$imageId;$f['pixels'][$images->file($imageId)['path']]=hash_file('sha256',$images->file($imageId)['path']);}
  $f['capabilities'][]=hash('sha256',$socialToken);$f['images'][]=$rendered;$f['pixels'][$images->file($rendered)['path']]=hash_file('sha256',$images->file($rendered)['path']);$f['pixels'][$images->jpeg($rendered)['path']]=$images->jpeg($rendered)['sha256'];
  foreach(['original','large','medium','thumb']as$size)$f['pixels'][$media->variant($id,$size)['path']]=hash_file('sha256',$media->variant($id,$size)['path']);
  $f['items'][]=['id'=>$id,'social'=>$post['id'],'newsletter'=>$edition['id'],'view_hash'=>hash('sha256',$q['token']),'view_url'=>$url,'photo_url'=>$view['item']['_image_urls'][$id],'social_url'=>'/publication-image/'.$socialToken,'width'=>$width,'js'=>$js,'alt'=>$alt,'credit'=>$credit,'proof'=>$proof];
 }
}finally{unlink($source);}
if($outside($f)!==$before)throw new RuntimeException('Preparation changed an outside original.');$f['outside_originals']=$outside($f);
$original=static function(string$table,string$column,string$id)use($db,&$f):void{$f['originals'][]=['table'=>$table,'column'=>$column,'id'=>$id,'hash'=>hash('sha256',serialize(Db::run($db,'SELECT * FROM `'.$table.'` WHERE `'.$column.'`=? ORDER BY 1',[$id])->fetchAll()))];};
foreach($f['items']as$item){$original('khcms_media','id',$item['id']);$original('khcms_media_metadata_revisions','media_id',$item['id']);foreach(['social','newsletter']as$module){$original('khn_'.$module.'_items','id',$item[$module]);$original('khn_'.$module.'_revisions','item_id',$item[$module]);}$original('native_campaign_views','token_hash',$item['view_hash']);}foreach($f['images']as$id)$original('native_social_images','id',$id);foreach($f['capabilities']as$hash)$original('native_image_capabilities','token_hash',$hash);
// Browser withdrawal adds version 2; only the original metadata revision is compared.
foreach($f['originals']as&$original)if($original['table']==='khcms_media_metadata_revisions'){$original['table']='khcms_media_metadata_revisions';$original['original_version']=1;}
file_put_contents($file,json_encode($f,JSON_THROW_ON_ERROR));chmod($file,0600);echo "Four own photo sources, social images and frozen newsletter originals prepared; zero external calls or provider jobs.\n";
