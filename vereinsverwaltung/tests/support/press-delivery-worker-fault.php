<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\SecretBox;
if(PHP_SAPI!=='cli'||getenv('KH_NATIVE_CI')!=='1'||(TestSupport::config()['label']??'')!=='native-press-delivery'||(TestSupport::config()['owner']??'')!=='isolated-native-ci'||!function_exists('posix_kill'))throw new RuntimeException('Owned private press crash fixture only.');
$input=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);$db=TestSupport::db();
(new Kulturhus\Native\Delivery\Outbox($db,SecretBox::fromFile($input['key_file'])))->run(static function(){posix_kill(getmypid(),SIGKILL);throw new RuntimeException('Own crash did not terminate');},1,['press'],$input['club']);
throw new RuntimeException('Expected own pending press claim.');
