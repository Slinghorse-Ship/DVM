<?php declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('zend.exception_ignore_args','1');require dirname(__DIR__).'/native/bootstrap.php';require dirname(__DIR__).'/src/bootstrap.php';require dirname(__DIR__,2).'/vendor/autoload.php';
try{if($argc!==3||$argv[1]!=='--config')throw new DomainException('Nur ursprüngliche private Systemkonfiguration verwenden.');echo json_encode(\Kulturhus\Native\System\Worker::run($argv[2]),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n";}
catch(Throwable){fwrite(STDERR,"Systemlauf nicht bestätigt. Privaten Originalstand und aktuelle Freigabe prüfen.\n");exit(1);}
