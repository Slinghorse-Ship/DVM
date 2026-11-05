<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db,Identity,SecretBox};
use Kulturhus\Native\Cms\{Media,MediaMetadata};
use Kulturhus\Native\Communication\{Workspace,Images,ImageAttribution,ImageUsage,Meta};
use Kulturhus\Native\Integration\{Accounts,Routes};

$db=TestSupport::db();TestSupport::seed($db);Kulturhus\Native\Cms\Schema::install($db);Kulturhus\Native\Communication\Schema::install($db);
$access=new Access($db,'admin');$club=$access->club();$box=new SecretBox(str_repeat('a',32));$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/attribution-runtime';mkdir($root,0700,true);
$count=0;
function check(bool $ok,string $message):void{global $count;if(!$ok)throw new RuntimeException($message);++$count;echo 'PASS '.$message."\n";}
function denied(callable $call,string $message):void{try{$call();}catch(DomainException){check(true,$message);return;}throw new RuntimeException($message);}
function pixels(GdImage $image):string{$hash=hash_init('sha256');for($y=0;$y<imagesy($image);++$y){$row='';for($x=0;$x<imagesx($image);++$x){$c=imagecolorsforindex($image,imagecolorat($image,$x,$y));$row.=pack('C4',$c['red'],$c['green'],$c['blue'],$c['alpha']);}hash_update($hash,$row);}return hash_final($hash);}
$image=imagecreatetruecolor(300,200);imagefill($image,0,0,imagecolorallocate($image,150,70,40));imagepng($image,$root.'/source.png');unset($image);
$media=new Media($db,$access,$root.'/media');$photo=$media->upload($root.'/source.png','photo.png','Original photo')['id'];$logo=$media->upload($root.'/source.png','logo.png','Original logo')['id'];
$metadata=new MediaMetadata($db,$access,$box);
$photoInput=['alt'=>'Original photo','credit'=>'© Fotograf Änne','source_name'=>'Eigenes Vereinsarchiv','license_name'=>'CC BY 4.0','license_url'=>'https://creativecommons.org/licenses/by/4.0/','rights_state'=>'cleared','consent_state'=>'not_required','channels'=>['website','social','newsletter','press']];
$photoMeta=$metadata->save($photo,$photoInput,0,'PRIVATE proof never exported');$logoMeta=$metadata->save($logo,['alt'=>'Original logo','credit'=>'Logo: Verein'],0);
$identity=(new Identity($db,$access))->save(['name'=>'Originalverein','origin'=>'https://example.invalid','address'=>'','logo_id'=>$logo],0);
$workspace=new Workspace($db,$access,'social');$images=new Images($db,$access,$root.'/media');
$baseline=Db::run($db,'SELECT * FROM khcms_media ORDER BY id')->fetchAll();$proof=Db::run($db,'SELECT * FROM khcms_media_metadata_revisions WHERE media_id=? AND version=1',[$photo])->fetch();$sourceHashes=[];foreach([$photo,$logo] as $id)foreach(['original','large','medium','thumb']as$variant)$sourceHashes[$id][$variant]=hash_file('sha256',$media->variant($id,$variant)['path']);
$previous=[];
foreach([[$photo,$logo],[$logo,$photo]]as$sourceOrder){
    $usage=['media'=>['a'=>$sourceOrder[0]===$photo?$photoMeta:$logoMeta,'b'=>$sourceOrder[1]===$photo?$photoMeta:$logoMeta]];$plan=ImageAttribution::plan($usage,1350);$canvas=imagecreatetruecolor(1080,1350);ImageAttribution::draw($canvas,$plan);$y=$plan['y']+16;
    foreach($sourceOrder as$ref){$source=$ref===$photo?$photoMeta:$logoMeta;$preview=imagecreatefromstring(ImageAttribution::preview($db,$access,$ref,$source['version']));$crop=imagecrop($canvas,['x'=>72,'y'=>$y,'width'=>936,'height'=>imagesy($preview)]);check(pixels($crop)===pixels($preview),'accented ascenders and descenders remain pixel-exact in both adjacent source orders');$y+=imagesy($preview);unset($preview,$crop);}unset($canvas);
}
foreach(['post','story'] as $kind)foreach(['editorial','poster','photo'] as $layout){
    $item=$workspace->save(['title'=>'Kultur','summary'=>'Gemeinsam','body'=>'Menschlicher Originaltext','kind'=>$kind,'channels'=>['instagram'],'image_design'=>['layout'=>$layout], 'blocks'=>[['type'=>'image','media_ref'=>$photo,'alt'=>'Local original caption']], 'story'=>$kind==='story'?[['title'=>'Kultur','text'=>'Gemeinsam','media_ref'=>$photo]]:[]]);
    $id=$images->render($item['id'])[0];$row=$images->history($item['id'])[0];$meta=json_decode($row['metadata_json'],true);$file=$images->file($id);$pixel=imagecreatefrompng($file['path']);$plan=$meta['attribution'];
    check(imagesx($pixel)===1080&&imagesy($pixel)===($kind==='story'?1920:1350),$kind.' '.$layout.' retains actual native dimensions');
    $expectedRefs=$layout==='poster'?[$logo]:[$logo,$photo];sort($expectedRefs,SORT_STRING);check(array_keys($meta['sources']['media'])===$expectedRefs,$kind.' '.$layout.' credits only actual rendered sources');
    $y=$plan['y']+16;
    foreach($expectedRefs as $ref){$preview=imagecreatefromstring(ImageAttribution::preview($db,$access,$ref,$meta['sources']['media'][$ref]['version']));$crop=imagecrop($pixel,['x'=>72,'y'=>$y,'width'=>936,'height'=>imagesy($preview)]);check(pixels($crop)===pixels($preview),$kind.' '.$layout.' actual PNG contains exact authenticated source glyphs');$y+=imagesy($preview);unset($crop,$preview);}
    check($plan['y']+$plan['height']===($kind==='story'?1660:1298),$kind.' '.$layout.' source strip stays inside native safe area');
    check(!str_contains($row['metadata_json'],'PRIVATE proof')&&($layout==='poster'||str_contains(implode(' ',$plan['lines']),'https://creativecommons.org/licenses/by/4.0/')),$kind.' '.$layout.' full public attribution contains no private proof');
    check($images->jpeg($id)['sha256']===$meta['jpeg_sha256'],$kind.' '.$layout.' approved JPEG binds same credited export');
    $previous[$id]=['row'=>$row,'png'=>hash_file('sha256',$file['path']),'jpg'=>hash_file('sha256',$images->jpeg($id)['path'])];unset($pixel);
}
$raw=imagecreatetruecolor(1080,1350);imagefill($raw,0,0,imagecolorallocate($raw,50,120,180));imagepng($raw,$root.'/canvas.png');unset($raw);
$doc=['schema'=>1,'width'=>1080,'height'=>1350,'background'=>'#3278b4','layers'=>[]];foreach([$photo,$logo,$photo]as$i=>$id)$doc['layers'][]=['id'=>'image-'.$i,'type'=>'image','media_ref'=>$id,'x'=>0,'y'=>0,'width'=>1080,'height'=>1350,'rotation'=>0,'opacity'=>1];
$item=$workspace->save(['title'=>'Original canvas','body'=>'Human text','channels'=>['facebook']]);$canvas=$images->saveCanvas($item['id'],$item['version'],$identity['version'],0,$doc,$root.'/canvas.png');$row=$images->history($item['id'])[0];$meta=json_decode($row['metadata_json'],true);$png=imagecreatefrompng($images->file($canvas)['path']);
$canvasRefs=[$photo,$logo];sort($canvasRefs,SORT_STRING);check(array_keys($meta['sources']['media'])===$canvasRefs&&count($meta['attribution']['lines'])===count(ImageAttribution::lines($photoMeta))+count(ImageAttribution::lines($logoMeta)),'repeated canvas source is credited once in stable original source order');
check(imagecolorsforindex($png,imagecolorat($png,300,300))['blue']===180,'source attribution preserves actual artwork outside reserved strip');
$y=$meta['attribution']['y']+16;foreach($canvasRefs as$ref){$raster=imagecreatefromstring(ImageAttribution::preview($db,$access,$ref,$meta['sources']['media'][$ref]['version']));$crop=imagecrop($png,['x'=>72,'y'=>$y,'width'=>936,'height'=>imagesy($raster)]);check(pixels($crop)===pixels($raster),'server canvas contains exact source preview glyphs');$y+=imagesy($raster);unset($crop,$raster);}unset($png);
$approved=$workspace->approve($item['id'],$item['version']);(new Accounts($db,$box))->save($access,'meta',['page_id'=>'123','instagram_id'=>'456','token'=>'synthetic-never-send','api_version'=>'v24.0'],0);$jobs=(new Meta($db,$box))->queue($access,$item['id'],gmdate('Y-m-d H:i:s',time()+3600),$root.'/media');$payload=$box->open('job:'.$club.':'.$jobs[0],Db::run($db,'SELECT ciphertext FROM native_delivery_jobs WHERE id=?',[$jobs[0]])->fetchColumn());$token=basename($payload['image_url']);
check(Images::publicFile($db,$club,$token,$root.'/media')['mime']==='image/jpeg','Meta capability serves actual approved credited JPEG without provider transport');
$missing=$meta;unset($missing['attribution']);denied(fn()=>ImageUsage::image($db,$club,['kind'=>'post','metadata_json'=>json_encode($missing)]),'new publication rejects missing original burned attribution');
ImageUsage::image($db,$club,['kind'=>'post','metadata_json'=>json_encode($missing)],false);check(true,'issued historical pixels remain readable with present rights only');
$tampered=$meta;$tampered['attribution']['lines'][0]='Invented credit';denied(fn()=>ImageUsage::image($db,$club,['kind'=>'post','metadata_json'=>json_encode($tampered)]),'new publication rejects invented attribution plan');
$legacy=$meta;$legacy['attribution']=ImageAttribution::plan($meta['sources'],1350,44);ImageUsage::image($db,$club,['kind'=>'post','metadata_json'=>json_encode($legacy)]);check(true,'original 44-pixel source plans remain valid without rewriting saved pixels');
denied(fn()=>ImageAttribution::preview($db,new Access($db,'outsider'),$photo,$photoMeta['version']),'foreign club cannot read private attribution raster');
denied(fn()=>ImageAttribution::preview($db,$access,$photo,$photoMeta['version']+1),'stale source version cannot render replacement attribution');
$onlyUrl=['source_url'=>'https://example.invalid/source'];check(str_contains(implode('',ImageAttribution::lines($onlyUrl)),$onlyUrl['source_url']),'unnamed source URL is not silently omitted');
$long='https://example.invalid/'.str_repeat('a',95);$lines=ImageAttribution::lines(['license_url'=>$long]);check(str_replace(' ','',implode('',$lines))==='Lizenz:'.$long,'long unbroken licence URL wraps without losing any characters');
denied(fn()=>ImageAttribution::lines(['credit'=>str_repeat('Sehr langer belegter Credit ',100)]),'oversized credit rejects instead of shrinking or truncating');
$history=Db::run($db,'SELECT * FROM native_social_images ORDER BY id')->fetchAll();$files=glob(dirname($root.'/media').'/communication-images/*');$photoMeta=$metadata->save($photo,array_replace($photoInput,['credit'=>str_repeat('Sehr langer belegter Credit ',15)]),$photoMeta['version']);
denied(fn()=>$images->saveCanvas($item['id'],$approved['version'],$identity['version'],0,$doc,$root.'/canvas.png'),'oversized current source cannot create canvas original');
check(Db::run($db,'SELECT * FROM native_social_images ORDER BY id')->fetchAll()===$history&&glob(dirname($root.'/media').'/communication-images/*')===$files,'rejected attribution leaves all prior rows and pixel files intact');
check(Images::publicFile($db,$club,$token,$root.'/media')!==null,'ordinary credit edit leaves already issued original image unchanged');
check(Db::run($db,'SELECT * FROM khcms_media ORDER BY id')->fetchAll()===$baseline,'source media originals are never replaced');check(Db::run($db,'SELECT * FROM khcms_media_metadata_revisions WHERE media_id=? AND version=1',[$photo])->fetch()===$proof&&$metadata->privateProof($photo,1)==='PRIVATE proof never exported','encrypted private consent revision remains byte-exact');
foreach($sourceHashes as$id=>$variants)foreach($variants as$variant=>$hash)check(hash_file('sha256',$media->variant($id,$variant)['path'])===$hash,'all source variants remain byte-exact');
foreach($previous as$id=>$original)check(hash_file('sha256',$images->file($id)['path'])===$original['png']&&hash_file('sha256',$images->jpeg($id)['path'])===$original['jpg'],'earlier credited image originals remain byte-exact');
check(str_contains(Routes::translate('/social-credit?media='.$photo.'&version=1'),'native_endpoint=social-credit'),'integrated editor translates private attribution route');
echo "$count native image attribution checks passed; actual pixels and original metadata, no external services.\n";
