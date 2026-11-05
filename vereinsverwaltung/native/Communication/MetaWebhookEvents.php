<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;

/** Signed notifications describe affected originals; human content is read from Meta separately. */
final class MetaWebhookEvents
{
    private static function eventHash(array $value):string
    {
        $sort=static function(mixed $entry)use(&$sort):mixed{if(!is_array($entry))return$entry;if(!array_is_list($entry))ksort($entry,SORT_STRING);foreach($entry as$key=>$child)$entry[$key]=$sort($child);return$entry;};return hash('sha256',json_encode($sort($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    }
    public static function tasks(array $body,array $account):array
    {
        $object=$body['object']??null;$entries=$body['entry']??null;
        if(!in_array($object,['page','instagram'],true)||!is_array($entries)||!array_is_list($entries)||count($entries)>100)throw new DomainException('Webhook-Ereignisse prüfen.');
        $tasks=[];
        foreach($entries as$entry){
            if(!is_array($entry)||!is_string($entry['id']??null)||!in_array($entry['id'],array_filter([$account['page_id'],$account['instagram_id']]),true))throw new DomainException('Webhook gehört nicht zum verbundenen Konto.');
            if($object==='instagram'&&($account['instagram_id']===''||$entry['id']!==$account['instagram_id']))throw new DomainException('Instagram-Ereignis gehört nicht zum verbundenen Konto.');
            $channel=$object==='instagram'||$entry['id']===$account['instagram_id']?'instagram':'facebook';
            foreach(['messaging','changes']as$key)if(isset($entry[$key])&&(!is_array($entry[$key])||!array_is_list($entry[$key])||count($entry[$key])>500))throw new DomainException('Webhook-Liste prüfen.');
            foreach($entry['messaging']??[]as$message){
                if(!is_array($message))throw new DomainException('Nachrichtenereignis prüfen.');
                $sender=SocialHub::remoteId($message['sender']['id']??null);$recipient=SocialHub::remoteId($message['recipient']['id']??null);$own=[$account['page_id'],$account['instagram_id']];
                if(!in_array($sender,$own,true)&&!in_array($recipient,$own,true))throw new DomainException('Nachrichtenkonto prüfen.');
                if(in_array($sender,$own,true)&&in_array($recipient,$own,true))continue;
                if(!array_intersect(['message','message_edit','reaction','read','delivery','postback'],array_keys($message)))continue;
                $peer=in_array($sender,$own,true)?$recipient:$sender;
                $messageChannel=in_array($account['instagram_id'],[$sender,$recipient],true)?'instagram':$channel;
                $mid=$message['message']['mid']??($message['message_edit']['mid']??($message['reaction']['mid']??null));
                $tasks[]=['kind'=>'message','channel'=>$messageChannel,'target'=>$peer,'message_id'=>$mid===null?null:SocialHub::remoteId($mid),'event_hash'=>self::eventHash($message)];
            }
            foreach($entry['changes']??[]as$change){
                if(!is_array($change)||!is_string($change['field']??null)||!is_array($change['value']??null))throw new DomainException('Beitragsereignis prüfen.');$value=$change['value'];$field=$change['field'];
                if($field==='feed'&&in_array($value['item']??null,['comment','reaction','like'],true)){$target=SocialHub::remoteId($value['post_id']??null);$kind=$value['item']==='comment'?'comment':'metric';}
                elseif($field==='comments'&&$channel==='instagram'){$target=SocialHub::remoteId($value['media']['id']??null);$kind='comment';}
                else continue;
                $tasks[]=['kind'=>$kind,'channel'=>$channel,'target'=>$target,'event_hash'=>self::eventHash($change)];
                if($kind==='comment')$tasks[]=['kind'=>'metric','channel'=>$channel,'target'=>$target,'event_hash'=>self::eventHash($change)];
            }
            if(count($tasks)>500)throw new DomainException('Zu viele Webhook-Ereignisse.');
        }
        return$tasks;
    }
}
