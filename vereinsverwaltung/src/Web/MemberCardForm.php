<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;
use Kulturhus\Native\Web\Html;
use Kulturhus\Verein\MemberCards;

/** Original human input and hidden command identity survive refusals without a new issue. */
final class MemberCardForm
{
    public const ACTIONS=['member-card-issue','member-card-revoke','member-card-recheck'];
    public static function number(mixed $value,int $minimum=0):int
    {
        if(!is_string($value)||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$value)||filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>$minimum,'max_range'=>4294967295]])===false)throw new DomainException('Ausweisangaben erneut prüfen.',422);return(int)$value;
    }
    public static function flag(mixed $value):bool{if(!is_string($value)||!in_array($value,['0','1'],true))throw new DomainException('Bestätigung zum Mitgliederausweis prüfen.',422);return$value==='1';}
    public static function text(mixed $value,int $max=500):string{if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||mb_strlen($value)>$max||preg_match('/[\x00-\x1f\x7f]/',$value))throw new DomainException('Ausweisangaben erneut prüfen.',422);return$value;}
    public static function id(mixed $value):string{if(!is_string($value)||!preg_match('/\A[a-f0-9]{32}\z/D',$value))throw new DomainException('Ausweisangaben erneut prüfen.',422);return$value;}
    public static function fingerprint(mixed $value):string{if(!is_string($value)||!preg_match('/\A[a-f0-9]{64}\z/D',$value))throw new DomainException('Ausweisangaben erneut prüfen.',422);return$value;}
    public static function frame(array $query,string $contact,array $post=[]):void
    {
        if(($query['view']??null)!=='portal'||($query['tab']??null)!=='card'||array_diff(array_keys($query),['view','tab','person','card_page'])!==[]||isset($query['person'])&&(!is_string($query['person'])||$query['person']!==$contact))throw new DomainException('Ausweis im ursprünglich geöffneten Mitgliederprofil prüfen.',422);
        if($post&&(!in_array($post['action']??null,self::ACTIONS,true)||!is_string($post['contact_id']??null)||$post['contact_id']!==$contact))throw new DomainException('Ausweis im ursprünglich geöffneten Mitgliederprofil prüfen.',422);
    }
    public static function prepare(MemberCards $cards,string $contact,array $post=[]):array
    {
        $state=$cards->state($contact);$isIssue=in_array($post['action']??null,['member-card-issue','member-card-recheck'],true);$isRevoke=($post['action']??null)==='member-card-revoke';$source=$state['source'];$until=$state['latest_valid_until'];
        $issue=['version'=>(string)$state['version'],'source_hash'=>$source['fingerprint']??'','request_id'=>bin2hex(random_bytes(16)),'valid_until'=>$until,'public_name'=>'0'];$revoke=['version'=>(string)$state['version'],'request_id'=>bin2hex(random_bytes(16)),'card_id'=>$state['head']['card_id']??'','reason'=>''];
        foreach($isIssue?array_keys($issue):[]as$key)$issue[$key]=$post[$key]??null;foreach($isRevoke?array_keys($revoke):[]as$key)$revoke[$key]=$post[$key]??null;$issueStale=false;$revokeStale=false;$saved=null;
        if($isIssue)try{$version=self::number($issue['version']);$hash=self::fingerprint($issue['source_hash']);$request=self::id($issue['request_id']);$saved=$cards->commandResult($contact,$request,'issue');$issueStale=$version!==$state['version']||!$source||!hash_equals($source['fingerprint'],$hash);}catch(DomainException $e){if(in_array($e->getCode(),[403,404,503],true))throw$e;$issueStale=true;}
        if($isRevoke)try{$version=self::number($revoke['version'],1);$id=self::id($revoke['card_id']);$request=self::id($revoke['request_id']);$saved=$cards->commandResult($contact,$request,'revoke');$revokeStale=$version!==$state['version']||($state['head']['card_id']??null)!==$id||!empty($state['head']['revoked']);}catch(DomainException $e){if(in_array($e->getCode(),[403,404,503],true))throw$e;$revokeStale=true;}
        return compact('state','issue','revoke','issueStale','revokeStale','saved');
    }
    public static function act(MemberCards $cards,string $contact,array $query,array $post):array
    {
        self::frame($query,$contact,$post);$action=$post['action'];$request=self::id($post['request_id']??null);$known=$cards->commandResult($contact,$request,$action==='member-card-revoke'?'revoke':'issue');if($known!==null)return['result'=>$known,'reused'=>true];$version=self::number($post['version']??null);
        if($action==='member-card-issue')return['result'=>$cards->issue($contact,$version,self::fingerprint($post['source_hash']??null),self::text($post['valid_until']??null,10),$request,self::flag($post['public_name']??null))];
        if($action==='member-card-revoke')return['result'=>$cards->revoke($contact,$version,self::id($post['card_id']??null),$request,self::text($post['reason']??null))];
        self::fingerprint($post['source_hash']??null);self::text($post['valid_until']??null,10);self::flag($post['public_name']??null);$state=$cards->state($contact);if(!$state['source'])throw new DomainException($state['notice']?:'Aktuelle Mitgliedschaft für den Ausweis prüfen.',409);
        return['draft'=>array_replace($post,['action'=>'member-card-issue','version'=>(string)$state['version'],'source_hash'=>$state['source']['fingerprint']])];
    }
    public static function hidden(string $name,mixed $value):string
    {
        if(is_array($value)){$h='';foreach($value as$key=>$part)$h.=self::hidden($name.'['.$key.']',$part);return$h;}
        return Html::hidden($name,is_scalar($value)?(string)$value:'');
    }
}
