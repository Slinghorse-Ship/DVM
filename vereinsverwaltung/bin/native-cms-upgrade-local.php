<?php declare(strict_types=1);
/** Explicit additive schema upgrade of registered synthetic local fixtures only. */
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/native/bootstrap.php';
foreach(array_slice($argv,1) as $label){
    if(!in_array($label,['demo','browser','integrated','integrated-manager','auth-browser','navigation'],true))throw new RuntimeException('Unbekannter lokaler Prüfstand.');
    putenv('KH_NATIVE_TEST_CONFIG='.dirname(__DIR__,2).'/.local/native-cms-integration/'.$label.'.json');
    $db=\Kulturhus\Native\TestSupport::db();
    \Kulturhus\Native\Cms\Schema::install($db);
    if(class_exists(\Kulturhus\Native\Cms\PublicationSchedule::class))\Kulturhus\Native\Cms\PublicationSchedule::install($db);
    echo $label.': CMS-Erweiterung additiv eingerichtet.'.PHP_EOL;
}
