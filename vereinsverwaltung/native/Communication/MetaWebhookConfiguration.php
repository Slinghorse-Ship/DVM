<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Access,Authority,Db,Identity,SecretBox};
use Kulturhus\Native\Integration\{Accounts,ManagerAccess};

/** An optional receiver has its own keys and current account/actor binding. */
final class MetaWebhookConfiguration
{
    public function __construct(private PDO $db,private SecretBox $box){}
    public function row(string $club,bool $lock=false):?array
    {
        if(!MetaWebhookSchema::installed($this->db))return null;
        $row=Db::run($this->db,'SELECT * FROM native_meta_webhook_settings WHERE club_id=?'.($lock?' FOR UPDATE':''),[$club])->fetch();return$row?:null;
    }
    public function status(Access $access):array
    {
        $access->require('social','manage');$club=$access->club();$row=$this->row($club);
        $last=$row?Db::run($this->db,"SELECT MAX(received_at) received,MAX(IF(state='done',completed_at,NULL)) completed,COUNT(IF(state IN ('pending','processing'),1,NULL)) pending FROM native_meta_webhook_receipts WHERE club_id=?",[$club])->fetch():[];
        $current=false;if($row)try{$this->current($row);$current=true;}catch(DomainException){}
        return ['installed'=>MetaWebhookSchema::installed($this->db),'version'=>$row?(int)$row['version']:0,'enabled'=>$row&&(bool)$row['enabled'],'current'=>$current,'callback'=>$row?Identity::forClub($this->db,$club)['origin'].'/meta-webhook/'.$row['callback_id']:'','received'=>$last['received']??null,'completed'=>$last['completed']??null,'pending'=>(int)($last['pending']??0)];
    }
    public function current(array $row):array
    {
        if(!(int)$row['enabled'])throw new DomainException('Webhook-Empfang ist ausgeschaltet.');
        $actor=Authority::resolve($this->db,['created_by'=>$row['actor_id'],'authority'=>$row['authority'],'club_id'=>$row['club_id']]);
        if($actor->require('social','manage')['club_id']!==$row['club_id'])throw new DomainException('Webhook-Zuordnung ist nicht mehr aktuell.');
        $actor->require('social','read');$actor->require('social','edit');
        if(Db::run($this->db,"SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='native_auth_accounts'")->fetchColumn()){
            $account=Db::run($this->db,'SELECT suspended FROM native_auth_accounts WHERE actor_id=?'.($this->db->inTransaction()?' FOR SHARE':''),[$row['actor_id']])->fetch();
            if($account&&(int)$account['suspended'])throw new DomainException('Das einrichtende Konto ist gesperrt.');
        }
        $version=Db::run($this->db,"SELECT version FROM native_private_settings WHERE club_id=? AND purpose='api-meta'".($this->db->inTransaction()?' FOR SHARE':''),[$row['club_id']])->fetchColumn();
        $account=(new Accounts($this->db,$this->box))->get($row['club_id'],'meta');
        if($version===false||!hash_equals($row['connection_id'],Accounts::binding($account)))throw new DomainException('Meta-Verbindung geändert. Empfang erneut einrichten.');
        return ['actor'=>$actor,'account'=>$account];
    }
    public function secrets(array $row):array
    {
        return$this->box->open('meta-webhook-settings:'.$row['club_id'].':'.$row['version'],$row['ciphertext']);
    }
    public function save(Access $access,array $input,int $version):void
    {
        if(!MetaWebhookSchema::installed($this->db))throw new DomainException('Webhook-Empfang zuerst in der Einrichtung aktualisieren.');
        $access->require('website','manage');$access->require('social','manage');if($input['enabled']??false){$access->require('social','read');$access->require('social','edit');}
        foreach(['app_secret','verify_token']as$key)if(!is_string($input[$key]??'')||strlen($input[$key]??'')>256||preg_match('/[\x00-\x20\x7f]/',$input[$key]??''))throw new DomainException('Webhook-Schlüssel prüfen.');
        if(!is_bool($input['enabled']??null))throw new DomainException('Webhook-Empfang auswählen.');
        Db::atomic($this->db,function()use($access,$input,$version):void{
            $u=$access->require('social','manage');$access->require('website','manage');$club=$u['club_id'];$old=$this->row($club,true);
            if(($old?(int)$old['version']:0)!==$version)throw new DomainException('Webhook-Einstellung inzwischen geändert. Bitte neu laden.');
            try{$account=(new Accounts($this->db,$this->box))->get($club,'meta');$connectionId=Accounts::binding($account);$connectionVersion=(int)Db::run($this->db,"SELECT version FROM native_private_settings WHERE club_id=? AND purpose='api-meta' FOR SHARE",[$club])->fetchColumn();}
            catch(DomainException $error){if($input['enabled'])throw$error;$connectionId=$old['connection_id']??str_repeat('0',64);$connectionVersion=(int)($old['connection_version']??0);}
            $same=$old&&hash_equals($old['connection_id'],$connectionId);$retain=$same||(!$input['enabled']&&$old);$secrets=$retain?$this->secrets($old):[];
            foreach(['app_secret','verify_token']as$key)if(($input[$key]??'')!=='')$secrets[$key]=$input[$key];
            if($input['enabled']&&(!preg_match('/\A[a-f0-9]{32}\z/Di',$secrets['app_secret']??'')||!preg_match('/\A[A-Za-z0-9_.:-]{20,256}\z/D',$secrets['verify_token']??'')))throw new DomainException('App-Secret und eigenen Verifikationstoken angeben.');
            $callback=$retain?$old['callback_id']:bin2hex(random_bytes(16));$next=$version+1;$cipher=$this->box->seal('meta-webhook-settings:'.$club.':'.$next,$secrets);
            Db::run($this->db,"INSERT INTO native_meta_webhook_settings VALUES(?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE version=VALUES(version),callback_id=VALUES(callback_id),actor_id=VALUES(actor_id),authority=VALUES(authority),enabled=VALUES(enabled),connection_version=VALUES(connection_version),connection_id=VALUES(connection_id),verify_hash=VALUES(verify_hash),ciphertext=VALUES(ciphertext),updated_at=VALUES(updated_at)",[$club,$next,$callback,$u['id'],$access instanceof ManagerAccess?'manager':'native',(int)$input['enabled'],$connectionVersion,$connectionId,hash('sha256',$secrets['verify_token']??''),$cipher]);
            $saved=$this->row($club);Db::run($this->db,'INSERT INTO native_meta_webhook_history VALUES(?,?,?,?,UTC_TIMESTAMP(6))',[$club,$next,json_encode($saved,JSON_THROW_ON_ERROR),$u['id']]);
        });
    }
}
