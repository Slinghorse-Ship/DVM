<?php declare(strict_types=1);
require __DIR__.'/../src/Finance/Tax/WindowsEricProcess.php';
use Kulturhus\Verein\WindowsEricProcess;
$python=(string)getenv('VEREIN_PYTHON');
if(!is_file($python))throw new RuntimeException('Set VEREIN_PYTHON to the isolated Python interpreter.');
$environment=[];foreach(['PATH','SystemRoot','WINDIR','TEMP','TMP','HOME'] as $key){$value=getenv($key);if($value!==false)$environment[$key]=$value;}
$environment['PYTHONUTF8']='1';$environment['PYTHONDONTWRITEBYTECODE']='1';
$fixture=__DIR__.'/support/eric-process-fixture.py';$checks=0;
function ipcCheck(bool $condition):void{if(!$condition)throw new RuntimeException('Private process IO regression');++$GLOBALS['checks'];}
function ipcRun(string $mode,string $input,array $extra=[],float $seconds=10):array {
    return WindowsEricProcess::run([$GLOBALS['python']],[$GLOBALS['python'],$GLOBALS['fixture'],$mode,...$extra],$GLOBALS['environment'],$input,$seconds);
}
foreach(['',json_encode(['xml'=>str_repeat('Äß€<xml/>',220000)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)] as $input){
    [$out,$err,$exit]=ipcRun('echo',$input);ipcCheck($out===$input);ipcCheck($err==='synthetisch äöü');ipcCheck($exit===7);
}
[$out,$err,$exit]=ipcRun('environment','{}');ipcCheck($exit===0&&$err===''&&json_decode($out,true)===['private_token'=>false,'private_command'=>false]);
foreach([['limit',''],['echo',str_repeat('x',8388609)]] as [$mode,$input]){
    $blocked=false;try{ipcRun($mode,$input);}catch(DomainException $e){$blocked=str_contains($e->getMessage(),'Größenlimit');}ipcCheck($blocked);
}
$pidFile=sys_get_temp_dir().'/kh-eric-process-'.bin2hex(random_bytes(8)).'.json';
try{
    $started=microtime(true);$blocked=false;
    try{ipcRun('hang','{}',[$pidFile],2);}catch(DomainException $e){$blocked=str_contains($e->getMessage(),'rechtzeitig');}
    ipcCheck($blocked&&microtime(true)-$started<5);ipcCheck(is_file($pidFile));
    $pids=json_decode(file_get_contents($pidFile),true,8,JSON_THROW_ON_ERROR);ipcCheck(count($pids)===2);
    foreach($pids as $pid){[$out,,$exit]=ipcRun('alive','',[(string)$pid]);ipcCheck($exit===0&&trim($out)==='stopped');}
}finally{if(is_file($pidFile))unlink($pidFile);}
echo "$checks private process checks passed; memory-only XML, bounded IO and child-tree cleanup; no database or transport\n";
