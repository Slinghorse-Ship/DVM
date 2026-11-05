<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use PDO;
use DomainException;
use Kulturhus\Native\Shared\{Access,Db,SecretBox};
use Kulturhus\Native\Integration\{Accounts,JsonHttp,MetaRejected};

/** Facebook Login/Page-token messaging; no unsolicited messages or window extensions. */
final class MessageReplyProvider implements ReplyProvider
{
    private \Closure $http;
    public function __construct(private PDO $db,private SecretBox $box,?callable $http=null)
    {
        $this->http=$http?\Closure::fromCallable($http):static fn($method,$path,$headers,$body)=>JsonHttp::request('meta',$method,$path,$headers,$body);
    }
    public static function threadId(string $club,string $channel,string $conversation):string
    {
        return substr(hash('sha256',$club.':'.$channel.':'.SocialHub::remoteId($conversation)),0,32);
    }
    public static function validateText(string $channel,string $text):void
    {
        if(!in_array($channel,['facebook','instagram'],true))throw new DomainException('Nachrichtenkanal prüfen.');
        if($channel==='instagram'&&(strlen($text)>1000||mb_strlen($text)>=1000))throw new DomainException('Instagram-Antwort auf höchstens 1000 UTF-8-Bytes begrenzen.');
        if(mb_strlen($text)>2000)throw new DomainException('Nachrichtenantwort auf höchstens 2000 Zeichen begrenzen.');
    }
    public function conversation(Access $access,string $id):array
    {
        $access->require('social','manage');Content::id($id);
        $row=Db::run($this->db,'SELECT * FROM native_social_inbox WHERE club_id=? AND id=?'.($this->db->inTransaction()?' FOR SHARE':''),[$access->club(),$id])->fetch();
        if(!$row)throw new DomainException('Gespräch nicht mehr verfügbar.');
        $row['payload']=$this->box->open('social-inbox:'.$access->club().':'.$id,$row['ciphertext']);unset($row['ciphertext']);
        return $row;
    }
    public function account(Access $access,array $source,?string $binding=null):array
    {
        $access->require('social','manage');
        $channel=$source['channel'];
        if(!in_array($channel,['facebook','instagram'],true))throw new DomainException('Nachrichtenkanal prüfen.');
        $account=(new Accounts($this->db,$this->box))->get($access->club(),'meta');
        $connection=Accounts::binding($account);
        if($binding!==null&&!hash_equals($binding,$connection))throw new DomainException('Antwort gehört zu einer früheren Meta-Verbindung.');
        $thread=$this->conversation($access,self::threadId($access->club(),$channel,$source['parent_id']));
        $payload=$thread['payload'];
        foreach(['page_id','instagram_id']as$key)if(($payload[$key]??null)!==$account[$key])throw new DomainException('Gespräch mit der aktuellen Meta-Verbindung abgleichen.');
        if(($payload['connection_id']??null)!==$connection||($payload['conversation']??null)!==$source['parent_id']||$thread['channel']!==$channel)throw new DomainException('Gespräch mit der aktuellen Meta-Verbindung abgleichen.');
        $original=$source['payload']['original']??$source['payload']??[];
        $recipient=SocialHub::remoteId($original['sender_id']??null);
        if($recipient!==($payload['recipient']??null)||in_array($recipient,[$account['page_id'],$account['instagram_id']],true))throw new DomainException('Ursprünglichen Absender erneut abgleichen.');
        if($channel==='instagram'&&$account['instagram_id']==='')throw new DomainException('Instagram-Konto fehlt.');
        return $account+['_reply_binding'=>$connection,'_recipient'=>$recipient,'_sender'=>$channel==='facebook'?$account['page_id']:$account['instagram_id'],'_conversation'=>$source['parent_id']];
    }
    private function call(array $account,string $method,string $path,?array $body=null):array
    {
        $response=($this->http)($method,'/'.$account['api_version'].'/'.$path,['Authorization: Bearer '.$account['token']],$body);
        $status=$response['status']??0;$result=$response['body']??null;
        if($method==='POST'&&in_array($status,[400,401,403,404,405,410,413,415,422],true)&&is_array($result['error']??null)&&($result['error']['is_transient']??false)!==true&&!in_array($result['error']['code']??null,[1,2,4,17,32,613],true))throw new MetaRejected('Nachricht vom Anbieter abgelehnt.');
        if($status<200||$status>=300||!is_array($result)||isset($result['error']))throw new DomainException('Anbieterergebnis nicht bestätigt.');
        return $result;
    }
    private static function time(mixed $value):?string
    {
        if(!is_string($value))return null;
        foreach(['Y-m-d H:i:s','Y-m-d\TH:i:sP','Y-m-d\TH:i:sO','Y-m-d\TH:i:s\Z']as$format){
            $date=\DateTimeImmutable::createFromFormat('!'.$format,$value,new \DateTimeZone('UTC'));
            $errors=\DateTimeImmutable::getLastErrors();
            if($date&&($errors===false||($errors['warning_count']===0&&$errors['error_count']===0))&&$date->format($format)===$value)return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        return null;
    }
    private static function addressed(array $message,string $from,string $to):bool
    {
        $targets=$message['to']['data']??null;
        return ($message['from']['id']??null)===$from&&is_array($targets)&&array_is_list($targets)&&count($targets)===1&&($targets[0]['id']??null)===$to;
    }
    public function current(Access $access,array $account,array $source,array $row):void
    {
        $original=$row['payload']['original'];
        $identity=$this->call($account,'GET','me?fields=id'.($row['channel']==='instagram'?',instagram_business_account':''));
        if(($identity['id']??null)!==$account['page_id']||($row['channel']==='instagram'&&($identity['instagram_business_account']['id']??null)!==$account['instagram_id']))throw new DomainException('Zugang gehört nicht zum gespeicherten Nachrichtenkonto.');
        $conversation=$this->call($account,'GET',rawurlencode(SocialHub::remoteId($source['parent_id'])).'?fields=id,participants,is_owner');
        $participants=$conversation['participants']['data']??null;
        if(($conversation['id']??null)!==$source['parent_id']||(array_key_exists('is_owner',$conversation)&&$conversation['is_owner']!==true)||!is_array($participants)||!array_is_list($participants)||count($participants)!==2)throw new DomainException('Gespräch ist diesem Zugang nicht zur Antwort zugeordnet.');
        $ids=array_column($participants,'id');sort($ids);$expected=[$account['_recipient'],$account['_sender']];sort($expected);
        if($ids!==$expected)throw new DomainException('Gesprächsteilnehmer haben sich geändert.');
        $message=$this->call($account,'GET',rawurlencode(SocialHub::remoteId($original['remote_id'])).'?fields=id,created_time,from,to,message');
        $date=self::time($message['created_time']??null);
        if(($message['id']??null)!==$original['remote_id']||($message['message']??null)!==$original['text']||!self::addressed($message,$account['_recipient'],$account['_sender'])||$date!==($original['created_at']??null))throw new DomainException('Originalnachricht geändert oder nicht mehr lesbar. Erneut abgleichen.');
        self::window($date);
        $this->account($access,$source,$account['_reply_binding']);
    }
    private static function window(?string $date):void
    {
        if($date===null||$date<=gmdate('Y-m-d H:i:s',time()-86400)||$date>gmdate('Y-m-d H:i:s',time()+30))throw new DomainException('Das 24-Stunden-Antwortfenster ist geschlossen.');
    }
    public function publish(Access $access,array $account,array $source,array $row):string
    {
        self::window(self::time($row['payload']['original']['created_at']??null));
        self::validateText($row['channel'],$row['payload']['text']);
        $this->account($access,$source,$account['_reply_binding']);$access->require('social','publish');
        $body=['recipient'=>['id'=>$account['_recipient']],'message'=>['text'=>$row['payload']['text']]];
        if($row['channel']==='facebook')$body['messaging_type']='RESPONSE';
        $result=$this->call($account,'POST',$account['page_id'].'/messages',$body);
        if(($result['recipient_id']??null)!==$account['_recipient'])throw new DomainException('Anbieter hat einen anderen Nachrichtenempfänger bestätigt.');
        return SocialHub::remoteId($result['message_id']??null);
    }
    public function proof(Access $access,array $account,array $row,string $remote):bool
    {
        $this->account($access,$row,$account['_reply_binding']);
        $message=$this->call($account,'GET',rawurlencode(SocialHub::remoteId($remote)).'?fields=id,created_time,from,to,message');
        $date=self::time($message['created_time']??null);
        $started=$row['payload']['sending_at']??null;
        $confirmed=($message['id']??null)===$remote&&($message['message']??null)===$row['payload']['text']&&self::addressed($message,$account['_sender'],$account['_recipient'])&&$date!==null&&is_string($started)&&$date>=$started&&$date<=gmdate('Y-m-d H:i:s',time()+30);
        $this->account($access,$row,$account['_reply_binding']);$access->require('social','publish');
        return $confirmed;
    }
}
