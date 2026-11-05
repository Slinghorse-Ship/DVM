<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;
use Kulturhus\Native\Auth\{Runtime,Service};
use Kulturhus\Native\Integration\MemberBankAuthentication;

/** Short lived native password/factor proof bound to one reviewed workbook and session. */
final class MemberImportBankPermit
{
    private const NS='member_excel_bank_confirmation';
    private function __construct(private PDO $db,private string $actor,private string $source,private string $purpose,#[\SensitiveParameter] private string $token,private array $security,private string $sessionHash,private int $expires){}
    private static function context(PDO $db,string $actor,#[\SensitiveParameter] array $session):array
    {
        if(session_status()!==PHP_SESSION_ACTIVE||session_id()===''||!is_string($session['auth_token']??null))throw new DomainException('Bankdatenimport benötigt eine persönliche Anmeldung.',403);
        $auth=Runtime::service($db);$token=$session['auth_token'];MemberBankAuthentication::identity($db,$actor,$auth,$token);
        $a=Permissions::actor($db,$actor);
        if($a['members_access']!=='write'||!MemberBankVault::fromEnvironment($db,$actor)->canManageMembers())throw new DomainException('Mitglieder- und Bankverwaltungsrecht für den Bankdatenimport erforderlich.',403);
        return[$auth,$token,$a,$auth->reauthenticationState($token)];
    }
    private static function binding(PDO $db,string $actor,string $id,string $hash,#[\SensitiveParameter] array $session,string $purpose='import'):array
    {
        if(!in_array($purpose,['import','export'],true))throw new DomainException('Bankbestätigung gehört nicht zum gewählten Vorgang.',403);
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$id)||!preg_match('/\A[a-f0-9]{64}\z/D',$hash))throw new DomainException('Die ursprüngliche Importkennung vollständig prüfen.',422);
        $stage=$session[$purpose==='import'?'member_excel_uploads':'member_excel_exports'][$id]??null;
        if(!is_array($stage)||($stage['actor']??null)!==$actor||($stage['hash']??null)!==$hash||($stage['created']??0)<time()-3600)throw new DomainException('Bankbestätigung gehört nicht zur aktuellen Importvorschau.',409);
        [$auth,$token,$a,$security]=self::context($db,$actor,$session);
        if(($stage['club']??null)!==$a['club_id'])throw new DomainException('Bankbestätigung gehört nicht zum aktuellen Verein.',403);
        return[$auth,$token,$a,$security];
    }
    public static function begin(PDO $db,string $actor,string $id,string $hash,#[\SensitiveParameter] array &$session,#[\SensitiveParameter]string $password,string $purpose='import'):array
    {
        unset($session[self::NS][$purpose.':'.$id]);[$auth,$token,$a,$security]=self::binding($db,$actor,$id,$hash,$session,$purpose);
        if($password===''||strlen($password)>4096)throw new DomainException('Aktuelles Passwort vollständig eingeben.',422);
        if(!$auth->reauthenticatePassword($token,\Kulturhus\Native\Demo\Runtime::confirmationPassword($password,$actor)))throw new DomainException('Aktuelles Passwort stimmt nicht.',403);
        if($auth->reauthenticationState($token)!==$security)throw new DomainException('Sicherheitsstatus geändert. Bitte erneut bestätigen.',403);
        $proof=['purpose'=>$purpose,'actor'=>$actor,'club'=>$a['club_id'],'hash'=>$hash,'session'=>hash('sha256',session_id()),'security'=>$security,'challenge'=>bin2hex(random_bytes(16)),'mode'=>$security['mfa']?'code':'ready','expires'=>time()+($security['mfa']?300:60)];
        $session[self::NS][$purpose.':'.$id]=$proof;return['requires_code'=>$security['mfa'],'challenge'=>$proof['challenge']];
    }
    public static function confirm(PDO $db,string $actor,string $id,string $hash,#[\SensitiveParameter] array &$session,string $challenge,#[\SensitiveParameter]string $code,string $purpose='import'):void
    {
        [$auth,$token,$a,$security]=self::binding($db,$actor,$id,$hash,$session,$purpose);$proof=$session[self::NS][$purpose.':'.$id]??null;
        if(!self::matches($proof,$actor,$a['club_id'],$hash,$purpose)||$proof['mode']!=='code'||$proof['challenge']!==$challenge||$proof['security']!==$security)throw new DomainException('Bankbestätigung abgelaufen oder für andere Angaben.',403);
        if($code===''||strlen($code)>200)throw new DomainException('Sicherheitscode vollständig eingeben.',422);
        $auth->reauthenticateFactor($token,$code,$security['stamp']);
        if($auth->reauthenticationState($token)!==$security)throw new DomainException('Sicherheitsstatus geändert. Bitte erneut bestätigen.',403);
        $proof['mode']='ready';$proof['expires']=time()+60;$session[self::NS][$purpose.':'.$id]=$proof;
    }
    private static function matches(mixed $proof,string $actor,string $club,string $hash,string $purpose):bool
    {
        return is_array($proof)&&($proof['purpose']??null)===$purpose&&($proof['actor']??null)===$actor&&($proof['club']??null)===$club&&($proof['hash']??null)===$hash&&($proof['session']??null)===hash('sha256',session_id())&&($proof['expires']??0)>time();
    }
    public static function fromSession(PDO $db,string $actor,string $id,string $hash,#[\SensitiveParameter] array $session,string $purpose='import'):self
    {
        [$auth,$token,$a,$security]=self::binding($db,$actor,$id,$hash,$session,$purpose);$proof=$session[self::NS][$purpose.':'.$id]??null;
        if(!self::matches($proof,$actor,$a['club_id'],$hash,$purpose)||$proof['mode']!=='ready'||$proof['security']!==$security)throw new DomainException('Bankdatenübernahme zuerst mit Passwort und gegebenenfalls Sicherheitscode bestätigen.',403);
        return new self($db,$actor,$hash,$purpose,$token,$security,hash('sha256',session_id()),$proof['expires']);
    }
    public static function state(PDO $db,string $actor,string $id,string $hash,#[\SensitiveParameter] array $session,string $purpose='import'):array
    {
        try{[$auth,$token,$a,$security]=self::binding($db,$actor,$id,$hash,$session,$purpose);$proof=$session[self::NS][$purpose.':'.$id]??null;if(self::matches($proof,$actor,$a['club_id'],$hash,$purpose)&&$proof['security']===$security)return['mode'=>$proof['mode'],'challenge'=>$proof['challenge'],'factor'=>$security['factor']];}catch(DomainException){}
        return['mode'=>'password','challenge'=>'','factor'=>''];
    }
    public static function forget(#[\SensitiveParameter] array &$session,string $id,string $purpose='import'):void{unset($session[self::NS][$purpose.':'.$id]);}
    public function assert(PDO $db,string $actor,string $source,string $purpose='import'):void
    {
        if($purpose!==$this->purpose||$db!==$this->db||$actor!==$this->actor||!hash_equals($this->source,$source)||$this->expires<=time()||session_status()!==PHP_SESSION_ACTIVE||!hash_equals($this->sessionHash,hash('sha256',session_id())))throw new DomainException('Bankbestätigung abgelaufen oder für andere Angaben.',403);
        [,,$a,$security]=self::context($db,$actor,['auth_token'=>$this->token]);
        if($security!==$this->security)throw new DomainException('Sicherheitsstatus geändert. Bitte erneut bestätigen.',403);
    }
}
