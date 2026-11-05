<?php declare(strict_types=1);
namespace Kulturhus\Native\Auth;
use Closure;
use DomainException;
use PDO;
use PDOException;
use Kulturhus\Native\Shared\{Access,Db};

/** Authentication only: never grants module rights, sends mail, or changes native_users. */
final class Service
{
    private const DUMMY='$2y$12$Axz32EQUzOWxORuWgiFnzuJ4hyfhHJyCrYSaeySFRZ.QljZdj5KYy';
    public function __construct(private PDO $db,#[\SensitiveParameter] private string $encryptionKey,private ?Closure $clock=null)
    {
        if(!extension_loaded('sodium')||strlen($encryptionKey)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES)throw new DomainException('Anmeldeschlüssel muss separat bereitgestellt werden.');
    }
    private function now():int{return $this->clock?($this->clock)():time();}
    private function atomic(callable $work):mixed
    {
        try{return Db::atomic($this->db,$work);}catch(PDOException $e){if(($e->errorInfo[1]??0)===1062)throw new Conflict('Konto oder Kennung bereits vorhanden.');if(in_array($e->errorInfo[1]??0,[1213,1205],true))throw new Conflict('Konto gleichzeitig geändert. Aktuellen Stand erneut prüfen.');throw $e;}
    }
    private static function email(string $email):string
    {
        $email=strtolower(trim($email));
        if(strlen($email)>254||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new DomainException('Gültige E-Mail-Adresse angeben.');return $email;
    }
    private static function password(#[\SensitiveParameter]string $password):void
    {
        if(!mb_check_encoding($password,'UTF-8')||mb_strlen($password,'UTF-8')<12||strlen($password)>72||str_contains($password,"\0"))throw new DomainException('Passwort: mindestens 12 Zeichen, höchstens 72 UTF-8-Bytes.');
    }
    private static function token(#[\SensitiveParameter]string $token):string
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new DomainException('Anmeldung oder Einmalkennung ungültig.');return hash('sha256',$token);
    }
    private function publicAccount(array $row):array
    {
        $result=['id'=>$row['id'],'actor_id'=>$row['actor_id'],'email'=>$row['email'],'login_name'=>$row['login_name']??null,'suspended'=>(bool)$row['suspended'],'active'=>(bool)$row['active'],'verified'=>(bool)$row['verified'],'version'=>(int)$row['version'],'totp_enabled'=>$row['totp_secret']!==null,'email_factor'=>(bool)$row['email_factor']];
        foreach(['name','club_id'] as $field)if(isset($row[$field]))$result[$field]=$row[$field];return $result;
    }
    private function account(string $id,bool $lock=false):array
    {
        $row=Db::run($this->db,'SELECT * FROM native_auth_accounts WHERE id=?'.($lock?' FOR UPDATE':''),[$id])->fetch();
        if(!$row)throw new DomainException('Konto nicht verfügbar.');return $row;
    }
    private function active(array $row):bool
    {
        return !$row['suspended']&&(!$row['email_factor']||$row['factor_email']===$row['email'])&&(bool)$row['active']&&(bool)$row['verified']&&$row['password_hash']!==null&&(bool)Db::run($this->db,'SELECT active FROM native_users WHERE id=?'.($this->db->inTransaction()?' FOR SHARE':''),[$row['actor_id']])->fetchColumn();
    }
    public function accounts(Access $admin):array
    {
        $owner=$admin->require('accounts','manage');
        $rows=Db::run($this->db,'SELECT a.*,u.name,u.club_id FROM native_auth_accounts a JOIN native_users u ON u.id=a.actor_id WHERE u.club_id=? ORDER BY u.name,a.id'.($this->db->inTransaction()?' FOR SHARE':''),[$owner['club_id']])->fetchAll();
        return array_map($this->publicAccount(...),$rows);
    }
    /** Call only from an authenticated account administrator. Invitations grant no module rights. */
    public function createInvitation(Access $admin,string $actorId,string $email,int $ttl=86400,?string $loginName=null,?int $expectedVersion=null):array
    {
        $email=self::email($email);if($ttl<60||$ttl>172800)throw new DomainException('Einladung höchstens zwei Tage gültig.');
        if($loginName!==null&&!preg_match('/\A[a-z0-9][a-z0-9.-]{0,89}\z/D',$loginName))throw new DomainException('Persönlichen Benutzernamen prüfen.');
        if($expectedVersion!==null&&($expectedVersion<0||$expectedVersion>4294967293))throw new DomainException('Gespeicherten Einladungsstand prüfen.');
        return $this->atomic(function()use($admin,$actorId,$email,$ttl,$loginName,$expectedVersion):array{
            $owner=$admin->require('accounts','manage');
            if($admin instanceof \Kulturhus\Native\Integration\MemberInvitationAccess)$admin->assertTarget($actorId);
            $target=Db::run($this->db,'SELECT id FROM native_users WHERE id=? AND club_id=? AND active=1 FOR UPDATE',[$actorId,$owner['club_id']])->fetchColumn();
            if(!$target)throw new DomainException('Aktive Person des verwalteten Vereins auswählen.');$actorId=(string)$target;
            $row=Db::run($this->db,'SELECT * FROM native_auth_accounts WHERE actor_id=? FOR UPDATE',[$actorId])->fetch();
            if($expectedVersion!==null&&(int)($row['version']??0)!==$expectedVersion)throw new Conflict('Einladung oder Konto inzwischen geändert. Aktuellen Stand neu öffnen.');
            if($row&&$row['suspended'])throw new DomainException('Gesperrte Einladung zuerst ausdrücklich freigeben.');
            $loginName=$loginName??($row['login_name']??null);
            if($loginName===null&&Db::run($this->db,'SELECT 1 FROM native_auth_accounts WHERE email=? AND actor_id<>? FOR UPDATE',[$email,$actorId])->fetchColumn())throw new Conflict('E-Mail ist bereits einem Konto zugeordnet. Bei persönlichen Familienzugängen einen eigenen Benutzernamen verwenden.');
            if($loginName!==null&&Db::run($this->db,'SELECT 1 FROM native_auth_accounts a JOIN native_users u ON u.id=a.actor_id WHERE a.email=? AND a.actor_id<>? AND (a.login_name IS NULL OR u.club_id<>?) LIMIT 1 FOR UPDATE',[$email,$actorId,$owner['club_id']])->fetchColumn())throw new Conflict('Bestehenden persönlichen Zugang und seine E-Mail-Zuordnung zuerst prüfen.');
            if($row&&($row['verified']||$row['password_hash']!==null))throw new Conflict('Das Konto ist bereits eingerichtet.');
            $id=$row['id']??bin2hex(random_bytes(16));
            if($row)Db::run($this->db,'UPDATE native_auth_accounts SET email=?,login_name=?,version=version+1 WHERE id=?',[$email,$loginName,$id]);
            else Db::run($this->db,'INSERT INTO native_auth_accounts(id,actor_id,email,login_name,created_at) VALUES(?,?,?,?,?)',[$id,$actorId,$email,$loginName,$this->now()]);
            $token=$this->issueToken($id,'invite',$ttl);return $token+['account'=>$this->publicAccount($this->account($id))];
        });
    }
    private static function identifier(string $identifier):string
    {
        $identifier=strtolower(trim($identifier));
        if(str_contains($identifier,'@'))return self::email($identifier);
        if(!preg_match('/\A[a-z0-9][a-z0-9.-]{0,89}\z/D',$identifier))throw new DomainException('E-Mail-Adresse oder persönlichen Benutzernamen angeben.');
        return $identifier;
    }
    /** Shared family mailboxes never choose one person's account by order or password. */
    private function identified(string $identifier,bool $lock=false):?array
    {
        $field=str_contains($identifier,'@')?'email':'login_name';
        $rows=Db::run($this->db,'SELECT * FROM native_auth_accounts WHERE '.$field.'=? LIMIT 2'.($lock?' FOR UPDATE':''),[$identifier])->fetchAll();
        return count($rows)===1?$rows[0]:null;
    }
    private function issueToken(string $id,string $purpose,int $ttl):array
    {
        Db::run($this->db,'UPDATE native_auth_tokens SET consumed_at=? WHERE account_id=? AND purpose=? AND consumed_at IS NULL',[$this->now(),$id,$purpose]);
        $token=bin2hex(random_bytes(32));$expires=$this->now()+$ttl;
        Db::run($this->db,'INSERT INTO native_auth_tokens(token_hash,account_id,purpose,expires_at,created_at) VALUES(?,?,?,?,?)',[hash('sha256',$token),$id,$purpose,$expires,$this->now()]);
        return ['token'=>$token,'expires_at'=>$expires];
    }
    private function consumeToken(string $hash,string $purpose):array
    {
        $token=Db::run($this->db,'SELECT * FROM native_auth_tokens WHERE token_hash=? AND purpose=? FOR UPDATE',[$hash,$purpose])->fetch();
        if(!$token||$token['consumed_at']!==null||(int)$token['expires_at']<=$this->now())throw new DomainException('Einmalkennung ungültig oder abgelaufen.');
        Db::run($this->db,'UPDATE native_auth_tokens SET consumed_at=? WHERE token_hash=?',[$this->now(),$hash]);return $this->account($token['account_id'],true);
    }
    public function acceptInvitation(#[\SensitiveParameter]string $token,#[\SensitiveParameter]string $password):array
    {
        $hash=self::token($token);self::password($password);$passwordHash=password_hash($password,PASSWORD_DEFAULT);
        return $this->atomic(function()use($hash,$passwordHash):array{
            $row=$this->consumeToken($hash,'invite');if($row['suspended'])throw new DomainException('Die Einladung wurde gesperrt.');
            if($row['verified']||$row['password_hash']!==null)throw new Conflict('Konto bereits eingerichtet.');
            if(!Db::run($this->db,'SELECT 1 FROM native_users WHERE id=? AND active=1 FOR SHARE',[$row['actor_id']])->fetchColumn())throw new DomainException('Die Einladung wurde gesperrt.');
            Db::run($this->db,'UPDATE native_auth_accounts SET password_hash=?,active=1,verified=1,version=version+1 WHERE id=?',[$passwordHash,$row['id']]);return $this->publicAccount($this->account($row['id']));
        });
    }
    /** Return value is for a trusted delivery adapter; a public UI must always give a generic answer. */
    public function requestReset(string $email,string $clientKey):?array
    {
        $this->limit('reset-client:'.$this->client($clientKey),5,900);$email=self::identifier($email);$this->limit('reset-email:'.$email,3,900);
        $candidate=$this->identified($email);if($candidate)$this->limit('reset-account:'.$candidate['id'],3,900);
        return $this->atomic(function()use($email):?array{
            $row=$this->identified($email,true);
            return $row&&$this->active($row)?$this->issueToken($row['id'],'reset',1800)+['account_id'=>$row['id'],'email'=>$row['email']]:null;
        });
    }
    public function resetPassword(#[\SensitiveParameter]string $token,#[\SensitiveParameter]string $newPassword):void
    {
        $hash=self::token($token);self::password($newPassword);$passwordHash=password_hash($newPassword,PASSWORD_DEFAULT);
        $this->atomic(function()use($hash,$passwordHash):void{
            $row=$this->consumeToken($hash,'reset');if(!$this->active($row))throw new DomainException('Konto nicht verfügbar.');
            Db::run($this->db,'UPDATE native_auth_accounts SET password_hash=?,pending_secret=NULL,pending_until=NULL,pending_session=NULL,version=version+1 WHERE id=?',[$passwordHash,$row['id']]);$this->revokeAll($row['id']);
        });
    }
    public function passwordLogin(string $email,#[\SensitiveParameter]string $password,string $clientKey):array
    {
        $client=$this->client($clientKey);$this->limit('login-client:'.$client,30,900);
        $email=self::identifier($email);$this->limit('login-email:'.$email,8,900);
        $candidate=$this->identified($email);
        if($candidate)$this->limit('login-account:'.$candidate['id'],8,900);
        $result=$this->atomic(function()use($email,$password,$client):array{
            $row=$this->identified($email,true);
            $valid=strlen($password)<=72&&!str_contains($password,"\0");
            $verified=PreservedPassword::verify($valid?$password:'invalid-password-length',($row['password_hash']??null)?:self::DUMMY,$this->encryptionKey,$row['id']??'');
            if(!$row||!$valid||!$verified||!$this->active($row))throw new DomainException('Anmeldedaten ungültig.');
            if(password_needs_rehash($row['password_hash'],PASSWORD_DEFAULT))Db::run($this->db,'UPDATE native_auth_accounts SET password_hash=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$row['id']]);
            if($row['email_factor']){$this->limit('email-login:'.$row['id'],5,300);return ['status'=>'second_factor']+$this->emailFactor()->issue($row,'login',null,hash('sha256',$client));}
            if($row['totp_secret']!==null){
                $challenge=bin2hex(random_bytes(32));$expires=$this->now()+300;
                Db::run($this->db,'INSERT INTO native_auth_challenges(token_hash,account_id,client_hash,expires_at) VALUES(?,?,?,?)',[hash('sha256',$challenge),$row['id'],hash('sha256',$client),$expires]);
                return ['status'=>'second_factor','challenge'=>$challenge,'expires_at'=>$expires];
            }
            return $this->session($row);
        });
        $this->clearLimit('login-email:'.$email);if($candidate)$this->clearLimit('login-account:'.$candidate['id']);return $result;
    }
    public function verifySecondFactor(#[\SensitiveParameter]string $challenge,#[\SensitiveParameter]string $code,string $clientKey):array
    {
        $hash=self::token($challenge);$client=$this->client($clientKey);$this->limit('factor-client:'.$client,30,900);$this->limit('factor-challenge:'.$hash,6,300);
        $candidate=Db::run($this->db,'SELECT account_id FROM native_auth_challenges WHERE token_hash=?',[$hash])->fetchColumn();
        if(!$candidate)$candidate=Db::run($this->db,'SELECT account_id FROM native_auth_email_codes WHERE id=? AND purpose="login"',[$hash])->fetchColumn();
        if($candidate)$this->limit('factor-account:'.$candidate,8,900);
        return $this->atomic(function()use($hash,$client,$code):array{
            $email=Db::run($this->db,'SELECT account_id FROM native_auth_email_codes WHERE id=? AND purpose="login"',[$hash])->fetchColumn();
            if($email){
                $row=$this->account($email,true);
                if(!$this->active($row)||!$row['email_factor']||$row['totp_secret']!==null)throw new DomainException('Zweiter Anmeldeschritt ungültig.');
                $this->emailFactor()->consume($row,'login',$hash,$code,null,hash('sha256',$client));
                $this->clearLimit('factor-account:'.$row['id']);return $this->session($row);
            }
            $challenge=Db::run($this->db,'SELECT * FROM native_auth_challenges WHERE token_hash=? FOR UPDATE',[$hash])->fetch();
            if(!$challenge||$challenge['consumed_at']!==null||(int)$challenge['expires_at']<=$this->now()||!hash_equals($challenge['client_hash'],hash('sha256',$client)))throw new DomainException('Zweiter Anmeldeschritt ungültig.');
            $row=$this->account($challenge['account_id'],true);if(!$this->active($row)||$row['totp_secret']===null||!$this->factor($row,$code))throw new DomainException('Zweiter Anmeldeschritt ungültig.');
            Db::run($this->db,'UPDATE native_auth_challenges SET consumed_at=? WHERE token_hash=?',[$this->now(),$hash]);
            $this->clearLimit('factor-account:'.$row['id']);return $this->session($this->account($row['id']));
        });
    }
    private function session(array $row,string $mode='standard'):array
    {
        return Sessions::create($this->db,$row['id'],$this->now(),$mode)+['account'=>$this->publicAccount($row)];
    }
    private function sessionAccount(#[\SensitiveParameter]string $token,bool $lock=false):array
    {
        $hash=self::token($token);
        $row=Db::run($this->db,'SELECT a.*,u.name,u.club_id,s.duration_mode AS session_mode,s.created_at AS session_created_at,s.expires_at AS session_expires_at FROM native_auth_sessions s JOIN native_auth_accounts a ON a.id=s.account_id JOIN native_users u ON u.id=a.actor_id WHERE s.token_hash=? AND s.revoked_at IS NULL AND s.expires_at>? AND a.active=1 AND a.verified=1 AND u.active=1'.($lock?' FOR UPDATE':($this->db->inTransaction()?' FOR SHARE':'')),[$hash,$this->now()])->fetch();
        if(!$row||!$this->active($row))throw new DomainException('Sitzung abgelaufen oder nicht verfügbar.');$row['session_expires_at']=Sessions::touch($this->db,$hash,$row,$this->now());return $row;
    }
    public function resolve(#[\SensitiveParameter]string $sessionToken):array{return $this->publicAccount($this->sessionAccount($sessionToken));}
    public function sessionDuration(#[\SensitiveParameter]string $sessionToken):array
    {
        $row=$this->sessionAccount($sessionToken);
        return ['mode'=>$row['session_mode'],'expires_at'=>(int)$row['session_expires_at']];
    }
    /** Bounded own-account metadata; identifiers cannot be used as bearer tokens. */
    public function sessions(#[\SensitiveParameter]string $sessionToken,mixed $page=1):array
    {
        if((!is_int($page)&&!is_string($page))||!preg_match('/\A[1-9][0-9]{0,6}\z/D',(string)$page)||(int)$page>1000000)throw new DomainException('Anmeldungsseite prüfen.');
        return $this->atomic(function()use($sessionToken,$page):array{
            $account=$this->sessionAccount($sessionToken,true);$now=$this->now();$current=self::token($sessionToken);
            $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_auth_sessions WHERE account_id=? AND revoked_at IS NULL AND expires_at>?',[$account['id'],$now])->fetchColumn();
            $pages=max(1,(int)ceil($total/6));$page=min((int)$page,$pages);
            $rows=Db::run($this->db,'SELECT token_hash AS id,created_at,last_seen,expires_at,duration_mode FROM native_auth_sessions WHERE account_id=? AND revoked_at IS NULL AND expires_at>? ORDER BY (token_hash=?) DESC,created_at DESC,token_hash DESC LIMIT 6 OFFSET '.(($page-1)*6),[$account['id'],$now,$current])->fetchAll();
            foreach($rows as&$row){$row['current']=hash_equals($current,$row['id']);foreach(['created_at','last_seen','expires_at']as$key)$row[$key]=$row[$key]===null?null:(int)$row[$key];}unset($row);
            return compact('rows','total','pages','page');
        });
    }
    /** Close exactly one other own login; repeated closure changes no historical row. */
    public function revokeSession(#[\SensitiveParameter]string $sessionToken,string $id):bool
    {
        if(!preg_match('/\A[a-f0-9]{64}\z/D',$id))throw new DomainException('Anmeldung nicht verfügbar.');
        return $this->atomic(function()use($sessionToken,$id):bool{
            $account=$this->sessionAccount($sessionToken,true);
            if(hash_equals(self::token($sessionToken),$id))throw new DomainException('Für diese Anmeldung bitte „Auf diesem Gerät abmelden“ verwenden.');
            $row=Db::run($this->db,'SELECT revoked_at,expires_at FROM native_auth_sessions WHERE token_hash=? AND account_id=? FOR UPDATE',[$id,$account['id']])->fetch();
            if(!$row)throw new DomainException('Anmeldung nicht verfügbar.');
            if($row['revoked_at']!==null||(int)$row['expires_at']<=$this->now())return false;
            Db::run($this->db,'UPDATE native_auth_sessions SET revoked_at=? WHERE token_hash=? AND account_id=? AND revoked_at IS NULL',[$this->now(),$id,$account['id']]);
            Db::run($this->db,"UPDATE native_auth_email_codes SET consumed_at=?,cipher=NULL,status=IF(status='queued','blocked',status) WHERE account_id=? AND session_hash=? AND consumed_at IS NULL",[$this->now(),$account['id'],$id]);
            return true;
        });
    }
    public function selectSessionDuration(#[\SensitiveParameter]string $sessionToken,string $mode):array
    {
        Sessions::validate($mode);
        return $this->atomic(function()use($sessionToken,$mode):array{
            $row=$this->sessionAccount($sessionToken,true);$this->logout($sessionToken);
            return $this->session($row,$mode);
        });
    }
    /** Opaque snapshot for a sensitive operation; never exposes password or factor material. */
    public function reauthenticationState(#[\SensitiveParameter] string $sessionToken): array
    {
        $row = $this->sessionAccount($sessionToken);
        return [
            'mfa' => $row['totp_secret'] !== null || (bool)$row['email_factor'],
            'factor' => $row['email_factor'] ? 'email' : ($row['totp_secret'] !== null ? 'totp' : 'none'),
            'email' => '',
            'stamp' => hash_hmac('sha256', json_encode([
                $row['id'], $row['actor_id'], (int) $row['version'],
                $row['password_hash'], $row['totp_secret'], (bool)$row['email_factor'], $row['factor_email'], self::token($sessionToken),
            ], JSON_THROW_ON_ERROR), $this->encryptionKey),
        ];
    }
    /** Account-wide limits persist across sessions and failed password checks. */
    public function reauthenticatePassword(#[\SensitiveParameter] string $sessionToken, #[\SensitiveParameter] string $password): bool
    {
        $row = $this->sessionAccount($sessionToken);
        $this->limit('stepup-password:' . $row['id'], 5, 900);
        return $this->atomic(function () use ($sessionToken, $password): bool {
            $row = $this->sessionAccount($sessionToken, true);
            $this->checkPassword($row, $password);
            if($row['email_factor'])$this->emailFactor()->issue($row,'stepup',self::token($sessionToken),null);
            return true;
        });
    }
    /** Uses the original TOTP/recovery or action-bound email factor without a new login. */
    public function reauthenticateFactor(#[\SensitiveParameter] string $sessionToken, #[\SensitiveParameter] string $code, string $expectedStamp): bool
    {
        $row = $this->sessionAccount($sessionToken);
        $this->limit('stepup-factor:' . $row['id'], 5, 900);
        return $this->atomic(function () use ($sessionToken, $code, $expectedStamp): bool {
            $row = $this->sessionAccount($sessionToken, true);
            $state = $this->reauthenticationState($sessionToken);
            if (!$state['mfa'] || !hash_equals($state['stamp'], $expectedStamp)) {
                throw new DomainException('Sicherheitscode ungültig oder Konto inzwischen geändert.');
            }
            if($row['email_factor']){
                $factor=$this->emailFactor();$factor->consume($row,'stepup',$factor->latest($row,'stepup',self::token($sessionToken)),$code,self::token($sessionToken),null);
            }elseif(!$this->factor($row,$code))throw new DomainException('Sicherheitscode ungültig oder Konto inzwischen geändert.');
            return true;
        });
    }
    public function logout(#[\SensitiveParameter]string $sessionToken):void
    {Db::run($this->db,'UPDATE native_auth_sessions SET revoked_at=? WHERE token_hash=? AND revoked_at IS NULL',[$this->now(),self::token($sessionToken)]);}
    public function revokeSessions(#[\SensitiveParameter]string $sessionToken):void
    {$this->atomic(function()use($sessionToken):void{$row=$this->sessionAccount($sessionToken,true);$this->revokeAll($row['id']);});}
    private function revokeAll(string $id,?string $keepHash=null):void
    {
        $sql='UPDATE native_auth_sessions SET revoked_at=? WHERE account_id=? AND revoked_at IS NULL';$args=[$this->now(),$id];
        if($keepHash!==null){$sql.=' AND token_hash<>?';$args[]=$keepHash;}Db::run($this->db,$sql,$args);
        Db::run($this->db,'UPDATE native_auth_challenges SET consumed_at=? WHERE account_id=? AND consumed_at IS NULL',[$this->now(),$id]);
        Db::run($this->db,"UPDATE native_auth_email_codes SET consumed_at=?,cipher=NULL,status=IF(status='queued','blocked',status) WHERE account_id=? AND consumed_at IS NULL",[$this->now(),$id]);
    }
    public function changePassword(#[\SensitiveParameter]string $sessionToken,#[\SensitiveParameter]string $currentPassword,#[\SensitiveParameter]string $newPassword):array
    {
        self::password($newPassword);$hash=password_hash($newPassword,PASSWORD_DEFAULT);$this->limit('reauth:'.self::token($sessionToken),8,900);
        return $this->atomic(function()use($sessionToken,$currentPassword,$hash):array{
            $row=$this->sessionAccount($sessionToken,true);$this->checkPassword($row,$currentPassword);
            Db::run($this->db,'UPDATE native_auth_accounts SET password_hash=?,version=version+1,pending_secret=NULL,pending_until=NULL,pending_session=NULL WHERE id=?',[$hash,$row['id']]);
            Db::run($this->db,'UPDATE native_auth_tokens SET consumed_at=? WHERE account_id=? AND purpose="reset" AND consumed_at IS NULL',[$this->now(),$row['id']]);
            $this->revokeAll($row['id']);return $this->session($this->account($row['id']),$row['session_mode']);
        });
    }
    /** Only the authenticated login address; person/contact/subscriber records are separate. */
    private function emailAvailable(array $account,string $email):void
    {
        $others=Db::run($this->db,'SELECT a.login_name,u.club_id FROM native_auth_accounts a JOIN native_users u ON u.id=a.actor_id WHERE a.email=? AND a.id<>? FOR UPDATE',[$email,$account['id']])->fetchAll();
        foreach($others as$other)if($account['login_name']===null||$other['login_name']===null||$other['club_id']!==$account['club_id'])throw new Conflict('Diese Login-Adresse ist bereits einem anderen Zugang zugeordnet.');
    }
    public function beginEmailChange(#[\SensitiveParameter]string $sessionToken,#[\SensitiveParameter]string $password,string $email,int $expectedVersion):array
    {
        $email=self::email($email);$current=$this->sessionAccount($sessionToken);$this->limit('email-address-password:'.$current['id'],5,900);
        return $this->atomic(function()use($sessionToken,$password,$email,$expectedVersion):array{
            $row=$this->sessionAccount($sessionToken,true);$this->checkPassword($row,$password);
            if((int)$row['version']!==$expectedVersion)throw new Conflict('Konto inzwischen geändert. Aktuellen Stand neu laden.');
            if($row['email']===$email)throw new DomainException('Eine neue Login-E-Mail-Adresse eingeben.');$this->emailAvailable($row,$email);
            $recipient=array_replace($row,['email'=>$email,'previous_email'=>$row['email']]);$code=$this->emailFactor()->issue($recipient,'address',self::token($sessionToken),null);
            return ['id'=>hash('sha256',$code['challenge']),'email'=>$email,'expires_at'=>$code['expires_at'],'status'=>'queued'];
        });
    }
    /** Recover an accepted request after a lost response without sending another code. */
    public function emailChangeState(#[\SensitiveParameter]string $sessionToken):?array
    {
        $account=$this->sessionAccount($sessionToken);$row=Db::run($this->db,"SELECT id,email,expires_at,status FROM native_auth_email_codes WHERE account_id=? AND purpose='address' AND session_hash=? AND account_version=? AND consumed_at IS NULL AND expires_at>? ORDER BY created_at DESC,id DESC LIMIT 1",[$account['id'],self::token($sessionToken),$account['version'],$this->now()])->fetch();
        if(!$row)return null;$row['expires_at']=(int)$row['expires_at'];return$row;
    }
    public function confirmEmailChange(#[\SensitiveParameter]string $sessionToken,string $id,#[\SensitiveParameter]string $code):array
    {
        if(!preg_match('/\A[a-f0-9]{64}\z/D',$id))throw new DomainException('Eigene E-Mail-Bestätigung öffnen.');$current=$this->sessionAccount($sessionToken);$this->limit('email-address-code:'.$current['id'],5,900);
        return $this->atomic(function()use($sessionToken,$id,$code):array{
            $account=$this->sessionAccount($sessionToken,true);$pending=Db::run($this->db,"SELECT email FROM native_auth_email_codes WHERE id=? AND account_id=? AND purpose='address' FOR UPDATE",[$id,$account['id']])->fetch();if(!$pending)throw new DomainException('Eigene E-Mail-Bestätigung öffnen.');
            $email=self::email($pending['email']);$this->emailAvailable($account,$email);$this->emailFactor()->consume(array_replace($account,['email'=>$email]),'address',$id,$code,self::token($sessionToken),null);
            Db::run($this->db,'UPDATE native_auth_accounts SET email=?,factor_email=IF(email_factor=1,?,factor_email),version=version+1,pending_secret=NULL,pending_until=NULL,pending_session=NULL WHERE id=?',[$email,$email,$account['id']]);
            Db::run($this->db,'UPDATE native_auth_tokens SET consumed_at=? WHERE account_id=? AND consumed_at IS NULL',[$this->now(),$account['id']]);$this->revokeAll($account['id']);return$this->session($this->account($account['id']),$account['session_mode']);
        });
    }
    public function cancelEmailChange(#[\SensitiveParameter]string $sessionToken,string $id):void
    {
        if(!preg_match('/\A[a-f0-9]{64}\z/D',$id))throw new DomainException('Eigene E-Mail-Bestätigung öffnen.');$this->atomic(function()use($sessionToken,$id):void{
            $account=$this->sessionAccount($sessionToken,true);$row=Db::run($this->db,"SELECT session_hash FROM native_auth_email_codes WHERE id=? AND account_id=? AND purpose='address' FOR UPDATE",[$id,$account['id']])->fetch();if(!$row||$row['session_hash']!==self::token($sessionToken))throw new DomainException('Eigene E-Mail-Bestätigung öffnen.');
            Db::run($this->db,"UPDATE native_auth_email_codes SET consumed_at=COALESCE(consumed_at,?),cipher=NULL,status=IF(status='queued','blocked',status) WHERE id=?",[$this->now(),$id]);
        });
    }
    /** An alias changes no person, mailbox, role, password, factor or authenticated device. */
    public function changeLoginName(#[\SensitiveParameter]string $sessionToken,#[\SensitiveParameter]string $password,string $name,int $expectedVersion):array
    {
        $name=strtolower(trim($name));
        if(!preg_match('/\A[a-z0-9][a-z0-9.-]{0,89}\z/D',$name)||$expectedVersion<1||$expectedVersion>4294967294)throw new DomainException('Benutzernamen mit Kleinbuchstaben, Zahlen, Punkt oder Bindestrich und den aktuellen Kontostand angeben.');
        $current=$this->sessionAccount($sessionToken);
        // Failed password attempts count across devices and survive the subsequent rollback.
        $this->limit('login-name-password:'.$current['id'],5,900);
        return $this->atomic(function()use($sessionToken,$password,$name,$expectedVersion):array{
            $row=$this->sessionAccount($sessionToken,true);$this->checkPassword($row,$password);
            if((int)$row['version']!==$expectedVersion)throw new Conflict('Konto inzwischen geändert. Aktuellen Stand neu laden.');
            if($row['login_name']===$name)return $this->publicAccount($row);
            Db::run($this->db,'UPDATE native_auth_accounts SET login_name=?,version=version+1 WHERE id=?',[$name,$row['id']]);
            Db::run($this->db,'INSERT INTO native_auth_login_names(account_id,account_version,previous_name,login_name,changed_at) VALUES(?,?,?,?,?)',[$row['id'],$expectedVersion+1,$row['login_name'],$name,$this->now()]);
            return $this->publicAccount($this->account($row['id']));
        });
    }
    public function beginTotp(#[\SensitiveParameter]string $sessionToken,#[\SensitiveParameter]string $password):array
    {
        $this->limit('reauth:'.self::token($sessionToken),8,900);
        return $this->atomic(function()use($sessionToken,$password):array{
            $row=$this->sessionAccount($sessionToken,true);$this->checkPassword($row,$password);
            if($row['totp_secret']!==null||$row['email_factor'])throw new DomainException('Zweiter Faktor ist bereits eingerichtet.');
            $secret=Totp::secret();$expires=$this->now()+600;
            Db::run($this->db,'UPDATE native_auth_accounts SET pending_secret=?,pending_until=?,pending_session=? WHERE id=?',[$this->encrypt($row['id'],$secret),$expires,self::token($sessionToken),$row['id']]);
            return ['secret'=>$secret,'otpauth_uri'=>'otpauth://totp/'.rawurlencode('DVM:'.$row['email']).'?secret='.$secret.'&issuer=DVM&algorithm=SHA1&digits=6&period=30','expires_at'=>$expires];
        });
    }
    public function confirmTotp(#[\SensitiveParameter]string $sessionToken,#[\SensitiveParameter]string $code):array
    {
        $this->limit('enroll:'.self::token($sessionToken),6,600);
        return $this->atomic(function()use($sessionToken,$code):array{
            $row=$this->sessionAccount($sessionToken,true);
            if($row['totp_secret']!==null||$row['email_factor']||$row['pending_secret']===null||(int)$row['pending_until']<=$this->now()||!hash_equals($row['pending_session']??'',self::token($sessionToken)))throw new DomainException('Einrichtung abgelaufen oder ungültig.');
            $counter=Totp::match($this->decrypt($row['id'],$row['pending_secret']),$code,$this->now(),-1);
            if($counter===null)throw new DomainException('Bestätigungscode ungültig.');
            Db::run($this->db,'UPDATE native_auth_accounts SET totp_secret=pending_secret,totp_counter=?,pending_secret=NULL,pending_until=NULL,pending_session=NULL,version=version+1 WHERE id=?',[$counter,$row['id']]);
            Db::run($this->db,'DELETE FROM native_auth_recovery WHERE account_id=?',[$row['id']]);$codes=[];
            for($i=0;$i<10;$i++){$raw=bin2hex(random_bytes(10));$codes[]=implode('-',str_split($raw,5));Db::run($this->db,'INSERT INTO native_auth_recovery(account_id,code_hash) VALUES(?,?)',[$row['id'],hash('sha256',$raw)]);}
            // The confirming session has just proved the second factor; older sessions have not.
            $this->revokeAll($row['id'],self::token($sessionToken));return ['recovery_codes'=>$codes,'account'=>$this->publicAccount($this->account($row['id']))];
        });
    }
    public function disableTotp(#[\SensitiveParameter]string $sessionToken,#[\SensitiveParameter]string $password,#[\SensitiveParameter]string $code):void
    {
        $row=$this->sessionAccount($sessionToken);$this->limit('factor-account:'.$row['id'],8,900);$this->limit('reauth:'.self::token($sessionToken),8,900);
        $this->atomic(function()use($sessionToken,$password,$code):void{
            $row=$this->sessionAccount($sessionToken,true);$this->checkPassword($row,$password);
            if($row['totp_secret']===null||!$this->factor($row,$code))throw new DomainException('Zweiter Faktor ungültig.');
            Db::run($this->db,'UPDATE native_auth_accounts SET totp_secret=NULL,totp_counter=-1,pending_secret=NULL,pending_until=NULL,pending_session=NULL,version=version+1 WHERE id=?',[$row['id']]);
            Db::run($this->db,'DELETE FROM native_auth_recovery WHERE account_id=?',[$row['id']]);$this->revokeAll($row['id'],self::token($sessionToken));
        });
    }
    private function emailFactor(): EmailFactor
    {
        return new EmailFactor($this->db,new \Kulturhus\Native\Shared\SecretBox($this->encryptionKey),$this->clock);
    }
    /** Private delivery adapter; no password or code is returned to an HTTP caller. */
    public function deliverEmailFactors(string $club, ?callable $send, int $limit=20): array
    {
        return $this->emailFactor()->run($club,$send,$limit);
    }
    public function beginEmailFactor(#[\SensitiveParameter]string $sessionToken, #[\SensitiveParameter]string $password, bool $enable): array
    {
        $row=$this->sessionAccount($sessionToken);$this->limit('email-security:'.$row['id'],5,900);
        return $this->atomic(function()use($sessionToken,$password,$enable):array{
            $row=$this->sessionAccount($sessionToken,true);$this->checkPassword($row,$password);
            if($row['totp_secret']!==null||$enable===(bool)$row['email_factor'])throw new DomainException('Aktuellen Faktorstatus neu laden.');
            return $this->emailFactor()->issue($row,$enable?'enable':'disable',self::token($sessionToken),null);
        });
    }
    public function confirmEmailFactor(#[\SensitiveParameter]string $sessionToken, #[\SensitiveParameter]string $challenge, #[\SensitiveParameter]string $code, bool $enable): void
    {
        $hash=self::token($challenge);$row=$this->sessionAccount($sessionToken);$this->limit('email-security-code:'.$row['id'],5,900);
        $this->atomic(function()use($sessionToken,$hash,$code,$enable):void{
            $row=$this->sessionAccount($sessionToken,true);
            if($row['totp_secret']!==null||$enable===(bool)$row['email_factor'])throw new DomainException('Aktuellen Faktorstatus neu laden.');
            $this->emailFactor()->consume($row,$enable?'enable':'disable',$hash,$code,self::token($sessionToken),null);
            Db::run($this->db,'UPDATE native_auth_accounts SET email_factor=?,factor_email=?,pending_secret=NULL,pending_until=NULL,pending_session=NULL,version=version+1 WHERE id=?',[(int)$enable,$enable?$row['email']:null,$row['id']]);
            $this->revokeAll($row['id'],self::token($sessionToken));
            Db::run($this->db,"UPDATE native_auth_email_codes SET consumed_at=?,cipher=NULL,status=IF(status='queued','blocked',status) WHERE account_id=? AND consumed_at IS NULL",[$this->now(),$row['id']]);
        });
    }
    private function checkPassword(array $row,#[\SensitiveParameter]string $password):void
    {if(strlen($password)>72||str_contains($password,"\0")||!PreservedPassword::verify($password,$row['password_hash']??self::DUMMY,$this->encryptionKey,$row['id']))throw new DomainException('Passwort ungültig.');}
    private function factor(array $row,#[\SensitiveParameter]string $code):bool
    {
        if($row['totp_secret']===null)return false;
        if(preg_match('/^[0-9]{6}$/D',$code)){
            $counter=Totp::match($this->decrypt($row['id'],$row['totp_secret']),$code,$this->now(),(int)$row['totp_counter']);
            if($counter===null)return false;Db::run($this->db,'UPDATE native_auth_accounts SET totp_counter=? WHERE id=?',[$counter,$row['id']]);return true;
        }
        $normalized=strtolower(str_replace('-','',trim($code)));if(!preg_match('/^[a-f0-9]{20}$/D',$normalized))return false;
        $changed=Db::run($this->db,'UPDATE native_auth_recovery SET used_at=? WHERE account_id=? AND code_hash=? AND used_at IS NULL AND revoked_at IS NULL',[$this->now(),$row['id'],hash('sha256',$normalized)]);return $changed->rowCount()===1;
    }
    public function setActive(Access $admin,string $id,bool $active,int $expectedVersion):array
    {
        return $this->atomic(function()use($admin,$id,$active,$expectedVersion):array{
            $owner=$admin->require('accounts','manage');$row=$this->account($id,true);
            if(!$active&&$row['actor_id']===$owner['id'])throw new DomainException('Der eigene Verwaltungszugang bleibt aktiv.');
            if(!Db::run($this->db,'SELECT id FROM native_users WHERE id=? AND club_id=? FOR SHARE',[$row['actor_id'],$owner['club_id']])->fetchColumn())throw new DomainException('Konto gehört nicht zum verwalteten Verein.');
            if((int)$row['version']!==$expectedVersion)throw new Conflict('Konto inzwischen geändert.');
            if($active&&(!$row['verified']||$row['password_hash']===null))throw new DomainException('Einladung zuerst abschließen.');
            Db::run($this->db,'UPDATE native_auth_accounts SET active=?,suspended=?,version=version+1 WHERE id=?',[(int)$active,(int)!$active,$id]);
            if(!$active){$this->revokeAll($id);Db::run($this->db,'UPDATE native_auth_tokens SET consumed_at=? WHERE account_id=? AND consumed_at IS NULL',[$this->now(),$id]);Db::run($this->db,'UPDATE native_auth_accounts SET pending_secret=NULL,pending_until=NULL,pending_session=NULL WHERE id=?',[$id]);}
            return $this->publicAccount($this->account($id));
        });
    }
    private function encrypt(string $id,#[\SensitiveParameter]string $secret):string
    {
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);$payload=json_encode(['id'=>$id,'secret'=>$secret],JSON_THROW_ON_ERROR);
        return base64_encode($nonce.sodium_crypto_secretbox($payload,$nonce,$this->encryptionKey));
    }
    private function decrypt(string $id,#[\SensitiveParameter]string $encrypted):string
    {
        $bytes=base64_decode($encrypted,true);if($bytes===false||strlen($bytes)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)throw new DomainException('Zweiter Faktor nicht verfügbar.');
        $value=sodium_crypto_secretbox_open(substr($bytes,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($bytes,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$this->encryptionKey);
        if($value===false)throw new DomainException('Zweiter Faktor nicht verfügbar.');$payload=json_decode($value,true,8,JSON_THROW_ON_ERROR);
        if(($payload['id']??'')!==$id||!is_string($payload['secret']??null))throw new DomainException('Zweiter Faktor nicht verfügbar.');return $payload['secret'];
    }
    private function client(string $key):string
    {if($key===''||strlen($key)>200||preg_match('/[\x00-\x1f\x7f]/',$key))throw new DomainException('Ungültige lokale Clientkennung.');return $key;}
    private function limit(string $scope,int $max,int $window):void
    {
        $allowed=$this->atomic(function()use($scope,$max,$window):bool{
            $hash=hash('sha256',$scope);$now=$this->now();Db::run($this->db,'INSERT INTO native_auth_limits(scope_hash,attempts,expires_at) VALUES(?,0,?) ON DUPLICATE KEY UPDATE scope_hash=VALUES(scope_hash)',[$hash,$now+$window]);
            $row=Db::run($this->db,'SELECT attempts,expires_at FROM native_auth_limits WHERE scope_hash=? FOR UPDATE',[$hash])->fetch();
            $expired=(int)$row['expires_at']<=$now;$attempts=$expired?0:(int)$row['attempts'];$expires=$expired?$now+$window:(int)$row['expires_at'];
            if($attempts>=$max)return false;Db::run($this->db,'UPDATE native_auth_limits SET attempts=?,expires_at=? WHERE scope_hash=?',[$attempts+1,$expires,$hash]);return true;
        });
        if(!$allowed)throw new DomainException('Zu viele Versuche. Bitte später erneut versuchen.');
    }
    private function clearLimit(string $scope):void{Db::run($this->db,'DELETE FROM native_auth_limits WHERE scope_hash=?',[hash('sha256',$scope)]);}
}
