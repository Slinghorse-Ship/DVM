<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use PDO;
use DomainException;
use Kulturhus\Native\Shared\{Access,Db,SecretBox};
use Kulturhus\Native\Integration\{Accounts,JsonHttp,MetaRejected};

/** A selected published Facebook post is corrected once; uncertain writes are only read. */
final class PostCorrections
{
    private \Closure $http;
    public function __construct(private PDO $db,private SecretBox $box,?callable $http=null)
    {$this->http=$http?\Closure::fromCallable($http):static fn($m,$p,$h,$b)=>JsonHttp::request('meta',$m,$p,$h,$b);}
    public function installed():bool
    {return(bool)Db::run($this->db,"SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='native_social_post_corrections'")->fetchColumn();}
    private function actor(Access $access):array
    {$access->require('social','read');$access->require('social','edit');return$access->require('social','publish');}
    public function source(Access $access,string $id):array
    {
        $access->require('social','read');Content::id($id);
        $row=Db::run($this->db,'SELECT * FROM native_social_objects WHERE club_id=? AND id=?'.($this->db->inTransaction()?' FOR UPDATE':''),[$access->club(),$id])->fetch(PDO::FETCH_ASSOC);
        if(!$row||$row['channel']!=='facebook'||$row['kind']!=='post'||!preg_match('/\A[0-9]+_[0-9]+\z/D',$row['remote_id']))throw new DomainException('Eigenen veröffentlichten Facebook-Beitrag auswählen.');
        $payload=json_decode($row['payload_json'],true,32,JSON_THROW_ON_ERROR);
        $source=['id'=>$id,'remote_id'=>$row['remote_id'],'caption'=>$row['caption'],'connection_id'=>$payload['connection_id']??''];
        $source['fingerprint']=$this->box->fingerprint(json_encode($source,JSON_THROW_ON_ERROR));return$source;
    }
    private function account(Access $access,array $source):array
    {
        $this->actor($access);$account=(new Accounts($this->db,$this->box))->get($access->club(),'meta');
        if(!is_string($source['connection_id'])||!hash_equals(Accounts::binding($account),$source['connection_id'])||!str_starts_with($source['remote_id'],$account['page_id'].'_'))throw new DomainException('Beitrag mit der aktuellen Facebook-Seite abgleichen.');
        return$account;
    }
    private function row(Access $access,string $id,bool $lock=false):array
    {
        $this->actor($access);Content::id($id);$row=Db::run($this->db,'SELECT * FROM native_social_post_corrections WHERE club_id=? AND id=?'.($lock?' FOR UPDATE':''),[$access->club(),$id])->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new DomainException('Beitragskorrektur nicht verfügbar.');return$row;
    }
    private function payload(array $row):array
    {return$this->box->open('post-correction:'.$row['club_id'].':'.$row['id'],$row['ciphertext']);}
    public function get(Access $access,string $id):array
    {$row=$this->row($access,$id);$row['original']=$this->payload($row);unset($row['ciphertext'],$row['request_hash']);return$row;}
    public function latest(Access $access,string $object):?array
    {
        $this->actor($access);Content::id($object);if(!$this->installed())return null;
        $id=Db::run($this->db,'SELECT id FROM native_social_post_corrections WHERE club_id=? AND object_id=? ORDER BY created_at DESC,id DESC LIMIT 1',[$access->club(),$object])->fetchColumn();return$id?$this->get($access,$id):null;
    }
    public function prepare(Access $access,string $object,string $fingerprint,string $text,string $key):array
    {
        if($this->db->inTransaction())throw new DomainException('Beitragskorrektur als eigenen Vorgang öffnen.');
        $actor=$this->actor($access);Content::id($object);Content::id($key);Content::originalText($text,60000,true);
        if(!preg_match('/\A[a-f0-9]{64}\z/D',$fingerprint)||!$this->installed())throw new DomainException('Beitragskorrektur im aktuellen Paket erneut öffnen.');
        $hash=$this->box->fingerprint(json_encode([$actor['id'],$object,$fingerprint,$text],JSON_THROW_ON_ERROR));
        return Db::atomic($this->db,function()use($access,$actor,$object,$fingerprint,$text,$key,$hash):array{
            Db::run($this->db,'SELECT id FROM native_users WHERE id=? FOR UPDATE',[$actor['id']]);$this->actor($access);
            $old=Db::run($this->db,'SELECT * FROM native_social_post_corrections WHERE club_id=? AND id=? FOR UPDATE',[$actor['club_id'],$key])->fetch(PDO::FETCH_ASSOC);
            if($old){if($old['actor_id']!==$actor['id']||!hash_equals($old['request_hash'],$hash))throw new DomainException('Kennung gehört zu einer anderen ursprünglichen Korrektur.');return$old;}
            $source=$this->source($access,$object);if(!hash_equals($fingerprint,$source['fingerprint']))throw new DomainException('Beitrag inzwischen geändert. Aktuellen Text prüfen.');$this->account($access,$source);
            if(Db::run($this->db,"SELECT 1 FROM native_social_post_corrections WHERE club_id=? AND object_id=? AND status IN ('pending','sending','uncertain') FOR UPDATE",[$actor['club_id'],$object])->fetchColumn())throw new DomainException('Frühere Korrektur zuerst prüfen.');
            Db::run($this->db,"INSERT INTO native_social_post_corrections VALUES(?,?,?,?,?,?,?,'pending',1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),NULL)",[$actor['club_id'],$key,$object,$actor['id'],$source['connection_id'],$hash,$this->box->seal('post-correction:'.$actor['club_id'].':'.$key,['source'=>$source,'text'=>$text])]);return$this->row($access,$key,true);
        });
    }
    private function call(array $account,string $method,string $remote,?array $body=null):array
    {
        $result=($this->http)($method,'/'.$account['api_version'].'/'.$remote,['Authorization: Bearer '.$account['token']],$body);
        if($method==='POST'&&($result['status']??0)>=400&&($result['status']??0)<500&&is_array($result['body']['error']??null))throw new MetaRejected('Facebook hat die Korrektur abgelehnt.');
        if(($result['status']??0)<200||($result['status']??0)>=300||!is_array($result['body']??null)||isset($result['body']['error']))throw new DomainException('Facebook-Ergebnis nicht bestätigt.');return$result['body'];
    }
    private function read(array $account,array $source):string
    {
        $data=$this->call($account,'GET',$source['remote_id'].'?fields=id,from,message');
        if(($data['id']??null)!==$source['remote_id']||($data['from']['id']??null)!==$account['page_id'])throw new DomainException('Ursprünglicher Facebook-Beitrag nicht bestätigt.');
        return Content::originalText($data['message']??'',100000);
    }
    private function current(Access $access,array $row):array
    {
        $payload=$this->payload($row);$account=$this->account($access,$payload['source']);$current=$this->source($access,$row['object_id']);
        if(!hash_equals($current['fingerprint'],$payload['source']['fingerprint']))throw new DomainException('Gespeicherter Originalbeitrag inzwischen geändert.');return$account;
    }
    private function state(Access $access,string $id,string $status):void
    {
        Db::atomic($this->db,function()use($access,$id,$status):void{$row=$this->row($access,$id,true);if(in_array($row['status'],['confirmed','blocked','rejected','closed'],true))return;Db::run($this->db,'UPDATE native_social_post_corrections SET status=?,version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND id=?',[$status,$row['club_id'],$id]);});
    }
    private function confirmed(Access $access,string $id):void
    {
        Db::atomic($this->db,function()use($access,$id):void{
            $row=$this->row($access,$id,true);if(in_array($row['status'],['confirmed','closed'],true))return;$payload=$this->payload($row);$this->account($access,$payload['source']);
            $object=Db::run($this->db,'SELECT * FROM native_social_objects WHERE club_id=? AND id=? FOR UPDATE',[$row['club_id'],$row['object_id']])->fetch(PDO::FETCH_ASSOC);
            // A confirmed old observation must not replace a newer local publication or observation.
            if($object){$current=$this->source($access,$row['object_id']);if(hash_equals($current['fingerprint'],$payload['source']['fingerprint'])){
                (new OriginalArchive($this->db,$this->box))->retain($row['club_id'],'object',$object,'changed');$data=json_decode($object['payload_json'],true,32,JSON_THROW_ON_ERROR);$data['caption']=$payload['text'];
                Db::run($this->db,'UPDATE native_social_objects SET caption=?,payload_json=?,last_seen=UTC_TIMESTAMP() WHERE club_id=? AND id=?',[$payload['text'],json_encode($data,JSON_THROW_ON_ERROR),$row['club_id'],$row['object_id']]);
            }}
            Db::run($this->db,"UPDATE native_social_post_corrections SET status='confirmed',version=version+1,confirmed_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND id=?",[$row['club_id'],$id]);
        });
    }
    public function execute(Access $access,string $object,string $fingerprint,string $text,string $key):array
    {
        $row=$this->prepare($access,$object,$fingerprint,$text,$key);if($row['status']!=='pending')return$this->get($access,$key);$attempted=false;
        try{
            $payload=$this->payload($row);$account=$this->current($access,$row);$before=$this->read($account,$payload['source']);
            if($before===$payload['text']){$this->confirmed($access,$key);return$this->get($access,$key);}
            if($before!==$payload['source']['caption'])throw new DomainException('Facebook-Original inzwischen geändert.');
            $claimed=Db::atomic($this->db,function()use($access,$key):bool{$fresh=$this->row($access,$key,true);if($fresh['status']!=='pending')return false;$this->current($access,$fresh);Db::run($this->db,"UPDATE native_social_post_corrections SET status='sending',version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND id=?",[$fresh['club_id'],$key]);return true;});
            if(!$claimed)return$this->get($access,$key);$account=$this->current($access,$row);
            if($this->read($account,$payload['source'])!==$before)throw new DomainException('Facebook-Original vor Übergabe geändert.');
            $this->current($access,$row);$attempted=true;$result=$this->call($account,'POST',$payload['source']['remote_id'],['message'=>$payload['text']]);
            if(($result['success']??null)!==true||$this->read($account,$payload['source'])!==$payload['text'])throw new DomainException('Korrektur nicht unabhängig bestätigt.');$this->confirmed($access,$key);
        }catch(MetaRejected){$this->state($access,$key,'rejected');}
        catch(\Throwable){$fresh=$this->row($access,$key);$this->state($access,$key,$attempted||$fresh['status']==='sending'?'uncertain':'blocked');}
        return$this->get($access,$key);
    }
    public function reconcile(Access $access,string $id):array
    {
        if($this->db->inTransaction())throw new DomainException('Ergebnisprüfung als eigenen Vorgang öffnen.');$row=$this->row($access,$id);
        if(in_array($row['status'],['sending','uncertain'],true)){$payload=$this->payload($row);$account=$this->account($access,$payload['source']);if($this->read($account,$payload['source'])===$payload['text'])$this->confirmed($access,$id);}return$this->get($access,$id);
    }
    public function close(Access $access,string $id,int $version,bool $checked):array
    {
        if($this->db->inTransaction())throw new DomainException('Abschluss als eigenen Vorgang öffnen.');
        return Db::atomic($this->db,function()use($access,$id,$version,$checked):array{
            $row=$this->row($access,$id,true);if($row['status']==='closed')return$this->get($access,$id);
            if((int)$row['version']!==$version||!in_array($row['status'],['pending','sending','uncertain'],true))throw new DomainException('Aktuellen Korrekturstand prüfen.');
            if($row['status']!=='pending'&&(!$checked||!Db::run($this->db,'SELECT updated_at<=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 5 MINUTE) FROM native_social_post_corrections WHERE club_id=? AND id=?',[$row['club_id'],$id])->fetchColumn()))throw new DomainException('Beim Anbieter prüfen; laufende Übergabe später abschließen.');
            Db::run($this->db,"UPDATE native_social_post_corrections SET status='closed',version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND id=?",[$row['club_id'],$id]);return$this->get($access,$id);
        });
    }
}
