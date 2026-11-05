<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/native/bootstrap.php';
foreach(array_slice($argv,1) as $label){
    if(!in_array($label,['demo','browser','integrated','integrated-manager','auth-browser','navigation'],true))throw new RuntimeException('Unbekannter lokaler Prüfstand.');
    putenv('KH_NATIVE_TEST_CONFIG='.dirname(__DIR__,2).'/.local/native-cms-integration/'.$label.'.json');
    \Kulturhus\Native\Cms\Navigation::install(\Kulturhus\Native\TestSupport::db());
    echo $label.': Menütabellen additiv eingerichtet.'.PHP_EOL;
}
