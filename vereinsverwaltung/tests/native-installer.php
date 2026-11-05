<?php declare(strict_types=1);
if (($argv[1]??'')==='--render') {
    $payload=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
    $target=$payload['target'];
    require $target.'/private/runtime.php';require $target.'/app/vereinsverwaltung/native/bootstrap.php';
    if ($payload['native_only'] ?? false) ini_set('open_basedir', implode(PATH_SEPARATOR, [$target.'/app/vereinsverwaltung', $target.'/app/banking', $target.'/app/vendor', $target.'/private', $target.'/web']));
    $_SERVER=['HTTP_HOST'=>$payload['host']??'musterverein.example','HTTPS'=>$payload['https']??'on','REQUEST_METHOD'=>$payload['method']??'GET','REMOTE_ADDR'=>'127.0.0.1','REQUEST_URI'=>$payload['path']];
    if(isset($payload['origin']))$_SERVER['HTTP_ORIGIN']=$payload['origin'];
    $_GET=$payload['query']??[];$_POST=[];$_COOKIE=[];
    \Kulturhus\Native\Integration\ManagerSession::start();$_SESSION['auth_token']=$payload['token'];session_write_close();
    ob_start();register_shutdown_function(static function():void {
        $html=ob_get_clean();$doc=new DOMDocument();@$doc->loadHTML($html?:'<html></html>');
        $legacy=array_filter(get_included_files(),static fn($f)=>str_contains($f,'/processwire/'));
        echo json_encode(['status'=>http_response_code()?:200,'legacy'=>count($legacy),'fatal'=>in_array(error_get_last()['type']??0,[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true),'text'=>$doc->textContent,'sha256'=>hash('sha256',$html),'bytes'=>strlen($html)],JSON_THROW_ON_ERROR);
    });
    require $target.'/web/index.php';exit;
}
require dirname(__DIR__).'/native/bootstrap.php';
require __DIR__.'/support/own-website-pdf.php';
require __DIR__.'/support/original-database-hash.php';
require dirname(__DIR__,2).'/distribution/src/PackageStore.php';
use Kulturhus\Native\{TestSupport};
use Kulturhus\Native\Installer\Install;
use Kulturhus\Native\Auth\Runtime;
use Kulturhus\Native\Integration\ManagerBridge;

$db=TestSupport::db(); $fixture=TestSupport::config();
$target=sys_get_temp_dir().'/kh-native-install-'.bin2hex(random_bytes(10));
$config=['target'=>$target,'dsn'=>'mysql:host=127.0.0.1;port=13371;dbname='.$fixture['database'].';charset=utf8mb4',
    'scheduler_php'=>PHP_BINARY,'username'=>$fixture['username'],'password'=>$fixture['password'],'club_id'=>'installer-test','club_name'=>'Synthetic installer club',
    'hosts'=>'musterverein.example','admin_email'=>'installer@example.invalid','admin_password'=>'Synthetic installer password 2026!'];
$installer=new Install(dirname(__DIR__,2)); $n=0;
function installerCheck(bool $ok,string $label):void {global $n;if(!$ok)throw new RuntimeException($label);++$n;echo 'PASS '.$label."\n";}
function installerReject(callable $fn,string $label):void {try{$fn();}catch(DomainException){installerCheck(true,$label);return;}throw new RuntimeException($label);}
try {
    mkdir($target,0700);file_put_contents($target.'/existing.txt','preserve');
    installerReject(fn()=>$installer->run($config,$db),'occupied target rejected before database writes');
    installerCheck($db->query('SHOW TABLES')->fetchAll()===[] && file_get_contents($target.'/existing.txt')==='preserve','existing files and empty schema preserved');
    unlink($target.'/existing.txt');rmdir($target);
    $result=$installer->run($config,$db);
    installerCheck(($result['scheduler']['php']??'')===PHP_BINARY && count($result['scheduler']['jobs']??[])===15 && ($result['scheduler']['status']??'')==='not-verified' && !str_contains($result['scheduler_text']??'',$fixture['password']), 'fresh CLI installation returns its original PHP selection and fifteen non-secret unverified hosting instructions');
    installerCheck($result['provider']==='native'&&$result['modules']===count(\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS),'fresh native installation initializes every declared module');
    installerCheck((int)$db->query('SELECT COUNT(*) FROM native_schema_versions')->fetchColumn()===count(\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS),'common persistent schema registry complete');
    installerCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='status'")->fetchColumn()===1&&(int)$db->query('SELECT COUNT(*) FROM native_status_settings')->fetchColumn()===0&&(int)$db->query('SELECT COUNT(*) FROM native_status_checks')->fetchColumn()===0,'fresh status schema starts private and manual with no invented observations');
    installerCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='extensions'")->fetchColumn()===1&&(int)$db->query('SELECT COUNT(*) FROM native_extensions')->fetchColumn()===0&&(int)$db->query('SELECT COUNT(*) FROM native_extension_values')->fetchColumn()===0&&(int)$db->query('SELECT COUNT(*) FROM native_extension_history')->fetchColumn()===0,'fresh extension schema installs no modules, public changes or private configuration automatically');
    installerCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='delivery'")->fetchColumn()===\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['delivery']&&(int)$db->query('SELECT COUNT(*) FROM native_social_human_sends')->fetchColumn()===0,'fresh delivery 12 installs an empty human intention ledger without inventing messages');
    installerCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='identity'")->fetchColumn()===\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['identity']&&(int)$db->query('SELECT COUNT(*) FROM native_catalog_charges')->fetchColumn()===0,'fresh current identity installs an empty tariff-charge ledger without inventing bills');
    installerCheck((int)$db->query('SELECT COUNT(*) FROM native_role_requests')->fetchColumn()===0,'identity nine adds an empty first-role confirmation ledger without inventing older accepted changes');
    installerCheck((int)$db->query('SELECT COUNT(*) FROM native_settings_requests')->fetchColumn()===0,'identity ten adds an empty general-settings original ledger without inventing earlier approvals');
    installerCheck(\Kulturhus\Verein\RoleAssignments::defaultsSupported($db),'current identity installation preserves nullable role identity and original role versions');
    installerCheck((int)$db->query('SELECT COUNT(*) FROM native_assignment_prices')->fetchColumn()===0&&(int)$db->query('SELECT COUNT(*) FROM native_assignment_price_changes')->fetchColumn()===0,'fresh identity seven creates no personal price or price decision');
    installerCheck((int)$db->query('SELECT COUNT(*) FROM native_catalog_automation')->fetchColumn()===0&&(int)$db->query('SELECT COUNT(*) FROM native_catalog_automation_runs')->fetchColumn()===0,'fresh installation enables no recurring policy and invents no scheduled charge');
    installerCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='cms'")->fetchColumn()===\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['cms']&&(int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('khcms_news_policy','khcms_news_policy_history','khcms_news_approvals')")->fetchColumn()===3,'fresh declared CMS installs private policy and immutable approval tables');
    $newCmsTables=['khcms_tree_copy_jobs','khcms_tree_copy_items','khcms_tree_copy_removals','khcms_page_tombstones','khcms_purged_paths','khcms_template_policy_state'];
    foreach($newCmsTables as$table)installerCheck((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'fresh CMS 18 adds no copy, purge or access decisions: '.$table);
    installerCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='language'")->fetchColumn()===1,'fresh language schema is explicitly installed without enabling translations');
    $languageTables=['native_language_settings','native_language_settings_revisions','native_language_rights','native_language_right_revisions','native_language_pages','native_language_revisions','native_language_routes','native_language_preferences'];
    foreach($languageTables as$table)installerCheck((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'fresh language module invents no translation, route, preference or grant: '.$table);

    installerCheck((int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE 'dwf_%'")->fetchColumn()>0,'document workflows initialized with manager');
    installerCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='workflows'")->fetchColumn()===\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['workflows']&&\Kulturhus\Verein\DocumentWorkflow\InspectionStore::installed($db),'fresh current workflows installs operational inspections independently of assessment approval');
    installerCheck((int)$db->query('SELECT COUNT(*) FROM dwf_creation_requests')->fetchColumn()===0,'fresh workflow-3 creates no invented personal creation receipt');
    $auth=Runtime::service($db,$target.'/private/auth.key');
    $login=$auth->passwordLogin($config['admin_email'],$config['admin_password'],'installer-check');
    installerCheck($login['status']==='authenticated','created administrator authenticates through native password service');
    $bridge=ManagerBridge::fromNativeSession($db,$auth,$login['session_token']);
    installerCheck($bridge->managerClub()===$config['club_id'],'administrator is bound to the installed club');
    $design=new \Kulturhus\Native\Cms\WebsiteDesign($db,$bridge->access());
    installerCheck($design->get()['template']==='klar'&&$design->get()['version']===1,'fresh website starts with the modern Klar template');
    $newsPages=new \Kulturhus\Native\Cms\Pages($db,$bridge->access());
    $upgradeOriginal=$newsPages->save(['title'=>'Original vor CMS-Upgrade','slug'=>'upgrade-original','body'=>'Genau erhaltene eigene Fassung']);
    $upgradeOriginal=$newsPages->publish($upgradeOriginal['id'],$upgradeOriginal['version']);
    $upgradeRows=[$db->query('SELECT * FROM khcms_pages ORDER BY id')->fetchAll(),$db->query('SELECT * FROM khcms_revisions ORDER BY page_id,revision')->fetchAll()];
    $cms18OldTables=array_values(array_diff($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),$newCmsTables));
    $cms18RegistryFilter=static fn(string $table):array=>$table==='native_schema_versions'?[" WHERE module<>'cms'",[]]:['',[]];
    $cms18OriginalHash=ownOriginalDatabaseHash($db,$cms18OldTables,$cms18RegistryFilter);
    foreach(array_reverse($newCmsTables) as$table){if((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()!==0)throw new RuntimeException('CMS 17 fixture requires empty future lifecycle tables.');$db->exec('DROP TABLE '.$table);}
    $db->exec("UPDATE native_schema_versions SET version=17 WHERE module='cms'");
    \Kulturhus\Native\Installer\SchemaRegistry::install($db);
    installerCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='cms'")->fetchColumn()===18,'actual CMS 17 upgrade installs declared lifecycle and template rules');
    foreach($newCmsTables as$table)installerCheck((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'CMS 18 upgrade creates no original copy, purge or policy decisions: '.$table);
    installerCheck(ownOriginalDatabaseHash($db,$cms18OldTables,$cms18RegistryFilter)===$cms18OriginalHash,'CMS 18 upgrade preserves every complete earlier business row and key');
    foreach(['khcms_news_approvals','khcms_news_policy_history','khcms_news_policy']as$table){if((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()!==0)throw new RuntimeException('Upgrade fixture requires empty new tables.');$db->exec('DROP TABLE '.$table);}
    $db->exec("UPDATE native_schema_versions SET version=8 WHERE module='cms'");
    \Kulturhus\Native\Installer\SchemaRegistry::install($db);
    installerCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='cms'")->fetchColumn()===\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['cms']&&!(new \Kulturhus\Native\Cms\NewsPolicy($db,$bridge->access()))->get()['enabled'],'actual CMS 8 upgrade creates optional current policy without retroactive publication changes');
    installerCheck($upgradeRows===[$db->query('SELECT * FROM khcms_pages ORDER BY id')->fetchAll(),$db->query('SELECT * FROM khcms_revisions ORDER BY page_id,revision')->fetchAll()],'CMS upgrade preserves exact draft and published revision bytes');
    foreach(['khcms_media_metadata_revisions','khcms_media_metadata']as$table){installerCheck((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'fresh metadata table contains no invented legacy rights: '.$table);$db->exec('DROP TABLE '.$table);}
    $db->exec("UPDATE native_schema_versions SET version=9 WHERE module='cms'");
    \Kulturhus\Native\Installer\SchemaRegistry::install($db);
    installerCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='cms'")->fetchColumn()===\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['cms']&&(int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('khcms_media_metadata','khcms_media_metadata_revisions')")->fetchColumn()===2,'actual CMS 9 upgrade installs current and immutable metadata tables');
    installerCheck($upgradeRows===[$db->query('SELECT * FROM khcms_pages ORDER BY id')->fetchAll(),$db->query('SELECT * FROM khcms_revisions ORDER BY page_id,revision')->fetchAll()],'CMS 9 metadata upgrade retains exact existing public and private originals');
    installerCheck((int)$db->query('SELECT COUNT(*) FROM khcms_media_quota')->fetchColumn()===0&&(int)$db->query('SELECT COUNT(*) FROM khcms_media_quota_history')->fetchColumn()===0,'fresh declared CMS invents no photo quota or policy history');
    installerCheck((int)$db->query('SELECT COUNT(*) FROM khcms_media_policy')->fetchColumn()===0&&(int)$db->query('SELECT COUNT(*) FROM khcms_media_policy_history')->fetchColumn()===0,'fresh declared CMS has optional image checks disabled without invented audit rows');
    $quotaSource=$target.'/private/quota-upgrade.png';$quotaPixels=imagecreatetruecolor(16,12);imagefill($quotaPixels,0,0,imagecolorallocate($quotaPixels,30,125,75));imagepng($quotaPixels,$quotaSource);unset($quotaPixels);
    $quotaImage=(new \Kulturhus\Native\Cms\Media($db,$bridge->access(),$target.'/private/media'))->upload($quotaSource,'Own upgrade photo.png','Own original upgrade photo');unlink($quotaSource);
    (new \Kulturhus\Native\Cms\MediaMetadata($db,$bridge->access()))->save($quotaImage['id'],['alt'=>'Original before quota upgrade','credit'=>'Own unchanged credit'],0);
    $metadataBeforeQuota=[$db->query('SELECT * FROM khcms_media_metadata ORDER BY club_id,media_id')->fetchAll(),$db->query('SELECT * FROM khcms_media_metadata_revisions ORDER BY club_id,media_id,version')->fetchAll()];
    $db->exec('DROP TABLE khcms_media_quota_history');$db->exec('DROP TABLE khcms_media_quota');$db->exec("UPDATE native_schema_versions SET version=10 WHERE module='cms'");
    \Kulturhus\Native\Installer\SchemaRegistry::install($db);
    installerCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='cms'")->fetchColumn()===\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['cms']&&(int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('khcms_media_quota','khcms_media_quota_history')")->fetchColumn()===2,'actual CMS 10 upgrade installs empty current and immutable photo quota tables');
    installerCheck($metadataBeforeQuota===[$db->query('SELECT * FROM khcms_media_metadata ORDER BY club_id,media_id')->fetchAll(),$db->query('SELECT * FROM khcms_media_metadata_revisions ORDER BY club_id,media_id,version')->fetchAll()],'quota upgrade preserves actual original image metadata and revisions');
    $policyRows=[$db->query('SELECT * FROM khcms_pages ORDER BY id')->fetchAll(),$db->query('SELECT * FROM khcms_revisions ORDER BY page_id,revision')->fetchAll(),$db->query('SELECT * FROM khcms_media ORDER BY id')->fetchAll()];
    $db->exec('DROP TABLE khcms_media_policy_history');$db->exec('DROP TABLE khcms_media_policy');$db->exec("UPDATE native_schema_versions SET version=11 WHERE module='cms'");\Kulturhus\Native\Installer\SchemaRegistry::install($db);
    $newPolicy=(new \Kulturhus\Native\Cms\MediaPolicy($db,$bridge->access()))->get();
    installerCheck($newPolicy===['version'=>0,'check_rights'=>false,'require_details'=>false]&&(int)$db->query("SELECT version FROM native_schema_versions WHERE module='cms'")->fetchColumn()===\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['cms'],'actual CMS 11 upgrade creates optional global image policy without blocking uploads');
    installerCheck($policyRows===[$db->query('SELECT * FROM khcms_pages ORDER BY id')->fetchAll(),$db->query('SELECT * FROM khcms_revisions ORDER BY page_id,revision')->fetchAll(),$db->query('SELECT * FROM khcms_media ORDER BY id')->fetchAll()],'CMS 11 policy upgrade preserves every original draft, published revision and photo row');
    installerCheck($upgradeRows===[$db->query('SELECT * FROM khcms_pages ORDER BY id')->fetchAll(),$db->query('SELECT * FROM khcms_revisions ORDER BY page_id,revision')->fetchAll()],'quota upgrade preserves every original published content byte');
    $ownArchivePng=$target.'/private/own-archive-upgrade.png';$raster=imagecreatetruecolor(32,24);imagefill($raster,0,0,imagecolorallocate($raster,40,120,180));imagepng($raster,$ownArchivePng);unset($raster);
    $archiveMedia=new \Kulturhus\Native\Cms\Media($db,$bridge->access(),$target.'/private/media');$ownArchivePhoto=$archiveMedia->upload($ownArchivePng,'Eigene Upgradeaufnahme.png','Eigenes unverändertes Foto');
    $archiveRows=$db->query('SELECT * FROM khcms_media ORDER BY id')->fetchAll();$archiveHashes=[];foreach(['original','large','medium','thumb']as$size)$archiveHashes[$size]=hash_file('sha256',$archiveMedia->variant($ownArchivePhoto['id'],$size)['path']);
    $db->exec('DROP TABLE khcms_media_archive_history');$db->exec('DROP TABLE khcms_media_archives');$db->exec("UPDATE native_schema_versions SET version=12 WHERE module='cms'");\Kulturhus\Native\Installer\SchemaRegistry::install($db);
    installerCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='cms'")->fetchColumn()===\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['cms']&&(int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('khcms_media_archives','khcms_media_archive_history')")->fetchColumn()===2,'actual CMS 12 upgrade installs reversible archive and immutable history tables');
    installerCheck((int)$db->query('SELECT COUNT(*) FROM khcms_media_archives')->fetchColumn()===0&&(int)$db->query('SELECT COUNT(*) FROM khcms_media_archive_history')->fetchColumn()===0,'archive upgrade invents no original archive state or history');
    installerCheck($archiveRows===$db->query('SELECT * FROM khcms_media ORDER BY id')->fetchAll(),'archive upgrade preserves exact original photo rows');
    foreach($archiveHashes as$size=>$hash)installerCheck(hash_file('sha256',$archiveMedia->variant($ownArchivePhoto['id'],$size)['path'])===$hash,'archive upgrade preserves actual original '.$size.' pixels');
    installerCheck($upgradeRows===[$db->query('SELECT * FROM khcms_pages ORDER BY id')->fetchAll(),$db->query('SELECT * FROM khcms_revisions ORDER BY page_id,revision')->fetchAll()],'archive upgrade preserves existing exact public and private website originals');
    $db->exec('DROP TABLE khcms_documents');$db->exec("UPDATE native_schema_versions SET version=13 WHERE module='cms'");\Kulturhus\Native\Installer\SchemaRegistry::install($db);
    installerCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='cms'")->fetchColumn()===\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['cms']&&(int)$db->query('SELECT COUNT(*) FROM khcms_documents')->fetchColumn()===0,'actual CMS 13 upgrade adds only empty new website document storage without importing legal PDFs');
    installerCheck($archiveRows===$db->query('SELECT * FROM khcms_media ORDER BY id')->fetchAll()&&$upgradeRows===[$db->query('SELECT * FROM khcms_pages ORDER BY id')->fetchAll(),$db->query('SELECT * FROM khcms_revisions ORDER BY page_id,revision')->fetchAll()],'document upgrade retains every exact original photo, draft and published website row');
    foreach($archiveHashes as $size=>$hash)installerCheck(hash_file('sha256',$archiveMedia->variant($ownArchivePhoto['id'],$size)['path'])===$hash,'document upgrade retains original '.$size.' photo pixels');

    $removalTables=['khcms_media_removals','khcms_media_removal_states','khcms_media_removal_history'];
    $earlierTables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    $earlierTables=array_values(array_diff($earlierTables,$removalTables));
    $registryFilter=static fn(string $table):array=>$table==='native_schema_versions'?[" WHERE module<>'cms'",[]]:['',[]];
    $allEarlierBytes=ownOriginalDatabaseHash($db,$earlierTables,$registryFilter);
    foreach(array_reverse($removalTables)as$table){installerCheck((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'fresh removal table has no invented original events: '.$table);$db->exec('DROP TABLE '.$table);}
    $db->exec("UPDATE native_schema_versions SET version=14 WHERE module='cms'");
    \Kulturhus\Native\Installer\SchemaRegistry::install($db);
    installerCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='cms'")->fetchColumn()===\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['cms']&&(int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('khcms_media_removals','khcms_media_removal_states','khcms_media_removal_history')")->fetchColumn()===3,'actual CMS 14 upgrade installs current removal state and immutable operation/history tables');
    foreach($removalTables as$table)installerCheck((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'CMS 14 upgrade invents no removed originals: '.$table);
    installerCheck(ownOriginalDatabaseHash($db,$earlierTables,$registryFilter)===$allEarlierBytes,'CMS 14 removal upgrade preserves every complete earlier row including original media, proofs and registry modules');
    foreach($archiveHashes as$size=>$hash)installerCheck(hash_file('sha256',$archiveMedia->variant($ownArchivePhoto['id'],$size)['path'])===$hash,'removal upgrade retains actual original '.$size.' pixels');

    $retentionSource=$target.'/private/own-retention-upgrade.png';$retentionPixel=imagecreatetruecolor(20,16);imagepng($retentionPixel,$retentionSource);unset($retentionPixel);
    $retentionImage=(new \Kulturhus\Native\Cms\Media($db,$bridge->access(),$target.'/private/media'))->upload($retentionSource,'Own retained upgrade.png','Own retained upgrade');unlink($retentionSource);
    $retentionRemoval=new \Kulturhus\Native\Cms\MediaRemoval($db,$bridge->access(),$target.'/private/media');$retentionState=$retentionRemoval->remove($retentionImage['id'],0,bin2hex(random_bytes(16)));$retentionThumb=$retentionRemoval->thumbnail($retentionImage['id']);
    $retentionTables=['khcms_media_retention','khcms_media_retention_history'];
    $beforeRetention=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    $beforeRetention=array_values(array_diff($beforeRetention,$retentionTables));$retentionBytes=ownOriginalDatabaseHash($db,$beforeRetention,$registryFilter);
    foreach(array_reverse($retentionTables)as$table){installerCheck((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'fresh retention table invents no original policy: '.$table);$db->exec('DROP TABLE '.$table);}
    $db->exec("UPDATE native_schema_versions SET version=15 WHERE module='cms'");\Kulturhus\Native\Installer\SchemaRegistry::install($db);
    installerCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='cms'")->fetchColumn()===\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['cms']&&(new \Kulturhus\Native\Cms\MediaRetention($db,$bridge->access()))->get()===['version'=>0,'minimum_days'=>30],'actual CMS 15 upgrade installs current retention without invented historical choices');
    foreach($retentionTables as$table)installerCheck((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'CMS 15 upgrade adds only empty own retention tables: '.$table);
    installerCheck(ownOriginalDatabaseHash($db,$beforeRetention,$registryFilter)===$retentionBytes,'retention upgrade preserves every complete earlier media/proof/removal/history/registry row');
    foreach($archiveHashes as$size=>$hash)installerCheck(hash_file('sha256',$archiveMedia->variant($ownArchivePhoto['id'],$size)['path'])===$hash,'retention upgrade keeps exact original '.$size.' pixels');
    installerCheck($retentionRemoval->get($retentionImage['id'])===$retentionState&&$retentionRemoval->thumbnail($retentionImage['id'])===$retentionThumb&&hash_file('sha256',$retentionThumb['path'])===$retentionThumb['sha256'],'CMS 15 retention upgrade keeps real earlier quarantine state, immutable deadline and original pixels');

    $documentQuotaTables=['khcms_document_quota','khcms_document_quota_history'];foreach($documentQuotaTables as$table){installerCheck((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'fresh declared CMS invents no document quota choice: '.$table);}
    $documentQuotaSource=$target.'/private/own-document-quota-upgrade.pdf';$documentQuotaBytes=ownWebsitePdf('Own original PDF prepared before the CMS 16 quota upgrade.');file_put_contents($documentQuotaSource,$documentQuotaBytes);$documentQuotaStore=new \Kulturhus\Native\Cms\WebsiteDocuments($db,$bridge->access(),$target.'/private/website-documents');$documentQuotaOriginals=$documentQuotaStore->uploadMany([['path'=>$documentQuotaSource,'name'=>'Own protected published quota PDF.pdf'],['path'=>$documentQuotaSource,'name'=>'Own protected private quota PDF.pdf']]);
    $documentQuotaPages=new \Kulturhus\Native\Cms\Pages($db,$bridge->access());$documentQuotaPage=$documentQuotaPages->save(['title'=>'Own original document quota upgrade','slug'=>'own-document-quota-upgrade','document_links'=>[['id'=>$documentQuotaOriginals[0]['id'],'label'=>'Own protected original PDF']]]);$documentQuotaPages->publish($documentQuotaPage['id'],1);$documentQuotaPublication=$documentQuotaPages->publicPage($documentQuotaPage['slug']);
    $beforeDocumentQuota=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);$beforeDocumentQuota=array_values(array_diff($beforeDocumentQuota,$documentQuotaTables));$documentQuotaDatabaseHash=ownOriginalDatabaseHash($db,$beforeDocumentQuota,$registryFilter);$documentQuotaFiles=[];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target,FilesystemIterator::SKIP_DOTS))as$file)if($file->isFile())$documentQuotaFiles[$file->getPathname()]=hash_file('sha256',$file->getPathname());
    foreach(array_reverse($documentQuotaTables)as$table)$db->exec('DROP TABLE '.$table);$db->exec("UPDATE native_schema_versions SET version=16 WHERE module='cms'");$legacyDocumentQuota=(new \Kulturhus\Native\Cms\DocumentQuota($db,$bridge->access()))->get();installerCheck($legacyDocumentQuota['version']===0&&$legacyDocumentQuota['limit_bytes']===0&&$legacyDocumentQuota['document_count']===2,'actual CMS 16 remains unlimited and counts both exact original PDFs without web DDL');installerCheck((int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('khcms_document_quota','khcms_document_quota_history')")->fetchColumn()===0,'actual CMS 16 quota read creates no schema table');
    \Kulturhus\Native\Installer\SchemaRegistry::install($db);installerCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='cms'")->fetchColumn()===\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['cms']&&(new \Kulturhus\Native\Cms\DocumentQuota($db,$bridge->access()))->get()===$legacyDocumentQuota,'actual CMS 16 upgrade initializes current optional quota without inventing historic policy');foreach($documentQuotaTables as$table)installerCheck((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'actual upgrade adds only empty own document quota table: '.$table);$documentQuotaExpectedTables=[...$beforeDocumentQuota,...$documentQuotaTables];sort($documentQuotaExpectedTables,SORT_STRING);installerCheck($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN)===$documentQuotaExpectedTables,'actual quota upgrade adds exactly its two new tables and preserves every earlier table');installerCheck(ownOriginalDatabaseHash($db,$beforeDocumentQuota,$registryFilter)===$documentQuotaDatabaseHash,'actual CMS 16 quota upgrade preserves every earlier complete original row and primary key');
    foreach($documentQuotaFiles as$path=>$hash)if(hash_file('sha256',$path)!==$hash)throw new RuntimeException('Earlier installed original file changed during document quota upgrade.');installerCheck(true,'actual document quota upgrade retains every earlier installed file, source, original PDF, pixel, key and quarantine journal byte exact');installerCheck($documentQuotaPages->publicPage($documentQuotaPage['slug'])===$documentQuotaPublication&&$documentQuotaStore->publicFile($documentQuotaOriginals[0]['id'])!==null&&$documentQuotaStore->publicFile($documentQuotaOriginals[1]['id'])===null,'actual document quota upgrade preserves the exact earlier publication and private/public document boundary');

    installerCheck(file_get_contents($target.'/web/.user.ini')===\Kulturhus\Native\Installer\Deployment::UPLOAD_INI,'fresh actual deployment has bounded PHP upload settings for real video and multi-upload');
    $ownCustomIni="upload_max_filesize=96M\npost_max_size=128M\nmax_file_uploads=30\n";file_put_contents($target.'/web/.user.ini',$ownCustomIni);try{\Kulturhus\Native\Installer\Deployment::write($target,\PackageStore::json($target.'/private/environment.json'));installerCheck(file_get_contents($target.'/web/.user.ini')===$ownCustomIni,'repeat actual deployment preserves an explicit own PHP upload configuration');}finally{file_put_contents($target.'/web/.user.ini',\Kulturhus\Native\Installer\Deployment::UPLOAD_INI);}
    $videoUpgradeTables=['native_video_recoveries','native_video_removal_history','native_video_removal_states','native_video_removals','native_video_retention_history','native_video_retention','native_video_archive_history','native_video_archives','native_video_quota_history','native_video_quota','native_video_metadata_revisions','native_video_metadata','native_video_capabilities','native_social_videos','native_social_video_sources'];
    foreach($videoUpgradeTables as$table)installerCheck((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'fresh communication 12 has no imported video: '.$table);
    $videoUpgradeOld=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);$videoUpgradeOld=array_values(array_diff($videoUpgradeOld,[...$videoUpgradeTables,'native_schema_versions']));$videoUpgradeHash=ownOriginalDatabaseHash($db,$videoUpgradeOld);$videoOtherVersions=$db->query("SELECT * FROM native_schema_versions WHERE module<>'communication' ORDER BY module")->fetchAll();
    foreach($videoUpgradeTables as$table)$db->exec('DROP TABLE '.$table);$db->exec("UPDATE native_schema_versions SET version=4 WHERE module='communication'");
    installerCheck(!(new \Kulturhus\Native\Communication\Videos($db,$bridge->access(),$target.'/private/media'))->installed(),'actual older communication 4 capability read adds no video schema');
    \Kulturhus\Native\Installer\SchemaRegistry::install($db);
    installerCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='communication'")->fetchColumn()===\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['communication'],'explicit communication 4 upgrade registers current immutable video storage');
    installerCheck(ownOriginalDatabaseHash($db,$videoUpgradeOld)===$videoUpgradeHash&&$db->query("SELECT * FROM native_schema_versions WHERE module<>'communication' ORDER BY module")->fetchAll()===$videoOtherVersions,'video upgrade retains every complete earlier data row and all other module versions');
    foreach($videoUpgradeTables as$table)installerCheck((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'communication 4 upgrade adds only an empty own table: '.$table);
    $installedVideoWork=new \Kulturhus\Native\Communication\Workspace($db,$bridge->access(),'social',$target.'/private/media');$installedVideoItem=$installedVideoWork->save(['title'=>'Own installed metadata upgrade source','kind'=>'reel','body'=>'Original human installed caption']);$installedVideoStore=new \Kulturhus\Native\Communication\Videos($db,$bridge->access(),$target.'/private/media');$installedVideoId=$installedVideoStore->save($installedVideoItem['id'],1,\Kulturhus\Native\Shared\Identity::forClub($db,$bridge->access()->club())['version'],__DIR__.'/fixtures/reel-profile/portrait-avc-aac.mp4','Own installed original AAC.mp4');$installedVideoFile=$installedVideoStore->file($installedVideoId);
    $metadataUpgradeTables=['native_video_metadata_revisions','native_video_metadata'];$metadataUpgradeOld=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);$metadataUpgradeOld=array_values(array_diff($metadataUpgradeOld,[...$metadataUpgradeTables,'native_schema_versions']));$metadataUpgradeHash=ownOriginalDatabaseHash($db,$metadataUpgradeOld);
    foreach($metadataUpgradeTables as$table){installerCheck((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'filled old source has no invented source metadata before upgrade');$db->exec('DROP TABLE '.$table);}$db->exec("UPDATE native_schema_versions SET version=6 WHERE module='communication'");\Kulturhus\Native\Installer\SchemaRegistry::install($db);installerCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='communication'")->fetchColumn()===\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['communication']&&ownOriginalDatabaseHash($db,$metadataUpgradeOld)===$metadataUpgradeHash,'actual communication 6 registry upgrade preserves every filled original video, private proof and earlier row');foreach($metadataUpgradeTables as$table)installerCheck((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'actual registry upgrade creates only an empty own metadata table');installerCheck($installedVideoStore->file($installedVideoId)===$installedVideoFile&&hash_file('sha256',$installedVideoFile['path'])===hash_file('sha256',__DIR__.'/fixtures/reel-profile/portrait-avc-aac.mp4'),'actual registry upgrade retains exact earlier private AVC/AAC bytes and descriptor');
    $sourceQuotaUpgradeTables=['native_video_quota_history','native_video_quota'];$sourceQuotaUpgradeOld=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);$sourceQuotaUpgradeOld=array_values(array_diff($sourceQuotaUpgradeOld,[...$sourceQuotaUpgradeTables,'native_schema_versions']));$sourceQuotaUpgradeHash=ownOriginalDatabaseHash($db,$sourceQuotaUpgradeOld);
    foreach($sourceQuotaUpgradeTables as$table){installerCheck((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'filled earlier sources start without invented private video quota policy');$db->exec('DROP TABLE '.$table);}$db->exec("UPDATE native_schema_versions SET version=7 WHERE module='communication'");\Kulturhus\Native\Installer\SchemaRegistry::install($db);installerCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='communication'")->fetchColumn()===\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['communication']&&ownOriginalDatabaseHash($db,$sourceQuotaUpgradeOld)===$sourceQuotaUpgradeHash,'actual communication 7 registry upgrade keeps every filled original source, metadata, proof and previous row');foreach($sourceQuotaUpgradeTables as$table)installerCheck((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'actual registry quota upgrade adds only an empty own table');installerCheck($installedVideoStore->file($installedVideoId)===$installedVideoFile,'actual original source quota upgrade preserves exact earlier bytes and descriptor');
    $videoArchiveUpgradeTables=['native_video_archive_history','native_video_archives'];
    $videoArchiveUpgradeOld=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);$videoArchiveUpgradeOld=array_values(array_diff($videoArchiveUpgradeOld,[...$videoArchiveUpgradeTables,'native_schema_versions']));$videoArchiveUpgradeHash=ownOriginalDatabaseHash($db,$videoArchiveUpgradeOld);
    foreach($videoArchiveUpgradeTables as$table){installerCheck((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'archive upgrade starts with no invented history');$db->exec('DROP TABLE '.$table);}
    $db->exec("UPDATE native_schema_versions SET version=8 WHERE module='communication'");

    $archiveSource=\Kulturhus\Native\Shared\Db::run($db,'SELECT source_id FROM native_social_videos WHERE id=?',[$installedVideoId])->fetchColumn();
    installerCheck((new \Kulturhus\Native\Communication\VideoArchive($db,$bridge->access()))->get($archiveSource)['version']===0&&$installedVideoStore->page($installedVideoItem['id'])['total']===1,'old communication 8 catalogue remains readable without archive DDL');
    \Kulturhus\Native\Installer\SchemaRegistry::install($db);
    installerCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='communication'")->fetchColumn()===\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['communication']&&ownOriginalDatabaseHash($db,$videoArchiveUpgradeOld)===$videoArchiveUpgradeHash,'actual communication 8 upgrade preserves every filled source, approval, proof and earlier row');
    foreach($videoArchiveUpgradeTables as$table)installerCheck((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'communication 9 adds only an empty own archive table');
    installerCheck($installedVideoStore->file($installedVideoId)===$installedVideoFile,'archive upgrade preserves original private AVC/AAC bytes and descriptor');
    $videoLifecycleUpgradeTables=['native_video_removal_history','native_video_removal_states','native_video_removals','native_video_retention_history','native_video_retention'];
    $videoLifecycleUpgradeOld=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);$videoLifecycleUpgradeOld=array_values(array_diff($videoLifecycleUpgradeOld,[...$videoLifecycleUpgradeTables,'native_schema_versions']));$videoLifecycleUpgradeHash=ownOriginalDatabaseHash($db,$videoLifecycleUpgradeOld);
    foreach($videoLifecycleUpgradeTables as$table){installerCheck((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'lifecycle upgrade begins without invented original state');$db->exec('DROP TABLE '.$table);}
    $db->exec("UPDATE native_schema_versions SET version=9 WHERE module='communication'");

    installerCheck((new \Kulturhus\Native\Communication\VideoRemoval($db,$bridge->access(),$target.'/private/media'))->get($archiveSource)['state']==='active'&&$installedVideoStore->file($installedVideoId)===$installedVideoFile,'old communication 9 preserves active original file without lifecycle web DDL');
    \Kulturhus\Native\Installer\SchemaRegistry::install($db);
    installerCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='communication'")->fetchColumn()===\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['communication']&&ownOriginalDatabaseHash($db,$videoLifecycleUpgradeOld)===$videoLifecycleUpgradeHash,'actual communication 9 upgrade preserves every filled earlier source, approval and original row');
    foreach($videoLifecycleUpgradeTables as$table)installerCheck((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'communication 10 adds only an empty own lifecycle table');
    installerCheck($installedVideoStore->file($installedVideoId)===$installedVideoFile,'lifecycle registry upgrade retains exact earlier AVC/AAC bytes');

    $videoRecoveryUpgradeOld=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);$videoRecoveryUpgradeOld=array_values(array_diff($videoRecoveryUpgradeOld,['native_schema_versions','native_video_recoveries']));$videoRecoveryUpgradeHash=ownOriginalDatabaseHash($db,$videoRecoveryUpgradeOld);
    $db->exec('DROP TABLE native_video_recoveries');$db->exec("UPDATE native_schema_versions SET version=10 WHERE module='communication'");

    installerCheck(!\Kulturhus\Native\Communication\VideoRecovery::installed($db),'filled communication 10 has no recovery capability or implicit web DDL');
    \Kulturhus\Native\Installer\SchemaRegistry::install($db);
    installerCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='communication'")->fetchColumn()===\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['communication']&&ownOriginalDatabaseHash($db,$videoRecoveryUpgradeOld)===$videoRecoveryUpgradeHash,'explicit filled communication 10 upgrade preserves every earlier original and adds recovery and carousel 12');
    installerCheck((int)$db->query('SELECT COUNT(*) FROM native_video_recoveries')->fetchColumn()===0,'recovery upgrade creates an empty own intention table without inventing recovered videos');
    installerCheck($installedVideoStore->file($installedVideoId)===$installedVideoFile,'recovery registry upgrade keeps the exact earlier private sound descriptor');
    $carouselOldTables=array_values(array_diff($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),['native_schema_versions','native_carousel_capabilities','native_carousel_progress']));$carouselOldHash=ownOriginalDatabaseHash($db,$carouselOldTables);
    foreach(['native_carousel_capabilities','native_carousel_progress']as$table){installerCheck((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'fresh carousel upgrade invents no publication or provider access');$db->exec('DROP TABLE '.$table);}
    $db->exec("UPDATE native_schema_versions SET version=11 WHERE module='communication'");
    installerCheck(!\Kulturhus\Native\Communication\CarouselProgress::installed($db),'filled communication 11 remains readable without implicit carousel DDL');\Kulturhus\Native\Installer\SchemaRegistry::install($db);
    installerCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='communication'")->fetchColumn()===\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['communication']&&ownOriginalDatabaseHash($db,$carouselOldTables)===$carouselOldHash,'explicit filled communication 11 upgrade retains every earlier whole original row');
    foreach(['native_carousel_capabilities','native_carousel_progress']as$table)installerCheck((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'explicit carousel upgrade creates only its empty own table');
    installerCheck((int)$db->query('SELECT COUNT(*) FROM native_press_messages')->fetchColumn()===0,'fresh communication 13 contains no invented or imported press message');
    $pressOldTables=array_values(array_diff($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),['native_schema_versions','native_press_messages']));$pressOldHash=ownOriginalDatabaseHash($db,$pressOldTables);
    $db->exec('DROP TABLE native_press_messages');$db->exec("UPDATE native_schema_versions SET version=12 WHERE module='communication'");

    installerCheck(!\Kulturhus\Native\Communication\PressSchema::installed($db),'filled communication 12 is detected without implicit press web DDL');
    \Kulturhus\Native\Installer\SchemaRegistry::install($db);
    installerCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='communication'")->fetchColumn()===\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['communication']&&(int)$db->query('SELECT COUNT(*) FROM native_press_messages')->fetchColumn()===0,'explicit communication 12 upgrade registers one empty original press-message table');
    installerCheck(ownOriginalDatabaseHash($db,$pressOldTables)===$pressOldHash,'actual press upgrade preserves every previous filled original, approval, key binding and role');
    $pressNewTables=array_values(array_diff($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),['native_schema_versions']));sort($pressOldTables,SORT_STRING);$expectedPressTables=[...$pressOldTables,'native_press_messages'];sort($expectedPressTables,SORT_STRING);sort($pressNewTables,SORT_STRING);installerCheck($pressNewTables===$expectedPressTables,'actual press upgrade adds exactly its one table and no retroactive delivery');
    $pressEventOldTables=array_values(array_diff($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),['native_schema_versions','native_press_event_origins']));$pressEventOldHash=ownOriginalDatabaseHash($db,$pressEventOldTables);
    $db->exec('DROP TABLE native_press_event_origins');$db->exec("UPDATE native_schema_versions SET version=13 WHERE module='communication'");$pressEventInstaller=new \Kulturhus\Native\Communication\EventDrafts($db,$bridge->access(),'press');
    installerCheck(!$pressEventInstaller->installed()&&$pressEventInstaller->sources()===[],'filled communication 13 hides press event selection without implicit schema changes');\Kulturhus\Native\Installer\SchemaRegistry::install($db);
    installerCheck($pressEventInstaller->installed()&&(int)$db->query('SELECT COUNT(*) FROM native_press_event_origins')->fetchColumn()===0&&ownOriginalDatabaseHash($db,$pressEventOldTables)===$pressEventOldHash,'actual communication 14 update retains every prior original and adds only empty press event provenance');
    $pressEventNewTables=array_values(array_diff($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),['native_schema_versions']));sort($pressEventNewTables,SORT_STRING);$pressEventExpected=[...$pressEventOldTables,'native_press_event_origins'];sort($pressEventExpected,SORT_STRING);installerCheck($pressEventNewTables===$pressEventExpected,'actual press event package update creates exactly one additional table');
    $installedOrphan=bin2hex(random_bytes(16));$installedOrphanPath=dirname($installedVideoFile['path']).'/'.$installedOrphan.'.mp4';copy(__DIR__.'/fixtures/reel-profile/portrait-avc-aac.mp4',$installedOrphanPath);chmod($installedOrphanPath,0600);touch($installedOrphanPath,time()-7200);
    $installedVideoRecovery=new \Kulturhus\Native\Communication\VideoRecovery($db,$bridge->access(),$target.'/private/media');$installedOrphanProof=$installedVideoRecovery->prepare($installedOrphan);$installedRecovered=$installedVideoRecovery->recover($installedOrphan,$installedOrphanProof['fingerprint'],$installedVideoItem['id'],1,\Kulturhus\Native\Shared\Identity::forClub($db,$bridge->access()->club())['version'],'Own installed recovered original.mp4');
    installerCheck($installedRecovered['target_id']!==$installedOrphan&&$installedVideoStore->file($installedRecovered['video_id'])['sha256']===hash_file('sha256',$installedOrphanPath),'actual installed recovery creates a distinct exact private version and preserves old source bytes');
    $installedRecoveryRows=$db->query('SELECT * FROM native_video_recoveries ORDER BY original_id')->fetchAll();\Kulturhus\Native\Installer\SchemaRegistry::install($db);
    installerCheck($db->query('SELECT * FROM native_video_recoveries ORDER BY original_id')->fetchAll()===$installedRecoveryRows&&$installedVideoRecovery->prepare($installedOrphan)['state']==='complete','repeat actual schema installation preserves original recovery decision and accepted result');



    $eventPreparationTables=['native_social_event_preparation_settings','native_social_event_preparation_history','native_social_event_preparations'];
    $eventPreparationOld=array_values(array_diff($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),['native_schema_versions',...$eventPreparationTables]));
    $eventPreparationHash=ownOriginalDatabaseHash($db,$eventPreparationOld);
    foreach($eventPreparationTables as$table)$db->exec('DROP TABLE '.$table);
    $db->exec("UPDATE native_schema_versions SET version=18 WHERE module='communication'");
    \Kulturhus\Native\Installer\SchemaRegistry::install($db);
    installerCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='communication'")->fetchColumn()===19&&ownOriginalDatabaseHash($db,$eventPreparationOld)===$eventPreparationHash,'actual communication 18 update preserves every earlier complete original row');
    foreach($eventPreparationTables as$table)installerCheck((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'event preparation update adds only an empty own table '.$table);

    foreach ([
        ['/login',[],'Dein Konto'],['/vereinsverwaltung/?view=dashboard',['view'=>'dashboard'],'Dashboard'],
        ['/search?q=Kein%20Suchtreffer&seite=999999',['q'=>'Kein Suchtreffer','seite'=>'999999'],'Keine veröffentlichten Inhalte gefunden.'],
        ['/suche/',['q'=>''],'Website durchsuchen'],
        ['/vereinsverwaltung/?view=website&tab=integrations',['view'=>'website','tab'=>'integrations'],'Freigabe für Beiträge'],
        ['/vereinsverwaltung/?view=website',['view'=>'website'],'Website'],['/vereinsverwaltung/?view=documents',['view'=>'documents'],'Dokumente']
    ] as [$path,$query,$title]) {
        // Run the actual installed front controller in a fresh process with synthetic HTTPS metadata.
        $process=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),__FILE__,'--render'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fwrite($pipes[0],json_encode(['target'=>$target,'token'=>$login['session_token'],'path'=>$path,'query'=>$query],JSON_THROW_ON_ERROR));fclose($pipes[0]);
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);$render=json_decode($out,true);
        installerCheck($exit===0&&$err===''&&($render['status']??0)===200&&($render['legacy']??-1)===0&&empty($render['fatal'])&&str_contains($render['text']??'',$title),'installed native front controller renders '.$title.' without ProcessWire');
    }
    $keyHash=hash_file('sha256',$target.'/private/auth.key');
    foreach([
        ['path'=>'/feeds/events.json','method'=>'OPTIONS','origin'=>'https://other.example.invalid','expected'=>204],
        ['path'=>'/feeds/veranstaltungen/','method'=>'OPTIONS','origin'=>'https://other.example.invalid','expected'=>204],
        ['path'=>'/vereinsverwaltung/','method'=>'OPTIONS','origin'=>'https://other.example.invalid','expected'=>405],
        ['path'=>'/feeds/news/','method'=>'OPTIONS','origin'=>'https://other.example.invalid','expected'=>405],
        ['path'=>'/feeds/events.json','method'=>'OPTIONS','host'=>'foreign.example.invalid','expected'=>403],
        ['path'=>'/feeds/events.json','method'=>'OPTIONS','https'=>'off','expected'=>403],
        ['path'=>'/feeds/events.json','method'=>'POST','origin'=>'https://other.example.invalid','expected'=>403],
        ['path'=>'/feeds/events.json','method'=>'POST','origin'=>'https://musterverein.example','expected'=>405],
    ]as$case){
        $process=proc_open([...TestSupport::phpCommand(),__FILE__,'--render'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fwrite($pipes[0],json_encode(['target'=>$target,'token'=>$login['session_token'],'native_only'=>true]+$case,JSON_THROW_ON_ERROR));fclose($pipes[0]);
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);$render=json_decode($out,true);
        installerCheck($exit===0&&$err===''&&($render['status']??0)===$case['expected']&&empty($render['fatal'])&&($render['legacy']??-1)===0,'actual installed controller restricts public feed method/origin/HTTPS '.$case['method'].' '.$case['path'].' '.$case['expected']);
    }
    $bankKey=$target.'/private/member-bank-keys/v1.key';
    installerCheck(is_file($bankKey)&&strlen(file_get_contents($bankKey))===32&&(fileperms($bankKey)&0077)===0&&(fileperms(dirname($bankKey))&0077)===0,'fresh native bank vault has its own private persistent key');
    $bankKeyHash=hash_file('sha256',$bankKey);
    foreach (['/assets/kulturhus-site.css','/assets/saxophone.webp','/site/assets/kulturhus-public/apple-touch-icon.png','/vereinsverwaltung/photo-copy.js','/vereinsverwaltung/photo-library.js','/vereinsverwaltung/native-page-media-picker.js','/vereinsverwaltung/native-page-media-picker.css','/vereinsverwaltung/native-mediabunny.mjs','/vereinsverwaltung/native-aac-encoder.mjs','/vereinsverwaltung/native-aac-worker.js','/vereinsverwaltung/native-aac-libav.mjs'] as $path) {
        $process=proc_open([...TestSupport::phpCommand(),__FILE__,'--render'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fwrite($pipes[0],json_encode(['target'=>$target,'token'=>$login['session_token'],'path'=>$path,'native_only'=>true],JSON_THROW_ON_ERROR));fclose($pipes[0]);
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);$render=json_decode($out,true);
        $asset=\Kulturhus\Native\Web\PublicAssets::sources()[$path]??\Kulturhus\Native\Web\PhotoImportAssets::scripts()[basename($path)]??\Kulturhus\Native\Integration\Assets::sources()[basename($path)]??\Kulturhus\Native\Web\ReelStudioAssets::sources()[basename($path)];
        $ok=$exit===0&&$err===''&&($render['status']??0)===200&&($render['legacy']??-1)===0&&empty($render['fatal'])&&($render['sha256']??'')===hash_file('sha256',$asset);
        if (!$ok) fwrite(STDERR,json_encode(['asset'=>$path,'exit'=>$exit,'status'=>$render['status']??null,'legacy'=>$render['legacy']??null,'fatal'=>$render['fatal']??null,'stderr'=>$err,'actual_hash'=>$render['sha256']??null,'expected_hash'=>hash_file('sha256',$asset)],JSON_THROW_ON_ERROR)."\n");
        installerCheck($ok,'installed website serves exact own asset with ProcessWire filesystem blocked: '.$path);
    }
    installerReject(fn()=>$installer->run($config,$db),'completed target requires explicit resume');
    installerCheck($installer->run($config,$db,true)===$result && hash_file('sha256',$target.'/private/auth.key')===$keyHash&&hash_file('sha256',$bankKey)===$bankKeyHash,'resume is idempotent and preserves account and both auth/bank keys');
    $process=proc_open([...TestSupport::phpCommand(),dirname(__DIR__,2).'/distribution/install.php','--config-stdin','--resume'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('Native distribution CLI unavailable.');
    fwrite($pipes[0],json_encode($config,JSON_THROW_ON_ERROR));fclose($pipes[0]);$cliOutput=stream_get_contents($pipes[1]);$cliError=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$cliStatus=proc_close($process);
    installerCheck($cliStatus===0&&$cliError===''&&json_decode($cliOutput,true,16,JSON_THROW_ON_ERROR)===$result,'common distribution CLI uses the native installer and preserves completed installation');
    $altered=$config;$altered['hosts']='another.example';
    installerReject(fn()=>$installer->run($altered,$db,true),'different configuration cannot claim a partial install');
    $other=$config;$other['target']=$target.'-other';
    installerReject(fn()=>$installer->run($other,$db),'nonempty database cannot become a fresh installation');
    installerCheck(!file_exists($other['target']),'nonempty database rejection leaves no new target');
    $state=file_get_contents($target.'/installation.json');unlink($target.'/installation.json');
    $chosen=$design->useTemplate('natur',$design->get()['version']);
    installerCheck($installer->run($config,$db,true)===$result,'resume completes after interrupted final receipt persistence');
    installerCheck($design->get()===$chosen,'resumed initialization preserves the chosen website design and its original version');
    installerCheck(file_get_contents($target.'/installation.json')===$state,'completion receipt reproduces identical installation identity');
    $actorsBefore=$db->query('SELECT * FROM actors ORDER BY id')->fetchAll();
    $db->exec('DROP TABLE native_social_human_sends');$db->exec('DROP TABLE native_social_replies');$db->exec("UPDATE native_schema_versions SET version=2 WHERE module='delivery'");
    \Kulturhus\Native\Installer\SchemaRegistry::install($db);
    installerCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='delivery'")->fetchColumn()===\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['delivery']&&(int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='native_social_replies'")->fetchColumn()===1,'explicit delivery schema maintenance adds encrypted reply history to existing version two');
    installerCheck($db->query('SELECT * FROM actors ORDER BY id')->fetchAll()===$actorsBefore&&hash_file('sha256',$target.'/private/auth.key')===$keyHash&&hash_file('sha256',$bankKey)===$bankKeyHash,'reply schema maintenance preserves original accounts and private keys');
    \Kulturhus\Native\Installer\SchemaRegistry::install($db);
    installerCheck($db->query('SELECT COUNT(*) FROM native_social_replies')->fetchColumn()===0,'repeat schema maintenance never prepares or sends a reply');
    $db->exec('DROP TABLE native_social_mail_dispatch');$db->exec('DROP TABLE native_social_mail_replies');$db->exec('DROP TABLE native_social_mail_threads');$db->exec('DROP TABLE native_social_mail_cursors');$db->exec("UPDATE native_schema_versions SET version=3 WHERE module='delivery'");
    \Kulturhus\Native\Installer\SchemaRegistry::install($db);
    installerCheck((int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('native_social_mail_threads','native_social_mail_replies','native_social_mail_cursors')")->fetchColumn()===3,'explicit version-three upgrade creates optional private conversation mail storage');
    installerCheck($db->query('SELECT * FROM actors ORDER BY id')->fetchAll()===$actorsBefore&&hash_file('sha256',$target.'/private/auth.key')===$keyHash&&hash_file('sha256',$bankKey)===$bankKeyHash,'mail storage upgrade preserves accounts and original private keys');
    $db->exec("INSERT INTO native_social_replies(club_id,id,attention_id,channel,parent_id,connection_id,source_fingerprint,status,version,ciphertext,created_by,updated_by,created_at,updated_at) VALUES('own-upgrade','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','facebook','own-original','own-connection',REPEAT('c',64),'sent',3,'exact retained ciphertext','synthetic','synthetic',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $db->exec('ALTER TABLE native_social_replies ADD UNIQUE KEY old_original_reply(club_id,attention_id,source_fingerprint,connection_id)');$db->exec('ALTER TABLE native_social_replies DROP INDEX original_reply_slot');$db->exec('ALTER TABLE native_social_replies DROP COLUMN reply_slot');$db->exec('DROP TABLE native_social_mail_dispatch');$db->exec("UPDATE native_schema_versions SET version=4 WHERE module='delivery'");
    $replyBefore=$db->query('SELECT * FROM native_social_replies')->fetch();
    \Kulturhus\Native\Installer\SchemaRegistry::install($db);
    $replyAfter=$db->query('SELECT * FROM native_social_replies')->fetch();unset($replyAfter['reply_slot']);
    installerCheck($replyBefore===$replyAfter,'version-four upgrade preserves exact original reply bytes, version and sent state');
    installerCheck($db->query('SELECT reply_slot FROM native_social_replies')->fetchColumn()===''&&(int)$db->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='native_social_replies' AND index_name='original_reply_slot'")->fetchColumn()===5,'version-five adds separate mail slots with original duplicate protection');
    installerCheck((int)$db->query('SELECT COUNT(*) FROM native_social_mail_dispatch')->fetchColumn()===0,'upgrade never queues historical email replies');
    \Kulturhus\Native\Installer\SchemaRegistry::install($db);installerCheck($db->query('SELECT * FROM native_social_replies')->fetch()['ciphertext']==='exact retained ciphertext','repeat maintenance retains original social reply bytes');
    $db->exec('DROP TABLE native_social_originals');$db->exec("UPDATE native_schema_versions SET version=5 WHERE module='delivery'");
    $beforeUpgrade=$db->query('SELECT * FROM native_social_replies')->fetchAll();
    \Kulturhus\Native\Installer\SchemaRegistry::install($db);
    installerCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='delivery'")->fetchColumn()===\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['delivery']&&$db->query('SELECT COUNT(*) FROM native_social_originals')->fetchColumn()===0,'explicit version-five upgrade adds an empty private original archive');
    installerCheck($beforeUpgrade===$db->query('SELECT * FROM native_social_replies')->fetchAll(),'original archive upgrade preserves exact sent reply bytes and duplicate slots');
    $eventOriginals=$db->query('SELECT * FROM native_event_sources ORDER BY club_id,source,external_id')->fetchAll();
    $db->exec('DROP TABLE native_event_workflow_history');$db->exec('DROP TABLE native_event_workflows');$db->exec("UPDATE native_schema_versions SET version=6 WHERE module='delivery'");
    \Kulturhus\Native\Installer\SchemaRegistry::install($db);
    installerCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='delivery'")->fetchColumn()===\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['delivery']&&(int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('native_event_workflows','native_event_workflow_history')")->fetchColumn()===2,'explicit version-six upgrade creates isolated event provider choices and history');
    installerCheck($eventOriginals===$db->query('SELECT * FROM native_event_sources ORDER BY club_id,source,external_id')->fetchAll()&&$beforeUpgrade===$db->query('SELECT * FROM native_social_replies')->fetchAll(),'event workflow upgrade preserves original provider identities and exact sent reply bytes');
    \Kulturhus\Native\Installer\SchemaRegistry::install($db);
    installerCheck((int)$db->query('SELECT COUNT(*) FROM native_event_workflows')->fetchColumn()===0&&(int)$db->query('SELECT COUNT(*) FROM native_event_workflow_history')->fetchColumn()===0,'repeat workflow upgrade never activates a provider, payment or automatic publication');
    $db->exec('DROP TABLE native_event_source_aliases');$db->exec("UPDATE native_schema_versions SET version=7 WHERE module='delivery'");
    \Kulturhus\Native\Installer\SchemaRegistry::install($db);
    installerCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='delivery'")->fetchColumn()===\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['delivery']&&(int)$db->query('SELECT COUNT(*) FROM native_event_source_aliases')->fetchColumn()===0,'explicit version-seven upgrade adds empty event identity associations without provider activity');
    installerCheck($eventOriginals===$db->query('SELECT * FROM native_event_sources ORDER BY club_id,source,external_id')->fetchAll()&&$beforeUpgrade===$db->query('SELECT * FROM native_social_replies')->fetchAll(),'source association upgrade preserves original imported events and sent reply bytes');
    $ticketTables=['native_ticket_offers','native_ticket_orders','native_ticket_passes','native_ticket_history','native_ticket_webhook_events'];
    foreach($ticketTables as$table){installerCheck((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'new ticket component starts empty: '.$table);$db->exec('DROP TABLE '.$table);}
    $db->exec("UPDATE native_schema_versions SET version=8 WHERE module='delivery'");\Kulturhus\Native\Installer\SchemaRegistry::install($db);
    installerCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='delivery'")->fetchColumn()===\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['delivery'],'explicit version-eight upgrade installs shared ticket and voucher sales');
    foreach($ticketTables as$table)installerCheck((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'upgrade does not create orders, codes or provider payments: '.$table);
    installerCheck($eventOriginals===$db->query('SELECT * FROM native_event_sources ORDER BY club_id,source,external_id')->fetchAll()&&$beforeUpgrade===$db->query('SELECT * FROM native_social_replies')->fetchAll(),'ticket upgrade preserves exact event source identities and sent social originals');
    $db->exec('DROP TABLE native_ticket_mail');$db->exec("UPDATE native_schema_versions SET version=9 WHERE module='delivery'");
    \Kulturhus\Native\Installer\SchemaRegistry::install($db);
    installerCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='delivery'")->fetchColumn()===\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['delivery']&&(int)$db->query('SELECT COUNT(*) FROM native_ticket_mail')->fetchColumn()===0,'version-nine upgrade adds empty ticket outbox without retroactive sends');
    installerCheck($eventOriginals===$db->query('SELECT * FROM native_event_sources ORDER BY club_id,source,external_id')->fetchAll(),'ticket mail upgrade preserves original events');
    $db->exec('DROP TABLE native_ticket_refunds');$db->exec("UPDATE native_schema_versions SET version=10 WHERE module='delivery'");
    \Kulturhus\Native\Installer\SchemaRegistry::install($db);
    installerCheck((int)$db->query("SELECT version FROM native_schema_versions WHERE module='delivery'")->fetchColumn()===\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['delivery']&&(int)$db->query('SELECT COUNT(*) FROM native_ticket_refunds')->fetchColumn()===0,'version-ten upgrade adds empty original refund and human intention ledgers without provider activity');
    file_put_contents($target.'/app/banking/vendor/autoload.php','changed');unlink($target.'/installation.json');
    try{$installer->run($config,$db,true);throw new LogicException('modified dependency accepted');}
    catch(RuntimeException $e){installerCheck(!$e instanceof LogicException,'modified deployed dependency stops resume');}
    echo "$n native installer checks passed; synthetic MySQL, no external requests.\n";
} finally {
    if(is_dir($target)) {
        $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file){if($file->isDir()&&!$file->isLink())rmdir($file->getPathname());else unlink($file->getPathname());}
        rmdir($target);
    }
}
