<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Db,SecretBox};

/** Authenticate original bytes, then atomically accept only owned, bounded, deduplicated notifications. */
final class MetaWebhookInbox
{
    public const MAX_BYTES=1048576;
    public function __construct(private PDO $db,private SecretBox $box){}
    private function lookup(string $club,string $callback):array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$callback)||!MetaWebhookSchema::installed($this->db))throw new DomainException('Empfang nicht verfügbar.');
        $row=Db::run($this->db,'SELECT * FROM native_meta_webhook_settings WHERE club_id=? AND callback_id=?',[$club,$callback])->fetch();if(!$row)throw new DomainException('Empfang nicht verfügbar.');
        return$row;
    }
    public function challenge(string $club,string $callback,array $query):string
    {
        $mode=$query['hub_mode']??null;$token=$query['hub_verify_token']??null;$challenge=$query['hub_challenge']??null;
        if($mode!=='subscribe'||!is_string($token)||strlen($token)>256||!is_string($challenge)||!preg_match('/\A[0-9]{1,100}\z/D',$challenge))throw new DomainException('Verifikation nicht angenommen.');
        $row=$this->lookup($club,$callback);(new MetaWebhookConfiguration($this->db,$this->box))->current($row);
        if(!hash_equals($row['verify_hash'],hash('sha256',$token)))throw new DomainException('Verifikation nicht angenommen.');return$challenge;
    }
    public function receive(string $club,string $callback,#[\SensitiveParameter]string $body,string $signature):int
    {
        if(strlen($body)>self::MAX_BYTES||!preg_match('/\Asha256=[a-f0-9]{64}\z/D',$signature))throw new DomainException('Eingang nicht angenommen.');
        $row=$this->lookup($club,$callback);$config=new MetaWebhookConfiguration($this->db,$this->box);$current=$config->current($row);$secrets=$config->secrets($row);
        if(!hash_equals('sha256='.hash_hmac('sha256',$body,$secrets['app_secret']),$signature))throw new DomainException('Signatur nicht angenommen.');
        try{$decoded=json_decode($body,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new DomainException('Ereignisse nicht angenommen.');}
        if(!is_array($decoded)||array_is_list($decoded))throw new DomainException('Ereignisse nicht angenommen.');$tasks=MetaWebhookEvents::tasks($decoded,$current['account']);
        return Db::atomic($this->db,function()use($club,$row,$config,$tasks):int{
            $now=$config->row($club,true);if(!$now||$now['callback_id']!==$row['callback_id']||(int)$now['version']!==(int)$row['version'])throw new DomainException('Empfang inzwischen geändert.');$config->current($now);$accepted=0;
            foreach($tasks as$task){
                $id=substr($this->box->fingerprint('meta-webhook:'.$club.':'.$row['connection_id'].':'.json_encode($task,JSON_THROW_ON_ERROR)),0,32);
                $frame=['connection_version'=>(int)$row['connection_version'],'connection_id'=>$row['connection_id'],'task'=>$task];
                $insert=Db::run($this->db,"INSERT IGNORE INTO native_meta_webhook_receipts(club_id,id,settings_version,channel,kind,ciphertext,state,received_at,due_at) VALUES(?,?,?,?,?,?,'pending',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",[$club,$id,$row['version'],$task['channel'],$task['kind'],$this->box->seal('meta-webhook:'.$club.':'.$id,$frame)]);$accepted+=$insert->rowCount();
            }
            if((int)Db::run($this->db,"SELECT COUNT(*) FROM native_meta_webhook_receipts WHERE club_id=? AND state IN ('pending','processing')",[$club])->fetchColumn()>10000)throw new DomainException('Eingangsgrenze erreicht.');
            return$accepted;
        });
    }
}
