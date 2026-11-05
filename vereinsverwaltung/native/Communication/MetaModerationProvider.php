<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;
use Kulturhus\Native\Integration\{JsonHttp,MetaRejected};

/** Fixed comment endpoints; each change is independently read back. */
final class MetaModerationProvider
{
    private \Closure $http;
    public function __construct(?callable $http=null)
    {$this->http=$http?\Closure::fromCallable($http):static fn($m,$p,$h,$b)=>JsonHttp::request('meta',$m,$p,$h,$b);}
    private function call(array $account,string $method,string $path,?array $body=null):array
    {
        $result=($this->http)($method,'/'.$account['api_version'].'/'.$path,['Authorization: Bearer '.$account['token']],$body);
        if($method==='POST'&&($result['status']??0)>=400&&($result['status']??0)<500&&isset($result['body']['error']))throw new MetaRejected('Meta hat die Moderation abgelehnt.');
        if(($result['status']??0)<200||($result['status']??0)>=300||!is_array($result['body']??null)||isset($result['body']['error']))throw new DomainException('Moderationsergebnis nicht bestätigt.');
        return$result['body'];
    }
    public function read(array $account,array $source):bool
    {
        $facebook=$source['channel']==='facebook';$id=SocialHub::remoteId($source['payload']['remote_id']);
        $current=$this->call($account,'GET',$id.'?fields='.($facebook?'id,message,is_hidden':'id,text,hidden'));
        $hidden=$current[$facebook?'is_hidden':'hidden']??null;
        if(($current['id']??null)!==$id||!is_bool($hidden)||Content::text($current[$facebook?'message':'text']??null,20000)!==$source['payload']['text'])throw new DomainException('Kommentar beim Anbieter geändert oder nicht bestätigt.');
        return$hidden;
    }
    public function set(array $account,array $source,bool $hidden):void
    {
        $result=$this->call($account,'POST',SocialHub::remoteId($source['payload']['remote_id']),[$source['channel']==='facebook'?'is_hidden':'hide'=>$hidden]);
        if(($result['success']??null)!==true)throw new DomainException('Moderationsergebnis nicht bestätigt.');
    }
}
