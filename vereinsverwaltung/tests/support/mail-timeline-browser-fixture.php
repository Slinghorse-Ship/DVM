<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);umask(0077);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';require __DIR__.'/mail-timeline-originals.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Db,SecretBox};
use Kulturhus\Native\Integration\{Accounts,ManagerBridge};
use Kulturhus\Native\Communication\Mail\Settings;
$config=TestSupport::config();if(($config['owner']??'')!=='cloud-onboarding-synthetic'||($config['label']??'')!=='integrated-manager')throw new RuntimeException('Own synthetic manager required.');
$db=TestSupport::db();$access=(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access();$box=SecretBox::fromFile(dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/integrated-manager-runtime/private.key');$path=dirname(__DIR__,3).'/.local/browser-tests/mail-timeline.json';
require __DIR__.'/original-database-hash.php';
$snapshot=static fn():string=>ownOriginalDatabaseHash($db,['native_social_inbox','native_social_attention','native_social_originals','native_social_objects','native_social_replies','native_social_mail_threads','native_social_mail_replies','native_social_mail_cursors','native_social_mail_dispatch','native_private_settings','native_delivery_jobs','khcms_pages','khcms_revisions','khcms_media','native_users','native_grants','documents','document_files','document_links','dwf_document_versions','dwf_signatures']);
if(($argv[1]??'prepare')==='check'){$fixture=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);if($fixture['database']!==$config['database']||$fixture['originals']!==$snapshot())throw new RuntimeException('Original mail/chat/content/account/document or job bytes changed.');echo "All exact original database bytes retained after complete mail timeline browsing.\n";exit;}
if((new Settings($db,$box))->configuration($access)['data']['enabled'])throw new RuntimeException('Synthetic mail module must remain off for read-only timeline fixture.');
$fixture=ownMailTimeline($db,$box,$access,(new Accounts($db,$box))->get($access->club(),'meta'),200000,'blocked');$fixture['comment']=ownMailTimeline($db,$box,$access,(new Accounts($db,$box))->get($access->club(),'meta'),1000,'blocked','comment');$fixture['database']=$config['database'];$fixture['originals']=$snapshot();file_put_contents($path,json_encode($fixture,JSON_THROW_ON_ERROR),LOCK_EX);chmod($path,0600);echo "70 original unsent synthetic mails prepared for bounded browser history; no provider or mailbox action.\n";
