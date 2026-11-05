<?php declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';
require dirname(__DIR__,2).'/src/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Db,SecretBox};
use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Native\Integration\StadtLandFunk\Connection;
use Kulturhus\Native\Communication\{Attention,Replies};
$config=TestSupport::config();
if(($config['owner']??'')!=='cloud-onboarding-synthetic'||($config['label']??'')!=='integrated-manager')throw new RuntimeException('Owned synthetic manager required.');
$db=TestSupport::db();$access=(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access();$club=$access->club();
$box=SecretBox::fromFile(dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/integrated-manager-runtime/private.key');
$identity=(new Connection($db,$box))->replyIdentity($access);
$path=dirname(__DIR__,3).'/.local/browser-tests/personal-replies.json';
$fixture=is_file($path)?json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR):null;
if($fixture&&($fixture['database']!==$config['database']||$fixture['identity']!==$identity))throw new RuntimeException('Owned personal fixture target differs.');
$post='own_personal_reply_browser';$object=substr(hash('sha256',$club.':'.$post),0,32);
$snapshot=static function(array $fixture)use($db,$club,$object):string{
 $records=[];
 $tables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND (TABLE_NAME LIKE 'khcms_%' OR TABLE_NAME LIKE 'khn_%' OR TABLE_NAME LIKE 'native_canvas_%' OR TABLE_NAME LIKE 'dwf_%' OR TABLE_NAME IN ('documents','document_files','document_links','contacts','appointments','native_private_settings','native_delivery_jobs','native_social_replies','native_social_attention','native_social_objects','native_social_inbox')) ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
 foreach($tables as$table){
  $keys=Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);
  $where='';$args=[];
  if($table==='native_social_replies'){$where=' WHERE NOT (club_id=? AND attention_id=?)';$args=[$club,$fixture['attention']];}
  $records[$table]=Db::run($db,'SELECT * FROM `'.$table.'`'.$where.' ORDER BY `'.implode('`,`',$keys).'`',$args)->fetchAll();
 }
 return hash('sha256',serialize($records));
};
if(($argv[1]??'prepare')==='check'){
 if(!$fixture||$snapshot($fixture)!==$fixture['originals'])throw new RuntimeException('Other originals, accounts, jobs or approvals changed.');
 $row=Db::run($db,'SELECT id FROM native_social_replies WHERE club_id=? AND attention_id=?',[$club,$fixture['attention']])->fetchColumn();
 $reply=$row?(new Replies($db,$box))->get($access,$row):null;
 if(!$reply||$reply['status']!=='draft'||$reply['remote_id']!==null||$reply['payload']['original']!==$fixture['source']||$reply['payload']['text']!=='Eigene geprüfte Antwort ohne JavaScript')throw new RuntimeException('Owned saved private reply differs.');
 echo "Other originals and exact private personal draft retained; no provider write or job.\n";exit;
}
if(($argv[1]??'prepare')!=='prepare')throw new RuntimeException('Unknown fixture action.');
$payload=$identity+['title'=>'Eigener StadtLand.Funk-Antwort-Browsertermin','thumbnail'=>'','permalink'=>'https://www.stadtlandfunk.app/post-detail?id='.$post];
Db::run($db,"INSERT INTO native_social_objects(club_id,id,channel,remote_id,kind,caption,payload_json,last_seen) VALUES(?,?,'stadtlandfunk',?,'event',?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json)",[$club,$object,$post,$payload['title'],json_encode($payload,JSON_THROW_ON_ERROR)]);
(new Attention($db,$box))->observe($access,'stadtlandfunk','comment',$post,[['id'=>'own_personal_question','creator_id'=>'own_personal_guest','text'=>'Eigene private Originalfrage <script>kein Code</script>','name'=>'Eigener synthetischer Gast','title'=>$payload['title']]],true);
$attention=substr(hash('sha256',$club.':stadtlandfunk:comment:'.$post.':own_personal_question'),0,32);
$source=(new Replies($db,$box))->source($access,$attention);
$fixture=['database'=>$config['database'],'identity'=>$identity,'attention'=>$attention,'source'=>$source['payload']];
$fixture['originals']=$snapshot($fixture);file_put_contents($path,json_encode($fixture,JSON_THROW_ON_ERROR),LOCK_EX);chmod($path,0600);
echo "Own bound personal comment prepared without provider request.\n";
