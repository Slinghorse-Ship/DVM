<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
$cfg=\Kulturhus\Native\TestSupport::config();
if(PHP_SAPI!=='cli'||getenv('KH_NATIVE_CI')!=='1'||($cfg['owner']??'')!=='isolated-native-ci'||($cfg['label']??'')!=='native-member-bank-interface')throw new RuntimeException('Owned isolated bank fixture required.');
$db=\Kulturhus\Native\TestSupport::db();
switch($argv[1]??''){
 case'fail-on':$db->exec("CREATE TRIGGER own_bank_ui_failure BEFORE INSERT ON member_bank_records FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Owned bank UI failure'; END");break;
 case'fail-off':$db->exec('DROP TRIGGER own_bank_ui_failure');break;
 default:throw new RuntimeException('Unknown owned bank action.');
}
