<?php declare(strict_types=1);
require_once __DIR__.'/../src/Finance/Tax/TaxEricAdapter.php';
use Kulturhus\Verein\TaxEricAdapter;
if(($argv[1]??'')==='fixture'){
    $configuration=json_decode(file_get_contents($argv[count($argv)-1]),true,64,JSON_THROW_ON_ERROR);
    $request=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);
    if($request!==['action'=>'status','environment'=>'test'])exit(97);
    file_put_contents($configuration['scratch'].'/calls',"local-status\n",FILE_APPEND);
    $mode=$configuration['fixture_mode'];
    if($mode==='crash')exit(9);
    if($mode==='invalid-json'){echo 'broken';exit;}
    $result=['code'=>0,'available'=>true,'version_xml'=>'<Version><ERiC>44.3.6.0</ERiC></Version>','transport_attempted'=>false,'sent'=>false];
    if($mode==='rejected')$result['code']=610001001;
    if($mode==='not-available')$result['available']=false;
    if($mode==='transport')$result['transport_attempted']=true;
    if($mode==='sent')$result['sent']=true;
    if($mode==='missing-flags')unset($result['sent'],$result['transport_attempted']);
    if($mode==='malformed-xml')$result['version_xml']='<Version>';
    if($mode==='entity')$result['version_xml']='<!DOCTYPE version [<!ENTITY private SYSTEM "file:///nonexistent">]><version>&private;</version>';
    if($mode==='oversized')$result['version_xml']='<version>'.str_repeat('x',65536).'</version>';
    if($mode==='changed')file_put_contents($configuration['catalog'],'changed during check');
    if($mode==='private-error'){echo json_encode(['code'=>-1,'message'=>'/private/operator/library secret']);exit(2);}
    echo json_encode($result);exit;
}
$directory=sys_get_temp_dir().'/kh-eric-probe-'.bin2hex(random_bytes(12));mkdir($directory,0700);
$previous=getenv('VEREIN_ERIC_RUNTIME');$checks=0;
function verifyEric(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;echo "PASS $label\n";}
try{
    $path=$directory.'/runtime.json';$catalog=$directory.'/catalog.json';file_put_contents($catalog,'{}');
    $command=[PHP_BINARY];if(php_ini_loaded_file())array_push($command,'-c',php_ini_loaded_file());array_push($command,__FILE__,'fixture');
    $config=['version'=>1,'manufacturer_id'=>'12345','library'=>__FILE__,'scratch'=>$directory,'catalog'=>$catalog,'command'=>$command,'environment'=>getenv('LD_LIBRARY_PATH')?['LD_LIBRARY_PATH'=>getenv('LD_LIBRARY_PATH')]:[],'profiles'=>[],'production_enabled'=>true,'production_versions'=>[]];
    putenv('VEREIN_ERIC_RUNTIME='.$directory.'/missing.json');$status=TaxEricAdapter::probe();verifyEric(!$status['available']&&!$status['runtime_verified'],'missing private SDK remains unavailable');
    putenv('VEREIN_ERIC_RUNTIME='.$path);
    foreach(['ok','rejected','not-available','transport','sent','missing-flags','malformed-xml','entity','oversized','changed','private-error','crash','invalid-json'] as $mode){
        $config['fixture_mode']=$mode;file_put_contents($path,json_encode($config,JSON_THROW_ON_ERROR));chmod($path,0600);
        $before=is_file($directory.'/calls')?file_get_contents($directory.'/calls'):'';
        $configured=TaxEricAdapter::status();verifyEric($configured['available']&&!$configured['runtime_verified']&&$configured['runtime_checked_at']===null,'configuration alone is not a runtime proof: '.$mode);
        $status=TaxEricAdapter::probe();verifyEric($status['runtime_verified']===($mode==='ok')&&$status['available']===($mode==='ok'),'local subprocess result checked: '.$mode);
        $output=json_encode($status);verifyEric(!str_contains($output,$directory)&&!str_contains($output,'12345')&&!str_contains($output,'operator/library secret'),'public result excludes private data: '.$mode);
        verifyEric(file_get_contents($directory.'/calls')===$before."local-status\n",'exactly one local status request, no certificate or transport: '.$mode);
        if($mode==='ok')verifyEric(strlen($status['runtime_sha256'])===64&&strlen($status['version_sha256'])===64&&$status['runtime_checked_at']!==null,'native proof bound to runtime and version response');
    }
    // Run the actual Python/ctypes bridge against an invalid binary, not the response fixture.
    $config['command']=[getenv('VEREIN_PYTHON')?:'/usr/bin/python3'];file_put_contents($path,json_encode($config,JSON_THROW_ON_ERROR));
    $status=TaxEricAdapter::probe();verifyEric(!$status['runtime_verified']&&!$status['available'],'actual loader rejects a present file that is not an ERiC library');
    $previousGate=getenv('VEREIN_ERIC_DISABLE_TRANSPORT');putenv('VEREIN_ERIC_DISABLE_TRANSPORT=1');
    try{$blocked=TaxEricAdapter::transmit(['environment'=>'test','xml_sha256'=>str_repeat('a',64)]);verifyEric($blocked['code']===-1&&$blocked['transport_attempted']===false&&$blocked['sent']===false&&$blocked['message']==='ELSTER-Netzwerkzugriffe sind in dieser Umgebung gesperrt.','actual PHP adapter forwards forced network refusal before any native SDK or certificate load');}
    finally{putenv($previousGate===false?'VEREIN_ERIC_DISABLE_TRANSPORT':'VEREIN_ERIC_DISABLE_TRANSPORT='.$previousGate);}
}finally{
    putenv($previous===false?'VEREIN_ERIC_RUNTIME':'VEREIN_ERIC_RUNTIME='.$previous);
    foreach(['runtime.json','catalog.json','calls'] as $file)if(is_file($directory.'/'.$file))unlink($directory.'/'.$file);rmdir($directory);
}
echo json_encode(['suite'=>'native-eric-runtime','passed'=>$checks,'official_sdk'=>false,'live_transport'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
$networkChecks=proc_open([getenv('VEREIN_PYTHON')?:'/usr/bin/python3',__DIR__.'/eric-no-transport-test.py'],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes);if(!is_resource($networkChecks)||proc_close($networkChecks)!==0)throw new RuntimeException('ERiC development network isolation failed');
