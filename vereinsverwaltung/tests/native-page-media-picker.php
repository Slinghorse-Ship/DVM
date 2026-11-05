<?php declare(strict_types=1);ob_start();
require dirname(__DIR__).'/native/bootstrap.php';require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Cms\{Schema,Pages,Media};
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Web\Website\PageMediaPicker;
use Kulturhus\Native\Web\Manager;
use Kulturhus\Native\Integration\EmbeddedManager;
$db=TestSupport::db();$access=new Access($db,'admin');
if(($argv[1]??'')==='album-memory'){
    $html=(new \Kulturhus\Native\Web\Gallery($db,$access,$argv[2],'own-token'))->manager($argv[3],['album_page'=>'17']);
    $dom=new DOMDocument();$dom->loadHTML($html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$xp=new DOMXPath($dom);
    echo json_encode(['options'=>$xp->query('//select[@name="images[]"]/option')->length,'images'=>$xp->query('//article[@data-album-image]')->length,'memory'=>memory_get_peak_usage(true)]);exit;
}
if(($argv[1]??'')==='labels-memory'){
    $html=(new PageMediaPicker($db,$access))->render([]);
    echo json_encode(['options'=>substr_count($html,'<option '),'images'=>substr_count($html,'<img '),'memory'=>memory_get_peak_usage(true)]);exit;
}
TestSupport::seed($db);Schema::install($db);$n=0;
function pickerCheck(bool $ok,string $label):void {global $n;if(!$ok)throw new RuntimeException($label);$n++;echo 'PASS '.$label."\n";}
function pickerReject(callable $fn,string $label,string $class=InvalidArgumentException::class):void {try{$fn();}catch(Throwable $e){pickerCheck($e instanceof $class,$label);return;}throw new RuntimeException('Expected rejection: '.$label);}
$root=dirname(__DIR__,2).'/.local/page-media-picker-'.bin2hex(random_bytes(8));mkdir($root,0700,true);
$pixel=imagecreatetruecolor(28,20);imagefill($pixel,0,0,imagecolorallocate($pixel,20,90,130));imagepng($pixel,$root.'/own.png');unset($pixel);
$media=new Media($db,$access,$root.'/media');$ids=[];
foreach(range(0,105)as$i)$ids[]=$media->upload($root.'/own.png','own-'.sprintf('%03d',$i).'.png','Own choice '.sprintf('%03d',$i).($i===105?' 100%_! <marker> Ä':''))['id'];
$foreign=(new Media($db,new Access($db,'outsider'),$root.'/other'))->upload($root.'/own.png','foreign-secret.png','Foreign secret');
$selected=array_reverse(array_slice($ids,0,100));$pages=new Pages($db,$access);
$items=array_map(fn($id,$i)=>['id'=>$id,'caption'=>'Own original caption '.$i,'tags'=>['Original '.$i]],$selected,array_keys($selected));
$page=$pages->save(['title'=>'Own selected order','slug'=>'own-selected-order','media_ids'=>$selected,'gallery_items'=>$items]);$page=$pages->publish($page['id'],$page['version']);$published=$pages->publicPage($page['slug']);
$tables=['khcms_media','khcms_pages','khcms_revisions'];$original=ownOriginalDatabaseHash($db,$tables);$pixels=[];
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/media',FilesystemIterator::SKIP_DOTS))as$file)if($file->isFile())$pixels[$file->getPathname()]=hash_file('sha256',$file->getPathname());
$picker=new PageMediaPicker($db,$access);$html=$picker->render($selected);$dom=new DOMDocument();$dom->loadHTML($html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$xp=new DOMXPath($dom);
$optionIds=static fn(string $query):array=>array_map(fn($node)=>$node->getAttribute('value'),iterator_to_array($xp->query($query)));
pickerCheck($optionIds('//option[@selected]')===$selected,'100 original selections retain exact saved order');
pickerCheck(count($optionIds('//option'))===106&&count(array_unique($optionIds('//option')))===106,'complete native fallback reaches every own image exactly once');
pickerCheck($xp->query('//select[@multiple and @size="6" and @name="media_ids[]"]')->length===1,'native multiple chooser has a bounded visible size and original submitted name');
pickerCheck(!str_contains($html,$foreign['id'])&&!str_contains($html,'Foreign secret'),'foreign identities and labels stay private');
pickerCheck(!str_contains($html,'<img')&&!str_contains($html,'variants')&&!str_contains($html,$root),'initial picker loads neither image files nor variant paths');
pickerCheck(str_contains($html,'&lt;marker&gt;'),'literal labels cannot become markup');
pickerCheck(ownOriginalDatabaseHash($db,$tables)===$original&&$pages->publicPage($page['slug'])===$published,'rendering preserves every original media/page/revision byte');
$missing=str_repeat('f',32);$unavailable=$picker->render([$missing,$ids[0]]);
pickerCheck(str_contains($unavailable,'value="'.$missing.'" selected data-unavailable')&&!str_contains($unavailable,'Foreign secret'),'unavailable original identity remains explicit without another club label');
pickerReject(fn()=>$picker->render(['bad']),'malformed selected identity rejected');
pickerReject(fn()=>$picker->render([[]]),'non-text selected identity rejected');
$manager=new Manager($db,$access,$root.'/media','own-token');$native=$manager->main('website',$page['id']);$embedded=(new EmbeddedManager($manager,$access))->main('website',$page['id']);
pickerCheck(str_contains($native,'data-page-media-picker')&&!str_contains($native,'class="media-picker"'),'native page editor replaces the unbounded thumbnail grid');
pickerCheck(str_contains($native,'<details class="page-sidebar-picker"><summary>Seiten auswählen</summary>'),'editing starts with the complete directory folded before the form');
pickerCheck(str_contains($embedded,'data-page-media-preview="/vereinsverwaltung/?native_endpoint=media-preview&amp;media=00000000000000000000000000000000&amp;variant=thumb"'),'integrated picker uses the real private image endpoint');
$saved=$manager->action('website',['operation'=>'page-save','csrf'=>'own-token','id'=>$page['id'],'version'=>(string)$page['version'],'title'=>$page['title'],'slug'=>$page['slug'],'template'=>'standard','body_html'=>'<p>Own new text</p>','media_ids'=>$selected],[]);
$current=$pages->get($page['id']);pickerCheck($current['media_ids']===$selected&&$current['gallery_items']===$items,'ordinary real page-save retains original order captions and tags');
pickerCheck($pages->publicPage($page['slug'])===$published,'private selected-order save preserves the exact earlier publication');
$same=true;foreach($pixels as$file=>$hash)if(hash_file('sha256',$file)!==$hash)$same=false;pickerCheck($same,'all 424 own stored image variants remain byte-identical');
$oldMethod=$_SERVER['REQUEST_METHOD']??null;$_SERVER['REQUEST_METHOD']='POST';http_response_code(422);
$post=['operation'=>'page-save','csrf'=>'own-token','id'=>$page['id'],'media_ids'=>[$ids[103],$ids[3]]];
pickerCheck($picker->rejectedSelection($current,$post,'own-token')['media_ids']===$post['media_ids'],'failed HTML save retains the exact valid own unsaved selection');
foreach([['csrf'=>'wrong'],['id'=>str_repeat('a',32)],['media_ids'=>[$foreign['id']]],['media_ids'=>[['bad']]],['media_ids'=>array_fill(0,101,$ids[0])]]as$bad)pickerCheck($picker->rejectedSelection($current,array_replace($post,$bad),'own-token')===$current,'invalid or foreign failed selection never replaces saved originals');
http_response_code(200);pickerCheck($picker->rejectedSelection($current,$post,'own-token')===$current,'successful rendering uses the actual saved selection');
if($oldMethod===null)unset($_SERVER['REQUEST_METHOD']);else $_SERVER['REQUEST_METHOD']=$oldMethod;
// Read every original album item in short windows, without loading the media store.
$album=$pages->save(['title'=>'Own complete album','slug'=>'galerie/own-complete','template'=>'gallery','media_ids'=>$selected,'gallery_items'=>$items]);$album=$pages->publish($album['id'],$album['version']);$albumPublication=$pages->publicPage($album['slug']);
$gallery=new \Kulturhus\Native\Web\Gallery($db,$access,$root.'/media','own-token');$beforeBrowsing=ownOriginalDatabaseHash($db,$tables);
for($i=1;$i<=17;$i++){
 $html=$gallery->manager($album['id'],['album_page'=>(string)$i]);$doc=new DOMDocument();$doc->loadHTML($html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$x=new DOMXPath($doc);
 $displayed=array_map(fn($node)=>$node->getAttribute('data-album-image'),iterator_to_array($x->query('//article[@data-album-image]')));
 pickerCheck($displayed===array_slice($selected,($i-1)*6,6),'album page '.$i.' retains the exact complete original order');
 pickerCheck($x->query('//select[@name="images[]"]/option')->length===6&&$x->query('//div[@data-page-media-picker]//img')->length===0,'album choice excludes every existing photo and loads only complete own labels');
}
$html=$gallery->manager($album['id'],['album_page'=>'999999999']);$doc->loadHTML($html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$x=new DOMXPath($doc);
pickerCheck($x->query('//article[@data-album-image]')->length===4&&$x->query('//select[@name="album_page"]/option[@selected and @value="17"]')->length===2,'high request reaches the last complete album page in both controls');
pickerCheck($x->query('//form[input[@name="direction" and @value="up"]]/button[@disabled]')->length===0&&$x->query('//form[input[@name="direction" and @value="down"]]/button[@disabled]')->length===1,'move buttons honor the complete original album rather than the visible slice');
foreach(['0','-1','1.0','01','1000000001',[],['1']]as$bad)pickerReject(fn()=>$gallery->manager($album['id'],['album_page'=>$bad]),'malformed album page rejected without writes');
$readerGallery=new \Kulturhus\Native\Web\Gallery($db,new Access($db,'reader'),$root.'/media','own-token');
$readerHtml=$readerGallery->manager($album['id'],['album_page'=>'17']);pickerCheck(substr_count($readerHtml,'data-album-image="')===4&&!str_contains($readerHtml,'data-album-upload-form')&&!str_contains($readerHtml,'name="images[]"'),'reader reaches all original photos without editorial actions');
pickerReject(fn()=>(new \Kulturhus\Native\Web\Gallery($db,new Access($db,'outsider'),$root.'/media','own-token'))->manager($album['id']),'another club cannot access original album',RuntimeException::class);
$translated=\Kulturhus\Native\Integration\Routes::translate('/?view=media&item='.$album['id'].'&album_page=17#album-image-list');pickerCheck(str_contains($translated,'album_page=17')&&str_ends_with($translated,'#album-image-list'),'integrated original page link retains its exact page and anchor');
pickerCheck(ownOriginalDatabaseHash($db,$tables)===$beforeBrowsing&&$pages->publicPage($album['slug'])===$albumPublication,'all album reads retain every original photo/page/revision and exact publication');
$oldMethod=$_SERVER['REQUEST_METHOD']??null;$_SERVER['REQUEST_METHOD']='POST';http_response_code(422);
$attach=['operation'=>'album-attach','csrf'=>'own-token','id'=>$album['id'],'images'=>[$ids[105],$ids[101]]];
pickerCheck($picker->rejectedAlbumSelection($album,$attach,'own-token')===$attach['images'],'failed album attachment retains valid exact own unsaved choice order');
foreach([['csrf'=>'wrong'],['id'=>str_repeat('a',32)],['images'=>[$foreign['id']]],['images'=>[[]]],['images'=>array_fill(0,101,$ids[105])]]as$bad)pickerCheck($picker->rejectedAlbumSelection($album,array_replace($attach,$bad),'own-token')===[],'invalid failed album choice cannot expose or adopt another original');
http_response_code(200);if($oldMethod===null)unset($_SERVER['REQUEST_METHOD']);else $_SERVER['REQUEST_METHOD']=$oldMethod;
// Inflate only new owned unused metadata; the chooser must never decode or buffer it.
Db::run($db,"UPDATE khcms_media SET variants_json=JSON_SET(variants_json,'$.own_unused',?) WHERE club_id=?",[str_repeat('x',60*1024),'synthetic-a']);
for($i=0;$i<900;$i++)Db::run($db,'INSERT INTO khcms_media SELECT ?,club_id,?, ?,mime,width,height,variants_json,sha256,created_by,created_at FROM khcms_media WHERE id=?',[bin2hex(random_bytes(16)),'Own metadata-only '.$i.'.png','Own unused label '.$i,$ids[0]]);
$proc=proc_open([...TestSupport::phpCommand(),'-d','memory_limit=16M',__FILE__,'labels-memory'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('Own memory process required.');fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);$proof=json_decode($output,true,64,JSON_THROW_ON_ERROR);
pickerCheck($code===0&&$errors===''&&$proof['options']===1006&&$proof['images']===0&&$proof['memory']<16*1024*1024,'complete labels work under 16 MiB with more than 50 MiB unused variant JSON');
for($i=0;$i<215;$i++)$pages->save(['title'=>'Own unrelated large draft '.$i,'slug'=>'own-album-unused-'.$i,'body'=>str_repeat('x',99000),'nav_visible'=>false]);
$unrelatedBytes=(int)Db::run($db,"SELECT SUM(OCTET_LENGTH(CAST(draft_json AS CHAR))) FROM khcms_pages WHERE club_id=? AND slug LIKE 'own-album-unused-%'",[$access->club()])->fetchColumn();
$beforeMemory=ownOriginalDatabaseHash($db,$tables);
$proc=proc_open([...TestSupport::phpCommand(),'-d','memory_limit=16M',__FILE__,'album-memory',$root.'/media',$album['id']],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('Own album memory process required.');fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);$albumMemory=json_decode($output,true,64,JSON_THROW_ON_ERROR);
pickerCheck($code===0&&$errors===''&&$albumMemory['options']===906&&$albumMemory['images']===4&&$albumMemory['memory']<16*1024*1024&&$unrelatedBytes>20*1024*1024,'actual complete album works under 16 MiB with 20 MiB unrelated drafts and 50 MiB unused variant JSON');
$albumMemory['unrelated_draft_bytes']=$unrelatedBytes;
pickerCheck(ownOriginalDatabaseHash($db,$tables)===$beforeMemory&&$pages->publicPage($album['slug'])===$albumPublication,'memory-limited actual album renderer writes no original row or publication');
foreach($pixels as$file=>$hash)if(hash_file('sha256',$file)!==$hash)throw new RuntimeException('An original photo variant changed.');
pickerCheck(true,'every actual stored original variant stays byte exact after all album views');
Db::run($db,"DELETE FROM native_grants WHERE actor_id='admin' AND module='website' AND capability='read'");
pickerReject(fn()=>$picker->render($selected),'immediate read revocation hides catalogue',DomainException::class);
echo json_encode(['suite'=>'native-page-media-picker','checks'=>$n,'memory_proof'=>$proof,'album_memory_proof'=>$albumMemory,'external_requests'=>0])."\n";

ob_end_flush();
