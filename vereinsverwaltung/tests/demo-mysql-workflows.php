<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/mysql-bootstrap.php';
use Kulturhus\Verein\{Database,Settings,Tests\MySqlFixture};
set_error_handler(static function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
$db=MySqlFixture::connect();$dsn=MySqlFixture::dsn($db);
if(str_contains($dsn,'dbname=k15753_migrate')){
    if(getenv('VEREIN_MODE')!=='synthetic'||getenv('KH_SECONDARY_PASSWORD')===false)throw new RuntimeException('Dedicated synthetic credentials required');
    putenv('VEREIN_DATABASE_USER=k15753_migrateu');putenv('VEREIN_DATABASE_PASSWORD='.getenv('KH_SECONDARY_PASSWORD'));
}else{
    if(!preg_match('/^mysql:host=mysql-test;dbname=kh_test_[a-f0-9]{24};charset=utf8mb4$/D',$dsn))throw new RuntimeException('Isolated synthetic database required');
    putenv('VEREIN_DATABASE_USER=root');putenv('VEREIN_DATABASE_PASSWORD=isolated-regression-only');
}
putenv('VEREIN_MODE=synthetic');putenv('KULTURHUS_DISABLE_MAIL=1');putenv('VEREIN_DATABASE_DSN='.$dsn);
$argv=[__FILE__,'--demo'];require __DIR__.'/../bin/init-demo.php';
if((int)$db->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn()!==Database::SCHEMA_VERSION)throw new RuntimeException('Incomplete demo schema');
foreach(['demo-manager','demo-finance','demo-member','demo-editor'] as $actor){
    $q=$db->prepare('SELECT club_id FROM actors WHERE id=?');$q->execute([$actor]);if($q->fetchColumn()!=='kulturhus-demo')throw new RuntimeException('Missing test role '.$actor);
}
if((new Settings($db,'demo-manager'))->section('tax')['data']['accounting_basis']!=='euer')throw new RuntimeException('Demo unexpectedly enabled balance production');
if((int)$db->query("SELECT COUNT(*) FROM finance_accounts WHERE club_id='kulturhus-demo'")->fetchColumn()<2)throw new RuntimeException('Missing seeded synthetic finance');
if(MySqlFixture::foreignKeyErrors($db)!==[])throw new RuntimeException('Broken demo relationships');
echo "PASS full MySQL demo initialization, four roles, synthetic finance, EÜR and foreign keys; no mail or banking\n";
