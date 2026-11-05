<?php declare(strict_types=1);
if(PHP_SAPI!=='cli-server'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);exit;}
require dirname(__DIR__,2).'/native/bootstrap.php';
$f=json_decode(file_get_contents(getenv('DVM_WEBSITE_MANIFEST_FIXTURE')),true,32,JSON_THROW_ON_ERROR);
if(('http://'.($_SERVER['HTTP_HOST']??''))!==$f['origin']){http_response_code(403);exit;}
if(!Kulturhus\Native\Integration\PublicSite::serve(Kulturhus\Native\TestSupport::db(),new Kulturhus\Native\Shared\Access(Kulturhus\Native\TestSupport::db(),$f['reader']),$f['root'].'/media',$f['root'],$f['club'],$f['origin']))http_response_code(404);
