<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/native/bootstrap.php';require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db,RuntimeSchema};
use Kulturhus\Native\Cms\{Pages,Schema};
use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Native\Web\{FeedDocument,Feeds};
$checks=0;
function check(bool $ok,string $label):void{global$checks;if(!$ok)throw new RuntimeException('FAIL '.$label);++$checks;echo 'PASS '.$label."\n";}
function reject(callable $f,string $label):void{try{$f();}catch(InvalidArgumentException|DomainException|LogicException){check(true,$label);return;}throw new RuntimeException('FAIL accepted '.$label);}
function xml(string $body):DOMXPath{$doc=new DOMDocument();if(!$doc->loadXML($body,LIBXML_NONET))throw new RuntimeException('Invalid XML.');return new DOMXPath($doc);}
$db=TestSupport::db();TestSupport::seed($db);RuntimeSchema::install($db);Schema::install($db);
$managerClub='own-feed-stream-'.bin2hex(random_bytes(5));$club=ManagerBridge::clubKey($managerClub);Db::run($db,"UPDATE native_users SET club_id=? WHERE id IN ('admin','editor','reader')",[$club]);
$pages=new Pages($db,new Access($db,'admin'));$foreign=new Pages($db,new Access($db,'outsider'));$ids=[];$news=[];$body=str_repeat('Ursprüngliche Zeichen Ä🙂,;\\ und Originalinhalt. ',1800);
for($i=0;$i<180;$i++){$p=$pages->save(['template'=>'event','slug'=>'veranstaltungen/stream-'.$i,'title'=>'Öffentliches Original '.$i,'excerpt'=>'Eigener Originalausschnitt','body'=>$body,'event_start'=>'2035-01-12T19:00','event_timezone'=>'Europe/Berlin']);$p=$pages->publish($p['id'],$p['version']);$ids[]=$p['id'];}
for($i=0;$i<60;$i++){$p=$pages->save(['template'=>'news','slug'=>'aktuelles/stream-'.$i,'title'=>'Eigener RSS-Beitrag '.$i,'news_date'=>'2035-01-11','news_channels'=>['rss'],'body'=>$body]);$p=$pages->publish($p['id'],$p['version']);$news[]=$p['id'];}
$plain=$pages->save(['slug'=>'verein/plain-original','title'=>'Ursprüngliche allgemeine Seite']);$plain=$pages->publish($plain['id'],$plain['version']);
$undated=$pages->save(['template'=>'event','slug'=>'veranstaltungen/undated-original','title'=>'Original ohne Terminzeit']);$undated=$pages->publish($undated['id'],$undated['version']);
$draft=$pages->save(['template'=>'event','slug'=>'veranstaltungen/private-original','title'=>'PRIVATE-STREAM-ORIGINAL','event_start'=>'2035-01-13T19:00']);
$other=$foreign->save(['template'=>'event','slug'=>'veranstaltungen/foreign-original','title'=>'FOREIGN-STREAM-ORIGINAL','event_start'=>'2035-01-13T19:00']);$foreign->publish($other['id'],$other['version']);
$times=[];foreach([
 ['fold-early','2026-10-25T02:30','Europe/Berlin','2026-10-25T00:30:00Z','2026-10-25T01:15:00Z'],
 ['fold-late','2026-10-25T02:30','Europe/Berlin','2026-10-25T01:30:00Z','2026-10-25T03:00:00Z'],
 ['new-york','2027-11-07T01:30','America/New_York','2027-11-07T06:30:00Z',''],
 ['leap-day','2028-02-29T19:00','Europe/Berlin','2028-02-29T18:00:00Z',''],
]as[$slug,$local,$zone,$start,$end]){$p=$pages->save(['template'=>'event','slug'=>'veranstaltungen/'.$slug,'title'=>'Zeitanker '.$slug,'event_start'=>$local,'event_timezone'=>$zone,'event_start_utc'=>$start,'event_end'=>$end]);$p=$pages->publish($p['id'],$p['version']);$times[$p['id']]=['start'=>$start,'end'=>$end,'title'=>$p['title']];}
reject(fn()=>$pages->save(['template'=>'event','slug'=>'invalid-spring-gap','title'=>'Unmögliche Uhrzeit','event_start'=>'2027-03-28T02:30','event_timezone'=>'Europe/Berlin']),'spring clock gap cannot become an invented public UTC anchor');
reject(fn()=>$pages->save(['template'=>'event','slug'=>'invalid-leap-day','title'=>'Unmögliches Datum','event_start'=>'2027-02-29T19:00']),'invalid leap day is rejected before a public original exists');
// Equal publication times and more than one metadata batch exercise exact cursor ties.
Db::run($db,"UPDATE khcms_pages SET published_at='2030-01-01 10:00:00.000000' WHERE club_id=? AND published_revision IS NOT NULL",[$club]);sort($ids,SORT_STRING);sort($news,SORT_STRING);
$changed=$pages->get($ids[0]);$changed=$pages->save(array_replace($changed,['title'=>'PRIVATE-PENDING-STREAM','body'=>'PRIVATE-PENDING-TEXT']),$changed['version']);
$tables=['khcms_pages','khcms_revisions','khcms_media','khcms_published_media','native_users','native_grants','native_delivery_jobs'];$before=ownOriginalDatabaseHash($db,$tables);
$document=new FeedDocument();reject(fn()=>$document->etag(),'incomplete representation cannot expose a validator');$document->append('Ä🙂 exact');$document->seal();check($document->contents()==='Ä🙂 exact'&&$document->length()===strlen('Ä🙂 exact')&&$document->etag()==='"'.hash('sha256','Ä🙂 exact').'"','sealed temporary representation has exact Unicode bytes and validator');reject(fn()=>$document->append('changed'),'sealed original cannot be changed');$document->close();reject(fn()=>$document->contents(),'closed temporary representation cannot be read');
$work=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/feed-stream';mkdir($work,0700);mkdir($work.'/web',0700);mkdir($work.'/media',0700);mkdir($work.'/temporary',0700);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('Loopback unavailable.');$address=stream_socket_get_name($socket,false);fclose($socket);$origin='http://'.$address;
$router=$work.'/router.php';file_put_contents($router,'<?php declare(strict_types=1); require '.var_export(dirname(__DIR__).'/native/bootstrap.php',true).'; register_shutdown_function(static function(){file_put_contents('.var_export($work.'/peak.json',true).',json_encode(["memory"=>memory_get_peak_usage(true)]));}); $db=\\Kulturhus\\Native\\TestSupport::db(); if(!\\Kulturhus\\Native\\Integration\\PublicSite::serve($db,new \\Kulturhus\\Native\\Shared\\Access($db,"reader"),'.var_export($work.'/media',true).','.var_export($work,true).','.var_export($managerClub,true).','.var_export($origin,true).')){http_response_code(404);header("Cache-Control: no-store");}');
$process=proc_open([...TestSupport::phpCommand(),'-d','memory_limit=16M','-d','sys_temp_dir='.$work.'/temporary','-S',$address,'-t',$work.'/web',$router],[0=>['pipe','r'],1=>['file',$work.'/server.log','a'],2=>['file',$work.'/server.log','a']],$pipes);if(!is_resource($process))throw new RuntimeException('Own HTTP server unavailable.');fclose($pipes[0]);
$request=static function(string $path,string $method='GET',array $headers=[])use($origin):array{$ctx=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'ignore_errors'=>true,'timeout'=>60,'follow_location'=>0]]);$body=@file_get_contents($origin.$path,false,$ctx);$raw=$http_response_header??[];preg_match('~\AHTTP/\S+ (\d{3})~',$raw[0]??'',$match);$parsed=[];foreach(array_slice($raw,1)as$line)if(str_contains($line,':')){[$key,$value]=explode(':',$line,2);$parsed[strtolower($key)]=trim($value);}return['status'=>(int)($match[1]??0),'body'=>$body===false?'':$body,'headers'=>$parsed];};
try{
    for($i=0;$i<40;$i++){if($request('/feeds/events.json','HEAD')['status']!==0)break;usleep(50000);}
    $feeds=new Feeds(new Pages($db,new Access($db,'reader')),$origin);
    $bytes=(int)Db::run($db,'SELECT SUM(OCTET_LENGTH(r.payload_json)) FROM khcms_pages p JOIN khcms_revisions r ON r.page_id=p.id AND r.revision=p.published_revision AND r.club_id=p.club_id WHERE p.club_id=?',[$club])->fetchColumn();check($bytes>16*1024*1024,'owned immutable public corpus actually exceeds the server memory limit');
    $metrics=[];
    foreach(['/feeds/events.json','/feeds/events.ics','/feeds/all.rss.xml','/feeds/news/','/feeds/alle/']as$route){
        $response=$request($route);check($response['status']===200&&!str_contains($response['body'],'PRIVATE-')&&!str_contains($response['body'],'FOREIGN-'),'actual 16-MB HTTP process serves complete own public originals '.$route);
        check($response['headers']['etag']==='"'.hash('sha256',$response['body']).'"'&&(int)$response['headers']['content-length']===strlen($response['body']),'streamed HTTP has exact full-body SHA and byte length '.$route);
        $proof=json_decode(file_get_contents($work.'/peak.json'),true,16,JSON_THROW_ON_ERROR);check($proof['memory']<16*1024*1024,'actual whole GET stays below 16 MB including generation, encoding and sending '.$route);
        $metrics[$route]=['bytes'=>strlen($response['body']),'php_peak_bytes'=>$proof['memory']];
        $head=$request($route,'HEAD');check($head['status']===200&&$head['body']===''&&$head['headers']['etag']===$response['headers']['etag']&&(int)$head['headers']['content-length']===strlen($response['body']),'large HEAD uses the same complete representation without loading or sending its body '.$route);
        $notModified=$request($route,'GET',['If-None-Match: W/'.$response['headers']['etag']]);check($notModified['status']===304&&$notModified['body']===''&&$notModified['headers']['etag']===$response['headers']['etag'],'large conditional GET retains exact original validator '.$route);
        check(glob($work.'/temporary/*')===[],'GET, HEAD and 304 leave no retained temporary original or public cache file '.$route);
        if($route==='/feeds/events.json'){
            check(strlen($response['body'])>16*1024*1024,'actual streamed JSON representation itself exceeds 16 MB without truncation');$json=json_decode($response['body'],true,512,JSON_THROW_ON_ERROR);$actual=array_column($json['events'],'id');
            check(count($actual)===185&&count(array_unique($actual))===185,'JSON has every dated and undated original exactly once across metadata batches');
            check(array_slice($actual,4,180)===$ids&&$actual[array_key_last($actual)]===$undated['id'],'equal UTC starts retain stable original IDs and undated event sorts last');
            foreach($json['events']as$e)if(isset($times[$e['id']]))check($e['start_utc']===$times[$e['id']]['start']&&$e['end_utc']===($times[$e['id']]['end']?:null),'published clock-fold or leap-day original retains exact UTC start and optional end '.$e['title']);
            unset($json);
        }elseif($route==='/feeds/events.ics'){
            check(strlen($response['body'])>16*1024*1024&&substr_count($response['body'],"BEGIN:VEVENT\r\n")===184,'actual large ICS retains every dated original without artificial entry limit');
            $unfold=preg_replace('/\r\n[ \t]/','',$response['body']);foreach($times as$id=>$time)check(str_contains($unfold,'UID:'.$id.'@kulturhus-native.invalid')&&str_contains($unfold,'DTSTART:'.str_replace(['-',':'],'',substr($time['start'],0,10)).'T'.str_replace(':','',substr($time['start'],11,8)).'Z'),'ICS retains exact native identity and original UTC anchor '.$time['title']);
            check(!str_contains($unfold,$undated['id'])&&!str_contains($unfold,'DTEND:20350112'),'undated original and invented end never become calendar entries');
            $lines=explode("\r\n",$response['body']);check(max(array_map(strlen(...),$lines))<=75&&!array_filter($lines,static fn($line)=>preg_match('//u',$line)!==1),'large escaped Unicode bodies fold at 75 bytes without breaking a code point');unset($unfold,$lines);
        }else{
            $xml=xml($response['body']);$expected=$route==='/feeds/all.rss.xml'?246:50;check($xml->query('//item')->length===$expected,'actual RSS preserves its exact all/news/mixed selection limit '.$route);
            if($route==='/feeds/news/'){$actual=[];foreach($xml->query('//item/guid')as$node)$actual[]=strstr($node->textContent,'@',true);check($actual===array_slice($news,0,50),'50 newest original RSS-only news remain stable across tied publication times');}
            unset($xml);
        }
        unset($response);
    }
    $id=array_key_first($times);$single=$request('/feeds/termine/?event='.$id);$head=$request('/feeds/events.ics?event='.$id,'HEAD');
    check($single['status']===200&&substr_count($single['body'],"BEGIN:VEVENT\r\n")===1&&$single['headers']['content-disposition']==='attachment; filename="termin-'.$id.'.ics"','actual selected original downloads as a safe native ICS filename');
    check($head['status']===200&&$head['body']===''&&$head['headers']['content-disposition']===$single['headers']['content-disposition']&&$head['headers']['etag']===$single['headers']['etag'],'single original download HEAD retains exact attachment and representation headers');
    foreach([$draft['id'],$other['id'],$undated['id'],str_repeat('a',32)]as$invalid){$response=$request('/feeds/termine/?event='.$invalid,'GET',['If-None-Match: *']);check($response['status']===404&&!isset($response['headers']['etag'])&&!isset($response['headers']['content-disposition']),'private, foreign, undated or absent original cannot download an empty or false 304 calendar');}
    check($before===ownOriginalDatabaseHash($db,$tables),'all large feeds, downloads, validators and rejections retain every exact original database byte');
}finally{proc_terminate($process);proc_close($process);}
// Club changes with an equal generation must still reject the original representation.
Db::run($db,"UPDATE khcms_state SET generation=(SELECT counter FROM (SELECT generation AS counter FROM khcms_state WHERE club_id=?) own_counter) WHERE club_id='synthetic-b'",[$club]);
$reader=new class($db)extends Access{public function __construct(private PDO $db){parent::__construct($db,'reader');}public function require(string $m,string $c):array{foreach(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,5)as$f)if(($f['function']??'')==='publicOriginals'){Db::run($this->db,"UPDATE native_users SET club_id='synthetic-b' WHERE id='reader'");break;}return parent::require($m,$c);}};
reject(fn()=>(new Feeds(new Pages($db,$reader),$origin))->rss(),'current club change cannot expose an earlier clubs original even if publication counters coincide');
echo $checks." native feed-stream checks passed; complete real 16-MB HTTP output, immutable originals, no external transport.\n";
echo json_encode(['passed'=>$checks,'public_payload_bytes'=>$bytes,'representations'=>$metrics,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
