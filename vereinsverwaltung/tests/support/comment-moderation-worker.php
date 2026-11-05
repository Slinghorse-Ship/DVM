<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;use Kulturhus\Native\Shared\{Access,Db,SecretBox};use Kulturhus\Native\Communication\CommentModeration;
if(PHP_SAPI!=='cli'||getenv('KH_NATIVE_CI')!=='1'||(TestSupport::config()['label']??'')!=='native-comment-moderation'||(TestSupport::config()['owner']??'')!=='isolated-native-ci')throw new RuntimeException('Owned isolated moderation worker required.');
$input=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);if(!is_string($input['box_key']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$input['box_key']))throw new RuntimeException('Owned moderation key required.');
$db=TestSupport::db();$box=new SecretBox(hex2bin($input['box_key']));$http=static function($method,$path,$headers,$body)use($db,$input):array{
 if(!str_starts_with($path,'/v25.0/')||$headers!==['Authorization: Bearer owned-synthetic-read-only'])throw new RuntimeException('Owned fixed provider contract required.');$id=explode('?',substr($path,strlen('/v25.0/')))[0];$raw=Db::run($db,'SELECT payload FROM own_comment_moderation_remote WHERE id=?',[$id])->fetchColumn();if(!$raw)throw new RuntimeException('Owned provider node missing.');
 if($method==='GET'){usleep(80000);return['status'=>200,'body'=>json_decode($raw,true,16,JSON_THROW_ON_ERROR)];}
 if($method!=='POST'||count($body)!==1||!is_bool(reset($body)))throw new RuntimeException('Owned moderation write required.');
 if(isset($input['barrier'])){
  if(!is_string($input['barrier'])||!str_starts_with($input['barrier'],dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/own-comment-kill-'))throw new RuntimeException('Owned barrier required.');file_put_contents($input['barrier'],'before-owned-provider-acceptance');while(true)usleep(100000);
 }
 Db::run($db,'INSERT INTO own_comment_moderation_posts(body) VALUES(?)',[json_encode($body,JSON_THROW_ON_ERROR)]);Db::run($db,"UPDATE own_comment_moderation_remote SET payload=JSON_SET(payload,'$.hidden',CAST(? AS JSON),'$.is_hidden',CAST(? AS JSON)) WHERE id=?",[json_encode(reset($body)),json_encode(reset($body)),$id]);usleep(200000);return['status'=>200,'body'=>['success'=>true]];
};
$result=(new CommentModeration($db,$box,$http))->execute(new Access($db,'admin'),$input['attention'],(int)$input['version'],$input['hidden'],$input['key']);echo json_encode(['id'=>$result['id'],'status'=>$result['status']],JSON_THROW_ON_ERROR)."\n";
