<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Db,SecretBox};
use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Native\Integration\StadtLandFunk\Connection;
use Kulturhus\Native\Communication\Attention;
$config=TestSupport::config();if(($config['owner']??'')!=='cloud-onboarding-synthetic'||($config['label']??'')!=='integrated-manager')throw new RuntimeException('Owned manager required.');
$db=TestSupport::db();$access=(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access();$club=$access->club();$box=SecretBox::fromFile(dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/integrated-manager-runtime/private.key');
$path=dirname(__DIR__,3).'/.local/browser-tests/social-chat.json';
require __DIR__.'/original-database-hash.php';
$snapshot=static fn():string=>ownOriginalDatabaseHash($db,['native_social_inbox','native_social_attention','native_social_originals','native_social_replies','native_social_objects','native_private_settings','native_delivery_jobs','documents','document_files','document_links','dwf_document_versions','dwf_signatures','khcms_pages','khcms_revisions','khcms_media']);
if(($argv[1]??'prepare')==='check'){
 $fixture=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
 if($fixture['database']!==$config['database']||$fixture['originals']!==$snapshot())throw new RuntimeException('Chat originals, account, jobs or documents changed.');
 echo "Exact messages, comments, accounts and originals retained.\n";exit;
}
if(($argv[1]??'prepare')!=='prepare')throw new RuntimeException('Unknown fixture action.');
$threads=[];$latest=[];
foreach(['facebook','instagram']as$channel){
 $id=substr(hash('sha256',$club.':own-chat-browser:'.$channel),0,32);$messages=[];
 for($i=1;$i<=121;$i++)$messages[]=['id'=>'own_chat_'.$channel.'_'.$i,'text'=>'Eigene Originalnachricht '.$i.($i===121?' <script>kein Code</script>':'').' · '.$channel,'incoming'=>$i%2===1,'from_name'=>'Eigener Chatgast '.($channel==='facebook'?'F':'I'),'created_at'=>gmdate('Y-m-d H:i:s',time()-122+$i)];
 $data=['conversation'=>'own_chat_'.$channel,'messages'=>$messages,'recipient'=>'own_guest'];
 Db::run($db,"INSERT INTO native_social_inbox(club_id,id,channel,ciphertext,last_incoming,updated_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE ciphertext=VALUES(ciphertext),last_incoming=VALUES(last_incoming),updated_at=VALUES(updated_at)",[$club,$id,$channel,$box->seal('social-inbox:'.$club.':'.$id,$data),end($messages)['created_at']]);
 $threads[$channel]=$id;$latest[$channel]=end($messages)['text'];
}
$identity=(new Connection($db,$box))->replyIdentity($access);$post='own_blank_chat_original';$object=substr(hash('sha256',$club.':'.$post),0,32);
Db::run($db,"INSERT INTO native_social_objects(club_id,id,channel,remote_id,kind,caption,payload_json,last_seen) VALUES(?,?,'stadtlandfunk',?,'event','Eigener leerer Chatentwurf',?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json)",[$club,$object,$post,json_encode($identity,JSON_THROW_ON_ERROR)]);
(new Attention($db,$box))->observe($access,'stadtlandfunk','comment',$post,[['id'=>'own_blank_question','creator_id'=>'own_blank_guest','text'=>'Eigene unbeantwortete Originalfrage','name'=>'Eigener unbeantworteter Gast','title'=>'Eigener leerer Chatentwurf']],true);
$attention=substr(hash('sha256',$club.':stadtlandfunk:comment:'.$post.':own_blank_question'),0,32);
if(Db::run($db,'SELECT 1 FROM native_social_replies WHERE club_id=? AND attention_id=?',[$club,$attention])->fetchColumn())throw new RuntimeException('Owned empty composer already has a saved answer.');
file_put_contents($path,json_encode(['database'=>$config['database'],'threads'=>$threads,'latest'=>$latest,'attention'=>$attention,'originals'=>$snapshot()],JSON_THROW_ON_ERROR),LOCK_EX);chmod($path,0600);
echo "Own 242 original messages and genuinely empty reply composer prepared.\n";
