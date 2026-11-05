<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Db,SecretBox};
use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Native\Communication\Attention;
require __DIR__.'/original-database-hash.php';
$config=TestSupport::config();if(($config['owner']??'')!=='cloud-onboarding-synthetic'||($config['label']??'')!=='integrated-manager')throw new RuntimeException('Own synthetic manager required.');
$db=TestSupport::db();$access=(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access();$club=$access->club();$box=SecretBox::fromFile(dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/integrated-manager-runtime/private.key');$path=dirname(__DIR__,3).'/.local/browser-tests/attention-catalogue.json';
$snapshot=static fn():string=>ownOriginalDatabaseHash($db,['native_social_inbox','native_social_attention','native_social_originals','native_social_objects','native_private_settings','native_social_replies','native_social_mail_threads','native_social_mail_replies','native_social_mail_dispatch','native_delivery_jobs','documents','document_files','document_links','dwf_document_versions','dwf_signatures','khcms_pages','khcms_revisions','khcms_media']);
if(($argv[1]??'prepare')==='check'){$f=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);if($f['database']!==$config['database']||$f['originals']!==$snapshot())throw new RuntimeException('Originals, approvals, mail or jobs changed.');echo "All exact original database bytes retained.\n";exit;}
\Kulturhus\Native\Installer\SchemaRegistry::install($db);$suffix=bin2hex(random_bytes(5));$prefix='Eigener Kommentargast '.$suffix;$ids=[];$texts=[];$channels=[];
for($i=0;$i<55;$i++){
 $key=str_pad((string)$i,3,'0',STR_PAD_LEFT);$channel=['facebook','instagram','stadtlandfunk'][$i%3];$kind=$i===54?'reaction':'comment';$parent='own_comment_parent_'.$suffix.'_'.$key;$remote='own_comment_'.$suffix.'_'.$key;$text='Genaue ursprüngliche Frage '.$key.' ä🙂 <script>kein Code</script>';
 (new Attention($db,$box))->observe($access,$channel,$kind,$parent,[['id'=>$remote,'text'=>$text,'name'=>$prefix.' '.$key,'title'=>'Originalbeitrag '.$key,'count'=>3]]);
 $id=substr(hash('sha256',$club.':'.$channel.':'.$kind.':'.$parent.':'.$remote),0,32);Db::run($db,'UPDATE native_social_attention SET observed_at=?,state=? WHERE club_id=? AND id=?',[gmdate('Y-m-d H:i:s',time()-$i*86400),$i%2?'reply_needed':'done',$club,$id]);$ids[$key]=$id;$texts[$key]=$text;$channels[$key]=$channel;
}
file_put_contents($path,json_encode(['database'=>$config['database'],'prefix'=>$prefix,'ids'=>$ids,'texts'=>$texts,'channels'=>$channels,'originals'=>$snapshot()],JSON_THROW_ON_ERROR),LOCK_EX);chmod($path,0600);echo "55 owned original comments/reactions on three platforms prepared without provider call.\n";
