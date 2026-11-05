<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';require __DIR__.'/balance-environments-runtime.php';
use Kulturhus\Native\TestSupport;use Kulturhus\Verein\TaxFilings;
$config=TestSupport::config();
if(PHP_SAPI!=='cli'||getenv('KH_NATIVE_CI')!=='1'||($config['label']??null)!=='native-balance-environments'||($config['owner']??null)!=='isolated-native-ci'||$argc!==5||!in_array($argv[1],['once','kill-after-claim'],true)||!preg_match('/^[a-f0-9]{32}$/D',$argv[2])||!preg_match('/^[1-9][0-9]*$/D',$argv[3]))throw new RuntimeException('Own isolated balance worker required.');
$base=realpath(dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/balance-environments-'.$config['database']);$barrier=realpath($argv[4]);
if(!$base||!$barrier||!str_starts_with($barrier,$base.'/')||!is_dir($barrier))throw new RuntimeException('Own private barrier required.');
$policy=new OwnBalanceEnvironmentRuntime();$policy->barrierRoot=$barrier;$policy->waitAfterClaim=$argv[1]==='kill-after-claim';
try{$result=(new TaxFilings(TestSupport::db(),'env-board',$policy))->send($argv[2],(int)$argv[3],'own-concurrent-certificate','own-concurrent-pin','SEND-PRODUCTION',true,false);echo json_encode(['result'=>$result['state'],'calls'=>$policy->transmissions],JSON_THROW_ON_ERROR).PHP_EOL;}
catch(DomainException){echo json_encode(['result'=>'refused','calls'=>$policy->transmissions],JSON_THROW_ON_ERROR).PHP_EOL;}
