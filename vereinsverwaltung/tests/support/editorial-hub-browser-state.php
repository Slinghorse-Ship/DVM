<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';
use Kulturhus\Native\TestSupport;
if(PHP_SAPI!=='cli'||getenv('KH_NATIVE_CI')!=='1'||(TestSupport::config()['label']??'')!=='native-editorial-hub'||(TestSupport::config()['owner']??'')!=='isolated-native-ci'||!in_array($argv[1]??null,['de','en'],true))throw new RuntimeException('Owned editorial fixture only');
$f=json_decode(file_get_contents(getenv('DVM_EDITORIAL_HUB_FIXTURE')),true,32,JSON_THROW_ON_ERROR);$db=TestSupport::db();$a=(new Kulturhus\Native\Integration\ManagerBridge($db,$f['manager_actor'],'native',static fn()=>null))->access();(new Kulturhus\Native\Language\Catalog($db,$a))->select($argv[1]);
