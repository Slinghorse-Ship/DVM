<?php declare(strict_types=1);
require_once __DIR__.'/k-browser-fixture.php';
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';require __DIR__.'/original-database-hash.php';
use Kulturhus\Native\TestSupport;
$k=ownKBrowserFixture();$config=TestSupport::config();if($k['standalone'])throw new RuntimeException('Own integrated K-browser child required.');
$db=TestSupport::db();$path=$k['directory'].'/feed-http.json';
$tables=['khcms_pages','khcms_revisions','khcms_media','khcms_published_media','native_users','native_grants','native_social_objects','native_social_attention','native_social_originals','native_social_inbox','native_social_replies','native_social_mail_threads','native_social_mail_replies','native_social_mail_dispatch','native_private_settings','native_delivery_jobs','documents','document_files','document_links','dwf_document_versions','dwf_signatures'];
$hash=ownOriginalDatabaseHash($db,$tables);
if(($argv[1]??'prepare')==='check'){$f=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);if($f['database']!==$config['database']||$f['originals']!==$hash)throw new RuntimeException('Public HTTP changed an original, account, document, media, approval or job.');echo "Every exact original database byte retained after cross-origin public feed reads.\n";exit;}
file_put_contents($path,json_encode(['database'=>$config['database'],'originals'=>$hash],JSON_THROW_ON_ERROR),LOCK_EX);chmod($path,0600);echo "Read-only public feed baseline captured without changing an original or transport.\n";
