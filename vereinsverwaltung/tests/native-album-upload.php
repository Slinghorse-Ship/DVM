<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/native/bootstrap.php';
require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Schema,Pages,Media,MediaMetadata,MediaQuota,Conflict};
use Kulturhus\Native\Web\Manager;
$config=TestSupport::config();
if(($config['owner']??'')!=='isolated-native-ci'||($config['label']??'')!=='native-album-upload'||getenv('KH_NATIVE_CI')!=='1')throw new RuntimeException('Exact fresh synthetic album CI required.');
$root=dirname((string)getenv('KH_NATIVE_TEST_CONFIG')).'/album-upload';
if(PHP_SAPI==='cli-server'){
 if(($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);exit;}
 header('Content-Type: application/json');header('Cache-Control: no-store');
 if($_SERVER['REQUEST_URI']==='/ready'){echo '{"ready":true}';exit;}
 $statusRoute=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/status';$method=$_SERVER['REQUEST_METHOD']??'';
 if($statusRoute?!in_array($method,['GET','HEAD'],true):$method!=='POST'){http_response_code(405);if($statusRoute)header('Allow: GET, HEAD');exit;}
 try{$db=TestSupport::db();$actor=$_GET['actor']??'admin';if(!in_array($actor,['admin','editor','reader','outsider'],true))throw new DomainException('Unknown synthetic actor.');$access=new Access($db,$actor);$result=$statusRoute?(new \Kulturhus\Native\Web\Website\AlbumPhotoStatus($db,$access))->query($_GET):(new Manager($db,$access,$root.'/media','own-album-csrf'))->action('media',$_POST,$_FILES);if($method!=='HEAD')echo json_encode($statusRoute?$result:['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR);}
 catch(Conflict $e){http_response_code(409);echo json_encode(['error'=>$e->getMessage()]);}
 catch(RuntimeException $e){if($e->getMessage()!=='Seite nicht gefunden.')throw $e;http_response_code(404);echo json_encode(['error'=>$e->getMessage()]);}
 catch(DomainException|InvalidArgumentException $e){http_response_code(422);echo json_encode(['error'=>$e->getMessage()]);}
 exit;
}
if(PHP_SAPI!=='cli')exit(1);
$db=TestSupport::db();TestSupport::seed($db);Schema::install($db);$access=new Access($db,'admin');$checks=0;$server=null;$child=null;$hold=null;
function albumCheck(bool $ok,string $label):void{global$checks;if(!$ok)throw new RuntimeException($label);$checks++;echo 'PASS '.$label."\n";}
mkdir($root,0700,true);
foreach(['old'=>[45,110,175],'new'=>[180,65,95]]as$name=>$color){$image=imagecreatetruecolor(48,32);imagefill($image,0,0,imagecolorallocate($image,...$color));imagepng($image,$root.'/'.$name.'.png');unset($image);}
file_put_contents($root.'/bad.png','own invalid PNG');
$sourceHash=hash_file('sha256',$root.'/new.png');
$media=new Media($db,$access,$root.'/media');$original=$media->upload($root.'/old.png','Own original Ä.png','Own original exact caption');
$pages=new Pages($db,$access);$album=$pages->save(['title'=>'Own exact album','slug'=>'galerie/own-atomic-album','template'=>'gallery','body'=>'Own unchanged body Ä','media_ids'=>[$original['id']],'gallery_items'=>[['id'=>$original['id'],'caption'=>'Own exact original caption Ä','tags'=>['own-ß','second']]]]);
$album=$pages->publish($album['id'],$album['version']);$publication=$pages->publicPage($album['slug']);$oldFiles=[];
foreach(['original','large','medium','thumb']as$variant){$path=$media->variant($original['id'],$variant)['path'];$oldFiles[$path]=hash_file('sha256',$path);}
$keys=[];$tables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME<>'khcms_state' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
foreach($tables as$table){$columns=Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);$where=$table==='khcms_pages'?' WHERE id<>?':'';$args=$table==='khcms_pages'?[$album['id']]:[];$keys[$table]=['columns'=>$columns,'values'=>Db::run($db,'SELECT `'.implode('`,`',$columns).'` FROM `'.$table.'`'.$where.' ORDER BY `'.implode('`,`',$columns).'`',$args)->fetchAll(PDO::FETCH_NUM)];}
$filter=static function(string$table)use($keys):array{$e=$keys[$table];if(!$e['values'])return[' WHERE 0=1',[]];$args=[];foreach($e['values']as$row)array_push($args,...$row);return[' WHERE (`'.implode('`,`',$e['columns']).'`) IN ('.implode(',',array_fill(0,count($e['values']),'('.implode(',',array_fill(0,count($e['columns']),'?')).')')).')',$args];};
$oldHash=ownOriginalDatabaseHash($db,$tables,$filter);
$snapshot=static function()use($db,$root):array{$files=[];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/media',FilesystemIterator::SKIP_DOTS))as$file)if($file->isFile())$files[$file->getPathname()]=hash_file('sha256',$file->getPathname());ksort($files);return[$db->query('SELECT * FROM khcms_media ORDER BY id')->fetchAll(),$files];};
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('Owned HTTP port unavailable.');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
$post=static function(array$fields,string|array$file,string$actor='admin')use($port):array{$uploads=[];if(is_string($file))$uploads['image']=new CURLFile($file,'image/png','Own accepted.png');else foreach($file as$i=>$path)$uploads['image['.$i.']']=new CURLFile($path,'image/png','Own batch '.$i.'.png');$curl=curl_init('http://127.0.0.1:'.$port.'/upload?actor='.$actor);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$fields+$uploads,CURLOPT_HTTPHEADER=>['Accept: application/json']]);$body=curl_exec($curl);if($body===false)throw new RuntimeException('Owned upload failed: '.curl_error($curl));$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);return[$status,json_decode($body,true,32,JSON_THROW_ON_ERROR)];};
$read=static function(array$query,string$actor='admin',string$method='GET')use($port):array{$curl=curl_init('http://127.0.0.1:'.$port.'/status?'.http_build_query($query+['actor'=>$actor]));$headers=[];curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_NOBODY=>$method==='HEAD',CURLOPT_HEADERFUNCTION=>static function($c,$line)use(&$headers){$headers[]=trim($line);return strlen($line);}]);$body=curl_exec($curl);if($body===false)throw new RuntimeException('Owned album read failed.');$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);return[$status,$body===''?$body:json_decode($body,true,32,JSON_THROW_ON_ERROR),$headers];};
try{
 $server=proc_open([...TestSupport::phpCommand(),'-d','post_max_size=64M','-d','upload_max_filesize=16M','-S','127.0.0.1:'.$port,__FILE__],[0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']],$pipes,dirname(__DIR__));if(!is_resource($server))throw new RuntimeException('Owned HTTP server unavailable.');fclose($pipes[0]);
 $ready=false;for($i=0;$i<60;$i++){$curl=curl_init('http://127.0.0.1:'.$port.'/ready');curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>1]);$body=curl_exec($curl);curl_close($curl);if($body==='{"ready":true}'){$ready=true;break;}usleep(100000);}albumCheck($ready,'fresh synthetic actual HTTP album endpoint starts');
 $nonce=bin2hex(random_bytes(16));$fields=['csrf'=>'own-album-csrf','operation'=>'album-upload','id'=>$album['id'],'version'=>(string)$album['version'],'caption'=>'Own accepted caption Ä','upload_id'=>$nonce];$quota=(new MediaQuota($db,$access))->get();
 [$status,$result]=$post($fields,$root.'/new.png');albumCheck($status===200&&$result['ok']===true&&$result['result']['item']===$album['id'],'genuine multipart upload saves into the exact own album');
 $saved=$pages->get($album['id']);albumCheck($saved['version']===$album['version']+1&&$saved['media_ids']===[$original['id'],$nonce],'identified photo and exactly one album revision commit together');
 albumCheck($result['result']['image']['id']===$nonce&&$result['result']['image']['name']==='Own accepted.png'&&$result['result']['image']['album']===['id'=>$album['id'],'version'=>$saved['version']]&&!isset($result['result']['html']),'identified upload confirms its exact image and album revision without replacing HTML');
 albumCheck($saved['gallery_items'][0]===$album['gallery_items'][0]&&$saved['gallery_items'][1]===['id'=>$nonce,'caption'=>'Own accepted caption Ä','tags'=>[]],'upload appends without rewriting original caption tags or order');
 albumCheck($pages->publicPage($album['slug'])===$publication,'actual private album upload preserves the exact earlier publication');
 $copy=$media->variant($nonce,'original');$size=getimagesize($copy['path']);albumCheck([$size[0],$size[1]]===[48,32]&&$copy['alt']==='Own accepted caption Ä','actual accepted original has exact new pixels and caption');
 $pixel=imagecreatefromstring(file_get_contents($copy['path']));$color=imagecolorsforindex($pixel,imagecolorat($pixel,24,16));unset($pixel);albumCheck([$color['red'],$color['green'],$color['blue']]===[180,65,95],'independent GD confirms actually stored multipart pixels');
 $metadata=(new MediaMetadata($db,$access))->get($nonce);albumCheck($metadata['version']===0&&$metadata['rights_state']==='unknown'&&!$metadata['has_proof']&&$media->publicVariant($nonce,'thumb')===null,'actual album upload invents no approval proof or publication');
 $record=Db::run($db,'SELECT variants_json FROM khcms_media WHERE id=?',[$nonce])->fetchColumn();$variants=json_decode($record,true,32,JSON_THROW_ON_ERROR);$after=(new MediaQuota($db,$access))->get();albumCheck(count($variants)===4&&$after['image_count']===$quota['image_count']+1&&$after['used_bytes']===$quota['used_bytes']+array_sum(array_column($variants,'bytes')),'committed album upload accounts for exactly four actual variants');
 $baseline=$snapshot();
 $query=['album_id'=>$album['id'],'version'=>(string)$album['version'],'upload_id'=>$nonce];$readHash=ownOriginalDatabaseHash($db,$tables);
 [$status,$found,$headers]=$read($query);albumCheck($status===200&&$found['found']===true&&$found['image']['id']===$nonce&&$found['album']===['id'=>$album['id'],'version'=>$saved['version'],'current'=>true],'actual GET finds only the exact own committed append revision');
 albumCheck(in_array('Cache-Control: no-store',$headers,true),'actual album status is private and never cached');
 [$status,$body]=$read($query,'admin','HEAD');albumCheck($status===200&&$body==='','actual HEAD confirms exact album status without a body');
 [$status,$body,$headers]=$read($query,'admin','POST');albumCheck($status===405&&$body===''&&in_array('Allow: GET, HEAD',$headers,true),'album status rejects actual mutation methods without replay');
 foreach([
  ['missing photo',array_replace($query,['upload_id'=>bin2hex(random_bytes(16))]),'admin',200],
  ['old original identity',array_replace($query,['upload_id'=>$original['id']]),'admin',200],
  ['wrong original version',array_replace($query,['version'=>'1']),'admin',200],
  ['missing album',array_replace($query,['album_id'=>bin2hex(random_bytes(16))]),'admin',200],
  ['another editor',$query,'editor',200],
  ['another club',$query,'outsider',200],
 ]as[$label,$input,$actor,$expected]){[$status,$answer]=$read($input,$actor);albumCheck($status===$expected&&$answer===['found'=>false],$label.' does not expose another association');}
 foreach([
  ['array photo',array_replace($query,['upload_id'=>['own']])],['malformed photo',array_replace($query,['upload_id'=>'../own'])],
  ['array album',array_replace($query,['album_id'=>['own']])],['malformed album',array_replace($query,['album_id'=>'../own'])],
  ['array version',array_replace($query,['version'=>['2']])],['fractional version',array_replace($query,['version'=>'2.0'])],
  ['zero version',array_replace($query,['version'=>'0'])],['overflow version',array_replace($query,['version'=>'4294967295'])],
 ]as[$label,$input]){[$status]=$read($input);albumCheck($status===422,$label.' rejects actual status read');}
 [$status]=$read($query,'reader');albumCheck($status===422,'reader cannot query the private upload association');
 albumCheck(ownOriginalDatabaseHash($db,$tables)===$readHash&&$snapshot()===$baseline&&$pages->get($album['id'])===$saved,'all status reads preserve every row file revision and current album exactly');
 $arrayVersion=array_replace($fields,['upload_id'=>bin2hex(random_bytes(16))]);unset($arrayVersion['version']);$arrayVersion['version[]']=(string)$saved['version'];
 $arrayAlbum=array_replace($fields,['version'=>(string)$saved['version'],'upload_id'=>bin2hex(random_bytes(16))]);unset($arrayAlbum['id']);$arrayAlbum['id[]']=$album['id'];
 foreach([
  ['invalid CSRF',array_replace($fields,['version'=>(string)$saved['version'],'csrf'=>'wrong','upload_id'=>bin2hex(random_bytes(16))]),$root.'/new.png','admin',422],
  ['array upload version',$arrayVersion,$root.'/new.png','admin',422],
  ['array upload album',$arrayAlbum,$root.'/new.png','admin',422],
  ['fractional upload version',array_replace($fields,['version'=>$saved['version'].'.0','upload_id'=>bin2hex(random_bytes(16))]),$root.'/new.png','admin',422],
  ['overflow upload version',array_replace($fields,['version'=>'4294967295','upload_id'=>bin2hex(random_bytes(16))]),$root.'/new.png','admin',422],
  ['leading-zero upload version',array_replace($fields,['version'=>'0'.$saved['version'],'upload_id'=>bin2hex(random_bytes(16))]),$root.'/new.png','admin',422],
  ['invalid upload album',array_replace($fields,['id'=>'../own','upload_id'=>bin2hex(random_bytes(16))]),$root.'/new.png','admin',422],
  ['stale original version',$fields,$root.'/new.png','admin',409],
  ['invalid pixels',array_replace($fields,['version'=>(string)$saved['version'],'upload_id'=>bin2hex(random_bytes(16))]),$root.'/bad.png','admin',422],
  ['duplicate original identity',array_replace($fields,['version'=>(string)$saved['version'],'upload_id'=>$original['id']]),$root.'/new.png','admin',409],
  ['malformed identity',array_replace($fields,['version'=>(string)$saved['version'],'upload_id'=>'../own']),$root.'/new.png','admin',422],
  ['reader upload',array_replace($fields,['version'=>(string)$saved['version'],'upload_id'=>bin2hex(random_bytes(16))]),$root.'/new.png','reader',422],
  ['foreign album',array_replace($fields,['version'=>(string)$saved['version'],'upload_id'=>bin2hex(random_bytes(16))]),$root.'/new.png','outsider',404],
 ]as[$label,$input,$path,$actor,$expected]){[$status]=$post($input,$path,$actor);albumCheck($status===$expected,$label.' rejects actual multipart upload');albumCheck($snapshot()===$baseline&&$pages->get($album['id'])===$saved,$label.' leaves all photo rows files and album bytes unchanged');}
 $hold=TestSupport::db();$hold->beginTransaction();Db::run($hold,'SELECT generation FROM khcms_state WHERE club_id=? FOR UPDATE',[$access->club()])->fetchColumn();
 $pending=bin2hex(random_bytes(16));$output=$root.'/pending-response.json';
 $child=proc_open(['curl','--silent','--show-error','--max-time','30','--output',$output,'--write-out','%{http_code}','--form','csrf=own-album-csrf','--form','operation=album-upload','--form','id='.$album['id'],'--form','version='.$saved['version'],'--form','upload_id='.$pending,'--form','caption=Own concurrent new caption','--form','image=@'.$root.'/new.png;filename=Own pending.png;type=image/png','http://127.0.0.1:'.$port.'/upload'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($child))throw new RuntimeException('Owned concurrent HTTP upload unavailable.');fclose($pipes[0]);
 $staged=$root.'/media/'.hash('sha256',$access->club()).'/'.$pending;$ready=false;
 for($i=0;$i<100;$i++){clearstatcache();if(is_dir($staged)&&count(array_diff(scandir($staged),['.','..']))===4){$ready=true;break;}usleep(100000);}
 albumCheck($ready&&proc_get_status($child)['running'],'real concurrent upload stages four files after reading the old album version');
 albumCheck(Db::run($db,'SELECT COUNT(*) FROM khcms_media WHERE id=?',[$pending])->fetchColumn()===0,'held club lock prevents an intermediate photo from being committed');
 $changed=$saved;$changed['body']='Own independently committed body Ä';$changed=(new Pages($hold,new Access($hold,'admin')))->save($changed,$saved['version']);$hold->commit();
 $http=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($child);$child=null;
 albumCheck($exit===0&&$stderr===''&&$http==='409','actual late album CAS conflict rejects after the upload was staged');
 albumCheck($pages->get($album['id'])===$changed&&$pages->publicPage($album['slug'])===$publication,'late conflict preserves independent edit and exact earlier publication');
 albumCheck($snapshot()===$baseline&&!is_dir($staged),'late conflict rolls back the new photo row and every staged variant without an orphan');
 albumCheck((new MediaQuota($db,$access))->get()===$after,'rolled-back album upload spends no photo quota');
 $readHash=ownOriginalDatabaseHash($db,$tables);[$status,$found]=$read($query);albumCheck($status===200&&$found['found']===true&&$found['album']===['id'=>$album['id'],'version'=>$saved['version'],'current'=>false],'later independent edit keeps the exact committed receipt without promoting a stale album version');
 [$status,$missing]=$read(array_replace($query,['version'=>(string)$saved['version'],'upload_id'=>$pending]));albumCheck($status===200&&$missing===['found'=>false],'rolled-back late upload has no discoverable association');
 albumCheck(ownOriginalDatabaseHash($db,$tables)===$readHash&&$snapshot()===$baseline&&$pages->get($album['id'])===$changed,'reading old and rejected associations preserves the independent edit and all originals');
 $legacy=array_replace($fields,['version'=>(string)$changed['version'],'caption'=>'']);unset($legacy['upload_id']);[$status]=$post($legacy,$root.'/new.png');
 albumCheck($status===200&&count($pages->get($album['id'])['media_ids'])===3,'existing ordinary clients without proposed IDs retain the actual upload path');
 $before=$pages->get($album['id']);$batchFields=array_replace($legacy,['version'=>(string)$before['version'],'caption'=>'Own batch caption Ä']);$q=(new MediaQuota($db,$access))->get();
 [$status]=$post($batchFields,[$root.'/new.png',$root.'/old.png']);$batch=$pages->get($album['id']);$newIds=array_slice($batch['media_ids'],count($before['media_ids']));
 albumCheck($status===200&&count($newIds)===2&&$batch['version']===$before['version']+1,'actual native two-file multipart commits exactly one complete album revision');
 albumCheck(array_slice($batch['gallery_items'],0,count($before['gallery_items']))===$before['gallery_items'],'complete native batch preserves exact prior ordering captions and tags');
 $bytes=0;foreach($newIds as$i=>$id){$record=$media->variant($id,'original');$pixel=imagecreatefromstring(file_get_contents($record['path']));$color=imagecolorsforindex($pixel,imagecolorat($pixel,24,16));unset($pixel);$expected=$i===0?[180,65,95]:[45,110,175];albumCheck([$record['width'],$record['height']]===[48,32]&&[$color['red'],$color['green'],$color['blue']]===$expected&&$record['alt']==='Own batch caption Ä','native batch stores each actual selected source and exact shared caption in order');$meta=(new MediaMetadata($db,$access))->get($id);albumCheck($meta['version']===0&&$meta['rights_state']==='unknown'&&!$meta['has_proof']&&$media->publicVariant($id,'thumb')===null,'native batch invents no approval proof or publication');$variants=json_decode(Db::run($db,'SELECT variants_json FROM khcms_media WHERE id=?',[$id])->fetchColumn(),true,32,JSON_THROW_ON_ERROR);$bytes+=array_sum(array_column($variants,'bytes'));}
 $qAfter=(new MediaQuota($db,$access))->get();albumCheck($qAfter['image_count']===$q['image_count']+2&&$qAfter['used_bytes']===$q['used_bytes']+$bytes,'whole native batch accounts for exactly the accepted variants');
 $baseline=$snapshot();$batchFields['version']=(string)$batch['version'];$badCaption=$batchFields;unset($badCaption['caption']);$badCaption['caption[]']='own invalid array';
 foreach([
  ['bad second actual batch photo',$batchFields,[$root.'/new.png',$root.'/bad.png'],'admin',422],
  ['eleven actual batch files',$batchFields,array_fill(0,11,$root.'/new.png'),'admin',422],
  ['single-file identity on batch',$batchFields+['upload_id'=>bin2hex(random_bytes(16))],[$root.'/new.png',$root.'/old.png'],'admin',422],
  ['stale batch version',array_replace($batchFields,['version'=>(string)$before['version']]),[$root.'/new.png',$root.'/old.png'],'admin',409],
  ['array caption',$badCaption,[$root.'/new.png',$root.'/old.png'],'admin',422],
  ['empty native selection',$batchFields,[],'admin',422],
  ['batch reader',$batchFields,[$root.'/new.png',$root.'/old.png'],'reader',422],
  ['batch invalid CSRF',array_replace($batchFields,['csrf'=>'wrong']),[$root.'/new.png',$root.'/old.png'],'admin',422],
 ]as[$label,$input,$paths,$actor,$expected]){[$status]=$post($input,$paths,$actor);albumCheck($status===$expected,$label.' rejects actual multipart request');albumCheck($snapshot()===$baseline&&$pages->get($album['id'])===$batch&&(new MediaQuota($db,$access))->get()===$qAfter,$label.' leaves every photo file row album and quota exact');}
 $currentFields=array_replace($batchFields,['version'=>(string)$batch['version']]);
 [$status]=$post($currentFields,array_fill(0,10,$root.'/new.png'));$ten=$pages->get($album['id']);albumCheck($status===200&&count($ten['media_ids'])===count($batch['media_ids'])+10&&$ten['version']===$batch['version']+1,'maximum ten actual selected files commit together in one revision');
 $baseline=$snapshot();$qAfter=(new MediaQuota($db,$access))->get();$currentFields['version']=(string)$ten['version'];
 file_put_contents($root.'/own-nine-mib.png',file_get_contents($root.'/new.png').str_repeat("\0",9*1024*1024));
 [$status]=$post($currentFields,array_fill(0,5,$root.'/own-nine-mib.png'));albumCheck($status===422,'actual batch total over 40 MiB rejects after prior files were decoded');albumCheck($snapshot()===$baseline&&$pages->get($album['id'])===$ten&&(new MediaQuota($db,$access))->get()===$qAfter,'actual total-size failure rolls back every earlier staged batch file and row');
 $capacityIds=[$original['id']];for($i=0;$i<98;$i++)$capacityIds[]=$media->upload($root.'/old.png','Own capacity '.$i.'.png','Own capacity original')['id'];
 $capacity=$pages->save(['title'=>'Own capacity album','slug'=>'galerie/own-capacity','template'=>'gallery','media_ids'=>$capacityIds]);$capacityFields=array_replace($currentFields,['id'=>$capacity['id'],'version'=>(string)$capacity['version']]);$baseline=$snapshot();$qAfter=(new MediaQuota($db,$access))->get();
 [$status]=$post($capacityFields,[$root.'/new.png',$root.'/old.png']);albumCheck($status===422,'actual two-file batch cannot exceed the remaining one album place');albumCheck($snapshot()===$baseline&&$pages->get($capacity['id'])===$capacity&&(new MediaQuota($db,$access))->get()===$qAfter,'album capacity rejection leaves all actual 99 originals and quota exact');
 [$status]=$post($capacityFields,[$root.'/new.png']);$full=$pages->get($capacity['id']);albumCheck($status===200&&count($full['media_ids'])===100&&array_slice($full['media_ids'],0,99)===$capacityIds,'actual one-file native batch fills exactly the last album place');
 albumCheck($pages->publicPage($album['slug'])===$publication,'all native batches preserve the exact earlier publication');
 albumCheck(ownOriginalDatabaseHash($db,$tables,$filter)===$oldHash,'all earlier original rows and frozen revisions outside the deliberately edited own album remain exact');
 foreach($oldFiles as$path=>$hash)albumCheck(hash_file('sha256',$path)===$hash,'every original private and published photo variant remains byte-identical');
 albumCheck(hash_file('sha256',$root.'/new.png')===$sourceHash,'owned source bytes remain exact after rejected and successful uploads');
 echo json_encode(['suite'=>'native-album-upload','checks'=>$checks,'actual_concurrent_http'=>true,'external_requests'=>0])."\n";
}finally{if($hold?->inTransaction())$hold->rollBack();if(is_resource($child)){proc_terminate($child);proc_close($child);}if(is_resource($server)){proc_terminate($server);proc_close($server);}}
