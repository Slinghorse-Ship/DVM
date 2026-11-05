<?php declare(strict_types=1);
// Model the real filled predecessor: all identity-eight rows stay original, new ledger absent.
if((int)$db->query('SELECT COUNT(*) FROM native_role_requests')->fetchColumn()!==0)throw new RuntimeException('Only a new empty role ledger may be removed in this owned predecessor fixture.');
$db->exec('DROP TABLE native_role_requests');$db->exec("UPDATE native_schema_versions SET version=8 WHERE module='identity'");
$nrUpgradeTables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$nrUpgradeFilter=static fn(string $table):array=>$table==='native_schema_versions'?[" WHERE module<>'identity'",[]]:['',[]];$nrUpgradeBefore=ownOriginalDatabaseHash($db,$nrUpgradeTables,$nrUpgradeFilter,true);
\Kulturhus\Native\Installer\SchemaRegistry::install($db);
$nrActualTables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$nrExpectedTables=[...$nrUpgradeTables,'native_role_requests'];sort($nrExpectedTables);sort($nrActualTables);
$check($nrActualTables===$nrExpectedTables&&(int)$db->query("SELECT version FROM native_schema_versions WHERE module='identity'")->fetchColumn()===\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS['identity']&&(int)$db->query('SELECT COUNT(*) FROM native_role_requests')->fetchColumn()===0&&ownOriginalDatabaseHash($db,$nrUpgradeTables,$nrUpgradeFilter,true)===$nrUpgradeBefore,'actual filled identity-eight package step adds one empty role-original ledger and retains every complete previous business row and all other module versions');
$nrUpgradeAfter=ownOriginalDatabaseHash($db,$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));\Kulturhus\Native\Installer\SchemaRegistry::install($db);
$check(ownOriginalDatabaseHash($db,$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN))===$nrUpgradeAfter,'repeated explicit current identity installation preserves every complete original and invents no old role confirmations');
