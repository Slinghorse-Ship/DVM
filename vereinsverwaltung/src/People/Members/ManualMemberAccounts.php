<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;

/** Durable manual onboarding without storing or sending a recoverable password. */
final class ManualMemberAccounts
{
    public static function enqueue(PDO $db,array $actor,string $contact,bool $sendMail,string $applicationId=''):void
    {
        $a=Permissions::actor($db,$actor['id']);
        if($a['club_id']!==$actor['club_id'])throw new DomainException('Verein stimmt nicht überein.');
        if($a['members_access']!=='write'){BoardAccess::require($db,$a['id']);if($applicationId===''||!DocumentCatalog::query($db,"SELECT 1 FROM membership_applications WHERE club_id=? AND id=? AND contact_id=? AND reviewed_by=? AND status='accepted'",[$a['club_id'],$applicationId,$contact,$a['id']])->fetchColumn())throw new DomainException('Bestätigte eigene Aufnahme erforderlich.');}
        $c=DocumentCatalog::query($db,'SELECT * FROM contacts WHERE club_id=? AND id=?',[$a['club_id'],$contact])->fetch();
        if(!$c||$c['kind']!=='person'||!DocumentCatalog::query($db,"SELECT 1 FROM memberships WHERE club_id=? AND contact_id=? AND ends_on IS NULL AND status IN ('aktiv','passiv')",[$a['club_id'],$contact])->fetchColumn())throw new DomainException('Aktuelle persönliche Mitgliedschaft erforderlich.');
        $old=DocumentCatalog::query($db,'SELECT * FROM manual_member_accounts WHERE club_id=? AND contact_id=?',[$a['club_id'],$contact])->fetch();
        $state=filter_var($c['email'],FILTER_VALIDATE_EMAIL)?'prepared':'needs_address';
        if($old){
            if($old['mail_status']==='sending')throw new DomainException('Der Zugangsmail-Versand läuft bereits. Bitte später erneut prüfen.');
            $mail=in_array($old['mail_status'],['sent','uncertain'],true)?$old['mail_status']:($sendMail?'pending':'disabled');
            DocumentCatalog::query($db,'UPDATE manual_member_accounts SET email=?,state=?,send_mail=?,mail_status=?,requested_by=? WHERE club_id=? AND contact_id=?',[$c['email'],$state,(int)$sendMail,$mail,$a['id'],$a['club_id'],$contact]);
        }else DocumentCatalog::query($db,'INSERT INTO manual_member_accounts(club_id,contact_id,email,username,creation_key,state,send_mail,mail_status,application_id,requested_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$a['club_id'],$contact,$c['email'],MemberLoginNames::fromName($c['name']),bin2hex(random_bytes(32)),$state,(int)$sendMail,$sendMail?'pending':'disabled',$applicationId?:null,$a['id'],gmdate('c')]);
        DocumentCatalog::query($db,'INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],$sendMail?'identity.manual.requested-with-mail':'identity.manual.requested-without-mail',$contact,(int)$c['version'],gmdate('c')]);
    }
    public static function request(PDO $db,string $actor,string $contact,bool $sendMail):void
    {
        $db->beginTransaction();try{self::enqueue($db,Permissions::actor($db,$actor),$contact,$sendMail);$db->commit();}catch(\Throwable $e){$db->rollBack();throw $e;}
    }
    public static function status(PDO $db,string $actor,string $contact):?array
    {
        $c=(new Registry($db,$actor))->contact($contact);
        $row=DocumentCatalog::query($db,'SELECT state,username,pw_user_id,send_mail,mail_status,sent_at FROM manual_member_accounts WHERE club_id=? AND contact_id=?',[$c['club_id'],$contact])->fetch();return $row?:null;
    }
    /** Stored manager connections are independent of the manual onboarding queue. */
    public static function linkedAccounts(PDO $db,string $actor,string $contact):array
    {
        $c=(new Registry($db,$actor))->contact($contact);
        if(Database::tableExists($db,'native_member_onboarding')){
            $native=DocumentCatalog::query($db,'SELECT au.id AS account_id,au.login_name AS username,au.active AS enabled,au.verified,a.active FROM actors a JOIN native_manager_identities i ON i.manager_actor_id=a.id AND i.manager_club_id=a.club_id JOIN native_auth_accounts au ON au.actor_id=i.native_id WHERE a.club_id=? AND a.contact_id=? ORDER BY au.id',[$c['club_id'],$contact])->fetchAll();
            if($native)return $native;
        }
        return DocumentCatalog::query($db,'SELECT i.pw_user_id,i.enabled,a.active FROM actors a JOIN processwire_identities i ON i.actor_id=a.id WHERE a.club_id=? AND a.contact_id=? ORDER BY i.pw_user_id',[$c['club_id'],$contact])->fetchAll();
    }
    private static function binding(PDO $db,string $club,string $contact):?array
    {
        $rows=DocumentCatalog::query($db,'SELECT i.*,a.active FROM actors a JOIN processwire_identities i ON i.actor_id=a.id WHERE a.club_id=? AND a.contact_id=?',[$club,$contact])->fetchAll();
        if(count($rows)>1)throw new DomainException('Kontozuordnung prüfen.');$b=$rows[0]??null;
        if($b&&(!$b['enabled']||!$b['active']))throw new DomainException('Zugang gesperrt.');return $b;
    }
    private static function contact(PDO $db,string $club,string $contact,string $email):array
    {
        $c=DocumentCatalog::query($db,"SELECT c.* FROM contacts c WHERE club_id=? AND id=? AND kind='person' AND EXISTS(SELECT 1 FROM memberships m WHERE m.club_id=c.club_id AND m.contact_id=c.id AND m.ends_on IS NULL AND m.status IN ('aktiv','passiv'))",[$club,$contact])->fetch();
        if(!$c||!filter_var($c['email'],FILTER_VALIDATE_EMAIL)||strcasecmp(trim($c['email']),trim($email))!==0)throw new DomainException('Mitgliedschaft oder Adresse inzwischen geändert.');return $c;
    }
    private static function sharedFamilyUsers(PDO $db,string $club,string $contact,string $email):array
    {
        return array_map('intval',DocumentCatalog::query($db,'SELECT i.pw_user_id FROM member_family_people own JOIN member_family_people relative ON relative.club_id=own.club_id AND relative.family_id=own.family_id JOIN contacts c ON c.club_id=relative.club_id AND c.id=relative.contact_id JOIN actors a ON a.club_id=c.club_id AND a.contact_id=c.id JOIN processwire_identities i ON i.actor_id=a.id WHERE own.club_id=? AND own.contact_id=? AND c.id!=? AND lower(c.email)=lower(?) AND a.active=1 AND i.enabled=1',[$club,$contact,$contact,$email])->fetchAll(\PDO::FETCH_COLUMN));
    }
    public static function run(PDO $db,string $club,object $accounts,string $url,?callable $send,string $onlyContact=''):array
    {
        $result=['ready'=>0,'sent'=>0,'blocked'=>0];
        $rows=DocumentCatalog::query($db,"SELECT * FROM manual_member_accounts WHERE club_id=? AND (?='' OR contact_id=?) AND (state='prepared' OR (state='ready' AND mail_status='pending')) ORDER BY created_at,contact_id LIMIT 20",[$club,$onlyContact,$onlyContact])->fetchAll();
        foreach($rows as $row){
            try{
                $requester=Permissions::actor($db,$row['requested_by']);if($requester['club_id']!==$club)throw new DomainException('Anfordernder Zugang nicht mehr berechtigt.');if($requester['members_access']!=='write'){BoardAccess::require($db,$requester['id']);if(!$row['application_id']||!DocumentCatalog::query($db,"SELECT 1 FROM membership_applications WHERE club_id=? AND id=? AND contact_id=? AND reviewed_by=? AND status='accepted'",[$club,$row['application_id'],$row['contact_id'],$requester['id']])->fetchColumn())throw new DomainException('Aufnahme nicht mehr gültig.');}
                $contact=self::contact($db,$club,$row['contact_id'],$row['email']);$binding=self::binding($db,$club,$row['contact_id']);
                if($binding){$account=$accounts->account((int)$binding['pw_user_id']);}
                else $account=$accounts->createMemberAccount($row['username'],$row['email'],bin2hex(random_bytes(32)).'aA9!',$row['creation_key'],self::sharedFamilyUsers($db,$club,$row['contact_id'],$row['email']));
                if(!$account||!$account['active']||strcasecmp(trim($account['email']),trim($row['email']))!==0)throw new DomainException('Website-Konto oder Adresse prüfen.');
                $db->beginTransaction();
                try{
                    self::contact($db,$club,$row['contact_id'],$row['email']);$binding=self::binding($db,$club,$row['contact_id']);
                    if($binding&&(int)$binding['pw_user_id']!==(int)$account['id'])throw new DomainException('Kontozuordnung inzwischen geändert.');
                    $other=DocumentCatalog::query($db,'SELECT a.club_id,a.contact_id,i.enabled,a.active FROM processwire_identities i JOIN actors a ON a.id=i.actor_id WHERE i.pw_user_id=?',[(int)$account['id']])->fetch();
                    if($other&&($other['club_id']!==$club||$other['contact_id']!==$row['contact_id']||!$other['enabled']||!$other['active']))throw new DomainException('Website-Konto gehört zu einem anderen Profil.');
                    if(!$other){$actor='pw:'.(int)$account['id'];DocumentCatalog::query($db,"INSERT INTO actors(id,club_id,role,contact_id,is_superuser) VALUES(?,?,'member',?,0)",[$actor,$club,$row['contact_id']]);DocumentCatalog::query($db,'INSERT INTO processwire_identities(pw_user_id,actor_id,enabled,version) VALUES(?,?,1,1)',[(int)$account['id'],$actor]);DocumentCatalog::query($db,'INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$club,$row['requested_by'],'identity.manual.provisioned',$actor,1,gmdate('c')]);}
                    DocumentCatalog::query($db,"UPDATE manual_member_accounts SET state='ready',pw_user_id=?,username=? WHERE club_id=? AND contact_id=?",[(int)$account['id'],$account['username'],$club,$row['contact_id']]);$db->commit();
                }catch(\Throwable $e){$db->rollBack();throw $e;}
                $result['ready']++;
                if(!$send||!MailPolicy::allowed($url))continue;
                if(DocumentCatalog::query($db,"UPDATE manual_member_accounts SET mail_status='sending' WHERE club_id=? AND contact_id=? AND state='ready' AND send_mail=1 AND mail_status='pending'",[$club,$row['contact_id']])->rowCount()!==1)continue;
                $link=rtrim($url,'/').'/login/?forgot=1';
                $text='Hallo '.$contact['name'].",\n\ndein Mitgliedszugang zum Kulturhus-Vereinsmanager ist eingerichtet.\nBenutzername: ".$account['username']."\n\nLege vor der ersten Anmeldung dein eigenes Passwort fest: ".$link."\nGib dort deinen Benutzernamen ein. Du erhältst anschließend einen zeitlich begrenzten Bestätigungslink und einen Code per E-Mail. Öffne den Link im selben Browser.\n\nWenn du bereits ein persönliches Passwort für diesen Zugang hast, kannst du es weiterverwenden.\n\nDein Kulturhus Jaderberg e. V.";
                try{$send($row['email'],'Dein Mitgliedszugang · Kulturhus',$text,ApplicationMail::html($text,$url,'Dein Mitgliedszugang',$link,'Eigenes Passwort festlegen'),[]);DocumentCatalog::query($db,"UPDATE manual_member_accounts SET mail_status='sent',sent_at=? WHERE club_id=? AND contact_id=?",[gmdate('c'),$club,$row['contact_id']]);$result['sent']++;DocumentCatalog::query($db,"UPDATE membership_welcome SET welcome_status='sent',welcome_sent_at=? WHERE club_id=? AND contact_id=? AND welcome_status='manual_access'",[gmdate('c'),$club,$row['contact_id']]);}
                catch(\Throwable){DocumentCatalog::query($db,"UPDATE manual_member_accounts SET mail_status='uncertain' WHERE club_id=? AND contact_id=?",[$club,$row['contact_id']]);}
            }catch(\Throwable){DocumentCatalog::query($db,"UPDATE manual_member_accounts SET state='needs_review' WHERE club_id=? AND contact_id=?",[$club,$row['contact_id']]);$result['blocked']++;}
        }
        return $result;
    }
}
