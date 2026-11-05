<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db,SecretBox};
use Kulturhus\Native\Integration\{Accounts,MetaRejected};

/** One durable selected comment intention; an uncertain write is only read, never resent. */
final class CommentModeration
{
    private Replies $replies;
    private MetaReplyProvider $accounts;
    private MetaModerationProvider $provider;
    public function __construct(private PDO $db,private SecretBox $box,?callable $http=null)
    {$this->replies=new Replies($db,$box,$http);$this->accounts=new MetaReplyProvider($db,$box,$http);$this->provider=new MetaModerationProvider($http);}
    public function installed():bool
    {return(bool)Db::run($this->db,"SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='native_social_moderations'")->fetchColumn();}
    private function actor(Access $access):array
    {$access->require('social','manage');$access->require('social','edit');return$access->require('social','publish');}
    private function source(Access $access,string $id):array
    {
        $source=$this->replies->source($access,$id);
        if($source['kind']!=='comment'||!in_array($source['channel'],['facebook','instagram'],true))throw new DomainException('Eigenen Facebook- oder Instagram-Kommentar auswählen.');
        return$source;
    }
    private function payload(array $row):array
    {return$this->box->open('comment-moderation:'.$row['club_id'].':'.$row['id'],$row['ciphertext']);}
    private function row(Access $access,string $id,bool $lock=false):array
    {
        $access->require('social','manage');Content::id($id);
        $row=Db::run($this->db,'SELECT * FROM native_social_moderations WHERE club_id=? AND id=?'.($lock?' FOR UPDATE':''),[$access->club(),$id])->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new DomainException('Moderationsvorgang nicht verfügbar.');return$row;
    }
    public function get(Access $access,string $id):array
    {$row=$this->row($access,$id);unset($row['ciphertext'],$row['request_hash']);return$row;}
    public function original(Access $access,string $id):array
    {return$this->payload($this->row($access,$id))['source'];}
    public function latest(Access $access,string $attention):?array
    {
        $access->require('social','manage');Content::id($attention);if(!$this->installed())return null;
        $id=Db::run($this->db,'SELECT id FROM native_social_moderations WHERE club_id=? AND attention_id=? ORDER BY created_at DESC,id DESC LIMIT 1',[$access->club(),$attention])->fetchColumn();return$id?$this->get($access,$id):null;
    }
    public function prepare(Access $access,string $attention,int $version,bool $hidden,string $key):array
    {
        if($this->db->inTransaction())throw new DomainException('Moderation benötigt einen eigenen Vorgang.');
        $actor=$this->actor($access);Content::id($attention);Content::id($key);if($version<1)throw new DomainException('Ursprüngliche Kommentarversion prüfen.');
        if(!$this->installed())throw new DomainException('Moderation benötigt das aktuelle installierte Paket.');
        $hash=$this->box->fingerprint(json_encode([$actor['id'],$attention,$version,$hidden],JSON_THROW_ON_ERROR));
        return Db::atomic($this->db,function()use($access,$actor,$attention,$version,$hidden,$key,$hash):array{
            Db::run($this->db,'SELECT id FROM native_users WHERE id=? FOR UPDATE',[$actor['id']]);$this->actor($access);
            $old=Db::run($this->db,'SELECT * FROM native_social_moderations WHERE club_id=? AND id=? FOR UPDATE',[$actor['club_id'],$key])->fetch(PDO::FETCH_ASSOC);
            if($old){if($old['actor_id']!==$actor['id']||!hash_equals($old['request_hash'],$hash))throw new DomainException('Moderationskennung gehört zu einem anderen Original.');return$old;}
            Db::run($this->db,'SELECT id FROM native_social_attention WHERE club_id=? AND id=? FOR UPDATE',[$actor['club_id'],$attention]);$source=$this->source($access,$attention);
            if((int)$source['version']!==$version)throw new DomainException('Kommentar inzwischen geändert. Neu abgleichen.');
            if(Db::run($this->db,"SELECT 1 FROM native_social_moderations WHERE club_id=? AND attention_id=? AND status IN ('pending','sending','uncertain') FOR UPDATE",[$actor['club_id'],$attention])->fetchColumn())throw new DomainException('Moderationsvorgang läuft. Ergebnis prüfen.');
            $account=$this->accounts->account($access,$source);$payload=['source'=>$source];
            Db::run($this->db,"INSERT INTO native_social_moderations(club_id,id,attention_id,actor_id,connection_id,request_hash,ciphertext,desired_hidden,status,version,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,'pending',1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",[$actor['club_id'],$key,$attention,$actor['id'],Accounts::binding($account),$hash,$this->box->seal('comment-moderation:'.$actor['club_id'].':'.$key,$payload),(int)$hidden]);
            return$this->row($access,$key,true);
        });
    }
    private function current(Access $access,array $row):array
    {
        $this->actor($access);$original=$this->payload($row)['source'];$current=$this->source($access,$row['attention_id']);
        if((int)$current['version']!==(int)$original['version']||!hash_equals($current['fingerprint'],$original['fingerprint']))throw new DomainException('Ursprünglicher Kommentar inzwischen geändert.');
        return$this->accounts->account($access,$original,$row['connection_id']);
    }
    private function state(Access $access,string $id,string $status,?bool $before=null):array
    {
        return Db::atomic($this->db,function()use($access,$id,$status,$before):array{
            $row=$this->row($access,$id,true);if(in_array($row['status'],['confirmed','rejected','blocked','closed'],true))return$row;
            Db::run($this->db,'UPDATE native_social_moderations SET status=?,before_hidden=COALESCE(before_hidden,?),version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND id=?',[$status,$before===null?null:(int)$before,$row['club_id'],$id]);return$this->row($access,$id,true);
        });
    }
    private function confirmed(Access $access,array $row,bool $before):array
    {
        $checker=$access->user()['id'];
        return Db::atomic($this->db,function()use($access,$row,$before,$checker):array{
            Db::run($this->db,'SELECT id FROM native_users WHERE id=? FOR UPDATE',[$checker]);
            Db::run($this->db,'SELECT id FROM native_social_attention WHERE club_id=? AND id=? FOR UPDATE',[$row['club_id'],$row['attention_id']]);
            $pinned=$this->payload($row)['source'];
            $object=Db::run($this->db,'SELECT * FROM native_social_objects WHERE club_id=? AND channel=? AND remote_id=? FOR UPDATE',[$row['club_id'],$pinned['channel'],$pinned['parent_id']])->fetch(PDO::FETCH_ASSOC);
            $fresh=$this->row($access,$row['id'],true);if(in_array($fresh['status'],['confirmed','closed'],true))return$fresh;
            $original=$this->payload($fresh)['source'];
            try{
                $this->actor($access);$account=$this->accounts->account($access,$original,$fresh['connection_id']);$current=$this->source($access,$fresh['attention_id']);
                if(hash_equals($current['fingerprint'],$original['fingerprint'])){
                    $p=$current['payload'];(new Attention($this->db,$this->box))->observe($access,$current['channel'],'comment',$current['parent_id'],[['id'=>$p['remote_id'],'text'=>$p['text'],'name'=>$p['name'],'created_at'=>$p['created_at'],'title'=>$p['title'],'hidden'=>(bool)$fresh['desired_hidden']]]);
                    if($object&&$object['comments_cipher']!==null){
                        $cache=$this->box->open('social-comments:'.$fresh['club_id'].':'.$object['id'],$object['comments_cipher']);$changed=false;
                        foreach($cache['comments']??[]as$index=>$comment)if(($comment['id']??null)===$p['remote_id']&&($comment['text']??null)===$p['text']&&($comment['hidden']??null)!==(bool)$fresh['desired_hidden']){$cache['comments'][$index]['hidden']=(bool)$fresh['desired_hidden'];$changed=true;}
                        if($changed){(new OriginalArchive($this->db,$this->box))->retain($fresh['club_id'],'object',$object,'changed');Db::run($this->db,'UPDATE native_social_objects SET comments_cipher=?,comments_at=UTC_TIMESTAMP() WHERE club_id=? AND id=?',[$this->box->seal('social-comments:'.$fresh['club_id'].':'.$object['id'],$cache),$fresh['club_id'],$object['id']]);}
                    }
                }
            }catch(DomainException){/* Confirmed old-connection evidence never overwrites a changed or unavailable original. */}
            Db::run($this->db,"UPDATE native_social_moderations SET status='confirmed',before_hidden=COALESCE(before_hidden,?),version=version+1,checked_by=?,confirmed_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND id=?",[(int)$before,$access->user()['id'],$fresh['club_id'],$fresh['id']]);return$this->row($access,$fresh['id'],true);
        });
    }
    public function execute(Access $access,string $attention,int $version,bool $hidden,string $key):array
    {
        $row=$this->prepare($access,$attention,$version,$hidden,$key);
        if($row['status']!=='pending')return$this->get($access,$row['id']);
        $attempted=false;
        try{
            $account=$this->current($access,$row);$source=$this->payload($row)['source'];$before=$this->provider->read($account,$source);
            if($before===$hidden){$this->confirmed($access,$row,$before);return$this->get($access,$row['id']);}
            $claimed=Db::atomic($this->db,function()use($access,$row,$before):bool{
                Db::run($this->db,'SELECT id FROM native_users WHERE id=? FOR UPDATE',[$row['actor_id']]);
                Db::run($this->db,'SELECT id FROM native_social_attention WHERE club_id=? AND id=? FOR UPDATE',[$row['club_id'],$row['attention_id']]);
                $fresh=$this->row($access,$row['id'],true);if($fresh['status']!=='pending')return false;$this->current($access,$fresh);
                Db::run($this->db,"UPDATE native_social_moderations SET status='sending',before_hidden=?,version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND id=?",[(int)$before,$fresh['club_id'],$fresh['id']]);return true;
            });
            if(!$claimed)return$this->get($access,$row['id']);
            if($this->row($access,$row['id'])['status']!=='sending')return$this->get($access,$row['id']);
            $account=$this->current($access,$row);$attempted=true;$this->provider->set($account,$source,$hidden);
            if($this->provider->read($account,$source)!==$hidden)throw new DomainException('Moderationsergebnis nicht bestätigt.');
            $this->confirmed($access,$row,$before);
        }catch(MetaRejected){$this->state($access,$row['id'],'rejected');}
        catch(\Throwable){$fresh=$this->row($access,$row['id']);$this->state($access,$row['id'],$attempted||$fresh['status']==='sending'?'uncertain':'blocked');}
        return$this->get($access,$row['id']);
    }
    public function reconcile(Access $access,string $id):array
    {
        if($this->db->inTransaction())throw new DomainException('Ergebnisprüfung benötigt einen eigenen Vorgang.');$this->actor($access);$row=$this->row($access,$id);
        if(!in_array($row['status'],['sending','uncertain'],true))return$this->get($access,$id);
        $source=$this->payload($row)['source'];$account=$this->accounts->account($access,$source,$row['connection_id']);
        if($this->provider->read($account,$source)===(bool)$row['desired_hidden'])$this->confirmed($access,$row,(bool)$row['before_hidden']);
        return$this->get($access,$id);
    }
    public function close(Access $access,string $id,int $version,bool $checked):array
    {
        if($this->db->inTransaction())throw new DomainException('Abschluss benötigt einen eigenen Vorgang.');$this->actor($access);
        return Db::atomic($this->db,function()use($access,$id,$version,$checked):array{
            $row=$this->row($access,$id,true);if($row['status']==='closed')return$this->get($access,$id);
            if((int)$row['version']!==$version)throw new DomainException('Moderationsvorgang inzwischen geändert.');
            if(!in_array($row['status'],['pending','sending','uncertain'],true))throw new DomainException('Dieser Vorgang ist bereits abgeschlossen.');
            if($row['status']!=='pending'){
                if(!$checked)throw new DomainException('Anbieterstand prüfen und bestätigen.');
                if(!Db::run($this->db,'SELECT updated_at<=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 5 MINUTE) FROM native_social_moderations WHERE club_id=? AND id=?',[$row['club_id'],$id])->fetchColumn())throw new DomainException('Vorgang läuft möglicherweise noch. Später prüfen.');
            }
            Db::run($this->db,"UPDATE native_social_moderations SET status='closed',version=version+1,checked_by=?,updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND id=?",[$access->user()['id'],$row['club_id'],$id]);return$this->get($access,$id);
        });
    }
}
