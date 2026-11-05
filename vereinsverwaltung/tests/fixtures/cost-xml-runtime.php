<?php declare(strict_types=1);
require_once __DIR__.'/php-runtime.php';
require_once __DIR__.'/../../src/Finance/Balance/BalanceTaxFiling.php';
/** Local transport fixture, actual Python generator, no native or network call. */
function costXmlRuntime():void
{
    $dir=sys_get_temp_dir().'/kh-eric-fixture-cost-'.bin2hex(random_bytes(8));mkdir($dir,0700);
    register_shutdown_function(static function()use($dir):void{foreach(['runtime.json','catalog.json'] as $name)if(is_file($dir.'/'.$name))unlink($dir.'/'.$name);rmdir($dir);});
    $profile=['family'=>'balance','label'=>'Synthetic cost XML','year'=>null,'test_only'=>true,'data_art'=>'Bilanz','verfahren'=>'ElsterBilanz',
        'vorgang'=>'send-Auth','test_marker'=>'700000004','payload_tag'=>'{http://rzf.fin-nrw.de/RMS/EBilanz/2016/XMLSchema}EBilanz','pdf_supported'=>false,'transport_enabled'=>false];
    $cfg=['version'=>1,'manufacturer_id'=>'12345','library'=>__FILE__,'scratch'=>$dir,'catalog'=>$dir.'/catalog.json',
        'command'=>taxFixturePhpCommand(),'environment'=>taxFixturePhpEnvironment(),'profiles'=>['Bilanz_6_9'=>$profile],
        'test_fixture_only'=>true,'balance_test_enabled'=>true,'production_enabled'=>false];
    if(getenv('VEREIN_PYTHON'))$cfg['test_python']=getenv('VEREIN_PYTHON');
    file_put_contents($dir.'/catalog.json','{"version":1,"profiles":[]}');file_put_contents($dir.'/runtime.json',json_encode($cfg));
    chmod($dir.'/runtime.json',0600);chmod($dir.'/catalog.json',0600);putenv('VEREIN_ERIC_RUNTIME='.$dir.'/runtime.json');putenv('VEREIN_TAX_AUTO_TEST=synthetic-only');
}
