<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';require __DIR__.'/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db,SecretBox};
use Kulturhus\Native\Integration\{ManagerBridge};
use Kulturhus\Native\Integration\StadtLandFunk\Connection;
use Kulturhus\Native\Communication\{EventDrafts,Workspace,Images,PersonalPublication};
use Kulturhus\Native\Cms\Pages;
use Kulturhus\Native\Delivery\Outbox;
$c=TestSupport::config();$standalone=($c['label']??'')==='demo';if(($c['owner']??'')!=='cloud-onboarding-synthetic'||!in_array($c['label']??'',['demo','integrated-manager'],true))throw new RuntimeException('Owned synthetic manager required.');
$db=TestSupport::db();$a=$standalone?new Access($db,'admin'):(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access();$club=$a->club();
$runtime=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/'.($standalone?'runtime-'.$c['database']:'integrated-manager-runtime');$key=$standalone?(getenv('KH_NATIVE_PRIVATE_KEY_FILE')?:dirname(__DIR__,3).'/.local/native-cms-integration/private.key'):$runtime.'/private.key';$box=SecretBox::fromFile($key);
$file=dirname(__DIR__,3).'/.local/browser-tests/personal-planning-'.($standalone?'standalone':'integrated').'.json';
$tables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND (TABLE_NAME LIKE 'khcms_%' OR TABLE_NAME LIKE 'khn_%' OR TABLE_NAME LIKE 'dwf_%' OR TABLE_NAME IN ('contacts','appointments','documents','document_files','native_private_settings','native_social_event_origins','native_social_images','native_delivery_jobs','native_delivery_sources','native_users','native_grants')) AND TABLE_NAME<>'khcms_state' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
$columns=[];foreach($db->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='page_id'")as$row)$columns[$row['TABLE_NAME']]=true;
$outside=static function(array $f)use($db,$tables,$club,$columns):string{
 return ownOriginalDatabaseHash($db,$tables,static function($table)use($f,$club,$columns):array{
  $ids=array_column($f['items']??[],'id');$pages=array_column($f['items']??[],'page');$images=array_column($f['items']??[],'image');$list=static fn($values)=>implode(',',array_fill(0,count($values),'?'));
  if($ids&&in_array($table,['khn_social_items','khn_social_revisions','khn_social_jobs','native_social_event_origins','native_delivery_sources'],true))return [' WHERE '.($table==='khn_social_items'?'id':'item_id').' NOT IN ('.$list($ids).')',$ids];
  if($pages&&($table==='khcms_pages'||isset($columns[$table])))return [' WHERE '.($table==='khcms_pages'?'id':'page_id').' NOT IN ('.$list($pages).')',$pages];
  if($images&&$table==='native_social_images')return [' WHERE id NOT IN ('.$list($images).')',$images];
  if($ids&&$table==='native_delivery_jobs')return [" WHERE NOT EXISTS (SELECT 1 FROM native_delivery_sources s WHERE s.job_id=native_delivery_jobs.id AND s.club_id=native_delivery_jobs.club_id AND s.module='social' AND s.item_id IN (".$list($ids).'))',$ids];
  if(($f['created_setting']??false)&&$table==='native_private_settings')return [" WHERE NOT (club_id=? AND purpose='api-stadtlandfunk')",[$club]];
  return ['',[]];
 });
};
if(($argv[1]??'prepare')==='check'){
 $f=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);if($f['database']!==$c['database']||$outside($f)!==$f['outside_originals'])throw new RuntimeException('Outside original or account changed.');
 foreach($f['items']as$item){foreach([1,2]as$version){$raw=Db::run($db,'SELECT payload FROM khn_social_revisions WHERE club_id=? AND item_id=? AND version=?',[$club,$item['id'],$version])->fetchColumn();if(hash('sha256',$raw)!==$item['revisions'][$version])throw new RuntimeException('Original approved social revision changed.');}
  if(hash_file('sha256',$item['png'])!==$item['png_hash']||hash_file('sha256',$item['jpeg'])!==$item['jpeg_hash'])throw new RuntimeException('Original image bytes changed.');
  $current=(new Workspace($db,$a,'social'))->get($item['id']);if(($item['browser']??true)===false){if($current['status']!=='approved'||$current['version']!==2)throw new RuntimeException('Owned unknown original changed.');continue;}if($current['status']!=='scheduled'||$current['version']!==3)throw new RuntimeException('Owned browser schedule not completed exactly once.');
  $jobs=Db::run($db,"SELECT j.kind,j.status,j.due_at,j.attempts,j.external_id FROM native_delivery_jobs j JOIN native_delivery_sources s ON s.job_id=j.id WHERE j.club_id=? AND s.item_id=? AND j.status='pending'",[$club,$item['id']])->fetchAll();
  if(count($jobs)!==1||$jobs[0]['kind']!=='stadtlandfunk'||$jobs[0]['due_at']!=='2098-10-10 10:00:00'||(int)$jobs[0]['attempts']!==0||$jobs[0]['external_id']!==null)throw new RuntimeException('Browser made an unintended provider attempt or duplicated its original job.');
 }
 if($f['created_setting']){if(hash('sha256',Db::run($db,"SELECT ciphertext FROM native_private_settings WHERE club_id=? AND purpose='api-stadtlandfunk'",[$club])->fetchColumn())!==$f['setting_hash'])throw new RuntimeException('Synthetic target changed.');Db::run($db,"DELETE FROM native_private_settings WHERE club_id=? AND purpose='api-stadtlandfunk'",[$club]);}
 echo "All outside originals, approved revisions and image bytes retained; owned future plans have no provider attempt.\n";exit;
}
$existing=Db::run($db,"SELECT ciphertext FROM native_private_settings WHERE club_id=? AND purpose='api-stadtlandfunk'",[$club])->fetchColumn();
if($standalone&&is_file($file)){$previous=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);if(($previous['created_setting']??false)&&is_string($existing)&&hash('sha256',$existing)===($previous['setting_hash']??null)){Db::run($db,"DELETE FROM native_private_settings WHERE club_id=? AND purpose='api-stadtlandfunk'",[$club]);$existing=false;}}
$f=['database'=>$c['database'],'items'=>[],'created_setting'=>$existing===false];$before=$outside($f);
if($f['created_setting']){$data=['account_id'=>'own_browser_person','home_area_id'=>'own_browser_area','connection_id'=>bin2hex(random_bytes(16)),'access_token'=>'synthetic-browser-never-send','expires_at'=>time()+86400,'actor_id'=>$a->user()['id'],'authority'=>$standalone?'native':'manager'];$cipher=$box->seal('api:'.$club.':stadtlandfunk',$data);Db::run($db,"INSERT INTO native_private_settings(club_id,purpose,version,ciphertext,updated_by) VALUES(?,'api-stadtlandfunk',1,?,?)",[$club,$cipher,$a->user()['id']]);$f['setting_hash']=hash('sha256',$cipher);}
(new Connection($db,$box))->publicationIdentity($a);$pages=new Pages($db,$a);$work=new Workspace($db,$a,'social');$images=new Images($db,$a,$runtime.'/media');$suffix=bin2hex(random_bytes(6));
foreach([[390,true,true],[320,true,true],[320,false,true],[820,true,true],[390,true,false]]as$index=>[$width,$js,$browserCase]){
 $page=$pages->save(['slug'=>'veranstaltungen/own-planning-'.$suffix.'-'.$index,'title'=>'Eigene Originalveranstaltung ä🙂 '.$suffix.' '.$index,'template'=>'event','event_start'=>'2099-11-14T20:00','event_end'=>'2099-11-14T20:30:00Z','event_timezone'=>'Europe/Berlin','event_location'=>'Eigener Saal','body'=>'<p>Eigener ursprünglicher Browsertermin.</p>']);$page=$pages->publish($page['id'],$page['version']);$item=(new EventDrafts($db,$a))->create($page['id'],$page['published_version']);$image=$images->render($item['id'])[0];$item=$work->approve($item['id'],$item['version']);$png=$images->file($image)['path'];$jpeg=$images->jpeg($image)['path'];$revisions=[];
 foreach([1,2]as$version)$revisions[$version]=hash('sha256',Db::run($db,'SELECT payload FROM khn_social_revisions WHERE club_id=? AND item_id=? AND version=?',[$club,$item['id'],$version])->fetchColumn());
 $f['items'][]=['id'=>$item['id'],'page'=>$page['id'],'image'=>$image,'title'=>$item['title'],'width'=>$width,'js'=>$js,'browser'=>$browserCase,'revisions'=>$revisions,'png'=>$png,'jpeg'=>$jpeg,'png_hash'=>hash_file('sha256',$png),'jpeg_hash'=>hash_file('sha256',$jpeg)];
}
$sample=$f['items'][4];$own=(new PersonalPublication($db,$box))->queue($a,$sample['id'],2,'2099-01-01 12:00:00',$runtime.'/media',true);$payload=$box->open('job:'.$club.':'.$own,Db::run($db,'SELECT ciphertext FROM native_delivery_jobs WHERE id=?',[$own])->fetchColumn());Db::run($db,"UPDATE native_delivery_jobs SET status='uncertain',reason='synthetic_unknown_upload' WHERE id=?",[$own]);$f['unknown_job']=$own;$outbox=new Outbox($db,$box);
for($i=0;$i<39;$i++){$kind=$i%3===0?'meta':'stadtlandfunk';$message=$kind==='meta'?['channel'=>$i%2?'facebook':'instagram','kind'=>'post','approval'=>$payload['approval']]:$payload;$job=$outbox->enqueue($club,$kind,'own-browser-planning-'.$suffix.'-'.$i,$message,'2099-01-01 12:00:00',$a);Db::run($db,'UPDATE native_delivery_jobs SET status=?,external_id=? WHERE id=?',[$i%4===0?'sent':'uncertain',$kind==='meta'?'synthetic_meta_reference':($i%4===0?'sf:own_temp:own_media:4102440000:own_browser_post_'.$i:null),$job]);}
if($outside($f)!==$before)throw new RuntimeException('Preparation changed an outside original.');$f['outside_originals']=$outside($f);file_put_contents($file,json_encode($f,JSON_THROW_ON_ERROR));chmod($file,0600);
echo "Own future planning forms and bounded provider history prepared; existing accounts retained, no provider calls.\n";
