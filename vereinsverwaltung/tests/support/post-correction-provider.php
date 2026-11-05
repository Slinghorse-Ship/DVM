<?php declare(strict_types=1);
/** Owned MySQL fake is shared by actual separate test processes; no socket is opened. */
function ownPostCorrectionProvider(PDO $db):Closure
{
    return static function(string $method,string $path,array $headers,?array $body)use($db):array{
        if($headers!==['Authorization: Bearer own-post-correction-only']||!str_starts_with($path,'/v25.0/'))throw new RuntimeException('Unexpected owned post contract');
        $id=explode('?',substr($path,strlen('/v25.0/')))[0];$mode=$db->query('SELECT mode FROM own_post_mode')->fetchColumn();
        if($method==='GET'){$raw=Kulturhus\Native\Shared\Db::run($db,'SELECT payload FROM own_post_remote WHERE id=?',[$id])->fetchColumn();$data=$raw?json_decode($raw,true,32,JSON_THROW_ON_ERROR):[];if($mode==='wrong-owner')$data['from']['id']='999';if($mode==='wrong-id')$data['id']='123_999999';return['status'=>200,'body'=>$data];}
        if($method!=='POST'||!preg_match('/\A123_[0-9]+\z/D',$id)||array_keys($body??[])!==['message']||!is_string($body['message']))throw new RuntimeException('Unexpected correction write');
        if($mode==='reject')return['status'=>400,'body'=>['error'=>['code'=>10]]];
        Kulturhus\Native\Shared\Db::run($db,'INSERT INTO own_post_calls(remote_id,message) VALUES(?,?)',[$id,$body['message']]);
        if($mode!=='not-applied')Kulturhus\Native\Shared\Db::run($db,"UPDATE own_post_remote SET payload=JSON_SET(payload,'$.message',?) WHERE id=?",[$body['message'],$id]);
        if($mode==='crash')posix_kill(posix_getpid(),SIGKILL);
        if($mode==='lost')throw new RuntimeException('Owned accepted correction response lost');
        return['status'=>200,'body'=>['success'=>true]];
    };
}
