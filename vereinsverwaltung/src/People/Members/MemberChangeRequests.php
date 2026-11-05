<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

/** Proposed contact details never change login identity or permissions. */
final class MemberChangeRequests
{
    public const FIELDS=['name'=>'Vor- und Nachname','email'=>'Kontakt-E-Mail','phone'=>'Telefon','address'=>'Anschrift'];
    public const STATES=['pending'=>'Wartet auf Prüfung','accepted'=>'Übernommen','rejected'=>'Nicht übernommen','withdrawn'=>'Zurückgezogen'];
    public function __construct(private PDO $db,private string $actor,#[\SensitiveParameter] private ?string $serverKey=null){}
    private function q(string $sql,array $args=[]):\PDOStatement{return DocumentCatalog::query($this->db,$sql,$args);}
    private function key():string{return hash_hkdf('sha256',$this->serverKey??ContactArchive::serverKey(),32,'kulturhus-member-changes-v1');}
    private function seal(array $data,array $row):string
    {
        $nonce=random_bytes(12);$tag='';$bytes=openssl_encrypt(json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,$nonce,$tag,$row['club_id'].':'.$row['contact_id'].':'.$row['id']);
        if($bytes===false)throw new DomainException('Die Änderung konnte nicht geschützt gespeichert werden.');return base64_encode($nonce.$tag.$bytes);
    }
    private function open(array $row):array
    {
        $raw=base64_decode($row['ciphertext'],true);if($raw===false||strlen($raw)<29)throw new DomainException('Der Änderungsantrag ist nicht lesbar.');
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),$row['club_id'].':'.$row['contact_id'].':'.$row['id']);
        if($plain===false)throw new DomainException('Der Änderungsantrag konnte nicht entschlüsselt werden.');return json_decode($plain,true,16,JSON_THROW_ON_ERROR);
    }
    private function fields(array $data):array
    {
        if(array_diff(array_keys($data),array_keys(self::FIELDS)))throw new DomainException('Hier können nur Name, Kontakt-E-Mail, Telefon und Anschrift geändert werden.');
        $out=[];foreach(self::FIELDS as $field=>$label){if(!is_string($data[$field]??null))throw new DomainException('Bitte '.$label.' prüfen.');$out[$field]=trim(str_replace(["\r\n","\r"],"\n",$data[$field]));if(str_contains($out[$field],"\0"))throw new DomainException('Bitte '.$label.' prüfen.');}
        if($out['name']===''||strlen($out['name'])>200)throw new DomainException('Bitte Vor- und Nachname mit höchstens 200 Zeichen angeben.');
        if(strlen($out['email'])>254||($out['email']!==''&&!filter_var($out['email'],FILTER_VALIDATE_EMAIL)))throw new DomainException('Bitte eine gültige Kontakt-E-Mail angeben oder das Feld leeren.');
        if(strlen($out['phone'])>80||strlen($out['address'])>1000)throw new DomainException('Telefon oder Anschrift ist zu lang.');return $out;
    }
    private function currentContact(array $actor,string $contact):array
    {
        $c=$this->q("SELECT * FROM contacts WHERE club_id=? AND id=? AND kind='person' AND archived_at IS NULL",[$actor['club_id'],$contact])->fetch();if(!$c)throw new DomainException('Mitgliederprofil nicht verfügbar.');return $c;
    }
    private function currentMembership(string $club,string $contact):bool{return (bool)$this->q("SELECT 1 FROM memberships WHERE club_id=? AND contact_id=? AND status IN ('aktiv','passiv') AND starts_on<=? AND (ends_on IS NULL OR ends_on>=?)",[$club,$contact,date('Y-m-d'),date('Y-m-d')])->fetchColumn();}
    public function ownContact():array
    {
        $a=Permissions::actor($this->db,$this->actor);if($a['role']==='editor'||!$a['contact_id'])throw new DomainException('Diesem Zugang ist kein eigenes Mitgliederprofil zugeordnet.');$c=$this->currentContact($a,$a['contact_id']);
        if(!$this->currentMembership($a['club_id'],$c['id']))throw new DomainException('Eigene Datenänderungen sind für aktuelle Mitglieder verfügbar.');return $c;
    }
    public function canSubmit():bool{try{$this->ownContact();return true;}catch(DomainException){return false;}}
    public function canReview():bool{return BoardAccess::allowed($this->db,$this->actor)&&(new Registry($this->db,$this->actor))->canWrite();}
    private function reviewer():array{if(!$this->canReview())throw new DomainException('Für die Prüfung sind ein aktuelles Vorstandsamt oder Superuser-Zugang sowie Mitglieder-Schreibrecht erforderlich.');return Permissions::actor($this->db,$this->actor);}
    private function entry(string $id,bool $review):array
    {
        $a=$review?$this->reviewer():Permissions::actor($this->db,$this->actor);$own=$review?null:$this->ownContact();
        $row=$this->q('SELECT * FROM member_change_requests WHERE club_id=? AND id=?',[$a['club_id'],$id])->fetch();if(!$row||($own&&$row['contact_id']!==$own['id']))throw new DomainException('Änderungsantrag nicht verfügbar.');$this->currentContact($a,$row['contact_id']);return $row;
    }
    public function listing(bool $review=false,string $state='pending',string $query=''):array
    {
        $a=$review?$this->reviewer():Permissions::actor($this->db,$this->actor);$own=$review?null:$this->ownContact();
        if($state!==''&&!isset(self::STATES[$state]))$state='pending';$query=trim($query);if(strlen($query)>150)throw new DomainException('Bitte einen kürzeren Suchtext eingeben.');
        $rows=$this->q("SELECT r.*,c.name FROM member_change_requests r JOIN contacts c ON c.club_id=r.club_id AND c.id=r.contact_id WHERE r.club_id=? AND c.archived_at IS NULL AND (?='' OR r.state=?) AND (?='' OR r.contact_id=?) ORDER BY r.created_at DESC,r.id",[$a['club_id'],$state,$state,$own['id']??'',$own['id']??''])->fetchAll();
        foreach($rows as &$row)unset($row['ciphertext']);unset($row);
        return array_values(array_filter($rows,static fn($row)=>$query===''||mb_stripos($row['name'],$query)!==false));
    }
    public function get(string $id,bool $review=false):array
    {
        $row=$this->entry($id,$review);$a=Permissions::actor($this->db,$this->actor);$row['data']=$this->open($row);unset($row['ciphertext']);$row['current']=$this->currentContact($a,$row['contact_id']);$row['conflict']=(int)$row['current']['version']!==(int)$row['contact_version'];$row['current_member']=$this->currentMembership($a['club_id'],$row['contact_id']);return $row;
    }
    /** Read only this member's exact original submission; never create a replacement proposal. */
    public function submissionResult(string $requestKey):?array
    {
        $contact=$this->ownContact();$a=Permissions::actor($this->db,$this->actor);
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$requestKey))throw new DomainException('Ursprüngliche Einreichungskennung prüfen.',422);
        $id=$this->q('SELECT id FROM member_change_requests WHERE club_id=? AND actor_id=? AND contact_id=? AND request_key=?',[$a['club_id'],$a['id'],$contact['id'],$requestKey])->fetchColumn();
        if($id===false)return null;
        $row=$this->get((string)$id);unset($row['request_key'],$row['request_hash']);return$row;
    }
    private function audit(array $a,array $row,string $action,int $version):void{$this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],$action,$row['id'],$version,gmdate('c')]);}
    public function submit(array $data,int $version,string $key,?string $expectedContact=null):string
    {
        $after=$this->fields($data);if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new DomainException('Bitte das Formular neu öffnen.');$this->db->beginTransaction();
        try{
            $c=$this->ownContact();$a=Permissions::actor($this->db,$this->actor);
            if($expectedContact!==null&&$expectedContact!==$c['id'])throw new DomainException('Die ursprüngliche Profilzuordnung wurde geändert. Aktuelle Zuordnung getrennt prüfen.',409);
            $hash=hash('sha256',json_encode([$a['club_id'],$c['id'],$a['id'],$version,$after],JSON_THROW_ON_ERROR));
            $old=$this->q('SELECT * FROM member_change_requests WHERE request_key=?',[$key])->fetch();if($old){if(!hash_equals($old['request_hash'],$hash))throw new DomainException('Diese Anfrage wurde bereits mit anderen Angaben gespeichert.');$this->db->commit();return $old['id'];}
            if((int)$c['version']!==$version)throw new DomainException('Dein Profil wurde inzwischen geändert. Bitte die aktuellen Angaben neu öffnen und vergleichen.');
            if($this->q("SELECT 1 FROM member_change_requests WHERE club_id=? AND contact_id=? AND state='pending'",[$a['club_id'],$c['id']])->fetchColumn())throw new DomainException('Es gibt bereits einen offenen Änderungsantrag. Ziehe ihn zuerst zurück, wenn du andere Angaben einreichen möchtest.');
            $before=$this->fields(array_intersect_key($c,self::FIELDS));if($after==$before)throw new DomainException('Bitte zuerst mindestens eine Angabe ändern.');
            $row=['id'=>bin2hex(random_bytes(16)),'club_id'=>$a['club_id'],'contact_id'=>$c['id']];$cipher=$this->seal(['before'=>$before,'after'=>$after,'decision_note'=>''],$row);
            $this->q('INSERT INTO member_change_requests(id,club_id,contact_id,actor_id,contact_version,ciphertext,request_key,request_hash,created_at) VALUES(?,?,?,?,?,?,?,?,?)',[$row['id'],$a['club_id'],$c['id'],$a['id'],$version,$cipher,$key,$hash,gmdate('c')]);$this->audit($a,$row,'member.change.requested',1);$this->db->commit();return $row['id'];
        }catch(\Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}
    }
    public function withdraw(string $id,int $version):void
    {
        $this->db->beginTransaction();try{$row=$this->entry($id,false);if($row['state']==='withdrawn'){$this->db->commit();return;}if($row['state']!=='pending'||(int)$row['version']!==$version)throw new DomainException('Der Antrag wurde bereits bearbeitet. Bitte den aktuellen Stand öffnen.');$a=Permissions::actor($this->db,$this->actor);$changed=$this->q("UPDATE member_change_requests SET state='withdrawn',version=version+1,reviewed_at=?,reviewed_by=? WHERE id=? AND club_id=? AND version=? AND state='pending'",[gmdate('c'),$a['id'],$id,$a['club_id'],$version]);if($changed->rowCount()!==1)throw new DomainException('Der Antrag wurde inzwischen geändert. Bitte neu öffnen.',409);$this->audit($a,$row,'member.change.withdrawn',$version+1);$this->db->commit();}catch(\Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}
    }
    public function review(string $id,int $version,string $decision,string $note):void
    {
        if(!in_array($decision,['accepted','rejected'],true))throw new DomainException('Bitte Übernehmen oder Nicht übernehmen wählen.');$note=trim($note);
        // Count textarea text, preserving the exact original bytes for sealing and replay.
        $logicalNote=str_replace(["\r\n","\r"],"\n",$note);
        if(!mb_check_encoding($note,'UTF-8')||mb_strlen($logicalNote,'UTF-8')>2000||str_contains($note,"\0")||($decision==='rejected'&&$note===''))throw new DomainException('Bitte eine kurze Begründung angeben (höchstens 2.000 Zeichen).');
        $this->db->beginTransaction();
        try{
            $row=$this->entry($id,true);$a=$this->reviewer();$data=$this->open($row);if($row['state']!=='pending'){if($row['state']===$decision&&$row['reviewed_by']===$a['id']&&str_replace(["\r\n","\r"],"\n",$data['decision_note'])===str_replace(["\r\n","\r"],"\n",$note)){$this->db->commit();return;}throw new DomainException('Dieser Antrag wurde bereits bearbeitet. Bitte den aktuellen Stand öffnen.');}
            if((int)$row['version']!==$version)throw new DomainException('Der Antrag wurde inzwischen geändert. Bitte neu öffnen.');
            if($decision==='accepted'){
                $c=$this->currentContact($a,$row['contact_id']);if(!$this->currentMembership($a['club_id'],$c['id']))throw new DomainException('Das Profil hat keine aktuelle Mitgliedschaft mehr. Bitte den Antrag nicht übernehmen.');
                $after=$this->fields($data['after']);$update=$this->q('UPDATE contacts SET name=?,email=?,phone=?,address=?,version=version+1 WHERE club_id=? AND id=? AND version=?',[$after['name'],$after['email'],$after['phone'],$after['address'],$a['club_id'],$c['id'],$row['contact_version']]);
                if($update->rowCount()!==1)throw new DomainException('Das Profil wurde inzwischen geändert. Vergleiche die Angaben und lehne den alten Antrag mit einer Begründung ab; das Mitglied kann neu einreichen.');
                DocumentCatalog::register($this->db,$a['club_id'],'contact',$c['id']);
                $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'contact.saved',$c['id'],(int)$c['version']+1,gmdate('c')]);
            }
            $data['decision_note']=$note;$cipher=$this->seal($data,$row);$changed=$this->q("UPDATE member_change_requests SET state=?,version=version+1,ciphertext=?,reviewed_at=?,reviewed_by=? WHERE id=? AND club_id=? AND version=? AND state='pending'",[$decision,$cipher,gmdate('c'),$a['id'],$id,$a['club_id'],$version]);if($changed->rowCount()!==1)throw new DomainException('Der Antrag wurde inzwischen geändert. Bitte neu öffnen.',409);$this->audit($a,$row,'member.change.'.$decision,$version+1);$this->db->commit();
        }catch(\Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}
    }
}
