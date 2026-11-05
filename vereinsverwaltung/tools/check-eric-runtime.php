<?php declare(strict_types=1);
/** Local native-library check only. No credentials, XML or ELSTER transport. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../src/Finance/Tax/TaxEricAdapter.php';
if($argc!==1){fwrite(STDERR,"Aufruf: php check-eric-runtime.php\nKonfiguration über VEREIN_ERIC_RUNTIME. Keine Übermittlung.\n");exit(64);}
$status=\Kulturhus\Verein\TaxEricAdapter::probe();
echo json_encode($status,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
exit($status['runtime_verified']?0:1);
