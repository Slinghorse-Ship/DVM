<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Db,SecretBox};
use Kulturhus\Native\Integration\{ManagerBridge,Accounts};
use Kulturhus\Native\Communication\{Attention,Replies,MessageReplyProvider};
$config=TestSupport::config();if(($config['owner']??'')!=='cloud-onboarding-synthetic'||($config['label']??'')!=='integrated-manager')throw new RuntimeException('Own synthetic manager required.');
$db=TestSupport::db();$access=(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access();$club=$access->club();$box=SecretBox::fromFile(dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/integrated-manager-runtime/private.key');
$path=dirname(__DIR__,3).'/.local/browser-tests/message-replies.json';
$snapshot=static function(array $attentionIds)use($db):string{
 $data=[];foreach(['native_social_inbox','native_social_attention','native_social_replies','native_social_objects','native_private_settings','native_delivery_jobs','documents','document_files','document_links','dwf_document_versions','dwf_signatures','khcms_pages','khcms_revisions','khcms_media']as$table){
  $keys=Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);
  $where=$table==='native_social_replies'?' WHERE attention_id NOT IN ('.implode(',',array_fill(0,count($attentionIds),'?')).')':'';
  $data[$table]=Db::run($db,'SELECT * FROM `'.$table.'`'.$where.' ORDER BY `'.implode('`,`',$keys).'`',$table==='native_social_replies'?$attentionIds:[])->fetchAll();
 }return hash('sha256',serialize($data));
};
if(($argv[1]??'prepare')==='check'){
 $fixture=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
 if($fixture['database']!==$config['database']||$fixture['originals']!==$snapshot(array_column($fixture['conversations'],'attention')))throw new RuntimeException('Other originals, accounts, messages or jobs changed.');
 foreach($fixture['conversations']as$channel=>$row){
  $service=new Replies($db,$box);$source=$service->source($access,$row['attention']);
  $draft=$service->forSource($access,$source,$service->connection($access,$row['attention']));
  if(!$draft||$draft['status']!=='draft'||$draft['remote_id']!==null||$draft['payload']['kind']!=='message'||$draft['payload']['text']!==($channel==='expired'?'Eigener Entwurf im geschlossenen Fenster':'Eigene manuelle Nachricht ohne JavaScript · '.$channel))throw new RuntimeException('Exact private draft differs.');
 }
 echo "Other originals and exact private message drafts retained; no API write or job.\n";exit;
}
if(($argv[1]??'prepare')!=='prepare')throw new RuntimeException('Unknown action.');
$account=(new Accounts($db,$box))->get($club,'meta');$conversations=[];
foreach(['facebook'=>'facebook','instagram'=>'instagram','expired'=>'facebook']as$key=>$channel){
 $parent='own_message_reply_browser_'.$key;$guest='own_message_guest_'.$key;$date=gmdate('Y-m-d H:i:s',time()-($key==='expired'?172800:120));$id=MessageReplyProvider::threadId($club,$channel,$parent);
 $message=['id'=>'own_message_question_'.$key,'text'=>"  Eigene private Originalnachricht <script>kein Code</script> · ".$key."\n ",'from_name'=>'Eigener Nachrichtengast '.$key,'from_id'=>$guest,'incoming'=>true,'created_at'=>$date];
 $payload=['conversation'=>$parent,'recipient'=>$guest,'page_id'=>$account['page_id'],'instagram_id'=>$account['instagram_id'],'connection_id'=>Accounts::binding($account),'messages'=>[$message]];
 Db::run($db,'INSERT INTO native_social_inbox VALUES(?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE ciphertext=VALUES(ciphertext),last_incoming=VALUES(last_incoming),updated_at=VALUES(updated_at)',[$club,$id,$channel,$box->seal('social-inbox:'.$club.':'.$id,$payload),$date]);
 (new Attention($db,$box))->observe($access,$channel,'message',$parent,[$message],true);
 $attention=substr(hash('sha256',$club.':'.$channel.':message:'.$parent.':'.$message['id']),0,32);
 $conversations[$key]=['thread'=>$id,'attention'=>$attention,'channel'=>$channel,'source'=>(new Replies($db,$box))->source($access,$attention)['payload']];
}
file_put_contents($path,json_encode(['database'=>$config['database'],'conversations'=>$conversations,'originals'=>$snapshot(array_column($conversations,'attention'))],JSON_THROW_ON_ERROR),LOCK_EX);chmod($path,0600);
echo "Own bound Facebook/Instagram message conversations and closed window prepared without API call.\n";
