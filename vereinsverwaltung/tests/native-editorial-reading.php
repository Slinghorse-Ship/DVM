<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/native/bootstrap.php';require dirname(__DIR__).'/src/bootstrap.php';require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Articles,Media};
use Kulturhus\Native\Integration\ManagerBridge;
$browser=getenv('DVM_EDITORIAL_READING_BROWSER')==='1';
$db=TestSupport::db();Kulturhus\Native\Installer\SchemaRegistry::install($db);TestSupport::seed($db);
$cfg=TestSupport::config();$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/editorial-reading-'.$cfg['database'];mkdir($root,0700);foreach(['media','web','manager-sessions']as$dir)mkdir($root.'/'.$dir,0700);
$nativeRoot=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$cfg['database'];mkdir($nativeRoot,0700);mkdir($nativeRoot.'/media',0700);mkdir($nativeRoot.'/sessions',0700);
foreach(['auth','private']as$name){file_put_contents($root.'/'.$name.'.key',random_bytes(32));chmod($root.'/'.$name.'.key',0600);}putenv('KH_NATIVE_AUTH_KEY_FILE='.$root.'/auth.key');putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$root.'/private.key');
Db::run($db,"INSERT INTO clubs VALUES('editorial-reading-browser','Own editorial reading')");
Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES('editorial-reading-admin','editorial-reading-browser','member',1),('editorial-reading-reader','editorial-reading-browser','member',0)");
$bridge=new ManagerBridge($db,'editorial-reading-admin','native',static fn()=>null);$admin=$bridge->access();
$settings=new Kulturhus\Verein\Settings($db,'editorial-reading-admin');$role=$settings->saveRole('',0,['name'=>'Own complete editorial read role','description'=>'Own isolated original reading','members_access'=>'none','finance_access'=>'none','settings_access'=>false,'website_custom'=>true,'website_levels'=>array_fill_keys(array_keys(Kulturhus\Verein\EditorialPolicy::AREAS),'read')]);$settings->assignRole('editorial-reading-reader',$role,0);
$readerBridge=new ManagerBridge($db,'editorial-reading-reader','native',static fn()=>null);$reader=$readerBridge->access();
$auth=Kulturhus\Native\Auth\Runtime::service($db);$tokens=[];
foreach(['admin'=>$bridge,'reader'=>$readerBridge]as$name=>$binding){$email='own-editorial-'.$name.'@example.invalid';$invite=$auth->createInvitation($admin,$binding->nativeActorId(),$email);$auth->acceptInvitation($invite['token'],'Own isolated editorial password 2026!');$tokens[$name]=$auth->passwordLogin($email,'Own isolated editorial password 2026!','own-editorial-'.$name)['session_token'];}
Db::run($db,"INSERT IGNORE INTO native_grants VALUES('reader','website','publish')");
$body=str_repeat('<p>Ursprünglicher gespeicherter Absatz mit <strong>echter Formatierung</strong> für die vollständige Prüfung.</p>',80);$modes=[];$files=[];
foreach(['standalone'=>[new Access($db,'admin'),$nativeRoot],'integrated'=>[$admin,$root]]as$mode=>[$access,$runtime]){
 $media=new Media($db,$access,$runtime.'/media');$images=[];
 for($i=0;$i<12;$i++){$pixels=imagecreatetruecolor(320,200);imagefill($pixels,0,0,imagecolorallocate($pixels,($i*17)%256,($i*37)%256,($i*73)%256));$source=$root.'/source-'.$mode.'-'.$i.'.png';imagepng($pixels,$source);imagedestroy($pixels);$image=$media->upload($source,'own-reading-'.$i.'.png','Gespeichertes eigenes Foto '.$i.' & <Kunst>');unlink($source);$images[]=$image['id'];foreach(['original','thumb','medium','large']as$size){$variant=$media->variant($image['id'],$size);$files[$variant['path']]=hash_file('sha256',$variant['path']);}}
 $work=new Articles($db,$access);$items=[];
 foreach([1440,820,390,320,390]as$i=>$width){$page=$work->save(['title'=>'Gespeicherte Beitragsfassung '.$mode.' & <Original>','slug'=>'aktuelles/reading-'.$mode.'-'.$i,'news_date'=>'2050-02-03','excerpt'=>'Ursprünglicher Kurztext','body_html'=>$body,'news_channels'=>['rss'],'media_ids'=>$images,'seo_title'=>'Ursprünglicher SEO-Titel','seo_description'=>'Ursprüngliche Suchmaschinenbeschreibung','nav_visible'=>false,'nav_label'=>'Ursprüngliches Menü','noindex'=>true]);$payload=Db::run($db,'SELECT payload_json FROM khcms_revisions WHERE club_id=? AND page_id=? AND revision=1',[$access->club(),$page['id']])->fetchColumn();$items[]=['id'=>$page['id'],'slug'=>$page['slug'],'title'=>$page['title'],'hash'=>hash('sha256',$payload),'width'=>$width,'js'=>$i!==4];}
 $modes[$mode]=['items'=>$items,'images'=>$images];
}
$tables=['khcms_pages','khcms_revisions','khcms_news_approvals','khcms_media','khcms_news_policy','khcms_news_policy_history'];$allowed=array_column($modes['standalone']['items'],'id');
$filter=static fn(string$table):array=>in_array($table,['khcms_pages','khcms_revisions','khcms_news_approvals'],true)?[' WHERE '.($table==='khcms_pages'?'id':'page_id').' NOT IN ('.implode(',',array_fill(0,count($allowed),'?')).')',$allowed]:['',[]];
$before=ownOriginalDatabaseHash($db,$tables,$filter);$keyHashes=[hash_file('sha256',$root.'/auth.key'),hash_file('sha256',$root.'/private.key')];
$socket=stream_socket_server('tcp://127.0.0.1:0');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
$fixture=$root.'/fixture.json';$data=['root'=>$root,'origin'=>'http://127.0.0.1:'.$port,'actor'=>'admin','manager_actor'=>'editorial-reading-admin','token'=>$tokens['admin'],'reader_actor'=>'reader','reader_manager_actor'=>'editorial-reading-reader','reader_token'=>$tokens['reader'],'modes'=>$modes];file_put_contents($fixture,json_encode($data,JSON_THROW_ON_ERROR));chmod($fixture,0600);putenv('DVM_PRESS_RECIPIENTS_FIXTURE='.$fixture);putenv('DVM_EDITORIAL_READING_FIXTURE='.$fixture);
$server=proc_open([...TestSupport::phpCommand(),'-S','127.0.0.1:'.$port,'-t',$root.'/web',__DIR__.'/support/press-recipients-router.php'],[0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']],$pipes);if(!is_resource($server))throw new RuntimeException('Own editorial HTTP server unavailable.');fclose($pipes[0]);$checks=0;
$check=static function(bool $ok,string $label)use(&$checks){if(!$ok)throw new RuntimeException($label);$checks++;echo 'PASS '.$label."\n";};
try{
 for($i=0;$i<100;$i++){$probe=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1);if($probe){fclose($probe);break;}usleep(50000);}
 if($browser){
 $node=proc_open(['node',__DIR__.'/native-news-reading-browser.mjs'],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes);if(!is_resource($node)||proc_close($node)!==0)throw new RuntimeException('Actual isolated editorial reading browser failed.');
 }else{foreach($modes as$mode=>$fixtureData){$scope=$mode==='standalone'?new Access($db,'reader'):$reader;$view=new Kulturhus\Native\Web\Manager($db,$scope,($mode==='standalone'?$nativeRoot:$root).'/media','own-editorial-csrf');$html=$view->main('news',$fixtureData['items'][0]['id']);$check(str_contains($html,'data-news-reading')&&substr_count($html,'<strong>echter Formatierung</strong>')===80&&!str_contains($html,'value="article-save"'),'actual original complete reading renderer stays readonly '.$mode);}}
 $check(ownOriginalDatabaseHash($db,$tables,$filter)===$before,'reading and five owned private approvals retain every outside page revision image and policy');
 foreach($modes as$mode=>$fixtureData)foreach($fixtureData['items']as$item){$page=(new Articles($db,$mode==='standalone'?new Access($db,'admin'):$admin))->get($item['id']);$check($page['version']===($mode==='standalone'&&$browser?2:1)&&(!$browser||$mode!=='standalone'||$page['news_review']==='approved'),'only explicit original publisher decisions create an owned revision '.$mode);$original=Db::run($db,'SELECT payload_json FROM khcms_revisions WHERE page_id=? AND revision=1',[$item['id']])->fetchColumn();$check(hash('sha256',$original)===$item['hash'],'saved original content survives actual reading and review '.$mode);$check(Db::run($db,'SELECT published_revision FROM khcms_pages WHERE id=?',[$item['id']])->fetchColumn()===null,'private review does not become website publication '.$mode);}
 foreach($files as$path=>$hash)$check(hash_file('sha256',$path)===$hash,'all original private photo and variant bytes remain exact');
 $check($keyHashes===[hash_file('sha256',$root.'/auth.key'),hash_file('sha256',$root.'/private.key')],'original personal authentication and private data keys remain exact');
 echo json_encode(['suite'=>'native-editorial-reading','checks'=>$checks,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
}finally{proc_terminate($server);proc_close($server);}
