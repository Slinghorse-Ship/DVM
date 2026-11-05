<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Db,SecretBox};
use Kulturhus\Native\Integration\{ManagerBridge,Accounts};
use Kulturhus\Native\Communication\{Attention,Replies,MessageReplyProvider};
use Kulturhus\Native\Communication\Mail\{Settings,Bridge};
$config=TestSupport::config();if(($config['owner']??'')!=='cloud-onboarding-synthetic'||($config['label']??'')!=='integrated-manager')throw new RuntimeException('Own synthetic manager required.');
$db=TestSupport::db();$access=(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access();$club=$access->club();$box=SecretBox::fromFile(dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/integrated-manager-runtime/private.key');$settings=new Settings($db,$box);$path=dirname(__DIR__,3).'/.local/browser-tests/direct-mail.json';
require __DIR__.'/original-database-hash.php';
$snapshot=static fn():string=>ownOriginalDatabaseHash($db,['native_social_inbox','native_social_attention','native_social_originals','native_social_objects','native_private_settings','native_social_replies','native_social_mail_threads','native_social_mail_replies','native_social_mail_cursors','native_social_mail_dispatch','native_delivery_jobs','documents','document_files','document_links','dwf_document_versions','dwf_signatures','khcms_pages','khcms_revisions','khcms_media'],static fn(string $table):array=>$table==='native_private_settings'?[" WHERE NOT (club_id=? AND purpose='social-mail')",[$club]]:['',[]]);
$mode=$argv[1]??'prepare';
if($mode==='check'){
 $fixture=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);if($fixture['database']!==$config['database']||$fixture['originals']!==$snapshot())throw new RuntimeException('Original mail, social commands, questions, accounts, approvals or jobs changed.');
 $state=$settings->configuration($access);if($state['data']['enabled']||!$state['data']['auto_send'])throw new RuntimeException('Own module must finish disabled with selected direct mode retained.');
 echo "Exact original and direct mail bytes retained; no browser transport and module off.\n";exit;
}
if($mode!=='prepare')throw new RuntimeException('Unknown fixture action.');
\Kulturhus\Native\Installer\SchemaRegistry::install($db);
$state=$settings->configuration($access);if($state['version']&&$state['data']['target']!=='mail-helper@example.invalid')throw new RuntimeException('Cannot overwrite another module configuration.');
$settings->save($access,['enabled'=>'1','receive_enabled'=>'1','auto_send'=>'1','target'=>'mail-helper@example.invalid','reply_to'=>'mail-return@example.invalid','imap_host'=>'imap.example.invalid','imap_user'=>'mail-return@example.invalid','imap_password'=>'Synthetic-conversation-mail-secret','folder'=>'INBOX','since'=>'2026-10-01'],$state['version']);
$account=(new Accounts($db,$box))->get($club,'meta');$connection=Accounts::binding($account);$remote=[];$postCount=0;
$http=static function($method,$path,$headers,$body)use(&$remote,&$postCount,$account):array{
 $id=explode('?',substr($path,strlen('/'.$account['api_version'].'/')))[0];
 if($method==='GET')return ['status'=>200,'body'=>$id==='me'?['id'=>$account['page_id'],'instagram_business_account'=>['id'=>$account['instagram_id']]]:($remote[$id]??[])];
 ++$postCount;$id='own_mail_sent_'.bin2hex(random_bytes(8));$text=$body['message']['text']??$body['message'];
 if(isset($body['recipient'])){$remote[$id]=['id'=>$id,'message'=>$text,'created_time'=>gmdate('Y-m-d\TH:i:sO'),'from'=>['id'=>isset($body['messaging_type'])?$account['page_id']:$account['instagram_id']],'to'=>['data'=>[['id'=>$body['recipient']['id']]]]];return ['status'=>200,'body'=>['message_id'=>$id,'recipient_id'=>$body['recipient']['id']]];}
 $remote[$id]=['id'=>$id,'message'=>$text,'text'=>$text];return ['status'=>200,'body'=>['id'=>$id]];
};
$service=new Replies($db,$box,$http);$sources=[];$bodies=[];$batch=[];$suffix=bin2hex(random_bytes(6));
$bridge=new Bridge($db,$box,static fn($club,$configuration,$message)=>$message['message_id'],static function()use(&$batch){return $batch;},$service);
foreach([['facebook','comment',false],['instagram','comment',false],['facebook','message',false],['instagram','message',false],['instagram','message',true]]as[$channel,$kind,$blocked]){
 $key=$channel.'-'.$kind.($blocked?'-blocked':'');$parent='own_direct_'.$key.'_'.$suffix;$original='own_original_'.$key.'_'.$suffix;$date=gmdate('Y-m-d H:i:s',time()-60);$guest='own_guest_'.$key;$text='Eigene Originalfrage '.$key;
 if($kind==='message'){
  $sender=$channel==='facebook'?$account['page_id']:$account['instagram_id'];$thread=MessageReplyProvider::threadId($club,$channel,$parent);$message=['id'=>$original,'text'=>$text,'incoming'=>true,'created_at'=>$date,'from_id'=>$guest,'from_name'=>'Eigener Mailgast'];
  Db::run($db,'INSERT INTO native_social_inbox VALUES(?,?,?,?,?,UTC_TIMESTAMP())',[$club,$thread,$channel,$box->seal('social-inbox:'.$club.':'.$thread,['page_id'=>$account['page_id'],'instagram_id'=>$account['instagram_id'],'connection_id'=>$connection,'conversation'=>$parent,'recipient'=>$guest,'messages'=>[$message]]),$date]);
  (new Attention($db,$box))->observe($access,$channel,'message',$parent,[$message],true);
  $remote[$parent]=['id'=>$parent,'participants'=>['data'=>[['id'=>$guest],['id'=>$sender]]]];$remote[$original]=['id'=>$original,'message'=>$text,'from'=>['id'=>$guest],'to'=>['data'=>[['id'=>$sender]]],'created_time'=>str_replace(' ','T',$date).'+0000'];
 }else{
  Db::run($db,'INSERT INTO native_social_objects(club_id,id,channel,remote_id,kind,caption,payload_json,last_seen) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP())',[$club,substr(hash('sha256',$parent),0,32),$channel,$parent,'post','Eigener Originalbeitrag',json_encode(['connection_id'=>$connection],JSON_THROW_ON_ERROR)]);
  (new Attention($db,$box))->observe($access,$channel,'comment',$parent,[['id'=>$original,'text'=>$text,'name'=>'Eigener Mailgast']],true);$remote[$original]=['id'=>$original,'message'=>$text,'text'=>$text];
 }
 $source=$service->source($access,substr(hash('sha256',$club.':'.$channel.':'.$kind.':'.$parent.':'.$original),0,32));$f=$bridge->prepare($access,$source['id'],(int)$source['version'],'');$accepted=$bridge->send($access,$f['id'],1,true);
 $human=$blocked?str_repeat('ä',501):'Echte menschliche Mailantwort '.$key.' <script>kein Code</script>'; $bodies[$key]=$human;
 $raw="From: mail-helper@example.invalid\r\nTo: mail-return@example.invalid\r\nMessage-ID: <own-direct-".$suffix.'-'.$key."@example.invalid>\r\nIn-Reply-To: ".$accepted['payload']['message_id']."\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n".$human;
 $binding=$settings->configuration($access)['data']['binding'];$cursor=Db::run($db,'SELECT uidvalidity FROM native_social_mail_cursors WHERE club_id=? AND binding=?',[$club,$binding])->fetchColumn();$batch=['uidvalidity'=>max(1,(int)$cursor+1),'messages'=>[['uid'=>1,'original'=>$raw]]];$result=$bridge->fetch($access);
 if($result['delivery'][$blocked?'blocked':'sent']!==1)throw new RuntimeException('Own direct delivery fixture failed.');
 $sources[$key]=['id'=>$source['id'],'kind'=>$kind,'channel'=>$channel,'parent_id'=>$parent,'thread'=>$kind==='message'?$thread:null,'blocked'=>$blocked];
}
if($postCount!==4)throw new RuntimeException('Own direct fixture posted unexpected number.');
$fixture=['database'=>$config['database'],'sources'=>$sources,'bodies'=>$bodies,'originals'=>$snapshot()];file_put_contents($path,json_encode($fixture,JSON_THROW_ON_ERROR),LOCK_EX);chmod($path,0600);
echo "Four genuine direct-contract replies and one unsent over-limit mail in owned synthetic manager prepared; no actual provider, SMTP or IMAP connection.\n";
