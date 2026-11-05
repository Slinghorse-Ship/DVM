<?php declare(strict_types=1);
require_once __DIR__.'/original-database-hash.php';
function ownMemberProfileOriginalDb():PDO
{
 $cfg=\Kulturhus\Native\TestSupport::config();$db=\Kulturhus\Native\TestSupport::db();if(($cfg['owner']??'')!=='isolated-native-ci'||!in_array($cfg['label']??'',['native-member-profile-original','native-profile-create-replay'],true)||!preg_match('/\Akh_native_[a-f0-9]{24}\z/D',$cfg['database']??'')||$db->query('SELECT DATABASE()')->fetchColumn()!==$cfg['database'])throw new RuntimeException('Owned profile original proof required.');return $db;
}
function ownMemberProfileOriginalProof(array $fixture):void
{
 $db=ownMemberProfileOriginalDb();
 $tables=array_values(array_diff($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),['native_auth_sessions','native_auth_limits','native_language_preferences']));$whole=ownOriginalDatabaseHash($db,$tables);$contacts=$db->query("SELECT id,name,phone,notes,honorary,version FROM contacts WHERE club_id='own-list-club' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);$audit=$db->query("SELECT action,COUNT(*) count FROM audit_events WHERE club_id='own-list-club' GROUP BY action ORDER BY action")->fetchAll(PDO::FETCH_KEY_PAIR);$counts=[];foreach($tables as$table)$counts[$table]=(int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
 header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');echo json_encode(compact('whole','contacts','audit','counts'),JSON_THROW_ON_ERROR);
}

function ownMemberProfileOriginalFault(array $fixture):void
{
 $db=ownMemberProfileOriginalDb();$token=$_SERVER['HTTP_X_OWNED_PROFILE_FAULT']??null;if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||!is_string($token)||!hash_equals($fixture['data']['fault_token'],$token)||!in_array($_POST['mode']??null,['on','off','result-on','result-off'],true)){http_response_code(403);exit;}
 if(str_starts_with($_POST['mode'],'result-')){if((\Kulturhus\Native\TestSupport::config()['label']??'')!=='native-profile-create-replay'){http_response_code(403);exit;}if($_POST['mode']==='result-on')$db->exec("CREATE TRIGGER own_profile_creation_result_fault BEFORE UPDATE ON native_api_operations FOR EACH ROW BEGIN IF NEW.result_json IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Owned disposable profile result write failure'; END IF; END");else $db->exec('DROP TRIGGER own_profile_creation_result_fault');}
 elseif($_POST['mode']==='on')$db->exec("CREATE TRIGGER own_member_profile_audit_fault BEFORE INSERT ON audit_events FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Owned disposable profile audit fault'; END");
 else $db->exec('DROP TRIGGER own_member_profile_audit_fault');
 header('Content-Type: application/json');echo '{"owned_fault":true}';
}
