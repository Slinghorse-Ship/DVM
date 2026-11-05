<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/mysql-bootstrap.php';
use Kulturhus\Verein\{Database};
require_once __DIR__.'/support/mysql-schema-proof.php';
use Kulturhus\Verein\Tests\{MySqlFixture,MySqlSchemaProof};
$db=MySqlFixture::connect();Database::migrate($db);Database::migrate($db);
$before=[];foreach(['balance_sources','balance_source_history','asset_sale_links','asset_partial_reviews','balance_segment_reviews','balance_clearing_reviews','balance_tax_reconciliation_reviews','balance_period_asset_sales','balance_period_area_reviews','balance_period_tax_reviews'] as $table)$before[$table]=MySqlSchemaProof::table($db,$table);
$restored=MySqlFixture::restoreBackup($db);
foreach($before as $table=>$ddl){
 if($ddl!==MySqlSchemaProof::table($restored,$table))throw new RuntimeException('Schema restore differs: '.$table);
 echo 'PASS exact restored definition: '.$table."\n";
}
if((int)$restored->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn()!==Database::SCHEMA_VERSION||MySqlFixture::foreignKeyErrors($restored))throw new RuntimeException('Migration/restore integrity failed');
echo (count($before)+1)." balance schema restore checks passed; synthetic MySQL only\n";
