<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/native/bootstrap.php';
require dirname(__DIR__).'/src/bootstrap.php';
$configuration=Kulturhus\Native\TestSupport::config();
if(!in_array($configuration['label']??'',['integrated','integrated-browser'],true))throw new RuntimeException('Eigene integrierte Fixture erforderlich.');
// Manager installation deliberately refuses a nonempty foreign schema. Install it first.
Kulturhus\Verein\Database::migrate(Kulturhus\Native\TestSupport::db());
require __DIR__.'/native-demo-seed.php';
require __DIR__.'/native-membership-demo-seed.php';
