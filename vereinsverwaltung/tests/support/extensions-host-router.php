<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';
use Kulturhus\Native\TestSupport;
if(PHP_SAPI!=='cli-server'||getenv('KH_NATIVE_CI')!=='1'||(TestSupport::config()['label']??'')!=='native-extension-hosts'||(TestSupport::config()['owner']??'')!=='isolated-native-ci'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);exit;}
$f=json_decode(file_get_contents(getenv('DVM_EXTENSION_HOST_FIXTURE')),true,32,JSON_THROW_ON_ERROR);
if('http://'.($_SERVER['HTTP_HOST']??'')!==$f['origin']){http_response_code(403);exit;}
putenv('VEREIN_PRIVATE_ROOT='.$f['root']);putenv('KH_NATIVE_AUTH_KEY_FILE='.$f['root'].'/auth.key');putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$f['root'].'/private.key');putenv('VEREIN_MODE=synthetic');putenv('VEREIN_AUTH=native');putenv('KULTURHUS_DISABLE_MAIL=1');putenv('KULTURHUS_DISABLE_WEBSITE=1');
$db=TestSupport::db();if(!\Kulturhus\Native\Integration\PublicSite::serve($db,new \Kulturhus\Native\Shared\Access($db,$f['public_actor']),$f['root'].'/media',$f['root'],$f['club'],$f['origin']))http_response_code(404);
