<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/native/bootstrap.php';
require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db,RuntimeSchema};
use Kulturhus\Native\Cms\{Media,Pages,Schema};
use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Native\Web\Feeds;
$checks=0;
function check(bool $ok,string $label):void{global$checks;if(!$ok)throw new RuntimeException('FAIL '.$label);++$checks;echo 'PASS '.$label."\n";}
function rss(string $body):DOMXPath{$doc=new DOMDocument();if(!$doc->loadXML($body,LIBXML_NONET))throw new RuntimeException('Invalid RSS.');return new DOMXPath($doc);}
function event(array $body,string $id):array{foreach($body['events']as$item)if($item['id']===$id)return$item;throw new RuntimeException('Event absent.');}
final class ImageInterleaveAccess extends Access
{
    public ?Closure $interleave=null;
    public function require(string $module,string $capability):array
    {
        $actor=parent::require($module,$capability);
        if($this->interleave!==null)foreach(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,8)as$frame)if(($frame['class']??'')===Media::class&&($frame['function']??'')==='publicVariant'){
            $run=$this->interleave;$this->interleave=null;$run();break;
        }
        return $actor;
    }
}
$db=TestSupport::db();TestSupport::seed($db);RuntimeSchema::install($db);Schema::install($db);
$managerClub='own-feed-images-'.bin2hex(random_bytes(5));$club=ManagerBridge::clubKey($managerClub);
Db::run($db,"UPDATE native_users SET club_id=? WHERE id IN ('admin','editor','reader')",[$club]);
$admin=new Access($db,'admin');$reader=new ImageInterleaveAccess($db,'reader');$foreign=new Access($db,'outsider');$pages=new Pages($db,$admin);
$work=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/feed-images';mkdir($work,0700);mkdir($work.'/web',0700);
$media=new Media($db,$admin,$work.'/media');$foreignMedia=new Media($db,$foreign,$work.'/media');$images=[];$files=[];
for($i=0;$i<5;$i++){
    $pixels=imagecreatetruecolor(96+$i,48+$i);imagefill($pixels,0,0,imagecolorallocate($pixels,($i*61)%256,($i*113)%256,($i*37)%256));
    $path=$work.'/own-'.$i.'.png';imagepng($pixels,$path);imagedestroy($pixels);
    $image=($i===4?$foreignMedia:$media)->upload($path,'own-'.$i.'.png','Originalbild '.$i.' ä🙂 & <Kunst>');unlink($path);$images[]=$image['id'];
    $variant=($i===4?$foreignMedia:$media)->variant($image['id'],'large');$files[$variant['path']]=hash_file('sha256',$variant['path']);
}
$p=$pages->save(['template'=>'event','slug'=>'veranstaltungen/own-original','title'=>'Originaltermin & <Kunst>','excerpt'=>'<script>private code</script> & echter Kurztext','body'=>'Exakte öffentliche Originalbeschreibung ä🙂','event_start'=>'2035-01-12T19:00','event_timezone'=>'America/New_York','event_end'=>'2035-01-13T03:15:00Z','event_location'=>'Eigener Saal','event_address'=>"Eigene Straße 1\nEigene Stadt",'event_price'=>'12,50','event_ticket_url'=>'https://example.invalid/own-ticket?x=1&y=2','media_ids'=>array_slice($images,0,3)]);$p=$pages->publish($p['id'],$p['version']);
$news=$pages->save(['template'=>'news','slug'=>'aktuelles/own-rss','title'=>'Eigener RSS-Beitrag','news_date'=>'2035-01-11','news_channels'=>['rss'],'body'=>'Originalnachricht','media_ids'=>[$images[1]]]);$news=$pages->publish($news['id'],$news['version']);
$empty=$pages->save(['template'=>'event','slug'=>'veranstaltungen/own-empty','title'=>'Eigener Termin ohne Bild','event_start'=>'2035-01-14T12:00']);$empty=$pages->publish($empty['id'],$empty['version']);
$otherPages=new Pages($db,$foreign);$other=$otherPages->save(['template'=>'event','slug'=>'veranstaltungen/foreign','title'=>'FOREIGN-IMAGE-QUESTION','event_start'=>'2035-01-15T12:00','media_ids'=>[$images[4]]]);$otherPages->publish($other['id'],$other['version']);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('Loopback unavailable.');$address=stream_socket_get_name($socket,false);fclose($socket);$origin='http://'.$address;
$feeds=new Feeds(new Pages($db,$reader),$origin,'Eigenes Feedoriginal',new Media($db,$reader,$work.'/media'));
$json=static fn()=>json_decode($feeds->eventsJson(),true,512,JSON_THROW_ON_ERROR);
$legacy=json_decode((new Feeds(new Pages($db,$reader),$origin))->eventsJson(),true,512,JSON_THROW_ON_ERROR);
check(!array_key_exists('images',event($legacy,$p['id'])),'existing three-argument projection retains its field contract');
$original=event($json(),$p['id']);$descriptors=[];
foreach(array_slice($images,0,3)as$i=>$id){$v=$media->variant($id,'large');$descriptors[]=['url'=>$origin.'/media/'.$id.'/large','mime'=>'image/png','width'=>96+$i,'height'=>48+$i,'bytes'=>filesize($v['path']),'alt'=>'Originalbild '.$i.' ä🙂 & <Kunst>'];}
check($original['images']===$descriptors&&$original['image']===$descriptors[0],'JSON exposes every exact published image in its original order and original cover');
check($original['start_utc']==='2035-01-13T00:00:00Z'&&$original['end_utc']==='2035-01-13T03:15:00Z'&&$original['timezone']==='America/New_York','image extension preserves exact original event time anchors');
check(event($json(),$empty['id'])['image']===null&&event($json(),$empty['id'])['images']===[],'image-free original has explicit null cover and empty collection');
foreach(['path','sha256','created_by','original_name','media_id']as$key)check(!array_key_exists($key,$original['image']),'public descriptor omits private media metadata '.$key);
$rss=rss($feeds->rss('mixed'));$item='//item[guid="'.$p['id'].'@kulturhus-native.invalid"]';$enclosure=$rss->query($item.'/enclosure');
check($enclosure->length===1&&$enclosure->item(0)->getAttribute('url')===$descriptors[0]['url']&&$enclosure->item(0)->getAttribute('type')==='image/png'&&(int)$enclosure->item(0)->getAttribute('length')===$descriptors[0]['bytes'],'RSS uses one exact original cover enclosure with real MIME and file bytes');
$description=html_entity_decode($rss->query($item.'/description')->item(0)->textContent,ENT_QUOTES|ENT_HTML5,'UTF-8');
foreach(['Datum: 12.01.2035','Uhrzeit: 19:00 Uhr','Zeitzone: America/New_York','Ende: 12.01.2035 22:15 Uhr','Veranstaltungsort: Eigener Saal','Anschrift: Eigene Straße 1 Eigene Stadt','Eintritt: 12,50 EUR','Tickets: https://example.invalid/own-ticket?x=1&y=2']as$fact)check(str_contains($description,$fact),'mixed RSS retains actual saved event fact '.$fact);
check($rss->query('//script')->length===0,'original markup is inert in RSS XML');
check(rss($feeds->rss('news'))->query('//item')->length===1&&rss($feeds->rss('news'))->query('//item/enclosure')->item(0)->getAttribute('url')===$descriptors[1]['url'],'RSS-only news retains its own original image and never substitutes event cover');
check(!(new Media($db,$reader,$work.'/media'))->publicVariant($images[3],'large')&&!(new Media($db,$reader,$work.'/media'))->publicVariant($images[4],'large'),'private and published foreign image cannot become own public metadata');
$publicBefore=$feeds->eventsJson();$publicRss=$feeds->rss('mixed');
$p=$pages->save(array_replace($p,['title'=>'PRIVATE-PENDING-IMAGE','body'=>'PRIVATE-PENDING-BODY','media_ids'=>[$images[3]],'event_start'=>'2035-02-01T09:00','event_end'=>'2035-02-01T16:00:00Z']),$p['version']);
check($feeds->eventsJson()===$publicBefore&&$feeds->rss('mixed')===$publicRss,'private title, time and image replacements preserve exact published bytes');
$tables=['khcms_pages','khcms_revisions','khcms_media','khcms_published_media','native_users','native_grants','native_delivery_jobs'];$before=ownOriginalDatabaseHash($db,$tables);
$router=$work.'/router.php';file_put_contents($router,'<?php declare(strict_types=1); require '.var_export(dirname(__DIR__).'/native/bootstrap.php',true).'; $db=\\Kulturhus\\Native\\TestSupport::db(); if(!\\Kulturhus\\Native\\Integration\\PublicSite::serve($db,new \\Kulturhus\\Native\\Shared\\Access($db,"reader"),'.var_export($work.'/media',true).','.var_export($work,true).','.var_export($managerClub,true).','.var_export($origin,true).')){http_response_code(404);header("Cache-Control: no-store");}');
$process=proc_open([...TestSupport::phpCommand(),'-S',$address,'-t',$work.'/web',$router],[0=>['pipe','r'],1=>['file',$work.'/server.log','a'],2=>['file',$work.'/server.log','a']],$pipes);if(!is_resource($process))throw new RuntimeException('Own HTTP server unavailable.');fclose($pipes[0]);
$request=static function(string $path,string $method='GET',array $headers=[])use($origin):array{$ctx=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'ignore_errors'=>true,'timeout'=>10,'follow_location'=>0]]);$body=@file_get_contents($origin.$path,false,$ctx);$raw=$http_response_header??[];preg_match('~\AHTTP/\S+ (\d{3})~',$raw[0]??'',$match);$parsed=[];foreach(array_slice($raw,1)as$line)if(str_contains($line,':')){[$key,$value]=explode(':',$line,2);$parsed[strtolower($key)]=trim($value);}return['status'=>(int)($match[1]??0),'body'=>$body===false?'':$body,'headers'=>$parsed];};
try{
    for($i=0;$i<40;$i++){if($request('/feeds/events.json')['status']!==0)break;usleep(50000);}
    foreach(['/feeds/events.json','/feeds/veranstaltungen/']as$route){$response=$request($route,'GET',['Origin: https://example.invalid']);$body=json_decode($response['body'],true,512,JSON_THROW_ON_ERROR);
        check($response['status']===200&&event($body,$p['id'])['images']===$descriptors,'actual anonymous HTTP alias exposes only immutable original image descriptors '.$route);
        check($response['headers']['etag']==='"'.hash('sha256',$response['body']).'"'&&$response['headers']['access-control-allow-origin']==='*','image-bearing JSON retains exact representation validator and anonymous CORS '.$route);
        $notModified=$request($route,'GET',['If-None-Match: W/'.$response['headers']['etag']]);check($notModified['status']===304&&$notModified['body']==='','actual image-bearing alias revalidates without a body '.$route);
    }
    foreach($descriptors as$i=>$descriptor){$path='/media/'.$images[$i].'/large';$response=$request($path);$head=$request($path,'HEAD');
        check($response['status']===200&&hash('sha256',$response['body'])===$files[$media->variant($images[$i],'large')['path']],'actual anonymous image GET returns exact native original pixels '.$i);
        check($response['headers']['content-type']===$descriptor['mime']&&strlen($response['body'])===$descriptor['bytes']&&(int)$response['headers']['content-length']===$descriptor['bytes'],'feed descriptor MIME and bytes describe the actual HTTP image '.$i);
        check($head['status']===200&&$head['body']===''&&(int)$head['headers']['content-length']===$descriptor['bytes'],'actual image HEAD retains the original byte length '.$i);
    }
    foreach([$images[3],$images[4]]as$id)check($request('/media/'.$id.'/large')['status']===404,'real public image route denies private or foreign original');
    $httpRss=$request('/feeds/alle/');check($httpRss['status']===200&&rss($httpRss['body'])->query($item.'/enclosure')->item(0)->getAttribute('url')===$descriptors[0]['url'],'actual mixed RSS route exposes exact immutable original enclosure');
    check($before===ownOriginalDatabaseHash($db,$tables),'all image, feed, alias, HEAD and conditional reads retain every original database byte');
    foreach($files as$path=>$hash)check(hash_file('sha256',$path)===$hash,'all actual HTTP reads retain exact source image bytes');
}finally{proc_terminate($process);proc_close($process);}
$cover=$media->variant($images[0],'large')['path'];$bytes=file_get_contents($cover);unlink($cover);clearstatcache(true,$cover);
try{
    $missing=event($json(),$p['id']);check($missing['image']===null&&$missing['images']===array_slice($descriptors,1),'missing original cover remains null while subsequent originals retain order');
    check(rss($feeds->rss('mixed'))->query($item.'/enclosure')->length===0,'missing cover never silently replaces the original with a later image');
}finally{file_put_contents($cover,$bytes);chmod($cover,0600);clearstatcache(true,$cover);}
foreach(['cancelled'=>'Abgesagt','sold_out'=>'Ausverkauft','postponed'=>'Verschoben']as$status=>$label){
    $p=$pages->save(array_replace($p,['event_status'=>$status,'title'=>'Originaltermin & <Kunst>','media_ids'=>array_slice($images,0,3),'event_start'=>'2035-01-12T19:00','event_end'=>'2035-01-13T03:15:00Z']),$p['version']);$p=$pages->publish($p['id'],$p['version']);$x=rss($feeds->rss('mixed'));$text=html_entity_decode($x->query($item.'/description')->item(0)->textContent,ENT_QUOTES|ENT_HTML5,'UTF-8');
    check(str_contains($text,'Status: '.$label)&&str_contains($text,'Tickets:')===($status==='postponed'),'RSS represents actual status and suppresses cancelled or sold-out ticket purchase '.$status);
    check((event($json(),$p['id'])['ticket_url']!==null)===($status==='postponed'),'JSON original status keeps its existing ticket suppression '.$status);
}
$second=TestSupport::db();$secondPages=new Pages($second,new Access($second,'admin'));
$reader->interleave=static function()use($secondPages,$p){$current=$secondPages->get($p['id']);$secondPages->unpublish($p['id'],$current['version']);};
check(!str_contains($feeds->eventsJson(),$p['id']),'withdrawal on a separate real connection during image rendering cannot leave stale JSON event or image');
$newsItem='//item[guid="'.$news['id'].'@kulturhus-native.invalid"]';$reader->interleave=static function()use($secondPages,$news){$current=$secondPages->get($news['id']);$secondPages->unpublish($news['id'],$current['version']);};
check(rss($feeds->rss('news'))->query($newsItem)->length===0,'withdrawal during RSS enclosure rendering restarts the entire immutable public representation');
Db::run($db,"DELETE FROM native_grants WHERE actor_id='reader' AND module='website' AND capability='read'");
try{$feeds->eventsJson();throw new LogicException('Revoked reader accepted.');}catch(DomainException){check(true,'existing image feed object immediately enforces current read revocation');}
Db::run($db,"INSERT INTO native_grants VALUES('reader','website','read')");Db::run($db,"UPDATE native_users SET active=0 WHERE id='reader'");
try{$feeds->rss();throw new LogicException('Disabled reader accepted.');}catch(DomainException){check(true,'existing image feed object immediately enforces disabled actor');}
echo $checks." native feed-image checks passed; exact originals, real MySQL and HTTP, no external transport.\n";
