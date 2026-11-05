<?php declare(strict_types=1);
require_once __DIR__.'/original-database-hash.php';
function ownLocationProofDb():PDO
{
 $cfg=\Kulturhus\Native\TestSupport::config();$db=\Kulturhus\Native\TestSupport::db();if(($cfg['owner']??'')!=='isolated-native-ci'||($cfg['label']??'')!=='native-inventory-location-interface'||!preg_match('/\Akh_native_[a-f0-9]{24}\z/D',$cfg['database']??'')||$db->query('SELECT DATABASE()')->fetchColumn()!==$cfg['database'])throw new RuntimeException('Owned disposable location proof required.');return $db;
}
function ownLocationProof():void
{
 $db=ownLocationProofDb();$tables=array_values(array_diff($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),['native_auth_sessions','native_auth_limits','native_language_preferences']));$whole=ownOriginalDatabaseHash($db,$tables);$locations=$db->query("SELECT * FROM inventory_locations WHERE club_id='own-list-club' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);$counts=[];foreach(['inventory_locations','inventory_events','audit_events']as$table)$counts[$table]=(int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();header('Content-Type: application/json');header('Cache-Control: private, no-store');echo json_encode(compact('whole','locations','counts'),JSON_THROW_ON_ERROR);
}
function ownLocationFault(array $fixture):void
{
 $db=ownLocationProofDb();$token=$_SERVER['HTTP_X_OWNED_LOCATION_FAULT']??null;if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||!is_string($token)||!hash_equals($fixture['data']['fault_token'],$token)||!in_array($_POST['mode']??null,['on','off'],true)){http_response_code(403);exit;}
 if($_POST['mode']==='on')$db->exec("CREATE TRIGGER own_location_ui_audit_failure BEFORE INSERT ON audit_events FOR EACH ROW BEGIN IF NEW.action='inventory.location' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Owned disposable location audit failure'; END IF; END");else $db->exec('DROP TRIGGER own_location_ui_audit_failure');header('Content-Type: application/json');echo '{"owned_fault":true}';
}
