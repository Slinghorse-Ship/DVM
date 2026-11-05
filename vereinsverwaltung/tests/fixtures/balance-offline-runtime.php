<?php declare(strict_types=1);
require_once __DIR__.'/php-runtime.php';
// Real Python generation, strictly simulated validation/transport. No SDK/network.
$offlineDir=sys_get_temp_dir().'/kh-eric-fixture-'.bin2hex(random_bytes(8));mkdir($offlineDir,0700);
$offlineRuntime=$offlineDir.'/runtime.json';$offlineCatalog=$offlineDir.'/catalog.json';
register_shutdown_function(static function()use($offlineDir):void{foreach(['runtime.json','catalog.json'] as $file)if(is_file($offlineDir.'/'.$file))unlink($offlineDir.'/'.$file);rmdir($offlineDir);});
$offlineProfile=['family'=>'balance','label'=>'Synthetic E-Bilanz','year'=>null,'test_only'=>true,'data_art'=>'Bilanz','verfahren'=>'ElsterBilanz','vorgang'=>'send-Auth','test_marker'=>'700000004','payload_tag'=>'{http://rzf.fin-nrw.de/RMS/EBilanz/2016/XMLSchema}EBilanz','pdf_supported'=>false,'transport_enabled'=>true];
$offlineConfig=['version'=>1,'manufacturer_id'=>'12345','library'=>__FILE__,'scratch'=>$offlineDir,'catalog'=>$offlineCatalog,'command'=>taxFixturePhpCommand(),'environment'=>taxFixturePhpEnvironment(),'profiles'=>['Bilanz_6_9'=>$offlineProfile],'test_fixture_only'=>true,'balance_test_enabled'=>true,'production_enabled'=>true,'production_versions'=>['Bilanz_6_9']];
if(getenv('VEREIN_PYTHON'))$offlineConfig['test_python']=getenv('VEREIN_PYTHON');
file_put_contents($offlineCatalog,json_encode(['version'=>1,'profiles'=>[]]));chmod($offlineCatalog,0600);
file_put_contents($offlineRuntime,json_encode($offlineConfig));chmod($offlineRuntime,0600);putenv('VEREIN_ERIC_RUNTIME='.$offlineRuntime);
