<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use DomainException;
use Kulturhus\Native\Communication\{Content,Replies,MessageReplyProvider};
use Kulturhus\Native\Shared\Access;

/** Retain the entered text and exact send intention; restoring a form never publishes. */
final class HumanFormState
{
    public static function submitted(array $post):bool
    {
        return ($post['operation']??null)==='social-reply-direct'||(($post['operation']??null)==='social-reply-save'&&($post['human_send']??null)==='1')||(in_array($post['operation']??null,['social-comment-moderate','social-comment-moderation-check','social-comment-moderation-close'],true)&&array_key_exists('reply_text',$post));
    }
    public static function restore(array $post):array
    {
        $out=[];foreach(['attention_id','human_key','reply_id','reply_text']as$key){$value=$post[$key]??'';if(!is_string($value))throw new DomainException('Menschliche Antwort erneut auswählen.');$out[$key]=$value;}
        Content::id($out['attention_id']);Content::id($out['human_key']);if($out['reply_id']!=='')Content::id($out['reply_id']);
        if(strlen($out['reply_text'])>8000||!mb_check_encoding($out['reply_text'],'UTF-8'))throw new DomainException('Antwortlänge und Zeichen prüfen.');
        foreach(['attention_version','reply_version']as$key){$value=$post[$key]??'0';if(!is_string($value)&&!is_int($value))throw new DomainException('Ursprüngliche Antwortfassung auswählen.');if(!preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/D',(string)$value))throw new DomainException('Ursprüngliche Antwortfassung auswählen.');$out[$key]=(int)$value;}
        return$out;
    }
    public static function route(Access $access,array $source):array
    {
        return $source['kind']==='message'?['section'=>'inbox','thread'=>MessageReplyProvider::threadId($access->club(),$source['channel'],$source['parent_id'])]:['section'=>'replies','attention'=>$source['id']];
    }
    public static function review(Access $access,Replies $service,array $post):array
    {
        $access->require('social','manage');$access->require('social','edit');$access->require('social','publish');$state=self::restore($post);$source=$service->source($access,$state['attention_id']);$service->connection($access,$source['id']);
        $state['attention_version']=(int)$source['version'];$state['human_key']=bin2hex(random_bytes(16));$state['reply_id']='';$state['reply_version']=0;
        return ['view'=>'social','compose_state'=>['human'=>$state]]+self::route($access,$source);
    }
    public static function correction(\PDO $db,Access $access,string $mediaRoot,string $csrf,bool $demo,array $post,string $error):string
    {
        $access->require('social','manage');$access->require('social','edit');$state=self::restore($post);$service=new Replies($db,\Kulturhus\Native\Shared\SecretBox::fromFile(getenv('KH_NATIVE_PRIVATE_KEY_FILE')?:dirname(__DIR__,4).'/.local/native-cms-integration/private.key'));$source=$service->source($access,$state['attention_id']);
        $_GET=array_replace($_GET,self::route($access,$source));unset($_GET['reply']);$state['error']=$error;
        $manager=new \Kulturhus\Native\Web\Manager($db,$access,$mediaRoot,$csrf,$demo);
        return $manager->shell($manager->main('social','',PHP_INT_MAX,['human'=>$state]));
    }
}
