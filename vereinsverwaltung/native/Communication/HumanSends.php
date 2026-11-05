<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};

/** A human click prepares one immutable intention and uses the existing provider send guard. */
final class HumanSends
{
    public function __construct(private PDO $db,private Replies $replies){}
    public function installed():bool
    {
        return (bool)Db::run($this->db,"SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='native_social_human_sends'")->fetchColumn();
    }
    public function send(Access $access,string $attention,int $sourceVersion,string $text,string $key,string $draftId='',int $draftVersion=0):array
    {
        if($this->db->inTransaction())throw new DomainException('Direktes Senden benötigt eine eigene Verbindung.');
        $access->require('social','manage');$access->require('social','edit');$user=$access->require('social','publish');
        Content::id($attention);Content::id($key);if($draftId!=='')Content::id($draftId);
        $text=Content::originalText($text,8000,true);
        if(!$this->installed())throw new DomainException('Direktes Senden benötigt das aktuelle installierte Paket.');
        $hash=$this->replies->humanFingerprint($access,json_encode([$attention,$sourceVersion,$text,$draftId,$draftVersion],JSON_THROW_ON_ERROR),'request');
        $reply=Db::atomic($this->db,function()use($access,$user,$attention,$sourceVersion,$text,$key,$draftId,$draftVersion,$hash){
            // Serialize this person's preparation, including exact duplicate browser requests.
            Db::run($this->db,'SELECT id FROM native_users WHERE id=? FOR UPDATE',[$user['id']]);
            $actor=$access->require('social','publish');$access->require('social','edit');$access->require('social','manage');
            $old=Db::run($this->db,'SELECT * FROM native_social_human_sends WHERE club_id=? AND request_key=? FOR UPDATE',[$actor['club_id'],$key])->fetch(PDO::FETCH_ASSOC);
            if($old){
                if($old['actor_id']!==$actor['id']||!hash_equals($old['request_hash'],$hash))throw new DomainException('Sendevorgang gehört zu einer anderen ursprünglichen Nachricht.');
                $reply=$this->replies->get($access,$old['reply_id']);$this->assertReply($access,$reply);return $reply;
            }
            if($draftId!==''&&Db::run($this->db,'SELECT 1 FROM native_social_human_sends WHERE club_id=? AND reply_id=? FOR UPDATE',[$actor['club_id'],$draftId])->fetchColumn())throw new DomainException('Dieser Entwurf gehört bereits zu einem Sendevorgang. Original prüfen.');
            $slot='h'.substr(bin2hex(random_bytes(16)),0,31);
            $reply=$this->replies->prepareHuman($access,$attention,$sourceVersion,$text,$slot,$draftId,$draftVersion);
            Db::run($this->db,'INSERT INTO native_social_human_sends VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$actor['club_id'],$key,$hash,$this->replies->humanFingerprint($access,$text,'text'),$reply['id'],$actor['id'],$reply['payload']['kind']]);
            return $reply;
        });
        // Accepted, uncertain and rejected provider attempts are never automatically replayed.
        if(!in_array($reply['status'],['draft','queued'],true))return $reply;
        try{return $this->replies->send($access,$reply['id'],(int)$reply['version'],true);}
        catch(DomainException $failure){
            $current=$this->replies->get($access,$reply['id']);$this->assertReply($access,$current);
            if(in_array($current['status'],['sending','sent','uncertain','rejected','blocked'],true))return $current;
            throw $failure;
        }
    }
    /** A human command cannot be reinterpreted as an incoming mail or an editable new draft. */
    public function assertReply(Access $access,array $reply):bool
    {
        if(!$this->installed())return false;
        $command=Db::run($this->db,'SELECT actor_id,text_hash FROM native_social_human_sends WHERE club_id=? AND reply_id=?',[$access->club(),$reply['id']])->fetch(PDO::FETCH_ASSOC);
        if(!$command){if(str_starts_with($reply['reply_slot']??'','h'))throw new DomainException('Ursprünglicher menschlicher Sendevorgang fehlt.');return false;}
        $actor=$access->require('social','publish');$access->require('social','edit');$access->require('social','manage');
        if($command['actor_id']!==$actor['id']||!hash_equals($command['text_hash'],$this->replies->humanFingerprint($access,$reply['payload']['text'],'text')))throw new DomainException('Menschlicher Sendevorgang oder ursprünglicher Text geändert.');
        return true;
    }
    private function scope(array $source,string $connection):array
    {
        $where='h.kind=? AND r.channel=? AND r.parent_id=? AND r.connection_id=?';$args=[$source['kind'],$source['channel'],$source['parent_id'],$connection];
        if($source['kind']!=='message'){$where.=' AND r.attention_id=?';$args[]=$source['id'];}
        return[$where,$args];
    }
    public function references(Access $access,array $source,string $connection):array
    {
        $access->require('social','manage');if(!$this->installed())return[];[$where,$args]=$this->scope($source,$connection);
        $rows=Db::run($this->db,'SELECT r.id,r.remote_id,r.status,h.created_at FROM native_social_human_sends h JOIN native_social_replies r ON r.club_id=h.club_id AND r.id=h.reply_id WHERE h.club_id=? AND '.$where.' ORDER BY h.created_at,r.id LIMIT 5001',[$access->club(),...$args])->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>5000)throw new DomainException('Menschlichen Antwortverlauf eingrenzen.');return $rows;
    }
    public function at(Access $access,array $source,string $connection,string $id):array
    {
        $access->require('social','manage');Content::id($id);[$where,$args]=$this->scope($source,$connection);
        $found=Db::run($this->db,'SELECT r.id FROM native_social_human_sends h JOIN native_social_replies r ON r.club_id=h.club_id AND r.id=h.reply_id WHERE h.club_id=? AND r.id=? AND '.$where,[$access->club(),$id,...$args])->fetchColumn();
        if(!$found)throw new DomainException('Eigene menschliche Antwort in diesem Gespräch auswählen.');return $this->replies->get($access,$id);
    }
    public function search(Access $access,array $source,string $connection,string $term,array $originalIds=[]):?array
    {
        $access->require('social','manage');$remote=array_fill_keys($originalIds,true);
        foreach(array_reverse($this->references($access,$source,$connection))as$ref){if($ref['remote_id']&&isset($remote[$ref['remote_id']]))continue;$reply=$this->at($access,$source,$connection,$ref['id']);if(mb_stripos($reply['payload']['text'],$term)!==false)return['id'=>'human:'.$ref['id'],'text'=>$reply['payload']['text']];}
        return null;
    }
}
