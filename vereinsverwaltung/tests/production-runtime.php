<?php declare(strict_types=1);
require __DIR__.'/../src/Infrastructure/Runtime/LocalRuntime.php';
use Kulturhus\Verein\{ProductionRuntime,LocalRuntime};
$n=0;function check(bool $v):void{global $n;if(!$v)throw new RuntimeException('Production gate regression #'.($n+1));$n++;}
putenv('VEREIN_MODE=production');putenv('KH_NATIVE_MANAGER=1');putenv('VEREIN_AUTH=native');putenv('KULTURHUS_LOCAL_DEMO=0');
putenv('VEREIN_ALLOWED_HOSTS=www.musterverein.example,musterverein.example');
$request=['HTTP_HOST'=>'www.musterverein.example','HTTPS'=>'on','REQUEST_URI'=>'/vereinsverwaltung/?document_file=private'];
check(ProductionRuntime::acceptsRequest($request));check(LocalRuntime::basePath()==='/vereinsverwaltung/');
check(ProductionRuntime::acceptsRequest(array_replace($request,['HTTP_HOST'=>'WWW.MUSTERVEREIN.EXAMPLE'])));
foreach([['HTTP_HOST'=>'evil.example'],['HTTPS'=>'off'],['REQUEST_URI'=>'/'],['HTTP_HOST'=>'localhost:8092'],['HTTP_HOST'=>'www.kulturhus.de'],['HTTP_HOST'=>'www.musterverein.example.evil.example'],['HTTP_HOST'=>'www.musterverein.example:443'],['HTTP_HOST'=>"www.musterverein.example\r\n"],['HTTP_HOST'=>' www.musterverein.example'],['HTTP_HOST'=>'www.musterverein.example.']] as $change)check(!ProductionRuntime::acceptsRequest(array_replace($request,$change)));
foreach(['','*','*.example','https://musterverein.example','musterverein.example/path','musterverein.example:443','musterverein.example,,other.example','localhost','127.0.0.1','-bad.example','musterverein.example.'] as $hosts){putenv('VEREIN_ALLOWED_HOSTS='.$hosts);check(!ProductionRuntime::acceptsRequest($request));}
putenv('VEREIN_ALLOWED_HOSTS');check(!ProductionRuntime::acceptsRequest($request));
check(!ProductionRuntime::acceptsRequest(array_replace($request,['HTTP_HOST'=>'www.kulturhus.de'])));
putenv('VEREIN_ALLOWED_HOSTS=www.musterverein.example,musterverein.example');
check(ProductionRuntime::allowedHosts('XN--MUSTER-VERIN-7IB.EXAMPLE,musterverein.example,musterverein.example')===['xn--muster-verin-7ib.example','musterverein.example']);
putenv('VEREIN_AUTH=demo');check(!ProductionRuntime::acceptsRequest($request));putenv('VEREIN_AUTH=processwire');
putenv('KH_NATIVE_MANAGER');putenv('VEREIN_AUTH=native');check(!ProductionRuntime::acceptsRequest($request));putenv('KH_NATIVE_MANAGER=1');check(ProductionRuntime::acceptsRequest($request));
putenv('VEREIN_AUTH=processwire');check(!ProductionRuntime::acceptsRequest($request));putenv('VEREIN_AUTH=native');
putenv('KULTURHUS_LOCAL_DEMO=php');check(!ProductionRuntime::acceptsRequest($request));putenv('KULTURHUS_LOCAL_DEMO=synthetic');check(!ProductionRuntime::acceptsRequest($request));putenv('KULTURHUS_LOCAL_DEMO=0');
putenv('KH_NATIVE_MANAGER');putenv('VEREIN_AUTH=processwire');check(!ProductionRuntime::acceptsRequest($request));
putenv('KULTURHUS_LOCAL_DEMO=1');check(!ProductionRuntime::acceptsRequest($request));
putenv('KULTURHUS_LOCAL_DEMO=0');putenv('VEREIN_MODE=synthetic');check(!ProductionRuntime::acceptsRequest($request));
putenv('VEREIN_SESSION_ROOT');putenv('VEREIN_PRIVATE_ROOT=/tmp/synthetic-manager');
check(ProductionRuntime::sessionDirectory()==='/tmp/synthetic-manager/sessions');
putenv('VEREIN_SESSION_ROOT=/tmp/explicit-session-root/');check(ProductionRuntime::sessionDirectory()==='/tmp/explicit-session-root/sessions');
foreach(['','mysql:host=example.invalid;dbname=club','relative/path'] as $invalid){putenv('VEREIN_SESSION_ROOT='.$invalid);putenv('VEREIN_PRIVATE_ROOT');$rejected=false;try{ProductionRuntime::sessionDirectory();}catch(RuntimeException){$rejected=true;}check($rejected);}
require_once __DIR__.'/../src/Finance/Banking/EnableBanking.php';
putenv('VEREIN_MODE=production');putenv('VEREIN_PRIVATE_ROOT=/tmp/synthetic-manager');putenv('VEREIN_BANKING_ROOT');
check(\Kulturhus\Verein\EnableBanking::root()==='/tmp/synthetic-manager/kulturhus-banking');
putenv('VEREIN_BANKING_ROOT=/tmp/selected-banking/');check(\Kulturhus\Verein\EnableBanking::root()==='/tmp/selected-banking');
foreach(['relative/banking',"/tmp/path\n"] as $invalid){putenv('VEREIN_BANKING_ROOT='.$invalid);$rejected=false;try{\Kulturhus\Verein\EnableBanking::root();}catch(DomainException){$rejected=true;}check($rejected);}
putenv('VEREIN_MODE=synthetic');check(\Kulturhus\Verein\EnableBanking::root()===dirname(__DIR__,2).'/.local/enable-banking');
echo "PASS $n production gate checks\n";
