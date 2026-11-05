<?php declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/native/bootstrap.php';
try{
    if($argc!==4||$argv[1]!=='--config'||$argv[3]!=='--instance-scope')throw new DomainException('Aufruf: native-auth-retention.php --config /privater/Originalpfad/worker.php --instance-scope');
    echo json_encode(\Kulturhus\Native\Auth\RetentionWorker::run($argv[2],true),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n";
}catch(Throwable){fwrite(STDERR,"Instanzbereinigung nicht bestätigt. Ursprüngliche private Hostautorität und Wartungsstand prüfen.\n");exit(1);}
