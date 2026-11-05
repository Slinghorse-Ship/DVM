<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Db,SecretBox};
use Kulturhus\Native\Integration\{ManagerBridge,Accounts};
use Kulturhus\Native\Communication\{Attention,Replies};
use Kulturhus\Native\Communication\Mail\{Settings,Bridge};
$config=TestSupport::config();if(($config['owner']??'')!=='cloud-onboarding-synthetic'||($config['label']??'')!=='integrated-manager')throw new RuntimeException('Own synthetic manager required.');
$db=TestSupport::db();$access=(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access();$club=$access->club();$box=SecretBox::fromFile(dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/integrated-manager-runtime/private.key');$settings=new Settings($db,$box);$service=new Bridge($db,$box);$path=dirname(__DIR__,3).'/.local/browser-tests/conversation-mail.json';
$snapshot=static function(array $ids)use($db,$club):string{
 $data=[];foreach(['native_social_inbox','native_social_attention','native_social_objects','native_private_settings','native_social_replies','native_social_mail_threads','native_social_mail_replies','native_social_mail_cursors','native_delivery_jobs','documents','document_files','document_links','dwf_document_versions','dwf_signatures','khcms_pages','khcms_revisions','khcms_media']as$table){
  $keys=Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);$where='';$args=[];
  if($table==='native_private_settings'){$where=" WHERE NOT (club_id=? AND purpose='social-mail')";$args=[$club];}
  if(in_array($table,['native_social_replies','native_social_mail_threads'],true)){$where=' WHERE NOT (club_id=? AND attention_id IN (?,?))';$args=[$club,...$ids];}
  $data[$table]=Db::run($db,'SELECT * FROM `'.$table.'`'.$where.' ORDER BY `'.implode('`,`',$keys).'`',$args)->fetchAll();
 }return hash('sha256',serialize($data));
};
$mode=$argv[1]??'prepare';
if($mode==='check'){
 $fixture=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);if($fixture['database']!==$config['database']||$fixture['originals']!==$snapshot(array_column($fixture['sources'],'id')))throw new RuntimeException('Other originals, original mail bytes, provider accounts or jobs changed.');
 $reply=(new Replies($db,$box))->forSource($access,$fixture['sources']['return'],$fixture['connection']);if(!$reply||$reply['status']!=='draft'||$reply['remote_id']!==null||$reply['payload']['text']!==$fixture['human'])throw new RuntimeException('Exact human email reply is not a private unsent draft.');
 $state=$settings->configuration($access);if($state['data']['enabled']||$state['data']['target']!=='mail-helper@example.invalid'||$state['data']['imap_password']!=='Synthetic-conversation-mail-secret')throw new RuntimeException('Own optional module must finish disabled without losing password.');
 $forward=$service->history($access,$fixture['sources']['forward']);$row=end($forward);if(!$row||$row['status']!=='prepared'||$row['payload']['note']!=='Eigene Notiz ohne JavaScript')throw new RuntimeException('Exact unsent mail forward differs.');
 echo "Exact human draft, prepared forwarding and other originals retained; optional module off and no live send.\n";exit;
}
if($mode!=='prepare')throw new RuntimeException('Unknown fixture action.');
\Kulturhus\Native\Installer\SchemaRegistry::install($db);
$state=$settings->configuration($access);if($state['version']&&$state['data']['target']!=='mail-helper@example.invalid')throw new RuntimeException('Cannot overwrite another module configuration.');
$settings->save($access,['enabled'=>'1','receive_enabled'=>'1','target'=>'mail-helper@example.invalid','reply_to'=>'mail-return@example.invalid','imap_host'=>'imap.example.invalid','imap_user'=>'mail-return@example.invalid','imap_password'=>'Synthetic-conversation-mail-secret','folder'=>'INBOX','since'=>'2026-10-01'],$state['version']);
$account=(new Accounts($db,$box))->get($club,'meta');$connection=Accounts::binding($account);$sources=[];
foreach(['return','forward']as$key){$parent='own_mail_browser_'.$key;$remote='own_mail_question_'.$key;$id=substr(hash('sha256',$club.':'.$parent),0,32);$payload=['connection_id'=>$connection];
 Db::run($db,"INSERT INTO native_social_objects(club_id,id,channel,remote_id,kind,caption,payload_json,last_seen) VALUES(?,?,'facebook',?,'post','Eigener Mail-Browserbeitrag',?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json)",[$club,$id,$parent,json_encode($payload,JSON_THROW_ON_ERROR)]);
 (new Attention($db,$box))->observe($access,'facebook','comment',$parent,[['id'=>$remote,'text'=>'Eigene unveränderte Originalfrage per '.$key,'name'=>'Eigener Mailgast','title'=>'Eigener Originalbeitrag']],true);
 $sources[$key]=(new Replies($db,$box))->source($access,substr(hash('sha256',$club.':facebook:comment:'.$parent.':'.$remote),0,32));
}
$human="  Echte menschliche Antwort per Mail <script>kein Code</script>\n ";$injected=new Bridge($db,$box,static fn($club,$configuration,$message)=>$message['message_id']);$binding=$settings->configuration($access)['data']['binding'];$history=array_values(array_filter($injected->history($access,$sources['return']),static fn($row)=>$row['binding']===$binding&&$row['source_fingerprint']===$sources['return']['fingerprint']));$thread=$history?end($history):null;
if(!$thread){$thread=$injected->prepare($access,$sources['return']['id'],(int)$sources['return']['version'],'');$thread=$injected->send($access,$thread['id'],(int)$thread['version'],true);}
if($thread['status']!=='accepted'||$thread['connection_id']!==$connection)throw new RuntimeException('Own synthetic return thread differs.');
$raw="From: mail-helper@example.invalid\r\nTo: mail-return@example.invalid\r\nMessage-ID: <own-mail-browser-return-".$thread['id']."@example.invalid>\r\nIn-Reply-To: ".$thread['payload']['message_id']."\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n".$human;
$binding=$settings->configuration($access)['data']['binding'];$cursor=Db::run($db,'SELECT uidvalidity FROM native_social_mail_cursors WHERE club_id=? AND binding=?',[$club,$binding])->fetchColumn();
(new Bridge($db,$box,null,static fn()=>['uidvalidity'=>max(1,(int)$cursor+1),'messages'=>[['uid'=>1,'original'=>$raw]]]))->fetch($access);
$fixture=['database'=>$config['database'],'sources'=>$sources,'connection'=>$connection,'human'=>$human];$fixture['originals']=$snapshot(array_column($sources,'id'));file_put_contents($path,json_encode($fixture,JSON_THROW_ON_ERROR),LOCK_EX);chmod($path,0600);
echo "Own synthetic mail conversation and exact private MIME original prepared; no actual SMTP or IMAP connection.\n";
