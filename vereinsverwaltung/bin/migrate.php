<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\Database;
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--synthetic'||getenv('VEREIN_MODE')!=='synthetic')throw new RuntimeException('Nur für die isolierte MySQL-Demo.');
$dsn=trim((string)(getenv('VEREIN_DATABASE_DSN')?:getenv('KULTURHUS_DEMO_DATABASE')));
$db=Database::connect($dsn);if(!Database::tableExists($db,'schema_migrations'))throw new RuntimeException('Demo zuerst initialisieren.');
Database::migrate($db);
echo "Lokale MySQL-Datenbankmigration abgeschlossen.\n";
