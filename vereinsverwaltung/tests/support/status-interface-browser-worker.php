<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
$cfg=\Kulturhus\Native\TestSupport::config();if(PHP_SAPI!=='cli'||getenv('KH_NATIVE_CI')!=='1'||($cfg['owner']??'')!=='isolated-native-ci'||($cfg['label']??'')!=='native-service-status-interface')throw new RuntimeException('Exact owned status interface worker required.');
$db=\Kulturhus\Native\TestSupport::db();
switch($argv[1]??''){
 case'fail-on':$db->exec("CREATE TRIGGER own_status_ui_browser_failure BEFORE INSERT ON native_status_settings FOR EACH ROW BEGIN IF NEW.club_id='status-ui' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Owned status browser SQL failure'; END IF; END");break;
 case'fail-off':$db->exec('DROP TRIGGER own_status_ui_browser_failure');break;
 case'role-off':$db->exec("UPDATE settings_roles SET settings_access=0 WHERE club_id='status-ui' AND id='status-settings-role'");break;
 case'role-on':$db->exec("UPDATE settings_roles SET settings_access=1 WHERE club_id='status-ui' AND id='status-settings-role'");break;
 default:throw new RuntimeException('Unknown owned status interface action.');
}
