<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db,SecretBox};

/** Canonical provider GETs may safely resume after interruptions; no notification sends a reply. */
final class MetaWebhookWorker
{
    public function __construct(private PDO $db,private SecretBox $box){}
    public function run(string $club,callable $read,int $limit=20):array
    {
        if($this->db->inTransaction()||$limit<1||$limit>100)throw new DomainException('Eigenen begrenzten Webhook-Abgleich aufrufen.');
        if(!MetaWebhookSchema::installed($this->db))return[];
        Db::run($this->db,"UPDATE native_meta_webhook_receipts SET state='pending',reason='read_interrupted',due_at=UTC_TIMESTAMP(6) WHERE club_id=? AND state='processing' AND started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)",[$club]);$result=[];$config=new MetaWebhookConfiguration($this->db,$this->box);
        for($i=0;$i<$limit;++$i){
            $receipt=Db::atomic($this->db,function()use($club):?array{$row=Db::run($this->db,"SELECT * FROM native_meta_webhook_receipts WHERE club_id=? AND state='pending' AND due_at<=UTC_TIMESTAMP(6) ORDER BY due_at,id LIMIT 1 FOR UPDATE SKIP LOCKED",[$club])->fetch();if(!$row)return null;Db::run($this->db,"UPDATE native_meta_webhook_receipts SET state='processing',attempts=attempts+1,started_at=UTC_TIMESTAMP(6) WHERE club_id=? AND id=?",[$club,$row['id']]);return$row;});
            if(!$receipt)break;$state='pending';$reason='provider_read_unconfirmed';
            try{
                $row=$config->row($club);if(!$row)throw new DomainException('Empfang nicht eingerichtet.');$current=$config->current($row);$frame=$this->box->open('meta-webhook:'.$club.':'.$receipt['id'],$receipt['ciphertext']);
                if(!hash_equals($frame['connection_id'],$row['connection_id'])){$state='paused';$reason='connection_changed';}
                else{
                    if($read($current['actor'],$frame['task'])!==true)throw new DomainException('Anbieterabgleich ist nicht bestätigt.');
                    Db::atomic($this->db,function()use($club,$row,$config,$receipt):void{$fresh=$config->row($club,true);if(!$fresh||(int)$fresh['version']!==(int)$row['version'])throw new DomainException('Empfang inzwischen geändert.');$config->current($fresh);$saved=Db::run($this->db,"UPDATE native_meta_webhook_receipts SET state='done',reason=NULL,completed_at=UTC_TIMESTAMP(6) WHERE club_id=? AND id=? AND state='processing' AND attempts=?",[$club,$receipt['id'],(int)$receipt['attempts']+1]);if($saved->rowCount()!==1)throw new DomainException('Ursprünglicher Leseauftrag inzwischen geändert.');});$state='done';$reason=null;
                }
            }catch(\Throwable){
                try{$live=$config->row($club);if(!$live)throw new DomainException('Nicht eingerichtet.');$config->current($live);}
                catch(\Throwable){$state='paused';$reason='receiver_unavailable';}
                if($state==='pending'&&(int)$receipt['attempts']+1>=20){$state='failed';$reason='provider_read_unconfirmed';}
            }
            if($state!=='done')Db::run($this->db,"UPDATE native_meta_webhook_receipts SET state=?,reason=?,due_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 60 SECOND),completed_at=IF(? IN ('paused','failed'),UTC_TIMESTAMP(6),NULL) WHERE club_id=? AND id=? AND state='processing' AND attempts=?",[$state,$reason,$state,$club,$receipt['id'],(int)$receipt['attempts']+1]);
            $result[]=['id'=>$receipt['id'],'state'=>$state];
        }
        return$result;
    }
    public function resume(Access $access,string $id):void
    {
        $access->require('social','manage');Content::id($id);Db::atomic($this->db,function()use($access,$id):void{
            $club=$access->club();$row=(new MetaWebhookConfiguration($this->db,$this->box))->row($club,true);if(!$row)throw new DomainException('Webhook-Empfang einrichten.');(new MetaWebhookConfiguration($this->db,$this->box))->current($row);
            $receipt=Db::run($this->db,"SELECT * FROM native_meta_webhook_receipts WHERE club_id=? AND id=? FOR UPDATE",[$club,$id])->fetch();if(!$receipt||!in_array($receipt['state'],['paused','failed','pending'],true))throw new DomainException('Eigenen offenen Eingang auswählen.');$frame=$this->box->open('meta-webhook:'.$club.':'.$id,$receipt['ciphertext']);
            if(!hash_equals($frame['connection_id'],$row['connection_id']))throw new DomainException('Eingang gehört zur früheren Verbindung.');
            Db::run($this->db,"UPDATE native_meta_webhook_receipts SET state='pending',reason='explicit_read_resume',due_at=UTC_TIMESTAMP(6),completed_at=NULL,attempts=0 WHERE club_id=? AND id=?",[$club,$id]);
        });
    }
}
