<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication\Mail;

use PDO;
use DomainException;
use Kulturhus\Native\Shared\{Access,Authority,Db,SecretBox};
use Kulturhus\Native\Communication\{Content,Replies};

/** Human mail forwarding, exact return association and optional immediate social delivery. */
final class Bridge
{
    private \Closure $sender;
    private \Closure $reader;
    private bool $realSender;
    public function __construct(private PDO $db,private SecretBox $box,?callable $sender=null,?callable $reader=null,private ?Replies $replies=null)
    {
        $this->realSender=$sender===null;
        $this->sender=$sender?\Closure::fromCallable($sender):fn($club,$config,$message)=>Transport::send($this->db,$this->box,$club,$config,$message);
        $this->reader=$reader?\Closure::fromCallable($reader):Transport::read(...);
    }
    private function configured(Access $access,?string $binding=null):array
    {
        $data=(new Settings($this->db,$this->box))->configuration($access)['data'];
        if(!$data['enabled']||($binding!==null&&!hash_equals($binding,$data['binding'])))throw new DomainException('Mailmodul ausgeschaltet oder Ziel inzwischen geändert.');
        $owner=Authority::resolve($this->db,['created_by'=>$data['actor'],'authority'=>$data['authority'],'club_id'=>$access->club()]);
        $owner->require('website','manage');$owner->require('social','manage');
        if($owner->club()!==$access->club())throw new DomainException('Mailmodul gehört zu einem anderen Verein.');
        return $data;
    }
    private function source(Access $access,string $id,int $version,?string $fingerprint=null,?string $connection=null):array
    {
        $service=new Replies($this->db,$this->box);$source=$service->source($access,$id);
        if((int)$source['version']!==$version||($fingerprint!==null&&!hash_equals($fingerprint,$source['fingerprint'])))throw new DomainException('Original inzwischen geändert. Neu öffnen.');
        $current=$service->connection($access,$id);
        if($connection!==null&&!hash_equals($current,$connection))throw new DomainException('Ursprüngliche Anbieterbindung geändert.');
        return $source+['connection'=>$current];
    }
    public function get(Access $access,string $id,bool $lock=false):array
    {
        $access->require('social','manage');Content::id($id);
        $row=Db::run($this->db,'SELECT * FROM native_social_mail_threads WHERE club_id=? AND id=?'.($lock?' FOR UPDATE':''),[$access->club(),$id])->fetch();
        if(!$row)throw new DomainException('Mailweiterleitung nicht verfügbar.');
        $row['payload']=$this->box->open('social-mail-thread:'.$access->club().':'.$id,$row['ciphertext']);unset($row['ciphertext'],$row['message_hash']);return $row;
    }
    public function prepare(Access $access,string $attention,int $sourceVersion,string $note,string $id='',int $version=0):array
    {
        $access->require('social','edit');$note=Content::originalText($note,2000);
        return Db::atomic($this->db,function()use($access,$attention,$sourceVersion,$note,$id,$version):array{
            $actor=$access->require('social','edit');$config=$this->configured($access);$source=$this->source($access,$attention,$sourceVersion);$club=$access->club();
            $old=$id!==''?$this->get($access,$id,true):null;
            if($old&&((int)$old['version']!==$version||!in_array($old['status'],['prepared','blocked'],true)||$old['attention_id']!==$attention||$old['source_fingerprint']!==$source['fingerprint']||$old['connection_id']!==$source['connection']||$old['binding']!==$config['binding']))throw new DomainException('Mailfassung inzwischen geändert oder Versand begonnen.');
            if(!$old&&$version!==0)throw new DomainException('Mailfassung erneut öffnen.');
            if(!$old&&Db::run($this->db,'SELECT 1 FROM native_social_mail_threads WHERE club_id=? AND attention_id=? AND source_fingerprint=? AND binding=? AND connection_id=? FOR UPDATE',[$club,$attention,$source['fingerprint'],$config['binding'],$source['connection']])->fetchColumn())throw new DomainException('Für dieses Original besteht bereits eine Mailweiterleitung.');
            $id=$old['id']??bin2hex(random_bytes(16));$messageId=$old['payload']['message_id']??'<'.bin2hex(random_bytes(24)).'@'.substr(strrchr($config['reply_to'],'@'),1).'>';
            $payload=['original'=>$source,'note'=>$note,'target'=>$config['target'],'reply_to'=>$config['reply_to'],'message_id'=>$messageId];
            Db::run($this->db,"INSERT INTO native_social_mail_threads(club_id,id,attention_id,kind,channel,parent_id,source_fingerprint,connection_id,binding,message_hash,status,version,ciphertext,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,'prepared',?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE status='prepared',version=VALUES(version),ciphertext=VALUES(ciphertext),updated_at=UTC_TIMESTAMP()",[$club,$id,$attention,$source['kind'],$source['channel'],$source['parent_id'],$source['fingerprint'],$source['connection'],$config['binding'],$this->box->fingerprint($club.':'.$messageId),$version+1,$this->box->seal('social-mail-thread:'.$club.':'.$id,$payload),$actor['id']]);
            return $this->get($access,$id);
        });
    }
    public static function envelope(array $row):array
    {
        $p=$row['payload'];$source=$p['original'];$platform=['facebook'=>'Facebook','instagram'=>'Instagram','stadtlandfunk'=>'StadtLand.Funk'][$source['channel']];$kind=$source['kind']==='message'?'Nachricht':'Kommentar';
        $original=$source['payload'];$subject=$kind.' · '.$platform.($original['title']!==''?' · '.mb_substr($original['title'],0,150):'');
        $text=($p['note']!==''?$p['note']."\n\n":'').$kind.' · '.$platform."\n".$original['name']."\n".$original['created_at']."\n\n".$original['text'];
        return ['to'=>$p['target'],'subject'=>$subject,'text'=>$text,'html'=>'<div style="white-space:pre-wrap">'.htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</div>','message_id'=>$p['message_id']];
    }
    public function send(Access $access,string $id,int $version,bool $confirm):array
    {
        $access->require('social','publish');
        if(!$confirm)throw new DomainException('Mailweiterleitung bewusst bestätigen.');
        if($this->db->inTransaction())throw new DomainException('Mailversand benötigt eine eigene Transaktion.');
        $before=$this->get($access,$id);
        if($before['status']!=='prepared'||(int)$before['version']!==$version)throw new DomainException('Mailfassung geändert oder Versand bereits begonnen.');
        $club=$access->club();
        if($this->realSender)Transport::ready($this->db,$this->box,$club);
        Db::atomic($this->db,function()use($access,$id,$version):void{
            $row=$this->get($access,$id,true);$this->configured($access,$row['binding']);
            if($row['status']!=='prepared'||(int)$row['version']!==$version)throw new DomainException('Mailfassung geändert oder bereits versendet.');
            $this->source($access,$row['attention_id'],(int)$row['payload']['original']['version'],$row['source_fingerprint'],$row['connection_id']);
            $actor=$access->require('social','publish');
            Db::run($this->db,"UPDATE native_social_mail_threads SET status='sending',version=version+1,sent_by=?,updated_at=UTC_TIMESTAMP() WHERE club_id=? AND id=?",[$actor['id'],$access->club(),$id]);
        });
        $started=false;
        try{return Db::atomic($this->db,function()use($access,$id,$version,&$started):array{
            $row=$this->get($access,$id,true);$config=$this->configured($access,$row['binding']);
            if($row['status']!=='sending'||(int)$row['version']!==$version+1)throw new DomainException('Mailversand bereits bearbeitet.');
            $this->source($access,$row['attention_id'],(int)$row['payload']['original']['version'],$row['source_fingerprint'],$row['connection_id']);$access->require('social','publish');
            $started=true;$result=($this->sender)($access->club(),$config,self::envelope($row));
            if(!is_string($result)||!hash_equals($row['payload']['message_id'],$result))throw new DomainException('SMTP-Annahme nicht eindeutig bestätigt.');
            $this->configured($access,$row['binding']);$access->require('social','publish');
            Db::run($this->db,"UPDATE native_social_mail_threads SET status='accepted',version=version+1,updated_at=UTC_TIMESTAMP() WHERE club_id=? AND id=?",[$access->club(),$id]);
            return $this->get($access,$id);
        });}catch(\Throwable){
            // The committed intent survives a rollback and cannot be replayed.
            Db::run($this->db,"UPDATE native_social_mail_threads SET status=?,version=version+1,updated_at=UTC_TIMESTAMP() WHERE club_id=? AND id=? AND status='sending'",[$started?'uncertain':'blocked',$club,$id]);
            if(!$started)throw new DomainException('Mailversand vor dem Anbieterauftrag gestoppt.');
            return $this->get($access,$id);
        }
    }
    public function history(Access $access,array $source):array
    {
        $access->require('social','manage');
        $ids=Db::run($this->db,'SELECT id FROM native_social_mail_threads WHERE club_id=? AND kind=? AND channel=? AND '.($source['kind']==='message'?'parent_id=?':'attention_id=?').' ORDER BY updated_at DESC,id DESC LIMIT 10',[$access->club(),$source['kind'],$source['channel'],$source['kind']==='message'?$source['parent_id']:$source['id']])->fetchAll(PDO::FETCH_COLUMN);$out=[];
        foreach($ids as$id){$row=$this->get($access,$id);$row['answers']=[];$answers=Db::run($this->db,'SELECT id FROM native_social_mail_replies WHERE club_id=? AND thread_id=? ORDER BY created_at DESC,id DESC LIMIT 5',[$access->club(),$id])->fetchAll(PDO::FETCH_COLUMN);foreach(array_reverse($answers)as$answer)$row['answers'][]=$this->answer($access,$answer);$out[]=$row;}
        return array_reverse($out);
    }
    public function answer(Access $access,string $id):array
    {
        $access->require('social','manage');Content::id($id);
        $row=Db::run($this->db,'SELECT * FROM native_social_mail_replies WHERE club_id=? AND id=?',[$access->club(),$id])->fetch();
        if(!$row)throw new DomainException('Mailantwort nicht verfügbar.');
        $row['payload']=$this->box->open('social-mail-answer:'.$access->club().':'.$id,$row['ciphertext']);unset($row['ciphertext'],$row['message_hash']);
        $row['delivery']=Db::run($this->db,'SELECT state,reply_id,reason FROM native_social_mail_dispatch WHERE club_id=? AND mail_id=?',[$access->club(),$id])->fetch()?:null;
        return $row;
    }
    public function useAnswer(Access $access,string $answer,string $attention,int $sourceVersion,string $text,string $reply='',int $replyVersion=0):array
    {
        $access->require('social','edit');
        return Db::atomic($this->db,function()use($access,$answer,$attention,$sourceVersion,$text,$reply,$replyVersion):array{
            $mail=$this->answer($access,$answer);if($mail['delivery'])throw new DomainException('Diese Mailantwort gehört zum direkten Versandauftrag.');
            $thread=$this->get($access,$mail['thread_id']);$this->configured($access,$thread['binding']);
            if($thread['attention_id']!==$attention)throw new DomainException('Mailantwort gehört zu einem anderen Original.');
            $this->source($access,$attention,$sourceVersion,$thread['source_fingerprint'],$thread['connection_id']);
            // Native HTML forms serialize textarea line endings as CRLF. Restore
            // the original MIME text when the human has not edited its content.
            $lines=static fn(string $value)=>str_replace(["\r\n","\r"],"\n",$value);
            if($lines($text)===$lines($mail['payload']['text']))$text=$mail['payload']['text'];
            return (new Replies($this->db,$this->box))->save($access,$attention,$sourceVersion,$text,$reply,$replyVersion);
        });
    }
    public function fetch(Access $access):array
    {
        if($this->db->inTransaction())throw new DomainException('Postfachabruf benötigt eine eigene Transaktion.');
        $config=$this->configured($access);if(!$config['receive_enabled'])return ['status'=>'disabled','new'=>0,'existing'=>0,'ignored'=>0,'failed'=>0];
        $club=$access->club();$lock=Settings::lock($club);
        if((int)Db::run($this->db,'SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1)throw new DomainException('Antwortpostfach wird bereits abgerufen.');
        try{
            $cursor=Db::run($this->db,'SELECT * FROM native_social_mail_cursors WHERE club_id=? AND binding=?',[$club,$config['binding']])->fetch();
            $batch=($this->reader)($config,(int)($cursor['uidvalidity']??0),(int)($cursor['last_uid']??0));
            if(!is_int($batch['uidvalidity']??null)||$batch['uidvalidity']<1||!is_array($batch['messages']??null)||!array_is_list($batch['messages'])||count($batch['messages'])>25)throw new DomainException('Postfachergebnis nicht vollständig.');
            $result=Db::atomic($this->db,function()use($access,$club,$config,$cursor,$batch):array{
                $this->configured($access,$config['binding']);$result=['status'=>'checked','new'=>0,'existing'=>0,'ignored'=>0,'failed'=>0];
                $last=(int)($batch['uidvalidity']===(int)($cursor['uidvalidity']??0)?($cursor['last_uid']??0):0);
                foreach($batch['messages']as$entry){
                    if(!is_array($entry)||!is_int($entry['uid']??null)||$entry['uid']<=$last)throw new DomainException('Postfachreihenfolge nicht eindeutig.');$last=$entry['uid'];
                    try{
                        if(!empty($entry['error'])||!is_string($entry['original']??null))throw new DomainException('Originalmail nicht lesbar.');
                        $message=Mime::parse($entry['original']);
                        if($message['automatic']||$message['from']!==$config['target']||!in_array($config['reply_to'],$message['to'],true)){$result['ignored']++;continue;}
                        // The immediate, uniquely known parent determines the
                        // conversation even when older References span threads.
                        // Multiple immediate parents remain deliberately ambiguous.
                        if(count($message['in_reply_to'])>1){$result['ignored']++;continue;}
                        $threads=$this->referencedThreads($club,$config['binding'],$message['in_reply_to']);
                        if(!$threads)$threads=$this->referencedThreads($club,$config['binding'],$message['references']);
                        if(count($threads)!==1){$result['ignored']++;continue;}$thread=array_key_first($threads);$hash=$this->box->fingerprint($club.':'.$message['message_id']);
                        $old=Db::run($this->db,'SELECT thread_id,ciphertext,id FROM native_social_mail_replies WHERE club_id=? AND message_hash=? FOR UPDATE',[$club,$hash])->fetch();
                        if($old){$previous=$this->box->open('social-mail-answer:'.$club.':'.$old['id'],$old['ciphertext']);if($old['thread_id']!==$thread||$previous['original']!==$message['original'])throw new DomainException('Mailkennung mit anderem Original wiederverwendet.');$result['existing']++;continue;}
                        $id=bin2hex(random_bytes(16));
                        if($config['auto_send'])$message+=['send_actor'=>$config['actor'],'send_authority'=>$config['authority']];
                        Db::run($this->db,'INSERT INTO native_social_mail_replies(club_id,id,thread_id,message_hash,ciphertext,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())',[$club,$id,$thread,$hash,$this->box->seal('social-mail-answer:'.$club.':'.$id,$message)]);
                        if($config['auto_send'])Db::run($this->db,"INSERT INTO native_social_mail_dispatch(club_id,mail_id,state,created_at,updated_at) VALUES(?,?,'pending',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",[$club,$id]);
                        $result['new']++;
                    }catch(DomainException){$result['failed']++;}
                }
                $this->configured($access,$config['binding']);
                Db::run($this->db,'INSERT INTO native_social_mail_cursors(club_id,binding,uidvalidity,last_uid,updated_at) VALUES(?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE uidvalidity=VALUES(uidvalidity),last_uid=VALUES(last_uid),updated_at=VALUES(updated_at)',[$club,$config['binding'],$batch['uidvalidity'],$last]);return $result;
            });
        }finally{Db::run($this->db,'SELECT RELEASE_LOCK(?)',[$lock]);}
        if($config['auto_send'])$result['delivery']=(new Dispatcher($this->db,$this->box,$this->replies))->run($access);
        return $result;
    }
    private function referencedThreads(string $club,string $binding,array $references):array
    {
        $threads=[];
        foreach($references as$reference){
            $hash=$this->box->fingerprint($club.':'.$reference);
            $ids=Db::run($this->db,"SELECT id FROM native_social_mail_threads WHERE club_id=? AND binding=? AND message_hash=? AND status IN ('sending','accepted','uncertain') UNION SELECT t.id FROM native_social_mail_replies m JOIN native_social_mail_threads t ON t.club_id=m.club_id AND t.id=m.thread_id WHERE m.club_id=? AND t.binding=? AND m.message_hash=? AND t.status IN ('sending','accepted','uncertain')",[$club,$binding,$hash,$club,$binding,$hash])->fetchAll(PDO::FETCH_COLUMN);
            foreach($ids as$id)$threads[$id]=true;
        }
        return $threads;
    }
    public static function receive(PDO $db,SecretBox $box,string $club):array
    {
        $row=Db::run($db,"SELECT ciphertext FROM native_private_settings WHERE club_id=? AND purpose='social-mail'",[$club])->fetch();
        if(!$row)return ['status'=>'disabled'];$data=$box->open('social-mail:'.$club,$row['ciphertext']);
        if(!$data['enabled']||!$data['receive_enabled'])return ['status'=>'disabled'];
        $access=Authority::resolve($db,['created_by'=>$data['actor'],'authority'=>$data['authority'],'club_id'=>$club]);
        $access->require('website','manage');$access->require('social','manage');
        if($access->club()!==$club)throw new DomainException('Vereinszuordnung geändert.');
        return (new self($db,$box))->fetch($access);
    }
}
