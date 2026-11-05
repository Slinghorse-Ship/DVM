<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Db,SecretBox};
use Kulturhus\Native\Integration\{ManagerBridge,Accounts};
use Kulturhus\Native\Communication\{Attention,OriginalArchive};
require __DIR__.'/original-database-hash.php';
require __DIR__.'/mail-timeline-originals.php';
$config=TestSupport::config();if(($config['owner']??'')!=='cloud-onboarding-synthetic'||($config['label']??'')!=='integrated-manager')throw new RuntimeException('Own synthetic manager required.');
$db=TestSupport::db();$access=(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access();$club=$access->club();$box=SecretBox::fromFile(dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/integrated-manager-runtime/private.key');$path=dirname(__DIR__,3).'/.local/browser-tests/social-originals.json';
$snapshot=static fn():string=>ownOriginalDatabaseHash($db,['native_social_inbox','native_social_attention','native_social_originals','native_social_objects','native_private_settings','native_social_replies','native_social_mail_threads','native_social_mail_replies','native_social_mail_dispatch','native_delivery_jobs','documents','document_files','document_links','dwf_document_versions','dwf_signatures','khcms_pages','khcms_revisions','khcms_media']);
if(($argv[1]??'prepare')==='check'){$f=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);if($f['database']!==$config['database']||$f['originals']!==$snapshot())throw new RuntimeException('Originals, approvals, mail or jobs changed.');echo "All exact original database bytes retained.\n";exit;}
\Kulturhus\Native\Installer\SchemaRegistry::install($db);$suffix=bin2hex(random_bytes(5));$prefix='Eigener Originalgast '.$suffix;$scenarios=[];$archive=new OriginalArchive($db,$box);
foreach(['facebook','instagram','stadtlandfunk']as$channel){
 $parent='own_archive_parent_'.$suffix.'_'.$channel;$remote='own_archive_comment_'.$suffix.'_'.$channel;$id=substr(hash('sha256',$club.':'.$channel.':comment:'.$parent.':'.$remote),0,32);$texts=[];
 for($i=0;$i<=($channel==='facebook'?55:0);$i++){$text='Genaue ursprüngliche Fassung '.$i.' ä🙂 <script>kein Code</script>';$texts[$i]=$text;(new Attention($db,$box))->observe($access,$channel,'comment',$parent,[['id'=>$remote,'text'=>$text,'name'=>$prefix.' '.$channel,'creator_id'=>'own_archive_guest','title'=>'Eigener ursprünglicher Beitrag']],true);}
 (new Attention($db,$box))->observe($access,$channel,'comment',$parent,[],true);$history=$archive->history($access,'attention',$id);$earlier=$archive->history($access,'attention',$id,$history['pages']);$firstId=$earlier['rows'][array_key_last($earlier['rows'])]['id'];
 $scenarios[$channel]=['attention'=>$id,'latest_text'=>end($texts),'first_text'=>$texts[0],'first_id'=>$firstId,'count'=>$history['total'],'pages'=>$history['pages']];
}
$private=ownMailTimeline($db,$box,$access,(new Accounts($db,$box))->get($club,'meta'),1000,'blocked','message');
(new Attention($db,$box))->observe($access,'facebook','message',$private['source']['parent_id'],[],true);
file_put_contents($path,json_encode(['database'=>$config['database'],'prefix'=>$prefix,'scenarios'=>$scenarios,'private'=>$private,'originals'=>$snapshot()],JSON_THROW_ON_ERROR),LOCK_EX);chmod($path,0600);echo "Own original histories on all three platforms, 56 exact Facebook versions and 70 original private mails prepared without provider action.\n";
