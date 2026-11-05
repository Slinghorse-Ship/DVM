<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\Pages;
use Kulturhus\Native\Web\PublicRoutes;

$db=TestSupport::db();TestSupport::seed($db);Kulturhus\Native\Cms\Schema::install($db);Kulturhus\Native\Communication\Schema::install($db);
$pages=new Pages($db,new Access($db,'admin'));
$slugs=['start','kontakt','impressum','datenschutz','datenloeschung','nutzungsbedingungen','satzung','verein','verein/team/kontakt'];$saved=[];
foreach($slugs as$slug){$page=$pages->save(['slug'=>$slug,'title'=>'Own published '.$slug,'body'=>'Ungültiger synthetischer Testtext Ä🙂','body_html'=>'<p>Ungültiger synthetischer Testtext Ä🙂</p>','nav_visible'=>false]);$saved[$slug]=$pages->publish($page['id'],1);}
foreach([['slug'=>'veranstaltungen','title'=>'Own events'],['slug'=>'veranstaltungen/own-original','title'=>'Own published event Ä','template'=>'event','event_start'=>'2035-05-12T19:00'],['slug'=>'aktuelles','title'=>'Own news'],['slug'=>'aktuelles/own-original','title'=>'Own published news Ä','template'=>'news','news_date'=>'2026-10-11'],['slug'=>'galerie','title'=>'Own gallery','template'=>'gallery'],['slug'=>'galerie/own-original','title'=>'Own published album Ä','template'=>'gallery']]as$input){$page=$pages->save($input);$saved[$page['slug']]=$pages->publish($page['id'],1);}
$private=array_replace($saved['kontakt'],['title'=>'PRIVATE ALIAS TITLE','body_html'=>'<p>PRIVATE ALIAS BODY</p>']);$pages->save($private,$private['version']);
$draft=$pages->save(['slug'=>'verein/private-original','title'=>'PRIVATE ALIAS DRAFT']);
$foreign=new Pages($db,new Access($db,'outsider'));$other=$foreign->save(['slug'=>'kontakt/foreign-original','title'=>'FOREIGN ALIAS ORIGINAL']);$foreign->publish($other['id'],1);
$tables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);$before=ownOriginalDatabaseHash($db,$tables);$counts=[];foreach($tables as$table)$counts[$table]=(int)Db::run($db,'SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
$config=TestSupport::config();$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$config['database'];mkdir($root.'/media',0700,true);mkdir($root.'/web',0700);
$image=imagecreatetruecolor(48,32);imagepng($image,$root.'/media/original.png');unset($image);$fileHash=hash_file('sha256',$root.'/media/original.png');
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('Own HTTP port required.');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);$origin='http://127.0.0.1:'.$port;
$n=0;$server=null;$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException($label);$n++;echo 'PASS '.$label."\n";};
$http=static function(string $path,string $method='GET')use($origin):array{$headers=[];$curl=curl_init($origin.$path);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_NOBODY=>$method==='HEAD',CURLOPT_TIMEOUT=>15,CURLOPT_HEADERFUNCTION=>static function($handle,string $line)use(&$headers):int{$headers[]=trim($line);return strlen($line);}]);$body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);if(!is_string($body))throw new RuntimeException('Own alias HTTP required.');return[$status,$body,$headers];};
try{
    putenv('KH_NATIVE_LOCAL_DEMO=synthetic');putenv('KH_NATIVE_DEMO_PORT='.$port);putenv('KH_NATIVE_REQUIRE_LOGIN=1');
    $server=proc_open([...TestSupport::phpCommand(),'-d','session.cookie_secure=0','-S','127.0.0.1:'.$port,'-t',$root.'/web',dirname(__DIR__).'/native/Web/router.php'],[0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']],$pipes,dirname(__DIR__,2));if(!is_resource($server))throw new RuntimeException('Own alias server required.');fclose($pipes[0]);
    for($i=0;$i<100;$i++){$ready=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1);if($ready){fclose($ready);break;}usleep(50000);}
    foreach($saved as$slug=>$page){
        if($slug==='start')continue;
        $alias='/'.$slug.'/';$check(PublicRoutes::pageAlias($alias)===$slug,'shared public alias preserves the exact original slug');
        [$status,$html]=$http($alias);$check($status===200&&!str_contains($html,'PRIVATE ALIAS')&&!str_contains($html,'FOREIGN ALIAS'),'actual anonymous direct alias shows only its own publication '.$slug);
        $dom=new DOMDocument();@$dom->loadHTML($html);$doc=new DOMXPath($dom);
        $check($doc->query('//link[contains(@href,"bootstrap-6.0.0-alpha.1.min.css")]')->length===1,'actual anonymous alias retains the local public framework');
        if(in_array($slug,$slugs,true))$check(str_contains($html,'Own published '.$slug)&&str_contains($html,'Ungültiger synthetischer Testtext'),'actual contact/legal/club alias retains exact published content');
        [$siteStatus,$siteHtml]=$http('/site/'.$slug);$check($siteStatus===200&&$siteHtml===$html,'direct alias and native site route return identical original bytes');
        [$headStatus,$head]=$http($alias,'HEAD');$check($headStatus===200&&$head==='','actual alias HEAD remains anonymous without a response body');
    }
    foreach(['/verein/private-original/','/kontakt/foreign-original/','/datenschutz/missing/']as$path){[$status]=$http($path);$check($status===404,'unpublished, foreign and missing public originals stay unavailable');}
    foreach(['/accounts','/design-preview','/media-preview','/website-document-preview','/album-photo-status']as$path){$check(PublicRoutes::pageAlias($path)===null,'private dispatcher is never converted to a public page alias');[$status,, $headers]=$http($path);$check($status===303&&in_array('Location: /login',$headers,true),'actual private dispatcher retains its authentication gate');}
    foreach(['/','/site/kontakt','//kontakt','/kontakt/../account','/kontakt/%2faccount','/kontakt/'.str_repeat('a',181)]as$path)$check(PublicRoutes::pageAlias($path)===null,'malformed or unrelated route cannot enter the public alias projection');
    if(getenv('DVM_PUBLIC_ALIAS_BROWSER')==='1'){putenv('DVM_PUBLIC_ALIAS_ORIGIN='.$origin);$process=proc_open(['node',__DIR__.'/public-aliases-browser.mjs'],[0=>STDIN,1=>STDOUT,2=>STDERR],$nodePipes,dirname(__DIR__,2));if(!is_resource($process)||proc_close($process)!==0)throw new RuntimeException('Actual public alias browser failed.');}
    $check(ownOriginalDatabaseHash($db,$tables)===$before,'all actual direct aliases preserve every original row, draft, revision and publication byte');
    foreach($counts as$table=>$count)$check((int)Db::run($db,'SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()===$count,'complete original table count retained: '.$table);
    $check(hash_file('sha256',$root.'/media/original.png')===$fileHash,'all private original image bytes remain intact');
    echo json_encode(['suite'=>'native-public-aliases','checks'=>$n,'anonymous_routes'=>count($saved)-1,'external_requests'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
}finally{if(is_resource($server)){proc_terminate($server);proc_close($server);}$files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($files as$file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($root);}
