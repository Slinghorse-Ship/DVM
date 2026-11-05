<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;

/** One answer per account; chat membership remains the only visibility boundary. */
final class ChatPolls
{
    public function __construct(private PDO $db,private string $actor,private ?DateTimeImmutable $clock=null){}

    private function now():string{return ($this->clock??new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');}
    private function chat(string $id):array{return (new ClubChat($this->db,$this->actor))->thread($id);}
    private function requestKey(string $key):void{if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new DomainException('Die Anfrage ist nicht mehr gültig. Bitte die Unterhaltung neu öffnen.');}
    private function row(string $chat,string $poll):array
    {
        $this->chat($chat);
        $row=DocumentCatalog::query($this->db,'SELECT * FROM club_chat_polls WHERE id=? AND chat_id=?',[$poll,$chat])->fetch();
        if(!$row)throw new DomainException('Umfrage nicht verfügbar.');
        return $row;
    }
    private function open(array $poll):bool{return !$poll['closed_at']&&(!$poll['closes_at']||$poll['closes_at']>$this->now());}

    public function create(string $chat,string $question,array $options,string $closesOn,string $key):string
    {
        $this->chat($chat);$this->requestKey($key);$question=trim($question);
        $options=array_values(array_filter(array_map(static fn($value)=>is_string($value)?trim($value):'',$options),static fn($value)=>$value!==''));
        if($question===''||mb_strlen($question)>500||str_contains($question,"\0"))throw new DomainException('Bitte eine Frage mit höchstens 500 Zeichen eingeben.');
        if(count($options)<2||count($options)>10)throw new DomainException('Bitte zwei bis zehn Antworten eingeben.');
        $known=[];foreach($options as $option){$normalized=mb_strtolower(preg_replace('/\s+/u',' ',$option));if(mb_strlen($option)>160||str_contains($option,"\0")||in_array($normalized,$known,true))throw new DomainException('Antworten müssen unterschiedlich sein und dürfen höchstens 160 Zeichen enthalten.');$known[]=$normalized;}
        $closesAt=null;
        if($closesOn!==''){
            $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$closesOn,new DateTimeZone('Europe/Berlin'));
            if(!$date||$date->format('Y-m-d\TH:i')!==$closesOn)throw new DomainException('Bitte ein gültiges Ende für die Umfrage angeben.');
            $closesAt=$date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }
        $fingerprint=hash('sha256',json_encode([$chat,$this->actor,$question,$options,$closesAt],JSON_THROW_ON_ERROR));
        $this->db->beginTransaction();
        try{
            $this->chat($chat);
            $old=DocumentCatalog::query($this->db,'SELECT * FROM club_chat_polls WHERE request_key=?',[$key])->fetch();
            if($old){if(!hash_equals($old['request_hash'],$fingerprint))throw new DomainException('Diese Umfrage wurde bereits mit anderen Angaben gespeichert.');$this->db->commit();return $old['id'];}
            if($closesAt!==null&&$closesAt<=$this->now())throw new DomainException('Das Ende der Umfrage muss in der Zukunft liegen.');
            if(DocumentCatalog::query($this->db,'SELECT 1 FROM club_chat_messages WHERE request_key=?',[$key])->fetchColumn())throw new DomainException('Diese Anfrage wurde bereits für eine Nachricht verwendet.');
            DocumentCatalog::query($this->db,'INSERT INTO club_chat_messages(chat_id,actor_id,body,created_at,request_key) VALUES(?,?,?,?,?)',[$chat,$this->actor,$question,$this->now(),$key]);
            $seq=(int)$this->db->lastInsertId();$id=bin2hex(random_bytes(16));
            DocumentCatalog::query($this->db,'INSERT INTO club_chat_polls(id,chat_id,message_seq,actor_id,question,closes_at,request_key,request_hash,created_at) VALUES(?,?,?,?,?,?,?,?,?)',[$id,$chat,$seq,$this->actor,$question,$closesAt,$key,$fingerprint,$this->now()]);
            foreach($options as $number=>$option)DocumentCatalog::query($this->db,'INSERT INTO club_chat_poll_options VALUES(?,?,?)',[$id,$number+1,$option]);
            PushNotifications::enqueue($this->db,$seq);
            $this->db->commit();return $id;
        }catch(\Throwable $error){$this->db->rollBack();throw $error;}
    }

    public function get(string $chat,string $poll):array
    {
        $row=$this->row($chat,$poll);
        $row['options']=DocumentCatalog::query($this->db,'SELECT o.option_number,o.label,COUNT(v.actor_id) votes FROM club_chat_poll_options o LEFT JOIN club_chat_poll_votes v ON v.poll_id=o.poll_id AND v.option_number=o.option_number WHERE o.poll_id=? GROUP BY o.option_number,o.label ORDER BY o.option_number',[$poll])->fetchAll();
        $own=DocumentCatalog::query($this->db,'SELECT option_number,version FROM club_chat_poll_votes WHERE poll_id=? AND actor_id=?',[$poll,$this->actor])->fetch();
        $row['own_option']=$own?(int)$own['option_number']:null;$row['vote_version']=$own?(int)$own['version']:0;
        $row['total_votes']=array_sum(array_column($row['options'],'votes'));$row['open']=$this->open($row);$row['can_close']=$row['open']&&$row['actor_id']===$this->actor;
        return $row;
    }

    public function forMessages(string $chat,array $messages):array
    {
        $this->chat($chat);if(!$messages)return [];
        $seqs=array_map(static fn($message)=>(int)$message['seq'],$messages);
        $rows=DocumentCatalog::query($this->db,'SELECT id,message_seq FROM club_chat_polls WHERE chat_id=? AND message_seq IN ('.implode(',',array_fill(0,count($seqs),'?')).')',[$chat,...$seqs])->fetchAll();$out=[];
        foreach($rows as $row)$out[(int)$row['message_seq']]=$this->get($chat,$row['id']);
        return $out;
    }

    public function vote(string $chat,string $poll,int $option,int $expectedVersion,string $key):void
    {
        $this->requestKey($key);$this->db->beginTransaction();
        try{
            $row=$this->row($chat,$poll);
            $old=DocumentCatalog::query($this->db,'SELECT * FROM club_chat_poll_votes WHERE poll_id=? AND actor_id=?',[$poll,$this->actor])->fetch();
            if($old&&$old['request_key']===$key){if((int)$old['option_number']!==$option)throw new DomainException('Diese Stimme wurde bereits mit einer anderen Auswahl gespeichert.');$this->db->commit();return;}
            if(!$this->open($row))throw new DomainException('Die Umfrage ist beendet. Das Ergebnis bleibt zum Nachlesen erhalten.');
            if($expectedVersion!==($old?(int)$old['version']:0))throw new DomainException('Deine Stimme wurde inzwischen geändert. Bitte die Unterhaltung neu öffnen.');
            if(!DocumentCatalog::query($this->db,'SELECT 1 FROM club_chat_poll_options WHERE poll_id=? AND option_number=?',[$poll,$option])->fetchColumn())throw new DomainException('Bitte eine Antwort aus dieser Umfrage auswählen.');
            if(DocumentCatalog::query($this->db,'SELECT 1 FROM club_chat_poll_votes WHERE request_key=?',[$key])->fetchColumn())throw new DomainException('Diese Anfrage wurde bereits für eine andere Stimme verwendet.');
            DocumentCatalog::query($this->db,'INSERT INTO club_chat_poll_votes(poll_id,actor_id,option_number,version,request_key,updated_at) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE option_number=VALUES(option_number),version=VALUES(version),request_key=VALUES(request_key),updated_at=VALUES(updated_at)',[$poll,$this->actor,$option,$expectedVersion+1,$key,$this->now()]);
            $this->db->commit();
        }catch(\Throwable $error){$this->db->rollBack();throw $error;}
    }

    public function close(string $chat,string $poll,int $expectedVersion):void
    {
        $this->db->beginTransaction();
        try{
            $row=$this->row($chat,$poll);
            if($row['actor_id']!==$this->actor)throw new DomainException('Nur die Person, die die Umfrage erstellt hat, kann sie beenden.');
            if(!$this->open($row)){$this->db->commit();return;}
            if((int)$row['version']!==$expectedVersion)throw new DomainException('Die Umfrage wurde inzwischen geändert. Bitte die Unterhaltung neu öffnen.');
            DocumentCatalog::query($this->db,'UPDATE club_chat_polls SET closed_at=?,version=version+1 WHERE id=?',[$this->now(),$poll]);$this->db->commit();
        }catch(\Throwable $error){$this->db->rollBack();throw $error;}
    }
}
