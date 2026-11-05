<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
if(PHP_SAPI!=='cli'||getenv('KH_NATIVE_CI')!=='1'||(TestSupport::config()['label']??'')!=='native-chat-files')throw new RuntimeException('Owned file concurrency fixture required.');
$input=json_decode((string)stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);$db=TestSupport::db();
$store=new Kulturhus\Native\Chat\Attachments($db,new Kulturhus\Native\Shared\Access($db,$input['actor']),$input['root']);
$result=$store->sendMany($input['thread'],$input['body'],$input['key'],$input['files']);
echo json_encode(['seq'=>$result['seq'],'duplicate'=>$result['duplicate']??false],JSON_THROW_ON_ERROR)."\n";
