<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';
require __DIR__.'/mail-timeline-originals.php';require __DIR__.'/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Db,SecretBox};
use Kulturhus\Native\Integration\{Accounts,ManagerBridge};
use Kulturhus\Native\Communication\{Attention,MessageReplyProvider};
$config=TestSupport::config();if(($config['owner']??'')!=='cloud-onboarding-synthetic'||($config['label']??'')!=='integrated-manager')throw new RuntimeException('Own synthetic manager required.');
$db=TestSupport::db();$access=(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access();$box=SecretBox::fromFile(dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/integrated-manager-runtime/private.key');$path=dirname(__DIR__,3).'/.local/browser-tests/conversation-search.json';
$tables=['native_social_inbox','native_social_attention','native_social_originals','native_social_objects','native_social_replies','native_social_mail_threads','native_social_mail_replies','native_social_mail_dispatch','native_private_settings','native_users','native_grants','native_delivery_jobs','khcms_pages','khcms_revisions','khcms_media','documents','document_files','document_links','dwf_document_versions','dwf_signatures'];
if(($argv[1]??'prepare')==='check'){$f=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);if($f['database']!==$config['database']||$f['originals']!==ownOriginalDatabaseHash($db,$tables))throw new RuntimeException('Original chat/mail/approval/account/document/media/job bytes changed.');echo "All exact original database bytes retained after complete search and direct target navigation.\n";exit;}
\Kulturhus\Native\Installer\SchemaRegistry::install($db);$account=(new Accounts($db,$box))->get($access->club(),'meta');$suffix=bin2hex(random_bytes(5));$fixture=['database'=>$config['database'],'scenarios'=>[]];
foreach(['facebook','instagram']as$channel){
    $parent='own_browser_search_'.$channel.'_'.$suffix;$id=MessageReplyProvider::threadId($access->club(),$channel,$parent);$needle='Frühe Suchfrage '.$channel.' '.$suffix;$outgoing='Gesendete Suchantwort '.$channel.' '.$suffix;$messages=[];
    for($i=0;$i<85;$i++)$messages[]=['id'=>'own_browser_'.$suffix.'_'.$channel.'_'.$i,'incoming'=>$i===0||$i===84,'from_id'=>$i===0||$i===84?'own_search_guest':$account[$channel==='facebook'?'page_id':'instagram_id'],'from_name'=>'Eigener Suchgast '.$suffix,'text'=>$i===0?str_repeat('Genaue ursprüngliche Vorbemerkung ',40).$needle.' ä🙂 <script>kein Code</script>':($i===10?$outgoing.' ä🙂':'Genaue eigene Verlaufsnachricht '.$i),'created_at'=>gmdate('Y-m-d H:i:s',time()-1000+$i)];
    Db::run($db,'INSERT INTO native_social_inbox VALUES(?,?,?,?,?,UTC_TIMESTAMP())',[$access->club(),$id,$channel,$box->seal('social-inbox:'.$access->club().':'.$id,['conversation'=>$parent,'recipient'=>'own_search_guest','page_id'=>$account['page_id'],'instagram_id'=>$account['instagram_id'],'connection_id'=>Accounts::binding($account),'messages'=>$messages]),end($messages)['created_at']]);
    (new Attention($db,$box))->observe($access,$channel,'message',$parent,[$messages[84]],true);
    $fixture['scenarios'][$channel]=['thread'=>$id,'needle'=>$needle,'id'=>$messages[0]['id'],'text'=>$messages[0]['text'],'outgoing'=>$outgoing,'outgoing_id'=>$messages[10]['id'],'outgoing_text'=>$messages[10]['text']];
}
$mail=ownMailTimeline($db,$box,$access,$account,200000,'blocked');
foreach($mail['mails']as$i=>$id){$text='Genaue Suchmail '.$suffix.' '.str_pad((string)$i,3,'0',STR_PAD_LEFT).' ä🙂 <eigene Antwort>';$mail['texts'][$i]=$text;Db::run($db,'UPDATE native_social_mail_replies SET ciphertext=? WHERE club_id=? AND id=?',[$box->seal('social-mail-answer:'.$access->club().':'.$id,['text'=>$text,'original'=>base64_encode('Genaue eigene synthetische MIME '.$suffix.' '.$i."\r\n".str_repeat('M',200000))]),$access->club(),$id]);}
(new Attention($db,$box))->observe($access,'facebook','message',$mail['source']['parent_id'],[],true);
$fixture['mail']=$mail;$fixture['mail']['needle']='Genaue Suchmail '.$suffix.' 000';$fixture['originals']=ownOriginalDatabaseHash($db,$tables);file_put_contents($path,json_encode($fixture,JSON_THROW_ON_ERROR),LOCK_EX);chmod($path,0600);
echo "Two exact 85-message native histories and 70 archived original human mail answers prepared without any transport.\n";
