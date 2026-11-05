<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once dirname(__DIR__).'/Shared/Pagination.php';
use PDO;use DomainException;
final class ContactArchive
{
    private string $key;
    public function __construct(private PDO $db,private string $actor,#[\SensitiveParameter] string $key){if(strlen($key)<32||!function_exists('openssl_encrypt'))throw new DomainException('Geschützter Archivschlüssel fehlt.');$this->key=hash_hkdf('sha256',$key,32,'kulturhus-contact-archive-v1');}
    public static function serverKey():string
    {
        $key=trim((string)getenv('APP_SECRET'));if(strlen($key)>=32)return hash('sha256',$key,true);
        if(getenv('VEREIN_MODE')==='synthetic'){
            return \Kulturhus\Verein\Infrastructure\Runtime\ServerKey::syntheticArchive(dirname(dirname(__DIR__, 1),2).'/.local/vereinsverwaltung');
        }
        if(getenv('VEREIN_AUTH')==='native')return \Kulturhus\Verein\Infrastructure\Runtime\ServerKey::nativeFile((string)getenv('KH_NATIVE_PRIVATE_KEY_FILE'));
        throw new DomainException('Privater Archivschlüssel ist nicht eingerichtet.');
    }
    private function access():array{$a=Permissions::actor($this->db,$this->actor);if($a['members_access']!=='write')throw new DomainException('Mitglieder-Schreibrecht erforderlich.',403);return $a;}
    private function seal(array $value,string $club,string $id):string{$nonce=random_bytes(12);$tag='';$data=openssl_encrypt(json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,$nonce,$tag,$club.':'.$id);if($data===false)throw new DomainException('Verschlüsselung fehlgeschlagen.');return base64_encode($nonce.$tag.$data);}
    private function open(string $value,string $club,string $id):array{$raw=base64_decode($value,true);if($raw===false||strlen($raw)<29)throw new DomainException('Archivdaten ungültig.');$plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),$club.':'.$id);if($plain===false)throw new DomainException('Archiv konnte nicht entschlüsselt werden.');return json_decode($plain,true,512,JSON_THROW_ON_ERROR);}
    public function archive(string $id,int $version,string $name,string $reason,string $end):void
    {
        $this->db->beginTransaction();try{$a=$this->access();$c=DocumentCatalog::query($this->db,'SELECT * FROM contacts WHERE club_id=? AND id=? AND archived_at IS NULL FOR UPDATE NOWAIT',[$a['club_id'],$id])->fetch();if(!$c)throw new DomainException('Profil nicht verfügbar.',404);if((int)$c['version']!==$version||trim($name)!==$c['name']||trim($reason)===''||strlen($reason)>500)throw new DomainException('Aktuellen Profilstand, Namen und Archivierungsgrund prüfen.');
            $protected=DocumentCatalog::query($this->db,'SELECT 1 FROM actors a LEFT JOIN admission_board b ON b.actor_id=a.id AND b.club_id=a.club_id WHERE a.club_id=? AND a.contact_id=? AND (a.id=? OR a.is_superuser=1 OR b.enabled=1)',[$a['club_id'],$id,$this->actor])->fetchColumn();if($protected)throw new DomainException('Eigenen Zugang oder Vorstands-/Superuser-Zugang zuerst geordnet über Rollen und Rechte umstellen.');
            $family=(new Registry($this->db,$this->actor))->family($id);if($family&&$family['payer_contact_id']===$id&&count($family['people'])>1)throw new DomainException('Zuerst einen anderen Beitragszahler für die Familie festlegen.');
            $current=DocumentCatalog::query($this->db,"SELECT * FROM memberships WHERE club_id=? AND contact_id=? AND ends_on IS NULL AND status IN ('aktiv','passiv') FOR UPDATE NOWAIT",[$a['club_id'],$id])->fetchAll();
            if($current){$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$end);if(!$d||$d->format('Y-m-d')!==$end||$end>date('Y-m-d'))throw new DomainException('Tatsächliches Austrittsdatum erforderlich.');foreach($current as $m)if($end<$m['starts_on'])throw new DomainException('Austritt liegt vor dem Eintritt.');}
            $snapshot=['contact'=>$c,'reason'=>trim($reason)];$cipher=$this->seal($snapshot,$a['club_id'],$id);$at=gmdate('c');
            DocumentCatalog::query($this->db,'INSERT INTO contact_archives(club_id,contact_id,ciphertext,archived_at,archived_by) VALUES(?,?,?,?,?)',[$a['club_id'],$id,$cipher,$at,$a['id']]);
            $changed=DocumentCatalog::query($this->db,"UPDATE contacts SET name=?,email='',address='',phone='',birthday=NULL,notes='',custom_fields='{}',legacy_member_number='',honorary=0,archived_at=?,version=version+1 WHERE club_id=? AND id=? AND version=? AND archived_at IS NULL",['Archiviertes Profil '.substr($id,0,8),$at,$a['club_id'],$id,$version]);if($changed->rowCount()!==1)throw new DomainException('Profil inzwischen geändert. Ursprüngliche Angaben erneut prüfen.',409);
            if($current){
                DocumentCatalog::query($this->db,"UPDATE memberships SET status='ehemalig',ends_on=?,version=version+1 WHERE club_id=? AND contact_id=? AND ends_on IS NULL",[$end,$a['club_id'],$id]);
                foreach($current as$m)DocumentCatalog::query($this->db,'INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'membership.ended.'.$m['status'],$m['id'],(int)$m['version']+1,$at]);
            }
            DocumentCatalog::query($this->db,'UPDATE processwire_identities SET enabled=0,version=version+1 WHERE actor_id IN (SELECT id FROM actors WHERE club_id=? AND contact_id=?)',[$a['club_id'],$id]);DocumentCatalog::query($this->db,'UPDATE actors SET active=0 WHERE club_id=? AND contact_id=?',[$a['club_id'],$id]);
            DocumentCatalog::query($this->db,"UPDATE manual_member_accounts SET send_mail=0,email='',state='needs_address' WHERE club_id=? AND contact_id=?",[$a['club_id'],$id]);
            if($c['email']!=='')DocumentCatalog::query($this->db,"UPDATE calendar_invitation_deliveries SET status='skipped' WHERE club_id=? AND lower(email)=lower(?) AND status='pending'",[$a['club_id'],$c['email']]);
            DocumentCatalog::query($this->db,"UPDATE document_sources SET label=?,available=0 WHERE club_id=? AND source_type='contact' AND source_id=?",['Archiviertes Profil '.substr($id,0,8),$a['club_id'],$id]);
            DocumentCatalog::query($this->db,"UPDATE document_folders SET name=? WHERE club_id=? AND id IN (SELECT folder_id FROM document_sources WHERE club_id=? AND source_type='contact' AND source_id=?)",['Archiviertes Profil '.substr($id,0,8),$a['club_id'],$a['club_id'],$id]);
            $this->audit($a,$id,'contact.archived');$this->db->commit();
        }catch(\Throwable $e){$this->abort($e);}
    }
    /** The original names remain encrypted at rest. Scan small ciphertext windows. */
    private function archiveRows(array $actor,string $query=''): \Generator
    {
        $cursor=null;
        do{
            $arguments=[];$matching='0';
            if($query!==''){$matching='EXISTS(SELECT 1 FROM memberships m WHERE m.club_id=a.club_id AND m.contact_id=a.contact_id AND LOCATE(LOWER(?),LOWER(m.member_number))>0)';$arguments[]=$query;}
            $sql='SELECT a.club_id,a.contact_id,a.ciphertext,a.archived_at,a.archived_by,c.version,c.archived_at AS current_archived_at,'.$matching.' AS member_matches FROM contact_archives a LEFT JOIN contacts c ON c.club_id=a.club_id AND c.id=a.contact_id WHERE a.club_id=?';$arguments[]=$actor['club_id'];
            if($cursor!==null){$sql.=' AND (a.archived_at<? OR (a.archived_at=? AND a.contact_id>?))';array_push($arguments,$cursor['archived_at'],$cursor['archived_at'],$cursor['contact_id']);}
            $rows=DocumentCatalog::query($this->db,$sql.' ORDER BY a.archived_at DESC,a.contact_id LIMIT 6',$arguments)->fetchAll();
            foreach($rows as$row)yield$row;
            if($rows)$cursor=$rows[array_key_last($rows)];
        }while(count($rows)===6);
    }
    private function readArchive(callable $read):mixed
    {
        $own=!$this->db->inTransaction();
        try{if($own)$this->db->exec('START TRANSACTION READ ONLY');$result=$read($this->access());if($own)$this->db->commit();return$result;}
        catch(\Throwable$error){if($own&&$this->db->inTransaction())$this->db->rollBack();throw$error;}
    }
    private function archiveSummary(array $row):array
    {
        $name='Geschützte Archivdaten nicht lesbar.';$problem='';$readable=false;
        try{
            $original=$this->open($row['ciphertext'],$row['club_id'],$row['contact_id'])['contact']??null;
            if(!is_array($original)||!is_string($original['name']??null)||$original['name']===''||($original['id']??null)!==$row['contact_id'])throw new DomainException('Geschützte Archivdaten nicht lesbar.');
            $name=$original['name'];
            if((int)$row['version']<1||$row['current_archived_at']===null||$row['current_archived_at']!==$row['archived_at'])throw new DomainException('Archiv und Profilstand passen nicht zusammen.');
            $readable=true;
        }catch(DomainException|\JsonException$error){$problem=$error instanceof \JsonException?'Geschützte Archivdaten nicht lesbar.':$error->getMessage();}
        return ['club_id'=>$row['club_id'],'contact_id'=>$row['contact_id'],'name'=>$name,'archived_at'=>$row['archived_at'],'archived_by'=>$row['archived_by'],'version'=>(int)$row['version'],'fingerprint'=>hash('sha256',$row['ciphertext']),'readable'=>$readable,'problem'=>$problem];
    }
    private function archiveMemberships(string $club,string $id):array
    {
        return DocumentCatalog::query($this->db,'SELECT member_number,status,starts_on,starts_precision,ends_on FROM memberships WHERE club_id=? AND contact_id=? ORDER BY starts_on,id',[$club,$id])->fetchAll();
    }
    public function page(array $filters=[],int $page=1):array
    {
        $query=$filters['q']??'';
        if(!is_string($query)||preg_match('//u',$query)!==1||mb_strlen($query,'UTF-8')>150||preg_match('/[\x00-\x1f\x7f]/',$query)||$page<1||$page>999999999)throw new DomainException('Archivsuche und Seite prüfen.',422);
        $query=trim($query);
        return$this->readArchive(function(array$actor)use($query,$page):array{
            if($query===''){
                $total=(int)DocumentCatalog::query($this->db,'SELECT COUNT(*) FROM contact_archives WHERE club_id=?',[$actor['club_id']])->fetchColumn();$window=Pagination::window($total,$page,6);$offset=$window['offset'];unset($window['offset']);
                $rawRows=DocumentCatalog::query($this->db,'SELECT a.club_id,a.contact_id,a.ciphertext,a.archived_at,a.archived_by,c.version,c.archived_at AS current_archived_at FROM contact_archives a LEFT JOIN contacts c ON c.club_id=a.club_id AND c.id=a.contact_id WHERE a.club_id=? ORDER BY a.archived_at DESC,a.contact_id LIMIT 6 OFFSET '.$offset,[$actor['club_id']])->fetchAll();$rows=[];
                foreach($rawRows as$raw){$row=$this->archiveSummary($raw);$row['memberships']=$this->archiveMemberships($actor['club_id'],$row['contact_id']);$rows[]=$row;}return ['rows'=>$rows]+$window;
            }
            $total=0;$rows=[];$last=[];$offset=($page-1)*6;
            foreach($this->archiveRows($actor,$query)as$raw){
                $row=$this->archiveSummary($raw);
                if($query!==''&&!(bool)(int)$raw['member_matches']&&mb_stripos($row['name'],$query,0,'UTF-8')===false)continue;
                $ordinal=$total++;if($ordinal%6===0)$last=[];$last[]=$row;if($ordinal>=$offset&&$ordinal<$offset+6)$rows[]=$row;
            }
            $window=Pagination::window($total,$page,6);unset($window['offset']);if($page>$window['page'])$rows=$last;
            foreach($rows as&$row)$row['memberships']=$this->archiveMemberships($actor['club_id'],$row['contact_id']);unset($row);
            return ['rows'=>$rows]+$window;
        });
    }
    public function archived(string $id):array
    {
        return$this->readArchive(function(array$actor)use($id):array{
            $row=DocumentCatalog::query($this->db,'SELECT a.club_id,a.contact_id,a.ciphertext,a.archived_at,a.archived_by,c.version,c.archived_at AS current_archived_at FROM contact_archives a LEFT JOIN contacts c ON c.club_id=a.club_id AND c.id=a.contact_id WHERE a.club_id=? AND a.contact_id=?',[$actor['club_id'],$id])->fetch();
            if(!$row)throw new DomainException('Archiv nicht verfügbar.',404);
            $summary=$this->archiveSummary($row);if($summary['readable']){$decoded=$this->open($row['ciphertext'],$actor['club_id'],$id);$summary['profile']=$decoded['contact'];$summary['archive_reason']=$decoded['reason']??'';}$summary['memberships']=$this->archiveMemberships($actor['club_id'],$id);return$summary;
        });
    }
    public function listing():array
    {
        return$this->readArchive(function(array$actor):array{$rows=[];foreach($this->archiveRows($actor)as$row){$data=$this->open($row['ciphertext'],$actor['club_id'],$row['contact_id']);$rows[]=['club_id'=>$row['club_id'],'contact_id'=>$row['contact_id'],'archived_at'=>$row['archived_at'],'archived_by'=>$row['archived_by'],'name'=>$data['contact']['name'],'memberships'=>$this->archiveMemberships($actor['club_id'],$row['contact_id'])];}return$rows;});
    }
    public function restore(string $id,?array $reviewed=null):void
    {
        $this->db->beginTransaction();try{
            $a=$this->access();$r=DocumentCatalog::query($this->db,'SELECT * FROM contact_archives WHERE club_id=? AND contact_id=? FOR UPDATE NOWAIT',[$a['club_id'],$id])->fetch();
            if(!$r)throw new DomainException('Archiv nicht verfügbar.',404);
            $current=DocumentCatalog::query($this->db,'SELECT version,archived_at FROM contacts WHERE club_id=? AND id=? FOR UPDATE NOWAIT',[$a['club_id'],$id])->fetch();
            if(!$current||$current['archived_at']===null||$current['archived_at']!==$r['archived_at'])throw new DomainException('Archiv und Profilstand passen nicht zusammen. Aktuellen Stand erneut öffnen.',409);
            if($reviewed!==null&&(!is_int($reviewed['version']??null)||!is_string($reviewed['fingerprint']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$reviewed['fingerprint'])||$reviewed['version']!==(int)$current['version']||!hash_equals(hash('sha256',$r['ciphertext']),$reviewed['fingerprint'])))throw new DomainException('Archivstand inzwischen geändert. Ursprüngliches Profil erneut prüfen.',409);
            $c=$this->open($r['ciphertext'],$a['club_id'],$id)['contact'];if($reviewed!==null&&array_key_exists('name',$reviewed)&&(!is_string($reviewed['name'])||$reviewed['name']!==$c['name']))throw new DomainException('Geprüfter Profilname passt nicht zum ursprünglichen Archiv.',409);
            $changed=DocumentCatalog::query($this->db,'UPDATE contacts SET name=?,email=?,address=?,phone=?,birthday=?,notes=?,custom_fields=?,legacy_member_number=?,honorary=?,archived_at=NULL,version=version+1 WHERE club_id=? AND id=? AND version=? AND archived_at IS NOT NULL',[$c['name'],$c['email'],$c['address'],$c['phone'],$c['birthday'],$c['notes'],$c['custom_fields']??'{}',$c['legacy_member_number'],$c['honorary'],$a['club_id'],$id,(int)$current['version']]);
            if($changed->rowCount()!==1)throw new DomainException('Profil inzwischen geändert. Ursprüngliche Angaben erneut prüfen.',409);
            DocumentCatalog::query($this->db,'DELETE FROM contact_archives WHERE club_id=? AND contact_id=?',[$a['club_id'],$id]);DocumentCatalog::register($this->db,$a['club_id'],'contact',$id);$this->audit($a,$id,'contact.restored');$this->db->commit();
        }catch(\Throwable $e){$this->abort($e);}
    }
    private function abort(\Throwable $error):never
    {
        if($this->db->inTransaction())$this->db->rollBack();
        if($error instanceof \PDOException&&in_array((int)($error->errorInfo[1]??0),[1205,1213,3572],true))throw new DomainException('Profil oder Archiv wird gerade bearbeitet. Ursprüngliche Angaben erneut prüfen.',409,$error);
        throw $error;
    }
    private function audit(array $a,string $id,string $action):void{DocumentCatalog::query($this->db,'INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,1,?)',[$a['club_id'],$a['id'],$action,$id,gmdate('c')]);}
}
