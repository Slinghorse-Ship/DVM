<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\SecretBox;
use Kulturhus\Native\Integration\JsonHttp;

/** Central-superuser configuration; members receive only their own current signed pass. */
final class MemberCardWallet
{
    public function __construct(private PDO $db,private string $actor,private ?SecretBox $box,private ?\Closure $clock=null,private ?\Closure $transport=null,private ?\Closure $identityGuard=null){}
    private function now():DateTimeImmutable{return($this->clock?($this->clock)():new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'));}
    private function q(string $sql,array $args=[]):\PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return$q;}
    private function transaction(callable $work):mixed{return MemberCardTransactions::run($this->db,$work,'Wallet-Einrichtung wird gerade bearbeitet. Bitte erneut prüfen.');}

    private static function provider(string $provider):void{if(!in_array($provider,['apple','google'],true))throw new DomainException('Wallet-Anbieter prüfen.',422);}
    private function admin():array
    {
        if($this->identityGuard)try{($this->identityGuard)();}catch(DomainException){throw new DomainException('Wallet-Zugang nicht verfügbar.',403);}$a=$this->q('SELECT id,club_id,active,is_superuser,role FROM actors WHERE id=? FOR SHARE NOWAIT',[$this->actor])->fetch(PDO::FETCH_ASSOC);
        if(!$a||!(int)$a['active']||!(int)$a['is_superuser']||$a['role']==='editor')throw new DomainException('Nur der zentrale Superuser darf die Wallet-Zugänge einrichten.',403);return$a;
    }
    private function lockClub(string $club):void{if(!$this->q('SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT',[$club])->fetchColumn())throw new DomainException('Verein für die Wallet-Einrichtung nicht verfügbar.',404);}
    private function row(string $club,string $provider,bool $write=false):?array
    {
        self::provider($provider);$r=$this->q('SELECT * FROM member_card_wallet_accounts WHERE club_id=? AND provider=?'.($write?' FOR UPDATE NOWAIT':' FOR SHARE NOWAIT'),[$club,$provider])->fetch(PDO::FETCH_ASSOC);return$r?:null;
    }
    private static function json(array $v):string{return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private function redacted(?array $r):array
    {
        if(!$r)return['version'=>0,'configured'=>false,'enabled'=>false,'connected'=>false,'ready'=>false,'public'=>[],'checked_at'=>null];
        $meta=json_decode($r['public_json'],true,32,JSON_THROW_ON_ERROR);$ready=$r['ciphertext']!==null&&(bool)$r['enabled']&&$r['checked_at']!==null;
        if($r['provider']==='google')$ready=$ready&&($meta['publishing_confirmed']??false)===true;
        if($r['provider']==='apple')$ready=$ready&&is_string($meta['certificate_expires_at']??null)&&strtotime($meta['certificate_expires_at'])>$this->now()->getTimestamp();
        return['version'=>(int)$r['version'],'configured'=>$r['ciphertext']!==null,'enabled'=>(bool)$r['enabled'],'connected'=>$r['checked_at']!==null,'ready'=>$ready,'public'=>$meta,'checked_at'=>$r['checked_at']];
    }
    public function state():array
    {
        return$this->transaction(function():array{$a=$this->admin();return['apple'=>$this->redacted($this->row($a['club_id'],'apple')),'google'=>$this->redacted($this->row($a['club_id'],'google'))];});
    }
    private function open(array $a,string $provider,array $row):array
    {
        if(!$this->box||!is_string($row['ciphertext']))throw new DomainException('Wallet-Zugang ist nicht vollständig eingerichtet.',503);
        $value=$this->box->open('member-card-wallet:'.$a['club_id'].':'.$provider,$row['ciphertext']);if(!hash_equals($row['credential_hash'],hash('sha256',self::json($value))))throw new DomainException('Gespeicherten Wallet-Zugang erneut einrichten.',409);return$value;
    }
    private function store(string $provider,int $version,bool $enabled,#[\SensitiveParameter]array $credentials,array $metadata,bool $checked):array
    {
        return$this->transaction(function()use($provider,$version,$enabled,$credentials,$metadata,$checked):array{
            $a=$this->admin();$this->lockClub($a['club_id']);$old=$this->row($a['club_id'],$provider,true);if($version<0||($old?(int)$old['version']:0)!==$version)throw new DomainException('Wallet-Einrichtung inzwischen geändert. Aktuellen Stand bewusst öffnen.',409);
            if(!$this->box)throw new DomainException('Privater Ausweisschlüssel ist nicht verfügbar.',503);$time=$this->now()->format('Y-m-d H:i:s');$cipher=$this->box->seal('member-card-wallet:'.$a['club_id'].':'.$provider,$credentials);$hash=hash('sha256',self::json($credentials));$checkedAt=$checked?$time:null;
            if($old){$n=$this->q('UPDATE member_card_wallet_accounts SET version=version+1,enabled=?,public_json=?,ciphertext=?,credential_hash=?,checked_at=?,updated_by=?,updated_at=? WHERE club_id=? AND provider=? AND version=?',[(int)$enabled,self::json($metadata),$cipher,$hash,$checkedAt,$a['id'],$time,$a['club_id'],$provider,$version])->rowCount();if($n!==1)throw new DomainException('Wallet-Einrichtung inzwischen geändert.',409);}
            else$this->q('INSERT INTO member_card_wallet_accounts(club_id,provider,version,enabled,public_json,ciphertext,credential_hash,checked_at,updated_by,updated_at) VALUES(?,?,1,?,?,?,?,?,?,?)',[$a['club_id'],$provider,(int)$enabled,self::json($metadata),$cipher,$hash,$checkedAt,$a['id'],$time]);
            $this->audit($a,$provider,'configured',$version+1,$time);return$this->redacted($this->row($a['club_id'],$provider));
        });
    }
    private function audit(array $a,string $provider,string $action,int $version,string $time):void{$this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'member-card.wallet.'.$action,$provider,$version,$time]);}
    public function saveApple(int $version,bool $enabled,string $type,string $team,#[\SensitiveParameter]string $p12,#[\SensitiveParameter]string $password):array
    {
        $this->transaction(fn()=>$this->admin());$c=MemberCardWalletKeys::apple($type,$team,$p12,$password,$this->now()->getTimestamp());return$this->store('apple',$version,$enabled,$c,MemberCardWalletKeys::publicMetadata('apple',$c),true);
    }
    public function saveGoogle(int $version,bool $enabled,string $issuer,string $class,#[\SensitiveParameter]string $serviceAccount,bool $publishingConfirmed):array
    {
        $this->transaction(fn()=>$this->admin());$c=MemberCardWalletKeys::google($issuer,$class,$serviceAccount);$public=MemberCardWalletKeys::publicMetadata('google',$c)+['publishing_confirmed'=>$publishingConfirmed];return$this->store('google',$version,$enabled,$c,$public,false);
    }
    public function toggle(string $provider,int $version,bool $enabled,?bool $publishingConfirmed=null):array
    {
        return$this->transaction(function()use($provider,$version,$enabled,$publishingConfirmed):array{
            $a=$this->admin();$this->lockClub($a['club_id']);$r=$this->row($a['club_id'],$provider,true);if(!$r||(int)$r['version']!==$version||$r['ciphertext']===null)throw new DomainException('Wallet-Einrichtung inzwischen geändert oder nicht eingerichtet.',409);
            $meta=json_decode($r['public_json'],true,32,JSON_THROW_ON_ERROR);if($provider==='google'&&$publishingConfirmed!==null)$meta['publishing_confirmed']=$publishingConfirmed;$time=$this->now()->format('Y-m-d H:i:s');
            if($this->q('UPDATE member_card_wallet_accounts SET version=version+1,enabled=?,public_json=?,updated_by=?,updated_at=? WHERE club_id=? AND provider=? AND version=?',[(int)$enabled,self::json($meta),$a['id'],$time,$a['club_id'],$provider,$version])->rowCount()!==1)throw new DomainException('Wallet-Einrichtung inzwischen geändert.',409);
            $this->audit($a,$provider,'activation',$version+1,$time);return$this->redacted($this->row($a['club_id'],$provider));
        });
    }
    public function remove(string $provider,int $version):void
    {
        $this->transaction(function()use($provider,$version):void{
            $a=$this->admin();$this->lockClub($a['club_id']);self::provider($provider);$time=$this->now()->format('Y-m-d H:i:s');
            if($this->q("UPDATE member_card_wallet_accounts SET version=version+1,enabled=0,public_json='{}',ciphertext=NULL,credential_hash=?,checked_at=NULL,updated_by=?,updated_at=? WHERE club_id=? AND provider=? AND version=?",[hash('sha256',''),$a['id'],$time,$a['club_id'],$provider,$version])->rowCount()!==1)throw new DomainException('Wallet-Einrichtung inzwischen geändert.',409);$this->audit($a,$provider,'removed',$version+1,$time);
        });
    }
    private static function b64(string $v):string{return rtrim(strtr(base64_encode($v),'+/','-_'),'=');}
    private function remote(string $provider,string $method,string $path,#[\SensitiveParameter]array $headers,#[\SensitiveParameter]?string $encoded=null):array
    {
        try{$r=$this->transport?($this->transport)($provider,$method,$path,$headers,$encoded):JsonHttp::request($provider,$method,$path,$headers,null,$encoded);}catch(\Throwable){throw new DomainException('Die Wallet-Verbindung konnte nicht bestätigt werden. Zugang und Freigabe beim Anbieter prüfen.',503);}
        if(!is_array($r)||!is_int($r['status']??null)||!is_array($r['body']??null))throw new DomainException('Die Wallet-Verbindung konnte nicht bestätigt werden.',503);return$r;
    }
    /** OAuth and one class GET only. The GenericClass API has no reviewStatus field. */
    private function checkGoogle(#[\SensitiveParameter]array $credentials):void
    {
        $time=$this->now()->getTimestamp();$claims=['iss'=>$credentials['client_email'],'aud'=>'https://oauth2.googleapis.com/token','scope'=>'https://www.googleapis.com/auth/wallet_object.issuer','iat'=>$time,'exp'=>$time+3600];$message=self::b64(self::json(['alg'=>'RS256','typ'=>'JWT'])).'.'.self::b64(self::json($claims));
        $key=@openssl_pkey_get_private($credentials['private_key']);if($key===false||!@openssl_sign($message,$signature,$key,OPENSSL_ALGO_SHA256))throw new DomainException('Google-Wallet-Schlüssel prüfen.',422);$assertion=$message.'.'.self::b64($signature);
        $r=$this->remote('google-wallet-oauth','POST','/token',['Content-Type: application/x-www-form-urlencoded'],http_build_query(['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$assertion],'','&',PHP_QUERY_RFC3986));
        $token=$r['body']['access_token']??null;$type=$r['body']['token_type']??null;if($r['status']!==200||!is_string($token)||$token===''||strlen($token)>8192||preg_match('/[\r\n\x00]/',$token)||!is_string($type)||strtolower($type)!=='bearer')throw new DomainException('Google hat den Wallet-Zugang nicht bestätigt.',422);
        $id=str_replace('.','%2E',rawurlencode($credentials['class_id']));$r=$this->remote('google-wallet','GET','/walletobjects/v1/genericClass/'.$id.'?fields=id',['Authorization: Bearer '.$token]);
        if($r['status']!==200||($r['body']['id']??null)!==$credentials['class_id'])throw new DomainException('Die gewählte Google-Ausweisklasse ist für dieses Dienstkonto nicht verfügbar.',422);
    }
    public function probeGoogle(int $version):array
    {
        $source=$this->transaction(function()use($version):array{$a=$this->admin();$r=$this->row($a['club_id'],'google');if(!$r||(int)$r['version']!==$version)throw new DomainException('Wallet-Einrichtung inzwischen geändert.',409);return['actor'=>$a,'row'=>$r,'credentials'=>$this->open($a,'google',$r)];});
        $this->checkGoogle($source['credentials']);
        return$this->transaction(function()use($source,$version):array{$a=$this->admin();$this->lockClub($a['club_id']);$r=$this->row($a['club_id'],'google',true);if($a['club_id']!==$source['actor']['club_id']||!$r||(int)$r['version']!==$version||!hash_equals($r['credential_hash'],$source['row']['credential_hash']))throw new DomainException('Wallet-Zugang während der Prüfung geändert. Aktuellen Stand erneut prüfen.',409);$time=$this->now()->format('Y-m-d H:i:s');
            if($this->q("UPDATE member_card_wallet_accounts SET version=version+1,checked_at=?,updated_by=?,updated_at=? WHERE club_id=? AND provider='google' AND version=?",[$time,$a['id'],$time,$a['club_id'],$version])->rowCount()!==1)throw new DomainException('Wallet-Einrichtung inzwischen geändert.',409);$this->audit($a,'google','checked',$version+1,$time);return$this->redacted($this->row($a['club_id'],'google'));
        });
    }
    public function availability(string $contact):array
    {
        $cards=new MemberCards($this->db,$this->actor,$this->box,$this->clock,$this->identityGuard);$cards->state($contact);
        return$this->transaction(function():array{if($this->identityGuard)($this->identityGuard)();$a=Permissions::actor($this->db,$this->actor);$out=[];foreach(['apple','google']as$p){$s=$this->redacted($this->row($a['club_id'],$p));$out[$p]=$s['ready'];}return$out;});
    }
    private function originalForWallet(string $provider,string $contact,string $cardId):array
    {
        self::provider($provider);$cards=new MemberCards($this->db,$this->actor,$this->box,$this->clock,$this->identityGuard);
        $source=$this->transaction(function()use($cards,$provider,$contact,$cardId):array{$card=$cards->card($contact,$cardId);$a=Permissions::actor($this->db,$this->actor);if($a['contact_id']!==$contact&&$a['members_access']!=='write')throw new DomainException('Nur eigener Wallet-Ausweis oder Mitgliederverwaltung mit Schreibrecht.',403);$r=$this->row($a['club_id'],$provider);if(!$r||!$this->redacted($r)['ready'])throw new DomainException('Der Verein hat diese Wallet-Anbindung noch nicht freigegeben.',409);return['card'=>$card,'actor'=>$a,'row'=>$r,'credentials'=>$this->open($a,$provider,$r)];});
        if($provider==='google')$this->checkGoogle($source['credentials']);else$source['credentials']=MemberCardWalletKeys::appleStored($source['credentials'],$this->now()->getTimestamp());
        return$this->transaction(function()use($source,$cards,$provider,$contact,$cardId):array{
            $card=$cards->card($contact,$cardId);$a=Permissions::actor($this->db,$this->actor);if($a['contact_id']!==$contact&&$a['members_access']!=='write')throw new DomainException('Nur eigener Wallet-Ausweis oder Mitgliederverwaltung mit Schreibrecht.',403);$r=$this->row($a['club_id'],$provider);if($card!==$source['card']||$a['club_id']!==$source['actor']['club_id']||!$r||(int)$r['version']!==(int)$source['row']['version']||!hash_equals($r['credential_hash'],$source['row']['credential_hash'])||!$this->redacted($r)['ready'])throw new DomainException('Ausweis oder Wallet-Zugang während der Prüfung geändert. Ausweis erneut öffnen.',409);
            $issued=new DateTimeImmutable($card['issued_at'],new DateTimeZone('UTC'));$expires=new DateTimeImmutable($card['valid_until'].' 23:59:59',new DateTimeZone('Europe/Berlin'));$card['issued_at']=$issued->format(DATE_ATOM);$card['expires_at']=$expires->format(DATE_ATOM);return['card'=>$card,'credentials'=>$source['credentials']];
        });
    }
    public function googleSaveUrl(string $contact,string $cardId,string $locale='de'):string
    {
        $source=$this->originalForWallet('google',$contact,$cardId);$source['card']['locale']=$locale;return'https://pay.google.com/gp/v/save/'.MemberCardGoogleWallet::token($source['card'],$source['credentials'],$this->now()->getTimestamp());
    }
    public function applePass(string $contact,string $cardId,string $locale='de'):string
    {
        $source=$this->originalForWallet('apple',$contact,$cardId);$source['card']['locale']=$locale;$png=(string)file_get_contents(dirname(__DIR__,3).'/public/push-icon-192.png');$image=@imagecreatefromstring($png);if(!$image)throw new DomainException('Wallet-Ausweislogo ist derzeit nicht verfügbar.',503);$icons=[];foreach(['icon.png'=>29,'icon@2x.png'=>58]as$name=>$size){$out=imagecreatetruecolor($size,$size);imagecopyresampled($out,$image,0,0,0,0,$size,$size,imagesx($image),imagesy($image));ob_start();imagepng($out);$icons[$name]=ob_get_clean();}return MemberCardApplePass::build($source['card'],$source['credentials'],$icons,$this->now()->getTimestamp());
    }
}
