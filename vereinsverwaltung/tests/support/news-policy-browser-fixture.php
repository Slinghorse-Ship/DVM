<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Native\Cms\{Articles,NewsPolicy};
use Kulturhus\Native\Integration\ManagerBridge;
require __DIR__.'/original-database-hash.php';
$config=TestSupport::config();if(($config['owner']??'')!=='cloud-onboarding-synthetic'||($config['label']??'')!=='integrated-manager')throw new RuntimeException('Own synthetic news policy required.');
$db=TestSupport::db();$file=dirname(__DIR__,3).'/.local/browser-tests/news-policy.json';
$access=(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access();$second=(new ManagerBridge($db,'manager-editor','native',static fn()=>null))->access();
$snapshot=static function(array $ids)use($db):string{
    return ownOriginalDatabaseHash($db,['khcms_pages','khcms_revisions','khcms_news_approvals','khcms_media','native_social_inbox','native_social_attention','native_social_originals','native_social_replies','native_social_mail_threads','native_social_mail_replies','native_social_mail_dispatch','native_private_settings','native_delivery_jobs','documents','document_files','document_links','dwf_document_versions','dwf_signatures'],static fn($table)=>in_array($table,['khcms_pages','khcms_revisions','khcms_news_approvals'],true)?[' WHERE '.($table==='khcms_pages'?'id':'page_id').' NOT IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids]:['',[]]);
};
if(($argv[1]??'prepare')==='restore'){$f=json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR);$p=new NewsPolicy($db,$access);$p->save($f['initial']['enabled'],$p->get()['version']);echo "Original optional policy restored; immutable new review history retained.\n";exit;}
if(($argv[1]??'prepare')==='check'){
    $f=json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
    if($f['database']!==$config['database']||$f['originals']!==$snapshot(array_column($f['items'],'id')))throw new RuntimeException('An existing original, human reply or provider job changed.');
    echo "All existing original bytes retained; only owned news and private review decisions changed.\n";exit;
}
\Kulturhus\Native\Installer\SchemaRegistry::install($db);
$second->require('website','publish');$policy=new NewsPolicy($db,$access);$initial=$policy->get();if($initial['enabled'])throw new RuntimeException('Browser corpus requires the owned default optional rule.');
$articles=new Articles($db,$access);$items=[];$suffix=bin2hex(random_bytes(5));
foreach([1440,820,390,320,390]as$i=>$width){
    $p=$articles->save(['title'=>'Eigene Vier-Augen-Prüfung '.$suffix.' '.$i,'slug'=>'aktuelles/vier-augen-'.$suffix.'-'.$i,'news_date'=>'2050-01-12','body'=>'Eigenes ursprüngliches Beitragsoriginal '.$suffix.' '.$i,'nav_visible'=>false]);
    $p=$articles->publish($p['id'],$p['version']);$items[]=['id'=>$p['id'],'slug'=>$p['slug'],'body'=>$p['body'],'width'=>$width,'js'=>$i!==4];
}
file_put_contents($file,json_encode(['database'=>$config['database'],'initial'=>$initial,'items'=>$items,'originals'=>$snapshot(array_column($items,'id'))],JSON_THROW_ON_ERROR));chmod($file,0600);
echo "Five owned published originals prepared for two real manager roles; no provider action.\n";
