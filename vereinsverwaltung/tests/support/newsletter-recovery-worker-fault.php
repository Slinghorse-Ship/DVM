<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;use Kulturhus\Native\Shared\SecretBox;
if(PHP_SAPI!=='cli'||getenv('KH_NATIVE_CI')!=='1'||(TestSupport::config()['label']??'')!=='native-newsletter-recovery'||(TestSupport::config()['owner']??'')!=='isolated-native-ci'||!function_exists('posix_kill'))throw new RuntimeException('Owned private newsletter crash fixture only.');
$input=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);$db=TestSupport::db();
(new Kulturhus\Native\Delivery\Outbox($db,SecretBox::fromFile($input['key_file'])))->run(static function(){posix_kill(getmypid(),SIGKILL);throw new RuntimeException('Own DOI crash did not terminate');},1,['confirm'],$input['club']);
throw new RuntimeException('Expected own original DOI claim.');
