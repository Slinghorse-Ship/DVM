<?php declare(strict_types=1);
require_once __DIR__.'/k-browser-fixture.php';
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Native\Cms\Pages;
$k=ownKBrowserFixture();$config=TestSupport::config();if($k['standalone'])throw new RuntimeException('Own integrated K-browser child required.');
$db=TestSupport::db();$access=(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access();$club=$access->club();$pages=new Pages($db,$access);$path=$k['directory'].'/website-footer.json';
require __DIR__.'/original-database-hash.php';
$snapshot=static fn():string=>ownOriginalDatabaseHash($db,['khcms_pages','khcms_revisions','khcms_media','khcms_navigation','khcms_navigation_revisions','khcms_state','native_club_settings','khcms_website_design','khcms_website_design_history','native_social_inbox','native_social_attention','native_social_originals','native_social_replies','native_social_mail_threads','native_social_mail_replies','native_social_mail_dispatch','native_private_settings','native_delivery_jobs','native_users','native_grants','documents','document_files','dwf_document_versions','dwf_signatures']);
if(($argv[1]??'prepare')==='check'){$fixture=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);if($fixture['database']!==$config['database']||$fixture['originals']!==$snapshot())throw new RuntimeException('Original footer, content, identity, accounts, documents or jobs changed.');echo "All original bytes retained after read-only actual website footer browsing.\n";exit;}
$labels=['datenloeschung'=>'Datenlöschung','nutzungsbedingungen'=>'Nutzungsbedingungen'];$legal=[];
foreach($labels as$slug=>$label){$p=$pages->publicPage($slug);if(!$p){if(Db::run($db,'SELECT id FROM khcms_pages WHERE club_id=? AND slug=?',[$club,$slug])->fetchColumn())throw new RuntimeException('Existing private legal page must not be published by fixture.');$p=$pages->save(['slug'=>$slug,'title'=>'Synthetischer Footertest '.$label,'body'=>'Ungültiger synthetischer Footertest: '.$label,'template'=>'standard','nav_visible'=>false]);$p=$pages->publish($p['id'],$p['version']);}$legal[$slug]=['id'=>$p['id'],'title'=>$p['title'],'hidden'=>!$p['nav_visible']];}
file_put_contents($path,json_encode(['database'=>$config['database'],'legal'=>$legal,'originals'=>$snapshot()],JSON_THROW_ON_ERROR),LOCK_EX);chmod($path,0600);echo "Own synthetic published legal originals prepared; no remote publication or import.\n";
