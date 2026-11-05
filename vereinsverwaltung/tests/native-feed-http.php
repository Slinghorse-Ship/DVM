<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db,HttpValidators,RuntimeSchema};
use Kulturhus\Native\Cms\{Pages,Schema};
use Kulturhus\Native\Integration\ManagerBridge;
require __DIR__.'/support/original-database-hash.php';
$db=TestSupport::db();TestSupport::seed($db);RuntimeSchema::install($db);Schema::install($db);
$club='own-feed-http-'.bin2hex(random_bytes(5));$nativeClub=ManagerBridge::clubKey($club);
Db::run($db,"UPDATE native_users SET club_id=? WHERE id IN ('admin','editor','reader')",[$nativeClub]);
$pages=new Pages($db,new Access($db,'admin'));$foreign=new Pages($db,new Access($db,'outsider'));$n=0;
function feedCheck(bool $ok,string $label):void{global$n;if(!$ok)throw new RuntimeException($label);++$n;echo 'PASS '.$label."\n";}
$tag='"own-real-etag"';
foreach([
 ['GET',$tag,true],['HEAD',$tag,true],['GET','W/'.$tag,true],['HEAD','"other", W/'.$tag,true],['GET','"other,with,comma", '.$tag,true],['GET',' * ',true],['HEAD','*',true],['GET','"OTHER-REAL-ETAG"',false],['POST',$tag,false],['GET','w/'.$tag,false],['GET','*, '.$tag,false],['GET',$tag.', malformed',false],['GET',$tag.',',true],['GET',', '.$tag,true],['GET',', , W/'.$tag.', , "other", ',true],['GET',', , ',false],['GET',', *, '.$tag,false],['GET',"\n".$tag,false],['GET','',false],['GET','"unclosed',false],['GET',str_repeat(' ',8193).$tag,false],['GET',' W/"own-real-etag" ',true],['GET','"quoted\\"broken", '.$tag,false],['GET',[],false],['PUT','*',false],['GET','""',false],['HEAD',"\tW/".$tag."\t",true],['GET',"\"opaque\xff\", ".$tag,true],['GET',"\"illegal\x7f\", ".$tag,false]
]as$i=>[$method,$header,$expected])feedCheck(HttpValidators::unchanged($method,$header,$tag)===$expected,'independent HTTP conditional contract '.$i);
$event=$pages->save(['template'=>'event','slug'=>'veranstaltungen/own-original','title'=>'Eigenes veröffentlichtes Feedoriginal ä🙂','body'=>'Genaue ursprüngliche öffentliche Beschreibung','event_start'=>'2035-01-12T19:00','event_end'=>'2035-01-12T22:00:00Z','event_ticket_url'=>'https://example.invalid/own-ticket']);$event=$pages->publish($event['id'],$event['version']);
$draft=$pages->save(['template'=>'event','slug'=>'veranstaltungen/private-original','title'=>'PRIVATE-FEED-QUESTION','body'=>'Private original bytes','event_start'=>'2035-01-13T19:00']);
$other=$foreign->save(['template'=>'event','slug'=>'veranstaltungen/foreign-original','title'=>'FOREIGN-FEED-QUESTION','event_start'=>'2035-01-14T19:00']);$foreign->publish($other['id'],$other['version']);
$work=sys_get_temp_dir().'/kh-feed-http-'.bin2hex(random_bytes(8));mkdir($work,0700);mkdir($work.'/media',0700);mkdir($work.'/web',0700);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('Loopback test socket unavailable.');$address=stream_socket_get_name($socket,false);fclose($socket);$origin='http://'.$address;
$router=$work.'/router.php';file_put_contents($router,'<?php declare(strict_types=1); require '.var_export(dirname(__DIR__).'/native/bootstrap.php',true).'; $db=\\Kulturhus\\Native\\TestSupport::db(); if(!\\Kulturhus\\Native\\Integration\\PublicSite::serve($db,new \\Kulturhus\\Native\\Shared\\Access($db,"reader"),'.var_export($work.'/media',true).','.var_export($work,true).','.var_export($club,true).','.var_export($origin,true).')){http_response_code(404);header("Cache-Control: no-store");}');
$process=proc_open([...TestSupport::phpCommand(),'-S',$address,'-t',$work.'/web',$router],[0=>['pipe','r'],1=>['file',$work.'/server.log','a'],2=>['file',$work.'/server.log','a']],$pipes);if(!is_resource($process))throw new RuntimeException('Synthetic HTTP server unavailable.');fclose($pipes[0]);
$request=static function(string $path,string $method='GET',array $headers=[])use($origin):array{
    $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'ignore_errors'=>true,'timeout'=>10,'follow_location'=>0]]);$body=@file_get_contents($origin.$path,false,$context);$raw=$http_response_header??[];
    preg_match('~\AHTTP/\S+ (\d{3})~',$raw[0]??'',$match);$parsed=[];foreach(array_slice($raw,1)as$line)if(str_contains($line,':')){[$name,$value]=explode(':',$line,2);$parsed[strtolower($name)]=trim($value);}
    return ['status'=>(int)($match[1]??0),'headers'=>$parsed,'body'=>$body===false?'':$body];
};
$tables=['khcms_pages','khcms_revisions','khcms_media','khcms_published_media','native_users','native_grants','native_delivery_jobs'];
try{
    for($i=0;$i<40;$i++){if($request('/feeds/events.json')['status']!==0)break;usleep(50000);}
    $originals=ownOriginalDatabaseHash($db,$tables);$responses=[];
    foreach(['/feeds/events.json','/feeds/veranstaltungen/','/feeds/events.ics','/feeds/termine/','/feeds/alle/','/feeds/news/','/feeds/news.rss.xml','/feeds/all.rss.xml']as$path){
        $r=$request($path);$responses[$path]=$r;$etag=$r['headers']['etag']??'';
        feedCheck($r['status']===200&&$etag==='"'.hash('sha256',$r['body']).'"','actual GET serves strong exact-byte representation '.$path);
        feedCheck(($r['headers']['cache-control']??'')==='public, max-age=0, must-revalidate','public feed cannot reuse a stale cancellation or withdrawal '.$path);
        feedCheck((int)($r['headers']['content-length']??-1)===strlen($r['body']),'real UTF-8 Content-Length is the actual byte count '.$path);
        $head=$request($path,'HEAD');feedCheck($head['status']===200&&$head['body']===''&&($head['headers']['etag']??'')===$etag&&(int)($head['headers']['content-length']??-1)===strlen($r['body']),'actual HEAD retains representation headers without body '.$path);
        foreach([$etag,'W/'.$etag,'"other,quoted,tag", W/'.$etag,', , W/'.$etag.', ,','*']as$conditional){$notModified=$request($path,'GET',['If-None-Match: '.$conditional]);feedCheck($notModified['status']===304&&$notModified['body']===''&&($notModified['headers']['etag']??'')===$etag,'real conditional GET uses RFC weak/list/wildcard comparison '.$path.' '.$conditional);}
        $miss=$request($path,'GET',['If-None-Match: "unrelated"']);feedCheck($miss['status']===200&&$miss['body']===$r['body'],'unmatched validator returns the exact original body '.$path);
        feedCheck(!str_contains($r['body'],'PRIVATE-FEED')&&!str_contains($r['body'],'FOREIGN-FEED'),'feeds retain only originally published own club data '.$path);
    }
    feedCheck($responses['/feeds/events.json']['body']===$responses['/feeds/veranstaltungen/']['body']&&$responses['/feeds/events.ics']['body']===$responses['/feeds/termine/']['body'],'original aliases share identical original bytes and validators');
    foreach(['/feeds/events.json','/feeds/veranstaltungen/']as$path){
        $cors=$request($path,'GET',['Origin: https://other-website.example.invalid']);feedCheck(($cors['headers']['access-control-allow-origin']??'')==='*'&&($cors['headers']['access-control-expose-headers']??'')==='ETag'&&!isset($cors['headers']['access-control-allow-credentials']),'actual public JSON can be read from another site without credentials '.$path);
        $options=$request($path,'OPTIONS',['Origin: https://other-website.example.invalid','Access-Control-Request-Method: GET','Access-Control-Request-Headers: If-None-Match']);feedCheck($options['status']===204&&$options['body']===''&&($options['headers']['access-control-allow-headers']??'')==='If-None-Match'&&($options['headers']['allow']??'')==='GET, HEAD, OPTIONS'&&!isset($options['headers']['etag'])&&!isset($options['headers']['content-length']),'real conditional JSON preflight is body-free and has no false representation '.$path);
        $notModified=$request($path,'HEAD',['Origin: https://other-website.example.invalid','If-None-Match: W/'.$cors['headers']['etag']]);feedCheck($notModified['status']===304&&$notModified['body']===''&&($notModified['headers']['access-control-allow-origin']??'')==='*','JSON validators retain CORS on actual body-free HEAD 304 '.$path);
        $refused=$request($path,'POST');feedCheck($refused['status']===405&&$refused['body']===''&&($refused['headers']['allow']??'')==='GET, HEAD, OPTIONS','public JSON never accepts a publication write '.$path);
    }
    foreach(['/feeds/events.ics','/feeds/news/','/feeds/unknown','/vereinsverwaltung/','/media-preview/'.$draft['id'].'/thumb']as$path){$r=$request($path,'OPTIONS',['Origin: https://other-website.example.invalid']);feedCheck(in_array($r['status'],[404,405],true)&&!isset($r['headers']['access-control-allow-origin']),'preflight cannot broaden public CORS to another namespace '.$path);}
    $single=$request('/feeds/termine/?event='.$event['id']);feedCheck($single['status']===200&&substr_count($single['body'],"BEGIN:VEVENT\r\n")===1,'single original ICS has its own exact public representation');
    $missing=$request('/feeds/termine/?event='.$draft['id'],'GET',['If-None-Match: *']);feedCheck($missing['status']===404&&!isset($missing['headers']['etag']),'wildcard validator cannot turn a private or missing event into 304');
    feedCheck($originals===ownOriginalDatabaseHash($db,$tables),'all HTTP reads, HEADs, preflights and refusals preserve every original and job byte');
    $saved=$pages->save(array_replace($event,['title'=>'Private pending actual change','body'=>'PRIVATE-PENDING-CHANGE']),$event['version']);
    $same=$request('/feeds/events.json','GET',['If-None-Match: '.$responses['/feeds/events.json']['headers']['etag']]);feedCheck($same['status']===304&&$same['body']==='','private editorial change cannot invalidate or leak into the approved JSON representation');
    $saved=$pages->save(array_replace($saved,['event_status'=>'cancelled']),$saved['version']);$saved=$pages->publish($saved['id'],$saved['version']);
    $changed=$request('/feeds/events.json','GET',['If-None-Match: '.$responses['/feeds/events.json']['headers']['etag']]);$json=json_decode($changed['body'],true,512,JSON_THROW_ON_ERROR);
    feedCheck($changed['status']===200&&$changed['headers']['etag']!==$responses['/feeds/events.json']['headers']['etag']&&$json['events'][0]['status']==='cancelled'&&$json['events'][0]['ticket_url']===null,'actual published cancellation immediately replaces the validator and removes ticket purchase link');
    feedCheck($json['events'][0]['id']===$event['id']&&$json['events'][0]['uid']===$event['id'].'@kulturhus-native.invalid','republished source retains its original stable event identity');
    Db::run($db,"UPDATE native_users SET active=0 WHERE id='reader'");$denied=$request('/feeds/events.json','GET',['Origin: https://other-website.example.invalid']);feedCheck($denied['status']===403&&!isset($denied['headers']['access-control-allow-origin']),'public reader revocation fails before exposing cross-origin feed data');
    echo $n." feed HTTP checks passed; actual loopback HTTP, immutable native originals, no external transport.\n";
}finally{
    proc_terminate($process);proc_close($process);
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as$file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($work);
}
