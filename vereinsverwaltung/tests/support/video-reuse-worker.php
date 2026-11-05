<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
if(PHP_SAPI!=='cli'||getenv('KH_NATIVE_CI')!=='1'||(TestSupport::config()['label']??'')!=='native-video-reuse'||(TestSupport::config()['owner']??'')!=='isolated-native-ci')exit(1);
$input=json_decode(stream_get_contents(STDIN),true,8,JSON_THROW_ON_ERROR);$db=TestSupport::db();$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.TestSupport::config()['database'];
$result=(new Kulturhus\Native\Communication\Videos($db,new Kulturhus\Native\Shared\Access($db,'admin'),$root.'/media'))->reuse($input['item'],1,0,$input['video']);
echo json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL;
