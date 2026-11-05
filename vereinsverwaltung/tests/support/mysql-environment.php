<?php declare(strict_types=1);
require_once __DIR__.'/../mysql-bootstrap.php';

// A parent MySqlFixture::connect() owns the database until all UI/readback children finish.
// Child processes must reuse its DSN; creating a database here would drop it on exit.
if(PHP_SAPI!=='cli'||getenv('VEREIN_MODE')!=='synthetic'||getenv('KULTURHUS_DISABLE_MAIL')!=='1')throw new RuntimeException('Synthetic CLI with disabled mail required.');
if(getenv('VEREIN_DATABASE')||getenv('VEREIN_AUTH')==='processwire')throw new RuntimeException('Production database/auth configuration is not accepted.');
$supportMysqlDsn=(string)getenv('KULTURHUS_DEMO_DATABASE');
if(getenv('VEREIN_DATABASE_USER')!=='root'||getenv('VEREIN_DATABASE_PASSWORD')!=='isolated-regression-only')throw new RuntimeException('Isolated MySQL test credentials required.');
\Kulturhus\Verein\Tests\MySqlFixture::existing($supportMysqlDsn);
require_once __DIR__.'/../../src/bootstrap.php';

/** Stable snapshots include composite keys and stay restricted to the synthetic tenant. */
function mysqlFixtureTenantRows(PDO $db,string $table,string $club):array
{
    if(!preg_match('/^[a-z0-9_]+$/D',$table))throw new RuntimeException('Invalid fixture table');
    $q=$db->prepare("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION");
    $q->execute([$table]);$keys=$q->fetchAll(PDO::FETCH_COLUMN);
    if(!$keys)throw new RuntimeException('Fixture snapshot requires a primary key');
    $q=$db->prepare('SELECT * FROM `'.$table.'` WHERE club_id=? ORDER BY `'.implode('`,`',$keys).'`');
    $q->execute([$club]);return $q->fetchAll();
}
