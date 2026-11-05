<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Db,SecretBox};
use Kulturhus\Native\Integration\{ManagerBridge,Accounts};
use Kulturhus\Native\Communication\{InboxArchive,InboxHistory,MessageReplyProvider};
require __DIR__.'/original-database-hash.php';require __DIR__.'/mail-timeline-originals.php';
$config=TestSupport::config();if(($config['owner']??'')!=='cloud-onboarding-synthetic'||($config['label']??'')!=='integrated-manager')throw new RuntimeException('Own synthetic inbox history required.');
$db=TestSupport::db();$access=(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access();$club=$access->club();$box=SecretBox::fromFile(dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/integrated-manager-runtime/private.key');$file=dirname(__DIR__,3).'/.local/browser-tests/inbox-history.json';
$snapshot=static fn():string=>ownOriginalDatabaseHash($db,['native_social_inbox','native_social_attention','native_social_originals','native_social_replies','native_social_mail_threads','native_social_mail_replies','native_social_mail_dispatch','native_private_settings','native_delivery_jobs','native_users','native_grants','documents','document_files','document_links','dwf_document_versions','dwf_signatures','khcms_pages','khcms_revisions','khcms_media']);
if(($argv[1]??'prepare')==='check'){$f=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);if($f['database']!==$config['database']||$f['originals']!==$snapshot())throw new RuntimeException('An original, human reply, account, document or job changed.');echo "All exact native original bytes retained after previous inbox browsing.\n";exit;}
$account=(new Accounts($db,$box))->get($club,'meta');$mail=ownMailTimeline($db,$box,$access,$account,1000,'blocked','message');$suffix=bin2hex(random_bytes(5));$name='Eigener Verlaufsgast '.$suffix;
$old=$box->open('social-inbox:'.$club.':'.$mail['thread'],Db::run($db,'SELECT ciphertext FROM native_social_inbox WHERE club_id=? AND id=?',[$club,$mail['thread']])->fetchColumn());
$old['messages']=[$mail['question']];
for($i=1;$i<85;++$i)$old['messages'][]=['id'=>$i===1?'123':'own_history_'.$suffix.'_'.$i,'text'=>'Ursprüngliche Kontonachricht '.$i.' ä🙂 <script>kein Code</script>','incoming'=>$i%2===1,'from_id'=>$old['recipient'],'from_name'=>$name,'created_at'=>'2020-01-01 '.sprintf('12:%02d:%02d',intdiv($i,60),$i%60)];
$old['messages'][0]['from_name']=$name;
$versions=[];for($i=0;$i<65;++$i)$versions[]=array_replace($old['messages'][1],['text'=>"Genaue frühere Kontofassung ".$i." ä🙂\n<ursprüngliche Zeile>"]);
$old['message_versions']=['123'=>$versions];$past=[];
for($i=0;$i<34;++$i)$past[]=array_replace($old,['connection_id'=>hash('sha256','own-history-'.$suffix.'-'.$i),'messages'=>[array_replace($old['messages'][1],['text'=>'Andere eigene Kontoverbindung '.$i])],'message_versions'=>[]]);
$old['connection_history']=$past;$current=InboxArchive::merge($old,array_replace(array_diff_key($old,['connection_history'=>true,'message_versions'=>true]),['connection_id'=>hash('sha256','current-own-history-'.$suffix),'messages'=>[array_replace($old['messages'][1],['text'=>'Genaue aktuelle Nachricht '.$suffix])]]));
$current['message_versions']=['123'=>array_map(static fn($m)=>array_replace($m,['text'=>'Aktuelle Verbindung: '.$m['text']]),$versions)];
$fixtures=[];$service=new InboxHistory($db,$box);
foreach(['facebook','instagram']as$channel){
    $payload=$current;if($channel==='instagram'){$payload['conversation']='own_history_ig_'.$suffix;foreach($payload['connection_history']as&$history)$history['conversation']=$payload['conversation'];unset($history);}
    $thread=MessageReplyProvider::threadId($club,$channel,$payload['conversation']);
    if($channel==='facebook')Db::run($db,'UPDATE native_social_inbox SET ciphertext=?,last_incoming=UTC_TIMESTAMP() WHERE club_id=? AND id=?',[$box->seal('social-inbox:'.$club.':'.$thread,$payload),$club,$thread]);
    else Db::run($db,'INSERT INTO native_social_inbox VALUES(?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())',[$club,$thread,$channel,$box->seal('social-inbox:'.$club.':'.$thread,$payload)]);
    $latest=$service->view($access,$thread,[]);$earliest=$service->view($access,$thread,['inbox_history_page'=>3]);$connections=$service->view($access,$thread,['inbox_history_kind'=>'connections']);$binding=$connections['rows'][0]['id'];$older=$service->view($access,$thread,['inbox_connection'=>$binding]);
    $fixtures[$channel]=['thread'=>$thread,'latest_id'=>$latest['selected']['id'],'latest_text'=>$latest['timeline']['messages'][0]['text'],'earliest_id'=>$earliest['rows'][array_key_last($earliest['rows'])]['id'],'earliest_text'=>$payload['message_versions']['123'][0]['text'],'connection'=>$binding,'older_version'=>$older['selected']['id'],'older_text'=>$older['timeline']['messages'][0]['text'],'messages'=>array_column($connections['timeline']['messages'],'text'),'total'=>$connections['timeline']['total']];
}
file_put_contents($file,json_encode(['database'=>$config['database'],'name'=>$name,'scenarios'=>$fixtures,'originals'=>$snapshot()],JSON_THROW_ON_ERROR),LOCK_EX);chmod($file,0600);echo "Own Facebook/Instagram original versions, 35 previous connections and 70 old human mails prepared without provider action.\n";
