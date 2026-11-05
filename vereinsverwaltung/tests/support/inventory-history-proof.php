<?php declare(strict_types=1);
require_once __DIR__.'/original-database-hash.php';
function ownInventoryHistoryProof():void
{
 $cfg=\Kulturhus\Native\TestSupport::config();$db=\Kulturhus\Native\TestSupport::db();if(($cfg['owner']??'')!=='isolated-native-ci'||($cfg['label']??'')!=='native-inventory-history-interface'||!preg_match('/\Akh_native_[a-f0-9]{24}\z/D',$cfg['database']??'')||$db->query('SELECT DATABASE()')->fetchColumn()!==$cfg['database'])throw new RuntimeException('Owned read-only history proof required.');$tables=array_values(array_diff($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),['native_auth_sessions','native_auth_limits','native_language_preferences']));$whole=ownOriginalDatabaseHash($db,$tables);$balances=array_column((new \Kulturhus\Verein\Finance($db,'own-list-admin'))->accounts(),'balance_cents','id');header('Content-Type: application/json');header('Cache-Control: private, no-store');echo json_encode(compact('whole','balances'),JSON_THROW_ON_ERROR);
}
