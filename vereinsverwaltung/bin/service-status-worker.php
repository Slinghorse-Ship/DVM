<?php declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('zend.exception_ignore_args','1');require dirname(__DIR__).'/native/bootstrap.php';require dirname(__DIR__).'/src/bootstrap.php';require dirname(__DIR__,2).'/vendor/autoload.php';
try{
    // Reject all unknown options, including every sending/publishing/payment mode.
    if($argc!==3||$argv[1]!=='--config'||!is_string($argv[2])||$argv[2]==='')throw new DomainException('Nur private Statuskonfiguration erlaubt.');
    echo json_encode(\Kulturhus\Native\Status\Worker::run($argv[2]),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE).PHP_EOL;
}catch(Throwable){fwrite(STDERR,"Statusprüfung nicht abgeschlossen. Private Konfiguration und aktuelle Berechtigung prüfen.\n");exit(1);}
