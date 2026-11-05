<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;

use DomainException;
use Kulturhus\Native\Chat\{Catalogue,Workspace};

/** Unsaved personal text and its original retry/version token, never a generated answer. */
final class ChatFormState
{
    public static function submitted(array $post):bool
    {return in_array($post['operation']??null,['chat-send','chat-edit','poll-create','poll-vote','poll-close'],true);}

    public static function restore(array $post):array
    {
        $op=$post['operation']??null;$id=$post['id']??null;$body=$post['body']??'';
        if(!self::submitted($post)||!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new DomainException('Eigene Unterhaltung öffnen.');
        if(str_starts_with($op,'poll-'))return self::poll($post,$op,$id);
        if(!is_string($body)||!preg_match('//u',$body)||strlen($body)>80000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$body))throw new DomainException('Nachrichtentext prüfen.');
        $state=['operation'=>$op,'id'=>$id,'body'=>$body,'context'=>Catalogue::state($post),'before'=>isset($post['chat_before'])?self::number($post['chat_before']):PHP_INT_MAX];
        if(is_string($post['chat_target_page']??null)&&strlen($post['chat_target_page'])<=20&&!preg_match('/[\x00-\x1f\x7f]/',$post['chat_target_page']))$state['target_page']=$post['chat_target_page'];
        if($op==='chat-send'){
            $key=$post['request_key']??null;if(!is_string($key))throw new DomainException('Ursprünglichen Sendevorgang öffnen.');Workspace::requestKey($key);$state['request_key']=$key;
            $reply=$post['reply_to']??'';if(!is_string($reply)&&!is_int($reply))throw new DomainException('Antwortbezug prüfen.');$state['reply_to']=$reply===''?null:self::number($reply);
        }else{$state['seq']=self::number($post['seq']??null);$state['version']=self::number($post['version']??null);}
        return $state;
    }
    private static function poll(array $post,string $op,string $id):array
    {
        $state=['operation'=>$op,'id'=>$id,'context'=>Catalogue::state($post),'before'=>isset($post['chat_before'])?self::number($post['chat_before']):PHP_INT_MAX];
        if($op!=='poll-close'){
            $key=$post['request_key']??null;if(!is_string($key))throw new DomainException('Ursprünglichen Umfragevorgang öffnen.');Workspace::requestKey($key);$state['request_key']=$key;
        }
        if($op==='poll-create'){
            foreach(['question'=>8000,'options'=>80000,'closes_at'=>100]as$field=>$limit){
                $value=$post[$field]??'';if(!is_string($value)||strlen($value)>$limit||!preg_match('//u',$value)||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))throw new DomainException('Umfrageeingabe prüfen.');$state[$field]=$value;
            }
        }else{
            $poll=$post['poll_id']??null;if(!is_string($poll)||!preg_match('/\A[a-f0-9]{32}\z/D',$poll))throw new DomainException('Ursprüngliche Umfrage öffnen.');$state['poll_id']=$poll;
            $version=$post['version']??null;$state['version']=$op==='poll-vote'&&in_array($version,[0,'0'],true)?0:self::number($version);
            if($op==='poll-vote'){
                $option=$post['option']??'';if(!is_string($option)&&!is_int($option))throw new DomainException('Umfrageauswahl prüfen.');
                if(strlen((string)$option)>20||preg_match('/[\x00-\x1f\x7f]/',(string)$option))throw new DomainException('Umfrageauswahl prüfen.');$state['option']=(string)$option;
            }
        }
        return$state;
    }
    public static function number(mixed $value):int
    {
        if((!is_string($value)&&!is_int($value))||!preg_match('/\A[1-9][0-9]{0,18}\z/D',(string)$value)||filter_var($value,FILTER_VALIDATE_INT)===false)throw new DomainException('Ursprünglichen Nachrichtenstand prüfen.');return(int)$value;
    }
}
