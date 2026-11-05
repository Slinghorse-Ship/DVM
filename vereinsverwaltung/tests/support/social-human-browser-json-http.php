<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration;
/** Loaded only after the owned loopback test router guard; never used by an application entrypoint. */
final class JsonHttp
{
    public static function request(string $provider,string $method,string $path,array $headers=[],?array $body=null,?string $encoded=null):array
    {
        if($provider!=='meta'||!str_starts_with($path,'/v25.0/')||$headers!==['Authorization: Bearer own-human-browser-only']||!in_array($method,['GET','POST'],true)||$encoded!==null)throw new \RuntimeException('Unexpected own fake provider contract.');
        $db=\Kulturhus\Native\TestSupport::db();$mode=$db->query('SELECT mode FROM own_human_browser_mode WHERE id=1')->fetchColumn();$id=rawurldecode(explode('?',substr($path,strlen('/v25.0/')))[0]);
        \Kulturhus\Native\Shared\Db::run($db,'INSERT INTO own_human_browser_calls(method,path,body) VALUES(?,?,?)',[$method,$path,$body===null?null:json_encode($body,JSON_THROW_ON_ERROR)]);
        if($method==='GET'){
            if($id==='me')return['status'=>200,'body'=>['id'=>'123','instagram_business_account'=>['id'=>'456']]];
            $raw=\Kulturhus\Native\Shared\Db::run($db,'SELECT payload FROM own_human_browser_remote WHERE id=?',[$id])->fetchColumn();$data=$raw?json_decode($raw,true,32,JSON_THROW_ON_ERROR):[];
            if($mode==='wrong-owner'&&preg_match('/\A123_98[0-9]+\z/D',$id))$data['from']['id']='999';
            if($mode==='wrong-owner'&&str_starts_with($id,'own_browser_')&&isset($data['participants']))$data['is_owner']=false;
            return['status'=>200,'body'=>$data];
        }
        if(count($body??[])===1&&(array_key_exists('hide',$body)||array_key_exists('is_hidden',$body))){
            $flag=reset($body);if(!is_bool($flag)||!str_starts_with($id,'own_browser_comment_'))throw new \RuntimeException('Unexpected owned moderation field');
            \Kulturhus\Native\Shared\Db::run($db,"UPDATE own_human_browser_remote SET payload=JSON_SET(payload,'$.hidden',CAST(? AS JSON),'$.is_hidden',CAST(? AS JSON)) WHERE id=?",[json_encode($flag),json_encode($flag),$id]);
            if($mode==='lost')throw new \RuntimeException('Own fake accepted moderation acknowledgement lost');return['status'=>200,'body'=>['success'=>true]];
        }
        if(preg_match('/\A123_98[0-9]+\z/D',$id)&&array_keys($body??[])===['message']&&is_string($body['message'])){
            \Kulturhus\Native\Shared\Db::run($db,"UPDATE own_human_browser_remote SET payload=JSON_SET(payload,'$.message',?) WHERE id=?",[$body['message'],$id]);
            if($mode==='lost')throw new \RuntimeException('Own accepted post correction acknowledgement lost');return['status'=>200,'body'=>['success'=>true]];
        }
        $sequence=(int)$db->lastInsertId();$sent='own_browser_sent_'.$sequence;$text=$body['message']['text']??($body['message']??'');if(!is_string($text))throw new \RuntimeException('Own human body missing');
        if($id==='123/messages'){$target=$body['recipient']['id'];$sender=isset($body['messaging_type'])?'123':'456';$data=['id'=>$sent,'message'=>$text,'created_time'=>gmdate('Y-m-d\TH:i:sO'),'from'=>['id'=>$sender],'to'=>['data'=>[['id'=>$target]]]];$result=['recipient_id'=>$target,'message_id'=>$sent];}
        elseif(str_ends_with($id,'/comments')||str_ends_with($id,'/replies')){$data=['id'=>$sent,'message'=>$text,'text'=>$text,'can_comment'=>true,'hidden'=>false];$result=['id'=>$sent];}
        else throw new \RuntimeException('Unexpected own fake provider write path');
        \Kulturhus\Native\Shared\Db::run($db,'INSERT INTO own_human_browser_remote VALUES(?,?)',[$sent,json_encode($data,JSON_THROW_ON_ERROR)]);
        if($mode==='lost')throw new \RuntimeException('Own fake accepted provider acknowledgement lost');
        return['status'=>200,'body'=>$result];
    }
}
