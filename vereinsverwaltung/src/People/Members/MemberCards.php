<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\SecretBox;
use Kulturhus\Native\Integration\ManagerBridge;

/** Immutable personal cards; current head, actual dates and personal binding remain authoritative. */
final class MemberCards
{
    public function __construct(private PDO $db,private string $actor,private ?SecretBox $box=null,private ?\Closure $clock=null,private ?\Closure $identityGuard=null){}
    private function now():DateTimeImmutable{return($this->clock?($this->clock)():new DateTimeImmutable('now',new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Berlin'));}
    private function q(string $sql,array $args=[]):\PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return$q;}
    private function transaction(callable $work):mixed{return MemberCardTransactions::run($this->db,$work,'Mitgliederausweis wird gerade bearbeitet. Deine Angaben bleiben erhalten.');}

    private function access(string $contact,bool $write=false):array
    {
        if($this->identityGuard)try{($this->identityGuard)();}catch(DomainException){throw new DomainException('Zugang zum Mitgliederausweis nicht verfügbar.',403);}
        $raw=$this->q('SELECT club_id FROM actors WHERE id=? FOR SHARE NOWAIT',[$this->actor])->fetchColumn();
        if(!is_string($raw))throw new DomainException('Zugang zum Mitgliederausweis nicht verfügbar.',403);
        $role=$this->q('SELECT role_id FROM settings_role_assignments WHERE club_id=? AND actor_id=? FOR SHARE NOWAIT',[$raw,$this->actor])->fetchColumn();
        if(is_string($role))$this->q('SELECT id FROM settings_roles WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$raw,$role])->fetchColumn();
        $this->q("SELECT capability FROM actor_capabilities WHERE actor_id=? AND capability='local_finance' FOR SHARE NOWAIT",[$this->actor])->fetchAll();
        if(!ProductionRuntime::nativeManager())$this->q('SELECT enabled FROM processwire_identities WHERE actor_id=? FOR SHARE NOWAIT',[$this->actor])->fetchAll();
        try{$a=Permissions::actor($this->db,$this->actor);}catch(DomainException){throw new DomainException('Zugang zum Mitgliederausweis nicht verfügbar.',403);}$self=$a['contact_id']===$contact;
        if($a['role']==='editor'||(!$self&&!in_array($a['members_access'],$write?['write']:['read','write'],true)))throw new DomainException('Nur eigener Mitgliederausweis oder ausdrücklich berechtigte Mitgliederverwaltung.',403);
        $person=$this->q('SELECT kind,archived_at FROM contacts WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$a['club_id'],$contact])->fetch(PDO::FETCH_ASSOC);
        if(!$person||$person['kind']!=='person'||($person['archived_at']!==null&&$self))throw new DomainException('Mitgliederprofil für den Ausweis nicht verfügbar.',404);
        return$a;
    }
    private function head(string $club,string $contact,bool $write=false):?array
    {
        $r=$this->q('SELECT * FROM member_card_heads WHERE club_id=? AND contact_id=?'.($write?' FOR UPDATE NOWAIT':' FOR SHARE NOWAIT'),[$club,$contact])->fetch(PDO::FETCH_ASSOC);return$r?:null;
    }
    private static function hex(string $value,int $length):void{if(!preg_match('/\A[a-f0-9]{'.$length.'}\z/D',$value))throw new DomainException('Mitgliederausweis erneut öffnen und Angaben prüfen.',422);}
    private static function json(array $data):string{return json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private function replay(array $a,string $contact,string $request,string $operation):?array
    {
        self::hex($request,32);$row=$this->q('SELECT actor_id,contact_id,operation,result_json FROM member_card_commands WHERE club_id=? AND request_id=? FOR SHARE NOWAIT',[$a['club_id'],$request])->fetch(PDO::FETCH_ASSOC);
        if(!$row)return null;if($row['actor_id']!==$a['id']||$row['contact_id']!==$contact||$row['operation']!==$operation)throw new DomainException('Dieser Ausweisvorgang gehört zu einer anderen ursprünglichen Auswahl.',409);
        return json_decode($row['result_json'],true,32,JSON_THROW_ON_ERROR);
    }
    private function remember(array $a,string $contact,string $request,string $operation,array $before,array $after,string $time):array
    {
        $result=['card_id'=>$after['card_id'],'version'=>$after['version'],'revoked'=>$after['revoked']];
        $original=['result'=>$result,'before'=>$before,'after'=>$after];
        $this->q('INSERT INTO member_card_commands(club_id,request_id,actor_id,contact_id,operation,card_id,result_json,happened_at) VALUES(?,?,?,?,?,?,?,?)',[$a['club_id'],$request,$a['id'],$contact,$operation,$after['card_id'],self::json($original),$time]);
        $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'member-card.'.$operation,$after['card_id'],$after['version'],$time]);return$original;
    }
    private function lockClub(string $club):void
    {
        if(!$this->q('SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT',[$club])->fetchColumn())throw new DomainException('Verein für den Mitgliederausweis nicht verfügbar.',404);
    }
    public function state(string $contact):array
    {
        return$this->transaction(function()use($contact):array{
            $a=$this->access($contact);$head=$this->head($a['club_id'],$contact);$source=null;$error='';$now=$this->now();
            try{$source=(new MemberCardSource($this->db))->read($a['club_id'],$contact,$now->format('Y-m-d'));}catch(DomainException $e){if($e->getCode()===503)throw$e;$error=$e->getMessage();}
            $card=$head?$this->q('SELECT id,issued_at,valid_until,snapshot_json FROM member_cards WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$a['club_id'],$head['card_id']])->fetch(PDO::FETCH_ASSOC):null;
            if($head&&!$card)throw new DomainException('Ursprünglicher Mitgliederausweis nicht verfügbar.',409);
            return['own'=>$a['contact_id']===$contact,'today'=>$now->format('Y-m-d'),'latest_valid_until'=>min($now->modify('+1 year')->modify('-1 day')->format('Y-m-d'),$source['data']['ends_on']??'9999-12-31'),'contact_id'=>$contact,'version'=>$head?(int)$head['version']:0,'head'=>$head,'card'=>$card?self::view($card):null,'source'=>$source,'notice'=>$error,'may_issue'=>$a['contact_id']===$contact||$a['members_access']==='write'];
        });
    }
    private static function view(array $row):array
    {
        $s=json_decode($row['snapshot_json'],true,32,JSON_THROW_ON_ERROR);return['id'=>$row['id'],'issued_at'=>$row['issued_at'],'valid_until'=>$row['valid_until'],'name'=>$s['name'],'club_name'=>$s['club_name'],'member_number'=>$s['member_number']];
    }
    public function issue(string $contact,int $version,string $sourceHash,string $validUntil,string $request,bool $publicNameConfirmed):array
    {
        return$this->transaction(function()use($contact,$version,$sourceHash,$validUntil,$request,$publicNameConfirmed):array{
            $a=$this->access($contact,true);$this->lockClub($a['club_id']);if($replay=$this->replay($a,$contact,$request,'issue'))return$replay['result'];
            self::hex($sourceHash,64);if($version<0||!$publicNameConfirmed)throw new DomainException('Bitte die Angaben und die Namensanzeige bei der QR-Prüfung bestätigen.',422);
            $now=$this->now();$today=$now->format('Y-m-d');$source=(new MemberCardSource($this->db))->read($a['club_id'],$contact,$today,true);$head=$this->head($a['club_id'],$contact,true);
            if(($head?(int)$head['version']:0)!==$version||!hash_equals($source['fingerprint'],$sourceHash))throw new DomainException('Mitgliedschaft oder Ausweis wurde inzwischen geändert. Angaben bitte erneut prüfen.',409);
            MemberCardSource::day($validUntil);$max=$now->modify('+1 year')->modify('-1 day')->format('Y-m-d');if($source['data']['ends_on']!==null)$max=min($max,$source['data']['ends_on']);
            if($validUntil<$today||$validUntil>$max)throw new DomainException('Gültigkeit zwischen heute und '.$max.' wählen.',422);
            if(!$this->box)throw new DomainException('Privater Ausweisschlüssel ist nicht verfügbar.',503);
            $id=bin2hex(random_bytes(16));$token=bin2hex(random_bytes(32));$time=$now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');$s=$source['data'];$snapshot=['name'=>$s['name'],'club_name'=>$s['club_name'],'member_number'=>$s['member_number'],'origin'=>$s['origin'],'public_name_confirmation'=>['scope'=>'name-club-current-validity','confirmed'=>true,'statement'=>'Bei der QR-Prüfung werden Name, Verein und aktuelle Gültigkeit angezeigt.']];
            $cipher=$this->box->seal('member-card:'.$a['club_id'].':'.$id,['token'=>$token]);
            $this->q('INSERT INTO member_cards(club_id,id,contact_id,membership_id,issued_by,issued_at,valid_until,snapshot_json,source_json,source_hash,token_hash,token_cipher) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$a['club_id'],$id,$contact,$s['membership_id'],$a['id'],$time,$validUntil,self::json($snapshot),self::json($s),$sourceHash,hash('sha256',$token),$cipher]);
            $after=['card_id'=>$id,'version'=>$version+1,'revoked'=>false,'reason'=>''];
            if($head){$n=$this->q('UPDATE member_card_heads SET card_id=?,version=version+1,revoked=0,reason=\'\',updated_by=?,updated_at=? WHERE club_id=? AND contact_id=? AND version=?',[$id,$a['id'],$time,$a['club_id'],$contact,$version])->rowCount();if($n!==1)throw new DomainException('Ausweis inzwischen geändert. Angaben erneut prüfen.',409);}
            else$this->q('INSERT INTO member_card_heads(club_id,contact_id,card_id,version,revoked,reason,updated_by,updated_at) VALUES(?,?,?,1,0,\'\',?,?)',[$a['club_id'],$contact,$id,$a['id'],$time]);
            return$this->remember($a,$contact,$request,'issue',$head??['version'=>0],$after,$time)['result'];
        });
    }
    public function revoke(string $contact,int $version,string $id,string $request,string $reason):array
    {
        return$this->transaction(function()use($contact,$version,$id,$request,$reason):array{
            $a=$this->access($contact,true);$this->lockClub($a['club_id']);if($replay=$this->replay($a,$contact,$request,'revoke'))return$replay['result'];
            self::hex($id,32);MemberCardSource::text($reason,500);$head=$this->head($a['club_id'],$contact,true);
            if(!$head||$head['card_id']!==$id||(int)$head['version']!==$version||(bool)$head['revoked'])throw new DomainException('Ausweis inzwischen geändert oder bereits gesperrt. Angaben erneut prüfen.',409);
            $time=$this->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');$after=['card_id'=>$id,'version'=>$version+1,'revoked'=>true,'reason'=>$reason];
            if($this->q('UPDATE member_card_heads SET version=version+1,revoked=1,reason=?,updated_by=?,updated_at=? WHERE club_id=? AND contact_id=? AND card_id=? AND version=? AND revoked=0',[$reason,$a['id'],$time,$a['club_id'],$contact,$id,$version])->rowCount()!==1)throw new DomainException('Ausweis inzwischen geändert. Angaben erneut prüfen.',409);
            return$this->remember($a,$contact,$request,'revoke',$head,$after,$time)['result'];
        });
    }
    public function card(string $contact,string $id):array
    {
        return$this->transaction(function()use($contact,$id):array{
            $a=$this->access($contact);self::hex($id,32);$row=$this->q('SELECT * FROM member_cards WHERE club_id=? AND contact_id=? AND id=? FOR SHARE NOWAIT',[$a['club_id'],$contact,$id])->fetch(PDO::FETCH_ASSOC);
            if(!$row)throw new DomainException('Mitgliederausweis nicht verfügbar.',404);$head=$this->head($a['club_id'],$contact);$source=(new MemberCardSource($this->db))->read($a['club_id'],$contact,$this->now()->format('Y-m-d'));
            if(!self::valid($row,$head,$source['data'],$this->now()->format('Y-m-d')))throw new DomainException('Dieser Ausweis ist nicht mehr gültig. Aktuellen Ausweis öffnen oder erneuern.',409);
            if(!$this->box)throw new DomainException('Privater Ausweisschlüssel ist nicht verfügbar.',503);$token=$this->box->open('member-card:'.$a['club_id'].':'.$id,$row['token_cipher'])['token']??null;
            if(!is_string($token)||!hash_equals($row['token_hash'],hash('sha256',$token)))throw new DomainException('Original des Ausweises nicht verfügbar.',409);
            $v=self::view($row);$v['valid_until']=min($row['valid_until'],$source['data']['ends_on']??$row['valid_until']);$v['verify_url']=$source['data']['origin'].((class_exists(\Kulturhus\Native\Demo\Runtime::class)&&\Kulturhus\Native\Demo\Runtime::active())?'/demo':'').'/mitgliederausweis/pruefen?token='.$token;return$v;
        });
    }
    private static function valid(array $row,?array $head,array $source,string $today):bool
    {
        if(!$head||$head['card_id']!==$row['id']||(bool)$head['revoked']||$row['valid_until']<$today||$source['membership_id']!==$row['membership_id'])return false;
        $s=json_decode($row['snapshot_json'],true,32,JSON_THROW_ON_ERROR);foreach(['name','club_name','member_number','origin']as$key)if(($s[$key]??null)!==$source[$key])return false;return true;
    }
    public function commandResult(string $contact,string $request,string $operation):?array
    {
        if(!in_array($operation,['issue','revoke'],true))throw new DomainException('Ausweisaktion prüfen.',422);
        return$this->transaction(function()use($contact,$request,$operation):?array{$a=$this->access($contact);$original=$this->replay($a,$contact,$request,$operation);return$original['result']??null;});
    }
    public function history(string $contact,int $page=1):array
    {
        if($page<1||$page>1000000)throw new DomainException('Ausweisseite prüfen.',422);
        return$this->transaction(function()use($contact,$page):array{
            $a=$this->access($contact);$total=(int)$this->q('SELECT COUNT(*) FROM member_cards WHERE club_id=? AND contact_id=?',[$a['club_id'],$contact])->fetchColumn();$pages=max(1,(int)ceil($total/6));$page=min($page,$pages);
            $rows=$this->q('SELECT id,issued_at,valid_until,snapshot_json FROM member_cards WHERE club_id=? AND contact_id=? ORDER BY issued_at DESC,id DESC LIMIT 6 OFFSET '.(($page-1)*6),[$a['club_id'],$contact])->fetchAll(PDO::FETCH_ASSOC);
            return['rows'=>array_map(self::view(...),$rows),'total'=>$total,'pages'=>$pages,'page'=>$page];
        });
    }
    /** Scan is read-only. A token never authorizes a private card or another person's data. */
    public static function verify(PDO $db,#[\SensitiveParameter]string $token,string $nativeClub,?DateTimeImmutable $now=null):array
    {
        if(!preg_match('/\A[a-f0-9]{64}\z/D',$token))return['valid'=>false];$service=new self($db,'');$today=($now??new DateTimeImmutable())->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d');
        return$service->transaction(function()use($service,$token,$nativeClub,$today):array{
            $row=$service->q('SELECT club_id,id,contact_id,membership_id,valid_until,snapshot_json FROM member_cards WHERE token_hash=? FOR SHARE NOWAIT',[hash('sha256',$token)])->fetch(PDO::FETCH_ASSOC);
            if(!$row||ManagerBridge::clubKey($row['club_id'])!==$nativeClub)return['valid'=>false];$head=$service->head($row['club_id'],$row['contact_id']);
            try{$source=(new MemberCardSource($service->db))->read($row['club_id'],$row['contact_id'],$today);}catch(DomainException $e){if($e->getCode()===503)throw$e;return['valid'=>false];}
            if(!self::valid($row,$head,$source['data'],$today))return['valid'=>false];
            return['valid'=>true,'name'=>$source['data']['name'],'club_name'=>$source['data']['club_name'],'valid_until'=>min($row['valid_until'],$source['data']['ends_on']??$row['valid_until'])];
        });
    }
}
