<?php declare(strict_types=1);
// The fixed server-side front controller supplies this path, never a request parameter.
ini_set('display_errors','0');ini_set('zend.exception_ignore_args','1');
try{
    if(!isset($dvmDemoConfig)||!is_string($dvmDemoConfig))throw new DomainException('Private Demo-Konfiguration erforderlich.');
    \Kulturhus\Native\Demo\Runtime::serve($dvmDemoConfig);
}catch(Throwable){if(!headers_sent()){if(http_response_code()<400)http_response_code(503);header('Cache-Control: no-store');}echo 'Demo derzeit nicht verfügbar. / Demo currently unavailable.';}
