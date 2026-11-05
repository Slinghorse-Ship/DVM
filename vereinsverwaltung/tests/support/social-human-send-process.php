<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;use Kulturhus\Native\Shared\{Access,Db,SecretBox};use Kulturhus\Native\Communication\Replies;
$cfg=TestSupport::config();if(PHP_SAPI!=='cli'||getenv('KH_NATIVE_CI')!=='1'||($cfg['label']??'')!=='native-social-human-sends'||($cfg['owner']??'')!=='isolated-native-ci')throw new RuntimeException('Own isolated human worker required');
$input=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);$remote=$input['remote'];$db=TestSupport::db();
$http=static function(string $method,string $path,array $headers,?array $body)use(&$remote):array{
    if($headers!==['Authorization: Bearer own-human-only'])throw new RuntimeException('Own worker header mismatch');$id=rawurldecode(explode('?',substr($path,strlen('/v25.0/')))[0]);
    if($method==='GET')return['status'=>200,'body'=>$id==='me'?['id'=>'123','instagram_business_account'=>['id'=>'456']]:($remote[$id]??[])];
    if($method!=='POST'||$path!=='/v25.0/123/messages')throw new RuntimeException('Own worker write contract mismatch');
    $transport=TestSupport::db();Db::run($transport,'INSERT INTO own_human_parallel_posts(body) VALUES(?)',[json_encode($body,JSON_THROW_ON_ERROR)]);$sent='own_parallel_sent_'.$transport->lastInsertId();$sender=isset($body['messaging_type'])?'123':'456';$target=$body['recipient']['id'];$remote[$sent]=['id'=>$sent,'message'=>$body['message']['text'],'created_time'=>gmdate('Y-m-d\TH:i:sO'),'from'=>['id'=>$sender],'to'=>['data'=>[['id'=>$target]]]];
    return['status'=>200,'body'=>['recipient_id'=>$target,'message_id'=>$sent]];
};
$reply=(new Replies($db,new SecretBox(str_repeat('h',32)),$http))->sendHuman(new Access($db,'admin'),$input['attention'],$input['version'],$input['text'],$input['key']);echo json_encode(['id'=>$reply['id'],'status'=>$reply['status']],JSON_THROW_ON_ERROR);
