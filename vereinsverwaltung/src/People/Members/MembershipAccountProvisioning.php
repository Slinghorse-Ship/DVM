<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;

/** Accepted members receive only a personal member identity, never inherited website authority. */
final class MembershipAccountProvisioning
{
    private string $key;
    public function __construct(private PDO $db,private string $club,private object $accounts,#[\SensitiveParameter] string $serverKey)
    {
        if(!function_exists('openssl_encrypt')||!function_exists('openssl_decrypt'))throw new DomainException('Die geschützte Kontoanlage benötigt OpenSSL.');
        if(strlen($serverKey)<32)throw new DomainException('Geschützter Anwendungsschlüssel für die Kontoanlage fehlt.');
        $this->key=hash_hkdf('sha256',$serverKey,32,'kulturhus-membership-onboarding-v1');
    }
    private function q(string $sql,array $args=[]):\PDOStatement{return DocumentCatalog::query($this->db,$sql,$args);}
    private function transaction(callable $fn):mixed{$this->db->beginTransaction();try{$result=$fn();$this->db->commit();return $result;}catch(\Throwable $error){$this->db->rollBack();throw $error;}}
    private function application(string $id,string $contact):array
    {
        $app=$this->q("SELECT a.*,c.email,c.name AS contact_name FROM membership_applications a JOIN contacts c ON c.club_id=a.club_id AND c.id=a.contact_id WHERE a.club_id=? AND a.id=? AND a.contact_id=? AND a.status='accepted'",[$this->club,$id,$contact])->fetch();
        if(!$app||!$this->q("SELECT 1 FROM memberships WHERE club_id=? AND contact_id=? AND status IN ('aktiv','passiv') AND ends_on IS NULL",[$this->club,$contact])->fetchColumn())throw new DomainException('Kontoanlage benötigt eine bestätigte Aufnahme und aktuelle Mitgliedschaft.');
        if(!filter_var($app['email'],FILTER_VALIDATE_EMAIL))throw new DomainException('Für den aufgenommenen Kontakt fehlt eine gültige E-Mail-Adresse.');
        return $app;
    }
    private function binding(string $contact):?array
    {
        $bindings=$this->q('SELECT i.*,a.active FROM actors a JOIN processwire_identities i ON i.actor_id=a.id WHERE a.club_id=? AND a.contact_id=?',[$this->club,$contact])->fetchAll();
        if(count($bindings)>1)throw new DomainException('Mehrere zentrale Konten für diese Person: Zuordnung bitte prüfen.');
        $binding=$bindings[0]??null;
        if($binding&&(!$binding['enabled']||!$binding['active']))throw new DomainException('Der vorhandene zentrale Zugang ist gesperrt und wird nicht automatisch reaktiviert.');
        return $binding;
    }
    private function seal(#[\SensitiveParameter] string $plain,string $id):string
    {
        $nonce=random_bytes(12);$tag='';$cipher=openssl_encrypt($plain,'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,$nonce,$tag,$this->club.':'.$id);
        if($cipher===false)throw new DomainException('Startpasswort konnte nicht geschützt gespeichert werden.');
        return base64_encode($nonce.$tag.$cipher);
    }
    private function open(string $cipher,string $id):string
    {
        $raw=base64_decode($cipher,true);if($raw===false||strlen($raw)<29)throw new DomainException('Geschütztes Startpasswort nicht verfügbar.');
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),$this->club.':'.$id);
        if($plain===false)throw new DomainException('Geschütztes Startpasswort konnte nicht gelesen werden.');return $plain;
    }
    public function provision(string $applicationId,string $contactId):array
    {
        $app=$this->application($applicationId,$contactId);
        $intent=$this->transaction(function()use($applicationId,$contactId,$app):array{
            $old=$this->q('SELECT * FROM membership_account_onboarding WHERE club_id=? AND application_id=?',[$this->club,$applicationId])->fetch();
            if($old){if($old['contact_id']!==$contactId)throw new DomainException('Gespeicherte Kontozuordnung stimmt nicht mit dem Antrag überein.');return $old;}
            $binding=$this->binding($contactId);$existing=$binding?$this->accounts->account((int)$binding['pw_user_id']):null;
            if($binding&&(!$existing||!$existing['active']))throw new DomainException('Das zugeordnete Website-Konto ist nicht verfügbar.');
            if(!$binding&&$this->accounts->findByEmail($app['email']))throw new DomainException('Zur E-Mail besteht bereits ein Website-Konto. Bitte dieses Konto ausdrücklich dem Mitgliederprofil zuordnen.');
            $username=$existing['username']??MemberLoginNames::fromName($app['contact_name']);$creationKey=bin2hex(random_bytes(32));
            $password=$existing?null:($this->seal(bin2hex(random_bytes(24)).'aA9!',$applicationId));
            $this->q('INSERT INTO membership_account_onboarding VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',[$this->club,$applicationId,$contactId,$existing['id']??null,$username,$creationKey,$password,(int)!$existing,(int)!$existing,$existing?'ready':'prepared',gmdate('c'),null,null]);
            return $this->q('SELECT * FROM membership_account_onboarding WHERE club_id=? AND application_id=?',[$this->club,$applicationId])->fetch();
        });
        if($intent['state']==='prepared'){
            // Durable intent and encrypted password exist before provisioning the account.
            $account=$this->accounts->createMemberAccount($intent['username'],$app['email'],$this->open($intent['password_cipher'],$applicationId),$intent['creation_key']);
            $intent=$this->transaction(function()use($intent,$account,$app,$applicationId,$contactId):array{
                $this->application($applicationId,$contactId);
                $binding=$this->binding($contactId);
                if($binding&&(int)$binding['pw_user_id']!==(int)$account['id'])throw new DomainException('Die Person wurde inzwischen einem anderen zentralen Konto zugeordnet.');
                $other=$this->q('SELECT i.*,a.club_id,a.contact_id,a.active FROM processwire_identities i JOIN actors a ON a.id=i.actor_id WHERE i.pw_user_id=?',[(int)$account['id']])->fetch();
                if($other&&($other['club_id']!==$this->club||$other['contact_id']!==$contactId||!$other['enabled']||!$other['active']))throw new DomainException('Das Website-Konto besitzt eine abweichende oder gesperrte Vereinszuordnung.');
                if(!$other){
                    $actor='pw:'.(int)$account['id'];
                    $this->q("INSERT INTO actors(id,club_id,role,contact_id,is_superuser) VALUES(?,?,'member',?,0)",[$actor,$this->club,$contactId]);
                    $this->q('INSERT INTO processwire_identities(pw_user_id,actor_id,enabled,version) VALUES(?,?,1,1)',[(int)$account['id'],$actor]);
                    $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$this->club,$app['reviewed_by'],'identity.member.provisioned',$actor,1,gmdate('c')]);
                }
                $this->q("UPDATE membership_account_onboarding SET pw_user_id=?,username=?,state='ready' WHERE club_id=? AND application_id=?",[(int)$account['id'],$account['username'],$this->club,$applicationId]);
                return $this->q('SELECT * FROM membership_account_onboarding WHERE club_id=? AND application_id=?',[$this->club,$applicationId])->fetch();
            });
        }
        $binding=$this->binding($contactId);$account=$this->accounts->account((int)$intent['pw_user_id']);
        if(!$binding||(int)$binding['pw_user_id']!==(int)$intent['pw_user_id']||!$account||!$account['active'])throw new DomainException('Der zentrale Zugang ist vor dem Versand nicht verfügbar.');
        $result=['account_id'=>(int)$intent['pw_user_id'],'username'=>$intent['username'],'created'=>(bool)$intent['created_new'],'password_change_required'=>(bool)$intent['password_change_required']];
        if($intent['password_cipher']!==null)$result['initial_password']=$this->open($intent['password_cipher'],$applicationId);
        return $result;
    }
    public function acknowledgeDelivery(string $applicationId):void
    {
        $this->q("UPDATE membership_account_onboarding SET password_cipher=NULL,delivered_at=COALESCE(delivered_at,?) WHERE club_id=? AND application_id=? AND state='ready'",[gmdate('c'),$this->club,$applicationId]);
    }
    public function requiresPasswordChange(int $userId):bool{return (bool)$this->q('SELECT 1 FROM membership_account_onboarding WHERE club_id=? AND pw_user_id=? AND password_change_required=1',[$this->club,$userId])->fetchColumn();}
    public function changePassword(int $userId,#[\SensitiveParameter] string $password,#[\SensitiveParameter] string $repeat):void
    {
        if(!$this->requiresPasswordChange($userId))throw new DomainException('Kein Erstpasswortwechsel offen.');
        if(strlen($password)<12||strlen($password)>200||!hash_equals($password,$repeat))throw new DomainException('Bitte zweimal dasselbe neue Passwort mit mindestens 12 Zeichen eingeben.');
        $binding=$this->q('SELECT i.enabled,a.active FROM processwire_identities i JOIN actors a ON a.id=i.actor_id WHERE i.pw_user_id=? AND a.club_id=?',[$userId,$this->club])->fetch();
        if(!$binding||!$binding['enabled']||!$binding['active'])throw new DomainException('Der zentrale Zugang ist gesperrt.');
        $this->accounts->changePassword($userId,$password);
        $this->q('UPDATE membership_account_onboarding SET password_change_required=0,password_cipher=NULL,password_changed_at=? WHERE club_id=? AND pw_user_id=?',[gmdate('c'),$this->club,$userId]);
    }
}
