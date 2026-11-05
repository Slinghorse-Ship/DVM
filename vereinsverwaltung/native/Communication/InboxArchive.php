<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use DomainException;

/** Current provider reads retain original earlier messages and previous exact versions. */
final class InboxArchive
{
    public static function merge(array $old,array $current):array
    {
        $same=true;foreach(['page_id','instagram_id','connection_id','conversation','recipient']as$key)$same=$same&&($old[$key]??null)===($current[$key]??null);
        $current['connection_history']=$old['connection_history']??[];
        if(!$same){if(count($current['connection_history'])>=100)throw new DomainException('Verbindungsverlauf erreicht; bisheriger Stand bleibt erhalten.');$current['connection_history'][]=array_diff_key($old,['connection_history'=>true]);return $current;}
        $messages=[];foreach($old['messages']as$message)$messages[$message['id']]=$message;
        $versions=$old['message_versions']??[];
        foreach($current['messages']as$message){
            $previous=$messages[$message['id']]??null;
            $changed=$previous&&count($previous)!==count($message);
            if($previous)foreach($previous as$key=>$value)$changed=$changed||!array_key_exists($key,$message)||$message[$key]!==$value;
            if($previous&&$changed){$versions[$message['id']][]=$previous;if(count($versions[$message['id']])>100)throw new DomainException('Originalverlauf erreicht; bisheriger Stand bleibt erhalten.');}
            $messages[$message['id']]=$previous&&!$changed?$previous:$message;
        }
        if(count($messages)>5000)throw new DomainException('Mehr als 5000 gespeicherte Originalnachrichten; bisheriger Stand bleibt erhalten.');
        $current['messages']=array_values($messages);usort($current['messages'],static fn($a,$b)=>[$a['created_at'],$a['id']]<=>[$b['created_at'],$b['id']]);$current['message_versions']=$versions;
        return $current;
    }
}
