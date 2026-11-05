<?php declare(strict_types=1);
/** CLI-only local installation manifest. Does not download, install or expose a service. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../src/Finance/Invoices/HybridEInvoice.php';
if($argc!==7){fwrite(STDERR,"Usage: php configure-hybrid-runtime.php config.json java mustang.jar python private-packages private-workdir\n");exit(2);}
try{
    $keys=['java','jar','python','packages','workdir'];$config=[];
    foreach($keys as $index=>$key){$path=realpath($argv[$index+2]);if(!$path)throw new RuntimeException('Missing path: '.$key);$config[$key]=$path;}
    foreach(['java','jar','python'] as $key){if(!is_file($config[$key]))throw new RuntimeException('Not a file: '.$key);$config[$key.'_sha256']=hash_file('sha256',$config[$key]);}
    if($config['jar_sha256']!=='42d7868cb68264874a7b8cab4c3587b03b23ccc7cd72373da917f66758bb9736')throw new RuntimeException('Expected reviewed official Mustang CLI 2.26.0 release; do not bypass without revalidation.');
    if(!is_dir($config['packages'])||!is_dir($config['workdir'])||!is_writable($config['workdir']))throw new RuntimeException('Private package or work directory missing.');
    $config['packages_sha256']=\Kulturhus\Verein\HybridEInvoice::packageHash($config['packages']);
    if(is_file($argv[1]))throw new RuntimeException('Configuration already exists. Write to a new file and compare before replacing.');
    if(file_put_contents($argv[1],json_encode($config,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n",LOCK_EX)===false)throw new RuntimeException('Could not write configuration.');chmod($argv[1],0600);
    fwrite(STDOUT,"Private runtime manifest written. Run the real synthetic validator test before enabling downloads.\n");
}catch(Throwable $ex){fwrite(STDERR,$ex->getMessage()."\n");exit(1);}
