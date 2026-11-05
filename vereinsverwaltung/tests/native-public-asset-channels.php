<?php declare(strict_types=1);
/** CMS18 regression: published channels authorize actual assets, independently of future field ACLs. */
require dirname(__DIR__).'/native/bootstrap.php';
require __DIR__.'/support/own-website-pdf.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Schema,Pages,Media,DataModel,WebsiteDocuments};
$db=TestSupport::db();TestSupport::seed($db);Schema::install($db);$access=new Access($db,'admin');$pages=new Pages($db,$access);$model=new DataModel($db,$access);$checks=0;
function channelCheck(bool $okay,string $label):void{global $checks;if(!$okay)throw new RuntimeException($label);++$checks;echo 'PASS '.$label.PHP_EOL;}
$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/asset-channels';mkdir($root,0700);$media=new Media($db,$access,$root.'/media');$ids=[];$bytes=[];
for($i=0;$i<4;$i++){$source=$root.'/source-'.$i.'.png';$im=imagecreatetruecolor(8,8);imagefill($im,0,0,imagecolorallocate($im,30*$i,40,90));imagepng($im,$source);imagedestroy($im);$ids[]=$media->upload($source,'Own channel image '.$i.'.png','Own exact original '.$i)['id'];}
foreach($ids as $id)foreach(['original','large','medium','thumb'] as $size){$variant=$media->variant($id,$size);$bytes[$variant['path']]=hash_file('sha256',$variant['path']);}
$imageField=$model->saveField(['key'=>'channel_image','label'=>'Own additional image','type'=>'media-reference']);$fileField=$model->saveField(['key'=>'channel_file','label'=>'Own additional PDF','type'=>'file-reference']);$model->saveTemplate(['key'=>'channel_story','label'=>'Own channel story','renderer'=>'news','fields'=>['channel_image','channel_file']]);
$pdfBytes=ownWebsitePdf('Own exact channel PDF');$pdfPath=$root.'/own.pdf';file_put_contents($pdfPath,$pdfBytes);$documents=new WebsiteDocuments($db,$access,$root.'/documents');$document=$documents->uploadMany([['path'=>$pdfPath,'name'=>'Own channel original.pdf']])[0];
$rss=$pages->save(['title'=>'Own RSS-only source','slug'=>'aktuelles/channel-rss','template'=>'channel_story','news_date'=>'2098-05-04','news_channels'=>['rss'],'media_ids'=>[$ids[0],$ids[1]],'custom_fields'=>['channel_image'=>$ids[2],'channel_file'=>$document['id']]]);$rss=$pages->publish($rss['id'],$rss['version']);
channelCheck($pages->publicPage($rss['slug'])===null&&$pages->publicPage($rss['slug'],'rss')!==null,'RSS-only source remains outside website and available to actual RSS');
channelCheck($media->publicVariant($ids[0],'medium')!==null&&$media->publicVariant($ids[0],'large',$rss)!==null,'RSS-only source authorizes its exact first image cover');
channelCheck($media->publicVariant($ids[1],'medium')===null&&$media->publicVariant($ids[1],'medium',$rss)===null,'RSS-only source never authorizes secondary image bytes or scoped metadata');
channelCheck($media->publicVariant($ids[2],'medium')===null,'RSS-only source never authorizes a custom-field image omitted by the feed');
channelCheck($documents->publicFile($document['id'])===null,'RSS-only source never authorizes custom-field PDF bytes omitted by the feed');
$none=$pages->save(['title'=>'Own disabled channel source','slug'=>'aktuelles/channel-none','template'=>'channel_story','news_date'=>'2098-05-04','news_channels'=>[],'media_ids'=>[$ids[3]],'custom_fields'=>['channel_image'=>$ids[2],'channel_file'=>$document['id']]]);$none=$pages->publish($none['id'],$none['version']);
channelCheck($pages->publicPage($none['slug'])===null&&$pages->publicPage($none['slug'],'rss')===null&&$media->publicVariant($ids[3],'medium')===null&&$media->publicVariant($ids[2],'medium')===null&&$documents->publicFile($document['id'])===null,'explicitly disabled channels authorize neither original page nor any image/PDF');
$web=$pages->save(['title'=>'Own independent website use','slug'=>'channel-independent-use','media_ids'=>[$ids[1]],'document_links'=>[['id'=>$document['id'],'label'=>'Own website PDF']]]);$web=$pages->publish($web['id'],$web['version']);
channelCheck($media->publicVariant($ids[1],'medium')!==null&&$documents->publicFile($document['id'])!==null,'independent exact website publication legitimately authorizes shared image and primary PDF');
channelCheck($media->publicVariant($ids[1],'medium',$rss)===null,'another public use cannot authorize metadata belonging to RSS-only secondary use');
$web=$pages->unpublish($web['id'],$web['version']);channelCheck($media->publicVariant($ids[1],'medium')===null&&$documents->publicFile($document['id'])===null&&$media->publicVariant($ids[0],'medium')!==null,'withdrawal of the independent website use revokes only its bytes while retaining real RSS cover');
$rss=$pages->save(array_replace($rss,['news_channels'=>[]]),$rss['version']);channelCheck($media->publicVariant($ids[0],'medium')!==null,'private draft channel change preserves the actual published RSS cover');
$rss=$pages->publish($rss['id'],$rss['version']);channelCheck($media->publicVariant($ids[0],'medium')===null,'deliberate disabled-channel publication immediately removes the old cover binding');
$fixed=$db->query('SELECT * FROM khcms_media ORDER BY id')->fetchAll();$history=$db->query('SELECT * FROM khcms_revisions ORDER BY page_id,revision')->fetchAll();foreach($ids as $id)$media->publicVariant($id,'medium');$documents->publicFile($document['id']);
channelCheck($db->query('SELECT * FROM khcms_media ORDER BY id')->fetchAll()===$fixed&&$db->query('SELECT * FROM khcms_revisions ORDER BY page_id,revision')->fetchAll()===$history,'channel authorization reads preserve exact original metadata and historical publication bytes');
foreach($bytes as $path=>$hash)channelCheck(hash_file('sha256',$path)===$hash,'channel checks preserve exact original image variant');channelCheck(file_get_contents($documents->file($document['id'])['path'])===$pdfBytes,'channel checks preserve exact original PDF bytes');
echo json_encode(['checks'=>$checks,'live_transport'=>false,'future_field_policy_dependency'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
