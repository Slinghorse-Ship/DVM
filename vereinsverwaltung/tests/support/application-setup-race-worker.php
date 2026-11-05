<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';
use Kulturhus\Native\TestSupport;
if(PHP_SAPI!=='cli'||getenv('KH_NATIVE_CI')!=='1'||(TestSupport::config()['label']??'')!=='native-application-setup-interface'||(TestSupport::config()['owner']??'')!=='isolated-native-ci')throw new RuntimeException('Owned application configuration fixture only');
$input=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);$db=TestSupport::db();echo json_encode(['connection_id'=>(int)$db->query('SELECT CONNECTION_ID()')->fetchColumn()],JSON_THROW_ON_ERROR).PHP_EOL;flush();
try{(new Kulturhus\Verein\MembershipApplications($db,'own-catalog-a','own-price-admin'))->saveSettings($input['configuration'],$input['version']);$result=['saved'=>true,'status'=>200];}catch(DomainException $failure){$result=['saved'=>false,'status'=>$failure->getCode(),'message'=>$failure->getMessage()];}
echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE).PHP_EOL;
