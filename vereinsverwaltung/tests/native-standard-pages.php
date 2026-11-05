<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/native/bootstrap.php';require dirname(__DIR__).'/src/bootstrap.php';require __DIR__.'/support/editorial-browser-fixture.php';require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Db,Access};
use Kulturhus\Native\Cms\{Pages,Media,ContentSections};
$db=TestSupport::db();Kulturhus\Native\Installer\SchemaRegistry::install($db);TestSupport::seed($db);
$fixture=ownEditorialBrowserFixture($db);$nativeRoot=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.TestSupport::config()['database'];mkdir($nativeRoot,0700);mkdir($nativeRoot.'/media',0700);mkdir($nativeRoot.'/sessions',0700);mkdir($nativeRoot.'/web',0700);copy($fixture['root'].'/auth.key',$nativeRoot.'/auth.key');chmod($nativeRoot.'/auth.key',0600);
Db::run($db,"INSERT INTO native_grants VALUES('admin','accounts','manage')");
$auth=Kulturhus\Native\Auth\Runtime::service($db);$nativeAccounts=[];foreach(['admin','reader']as$role){$email='own-standard-native-'.$role.'@example.invalid';$invite=$auth->createInvitation(new Access($db,'admin'),$role,$email);$auth->acceptInvitation($invite['token'],'Own original page password 2026!');$nativeAccounts[$role]=$auth->passwordLogin($email,'Own original page password 2026!','own-standard-native-'.$role)['session_token'];}
$browser=getenv('DVM_STANDARD_PAGES_BROWSER')==='1';$modes=[];$ids=[];
foreach(['integrated'=>[$fixture['admin'],$fixture['root']],'standalone'=>[new Access($db,'admin'),$nativeRoot]]as$mode=>[$access,$runtime]){
 $pages=new Pages($db,$access);$media=new Media($db,$access,$runtime.'/media');$image=imagecreatetruecolor(100,70);imagefill($image,0,0,imagecolorallocate($image,60,120,80));imagepng($image,$runtime.'/content-sections.png');imagedestroy($image);$photo=$media->upload($runtime.'/content-sections.png','sections.png','Own content sections original pixels');
 $event=$pages->save(['title'=>'Own ticket management '.$mode,'slug'=>'own-ticket-management','template'=>'event','event_start'=>'2099-12-01T20:00']);(new \Kulturhus\Native\Events\Tickets\Offers($db,$access))->save($event['id'],['capacity'=>50,'price_cents'=>500,'accounting_sphere'=>'zweck','active'=>false],0);
 $f=['logo_upload'=>['first'=>bin2hex(random_bytes(16)),'stale'=>bin2hex(random_bytes(16)),'invalid'=>bin2hex(random_bytes(16)),'recovered'=>bin2hex(random_bytes(16)),'file'=>$runtime.'/content-sections.png'],'ticket_event'=>$event['id'],'pages'=>[],'public'=>[],'photo'=>$photo['id'],'photo_hash'=>hash_file('sha256',$media->variant($photo['id'],'original')['path'])];
 foreach([1440,820,390,320]as$width){
  $slug='own-content-sections-'.$width;$sections=ContentSections::normalize([['id'=>bin2hex(random_bytes(16)),'heading'=>'First original '.$width,'body_html'=>'<p>Exact original <strong>formatted section 🎶</strong> &amp; 0</p>','layout'=>'text'],['id'=>bin2hex(random_bytes(16)),'heading'=>'Second original '.$width,'body_html'=>'<p>Exact second original</p>','layout'=>'image-after','media_id'=>$photo['id'],'alt'=>'Own authored alt '.$width,'caption'=>'Own exact caption <&>','credit'=>'Own photographer <&>']]);
  $p=$pages->save(['title'=>'Own content sections '.$width,'slug'=>$slug,'body_html'=>'<p>Exact main original '.$width.' &amp; 0</p>','excerpt'=>'Exact original intro','nav_visible'=>false,'content_sections'=>$sections]);$p=$pages->publish($p['id'],$p['version']);$f['pages'][$width]=$p;$f['public'][$width]=$pages->publicPage($slug);$ids[]=$p['id'];
 }
 $f['generation']=$pages->generation();$modes[$mode]=['pages'=>$pages,'media'=>$media,'data'=>$f];
}
$tables=['khcms_pages','khcms_revisions','khcms_media','club_settings','settings_roles','settings_role_assignments','actors','contacts','native_users','native_grants','native_social_inbox','native_private_settings','native_delivery_jobs'];
$logoIds=[];foreach($modes as $state){$logoIds[]=$state['data']['logo_upload']['first'];$logoIds[]=$state['data']['logo_upload']['stale'];$logoIds[]=$state['data']['logo_upload']['recovered'];}
$filter=static fn(string$table):array=>in_array($table,['khcms_pages','khcms_revisions'],true)?[' WHERE '.($table==='khcms_pages'?'id':'page_id').' NOT IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids]:($table==='khcms_media'?[' WHERE id NOT IN ('.implode(',',array_fill(0,count($logoIds),'?')).')',$logoIds]:['',[]]);$before=ownOriginalDatabaseHash($db,$tables,$filter);$keys=[];foreach([$fixture['root'].'/auth.key',$fixture['root'].'/private.key',$nativeRoot.'/auth.key']as$file)$keys[$file]=hash_file('sha256',$file);$server=null;$nativeServer=null;$nativeLog=null;
try{
 if($browser){
  $socket=stream_socket_server('tcp://127.0.0.1:0');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);$nativeOrigin='http://127.0.0.1:'.$port;
  $file=$fixture['root'].'/native-fixture.json';file_put_contents($file,json_encode(['origin'=>$nativeOrigin,'root'=>$nativeRoot,'private_key'=>$fixture['root'].'/private.key','accounts'=>$nativeAccounts],JSON_THROW_ON_ERROR));chmod($file,0600);putenv('DVM_STANDARD_NATIVE_FIXTURE='.$file);
  $nativeLog=fopen($nativeRoot.'/http.log','ab');$nativeServer=proc_open([...TestSupport::phpCommand(),'-S','127.0.0.1:'.$port,'-t',$nativeRoot.'/web',__DIR__.'/support/standard-pages-native-router.php'],[0=>['pipe','r'],1=>$nativeLog,2=>$nativeLog],$pipes);if(!is_resource($nativeServer))throw new RuntimeException('Own native standard pages HTTP server unavailable.');fclose($pipes[0]);
  for($i=0;$i<100;$i++){$probe=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1);if($probe){fclose($probe);break;}usleep(50000);}
  $fixture['modes']=['integrated'=>['origin'=>$fixture['origin'],'data'=>$modes['integrated']['data']],'standalone'=>['origin'=>$nativeOrigin,'data'=>$modes['standalone']['data']]];
  $server=ownEditorialBrowserStart($fixture,$modes['integrated']['data']);putenv('DVM_STANDARD_PAGES_FIXTURE='.getenv('DVM_EDITORIAL_WORKFLOW_FIXTURE'));$node=proc_open(['node',__DIR__.'/native-content-sections-browser.mjs'],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes);if(!is_resource($node)||proc_close($node)!==0)throw new RuntimeException('Own standard pages browser failed.');
 }
 foreach($modes as$mode=>$state){$pages=$state['pages'];$media=$state['media'];$f=$state['data'];
  foreach($f['pages']as$width=>$original){$current=$pages->get($original['id']);
   foreach(['body','body_html','title','slug','excerpt','template_definition','field_definitions','custom_fields','media_ids','gallery_items','nav_visible']as$key)if($current[$key]!==$original[$key])throw new RuntimeException('Unrelated page original changed: '.$key);
   if($pages->publicPage($original['slug'])!==$f['public'][$width]||$current['published_revision']!==$original['published_revision'])throw new RuntimeException('Published section original changed.');
   if($browser){if(count($current['content_sections'])!==2||array_column($current['content_sections'],'id')!==array_reverse(array_column($original['content_sections'],'id')))throw new RuntimeException('Expected original section IDs and final order missing.');foreach($current['content_sections']as$row){$old=array_values(array_filter($original['content_sections'],fn($item)=>$item['id']===$row['id']))[0];unset($row['heading'],$old['heading']);if($row!==$old)throw new RuntimeException('Section text photo or authored metadata changed.');}}
  }
  if($browser){foreach(['first','recovered'] as $key)if(!Db::run($db,'SELECT 1 FROM khcms_media WHERE club_id=? AND id=?',[($mode==='standalone'?'synthetic-a':$fixture['admin']->club()),$f['logo_upload'][$key]])->fetchColumn())throw new RuntimeException('Accepted logo upload missing.');foreach(['stale','invalid'] as $key)if(Db::run($db,'SELECT 1 FROM khcms_media WHERE id=?',[$f['logo_upload'][$key]])->fetchColumn())throw new RuntimeException('Failed logo upload was not rolled back.');}
  if($pages->generation()!==$f['generation']||hash_file('sha256',$media->variant($f['photo'],'original')['path'])!==$f['photo_hash'])throw new RuntimeException('Private sections changed public generation or photo bytes.');
 }
 foreach($keys as$file=>$hash)if(hash_file('sha256',$file)!==$hash)throw new RuntimeException('Personal authentication keys changed.');
 if(ownOriginalDatabaseHash($db,$tables,$filter)!==$before)throw new RuntimeException('Outside original rows changed.');
 echo json_encode(['suite'=>'native-standard-pages','pages'=>count($ids),'modes'=>array_keys($modes),'browser'=>$browser,'outside_originals'=>'exact','external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
}finally{if($server){proc_terminate($server['process']);proc_close($server['process']);fclose($server['log']);}if(is_resource($nativeServer)){proc_terminate($nativeServer);proc_close($nativeServer);}if(is_resource($nativeLog))fclose($nativeLog);}
