<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
require dirname(__DIR__).'/src/bootstrap.php';
require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\{TestSupport};
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Schema,Pages,WebsiteDesign};
use Kulturhus\Native\Integration\ManagerBridge;

$db=TestSupport::db();Kulturhus\Verein\Database::migrate($db);$seed=TestSupport::seed($db);Schema::install($db);Kulturhus\Native\Communication\Schema::install($db);
$club='manifest-'.bin2hex(random_bytes(5));$native=ManagerBridge::clubKey($club);
Db::run($db,'INSERT INTO clubs(id,name) VALUES(?,?)',[$club,'Ähren & Freunde <Verein>']);
foreach(['admin','reader']as$role){
 Db::run($db,'INSERT INTO native_users(id,club_id,name) VALUES(?,?,?)',[$club.'-'.$role,$native,'Own manifest '.$role]);
 Db::run($db,'INSERT INTO native_grants(actor_id,module,capability) SELECT ?,module,capability FROM native_grants WHERE actor_id=? AND module=\'website\'',[$club.'-'.$role,$seed[$role]]);
}
$admin=new Access($db,$club.'-admin');$pages=new Pages($db,$admin);
Db::run($db,'INSERT INTO native_club_settings(club_id,version,payload,updated_by,updated_at) VALUES(?,1,?,?,UTC_TIMESTAMP(6))',[$native,json_encode(['name'=>'Ähren & Freunde <Verein>','origin'=>'https://example.invalid','address'=>'PRIVATE-ADDRESS','logo_id'=>''],JSON_THROW_ON_ERROR),$club.'-admin']);
$home=$pages->save(['slug'=>'start','title'=>'Eigene öffentliche Startseite']);$pages->publish($home['id'],$home['version']);
$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/website-manifest';mkdir($root,0700);mkdir($root.'/media',0700);mkdir($root.'/web',0700);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);$origin='http://127.0.0.1:'.$port;
$fixture=$root.'/fixture.json';file_put_contents($fixture,json_encode(['reader'=>$club.'-reader','club'=>$club,'root'=>$root,'origin'=>$origin],JSON_THROW_ON_ERROR));putenv('DVM_WEBSITE_MANIFEST_FIXTURE='.$fixture);
$checks=0;$check=static function(bool $ok,string $label)use(&$checks){if(!$ok)throw new RuntimeException($label);$checks++;echo 'PASS '.$label."\n";};
$request=static function(string $path,string $method='GET',array $headers=[])use($origin):array{
 $context=stream_context_create(['http'=>['method'=>$method,'ignore_errors'=>true,'header'=>implode("\r\n",$headers),'timeout'=>20]]);
 $body=file_get_contents($origin.$path,false,$context);$lines=$http_response_header??[];preg_match('~HTTP/\S+ (\d+)~',$lines[0]??'',$match);$map=[];foreach($lines as$line){if(str_contains($line,':')){[$key,$value]=explode(':',$line,2);$map[strtolower($key)]=trim($value);}}
 return[(int)($match[1]??0),$map,$body];
};
$server=proc_open([...TestSupport::phpCommand(),'-S','127.0.0.1:'.$port,'-t',$root.'/web',__DIR__.'/support/website-manifest-router.php'],[0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']],$pipes);
if(!is_resource($server))throw new RuntimeException('Own HTTP server required.');fclose($pipes[0]);
try{
 $ready=false;for($i=0;$i<100;$i++){$s=@fsockopen('127.0.0.1',$port,$errno,$error,.1);if($s){fclose($s);$ready=true;break;}usleep(50000);}if(!$ready)throw new RuntimeException('Own HTTP server unavailable.');
 $design=new WebsiteDesign($db,$admin);$version=0;$previous=null;
 foreach(array_keys(WebsiteDesign::templates())as$key){
  $state=$design->useTemplate($key,$version);$version=$state['version'];
  $tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$before=ownOriginalDatabaseHash($db,$tables);
  [$status,$headers,$body]=$request('/site.webmanifest?name=FORGED&token=PRIVATE');$manifest=json_decode($body,true,32,JSON_THROW_ON_ERROR);
  $check($status===200&&str_starts_with($headers['content-type'],'application/manifest+json'),'actual manifest MIME and valid JSON '.$key);
  $check($manifest['name']==='Ähren & Freunde <Verein>'&&!str_contains($body,'PRIVATE')&&!str_contains($body,'FORGED')&&!str_contains($body,'example.invalid'),'current own club name ignores query overrides '.$key);
  $check($manifest['start_url']==='/'&&$manifest['scope']==='/'&&$manifest['id']==='/'&&!isset($headers['set-cookie']),'website shortcut has stable public identity without private session '.$key);
  $check($previous===null||$previous!==$headers['etag'],'conscious design change refreshes original manifest validator '.$key);$previous=$headers['etag'];
  [$status,$head,$body]=$request('/site.webmanifest','HEAD');$check($status===200&&$body===''&&$head['etag']===$headers['etag'],'actual HEAD has original validator and no body '.$key);
  [$status,,$body]=$request('/site.webmanifest','GET',['If-None-Match: '.$headers['etag']]);$check($status===304&&$body==='','exact original conditional HTTP returns 304 '.$key);
  [$status,,$body]=$request('/site.webmanifest','POST');$check($status===405&&$body==='','manifest rejects write methods '.$key);
  foreach([180,192,512]as$size){
   [$status,$iconHeaders,$bytes]=$request('/website-icon-'.$size.'.png');$image=getimagesizefromstring($bytes);
   $check($status===200&&$iconHeaders['content-type']==='image/png'&&$image[0]===$size&&$image[1]===$size,'actual PNG is correctly sized for Apple and Android '.$key.'/'.$size);
   $png=imagecreatefromstring($bytes);$color=sprintf('#%06x',imagecolorat($png,0,0)&0xffffff);imagedestroy($png);$check($color===$manifest['theme_color'],'icon retains current selected website color '.$key.'/'.$size);
  }
  [$status,$svgHeaders,$svg]=$request('/website-icon.svg');$xml=new DOMDocument();$check($status===200&&$xml->loadXML($svg)&&$xml->getElementsByTagName('text')->item(0)->textContent==='Ä','Unicode icon is valid original SVG '.$key);
  [$status,$pageHeaders,$html]=$request('/');if($status!==200)throw new RuntimeException('Own home response '.$status);$dom=new DOMDocument();@$dom->loadHTML($html);$xp=new DOMXPath($dom);
  $check($status===200&&$xp->query('//link[@rel="manifest"]')->item(0)?->getAttribute('href')==='/site.webmanifest','real published layout discovers its manifest '.$key);
  $check($xp->query('//link[@rel="apple-touch-icon"]')->item(0)?->getAttribute('href')==='/website-icon-180.png'&&str_contains($pageHeaders['content-security-policy'],"manifest-src 'self'"),'actual Apple icon and allowed same-origin manifest '.$key);
  $check(ownOriginalDatabaseHash($db,$tables)===$before,'all earlier tables and original bytes survive readonly requests '.$key);
 }
 if(getenv('DVM_WEBSITE_MANIFEST_BROWSER')==='1'){
  $node=proc_open(['node',__DIR__.'/website-manifest-browser.mjs'],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes);
  if(!is_resource($node)||proc_close($node)!==0)throw new RuntimeException('Actual website manifest browser failed.');
 }
 [$status,$oldHeaders]=$request('/site.webmanifest');
 Db::run($db,"UPDATE native_club_settings SET payload=JSON_SET(payload,'$.name','& Neuer Verein'),version=version+1 WHERE club_id=?",[$native]);
 [$status,$newHeaders,$renamed]=$request('/site.webmanifest','GET',['If-None-Match: '.$oldHeaders['etag']]);
 $check($status===200&&$newHeaders['etag']!==$oldHeaders['etag']&&json_decode($renamed,true)['name']==='& Neuer Verein','renamed own club immediately replaces earlier manifest validator');
 [$status,,$svg]=$request('/website-icon.svg');$xml=new DOMDocument();$check($status===200&&$xml->loadXML($svg)&&$xml->getElementsByTagName('text')->item(0)->textContent==='&','human ampersand remains valid escaped icon text');
 Db::run($db,'DELETE FROM native_grants WHERE actor_id=? AND module=?',[$club.'-reader','website']);
 [$status,,$body]=$request('/site.webmanifest');$check($status===403&&!str_contains($body,'Ähren'),'revoked public website permission immediately stops manifest');
 echo json_encode(['suite'=>'native-website-manifest','checks'=>$checks,'actual_http'=>true,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
}finally{proc_terminate($server);proc_close($server);}
