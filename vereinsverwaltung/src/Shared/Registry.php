<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once __DIR__.'/Pagination.php';
require_once __DIR__.'/../Events/AppointmentSeries.php';
use DomainException;
use PDO;
/** Actor IDs must originate from the application's own authenticated session. */
final class Registry
{
    private bool $insideTransaction=false;
    use MemberOverview;
    use MemberFamilies;
    use AppointmentSeries;
    public function __construct(private PDO $db, private string $actorId, private ?WebsiteCalendar $websiteCalendar = null) {}

    public function calendarNotice(): string { return $this->websiteCalendar?->notice() ?? ''; }
    private function actor(bool $write = false): array
    {
        $actor=Permissions::actor($this->db,$this->actorId);
        if ($write ? $actor['members_access']!=='write' : ($actor['members_access']==='none'&&$actor['role']!=='member')) {
            throw new DomainException('Keine Berechtigung für diese Mitgliederdaten.');
        }
        if($actor['members_access']!=='none')$actor['role']='members';
        return $actor;
    }
    public function canReadProfiles(): bool
    {
        try { $this->actor(); return true; } catch (DomainException) { return false; }
    }
    public function canWrite(): bool
    {
        try { $this->actor(true); return true; } catch (DomainException) { return false; }
    }
    public function contacts(string $search = ''): array
    {
        $a = $this->actor();
        if (strlen($search) > 150) throw new DomainException('Suchtext ist zu lang.');
        $q = $this->db->prepare("SELECT * FROM contacts WHERE archived_at IS NULL AND club_id = ? AND (? = 'members' OR id = ?) AND instr(lower(name), lower(?)) > 0 ORDER BY name, id LIMIT 1000");
        $q->execute([$a['club_id'], $a['role'], $a['contact_id'], trim($search)]);
        return $q->fetchAll();
    }
    /** Small complete choices; never loads profile notes, addresses or memberships as DTOs. */
    public function contactChoicePage(array $input=[],bool $calendar=false,string $kind=''):array
    {
        $query=$input['contact_query']??'';$requested=$input['contact_page']??1;$selected=$input['contact_id']??'';
        if(!is_string($query)||preg_match('//u',$query)!==1||mb_strlen($query,'UTF-8')>150||preg_match('/[\x00-\x1f\x7f]/',$query)
            ||(!is_int($requested)&&!is_string($requested))||!preg_match('/\A[1-9][0-9]*\z/D',(string)$requested)
            ||($requested=filter_var($requested,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000000]]))===false
            ||!is_string($selected)||strlen($selected)>64||preg_match('/[\x00-\x1f\x7f]/',$selected))throw new DomainException('Kontaktsuche und Seite prüfen.');
        $contactKind=$input['contact_kind']??'';$exact=$input['contact_exact']??false;
        if(!in_array($contactKind,['','person','organisation'],true)||!is_bool($exact))throw new DomainException('Kontaktart und Suchweise prüfen.');
        $a=$calendar?$this->calendarWriter():$this->actor();$query=trim($query);$args=[$a['club_id']];$where='c.club_id=? AND c.archived_at IS NULL';
        if($contactKind!==''){$where.=' AND c.kind=?';$args[]=$contactKind;}
        if(!$calendar){$where.=" AND (?='members' OR c.id=?)";array_push($args,$a['role'],$a['contact_id']);}
        elseif(in_array($kind,['assembly','board'],true)){
            BoardAccess::require($this->db,$this->actorId);
            if($kind==='assembly'){$today=date('Y-m-d');$where.=" AND c.kind='person' AND EXISTS(SELECT 1 FROM memberships m WHERE m.club_id=c.club_id AND m.contact_id=c.id AND m.status IN('aktiv','passiv') AND m.starts_on<=? AND (m.ends_on IS NULL OR m.ends_on>=?))";array_push($args,$today,$today);}
            else{$allowed=array_column(CalendarInvitations::meetingEligibleContacts($this->db,$a['club_id'],'board'),'id');if(!$allowed)$where.=' AND 1=0';else{$where.=' AND c.id IN ('.implode(',',array_fill(0,count($allowed),'?')).')';array_push($args,...$allowed);}}
        }
        if($calendar&&array_key_exists('calendar_ids',$input)){
            $ids=$input['calendar_ids'];if(!is_array($ids)||!array_is_list($ids)||count($ids)>10000)throw new DomainException('Teilnehmerauswahl prüfen.');
            foreach($ids as$id)if(!is_string($id)||$id===''||strlen($id)>64||preg_match('//u',$id)!==1||preg_match('/[\x00-\x1f\x7f]/',$id))throw new DomainException('Teilnehmerauswahl prüfen.');
            if(!$ids)$where.=' AND 1=0';else{$where.=' AND c.id IN ('.implode(',',array_fill(0,count($ids),'?')).')';array_push($args,...$ids);}
        }
        $base=' FROM contacts c WHERE '.$where;$selection=null;
        if($selected!==''){$q=$this->db->prepare('SELECT c.id,c.name,c.version'.$base.' AND c.id=?');$q->execute([...$args,$selected]);$selection=$q->fetch(\PDO::FETCH_ASSOC);if(!$selection)throw new DomainException('Gewählter Kontakt ist nicht mehr verfügbar.');}
        $matched=$base;$matchedArgs=$args;
        if($query!==''){$matched.=$exact?' AND BINARY LOWER(c.name)=BINARY LOWER(?)':($calendar?' AND instr(lower(c.name),lower(?))>0':' AND (instr(lower(c.name),lower(?))>0 OR instr(lower(c.email),lower(?))>0)');$matchedArgs[]=$query;if(!$calendar&&!$exact)$matchedArgs[]=$query;}
        $q=$this->db->prepare('SELECT COUNT(*)'.$matched);$q->execute($matchedArgs);$total=(int)$q->fetchColumn();$window=Pagination::window($total,$requested,6);
        $q=$this->db->prepare('SELECT c.id,c.name,c.version'.$matched.' ORDER BY c.name,c.id LIMIT 6 OFFSET '.$window['offset']);$q->execute($matchedArgs);
        $rows=$q->fetchAll(\PDO::FETCH_ASSOC);$current=$calendar?$this->calendarWriter():$this->actor();
        if($current['club_id']!==$a['club_id']||$current['id']!==$a['id']||(!$calendar&&($current['role']!==$a['role']||($current['contact_id']??null)!==($a['contact_id']??null))))throw new DomainException('Kontaktauswahl nach geänderten Zugangsrechten erneut öffnen.');
        if($calendar&&in_array($kind,['assembly','board'],true))BoardAccess::require($this->db,$this->actorId);
        return ['rows'=>$rows,'selected'=>$selection,'query'=>$query,'page'=>$window['page'],'pages'=>$window['pages'],'total'=>$total];
    }

    public function contact(string $id): array
    {
        $a = $this->actor();
        $q = $this->db->prepare("SELECT * FROM contacts WHERE archived_at IS NULL AND club_id = ? AND id = ? AND (? = 'members' OR id = ?)");
        $q->execute([$a['club_id'], $id, $a['role'], $a['contact_id']]);
        $contact = $q->fetch();
        if (!$contact) throw new DomainException('Profil nicht verfügbar.');
        return $contact;
    }
    public function memberships(string $contactId): array
    {
        $c = $this->contact($contactId);
        $q = $this->db->prepare('SELECT * FROM memberships WHERE club_id = ? AND contact_id = ? ORDER BY starts_on, id');
        $q->execute([$c['club_id'], $contactId]);
        return $q->fetchAll();
    }
    public function saveContact(?string $id, int $version, string $kind, string $name, string $email, string $address, ?array $details = null): string
    {
        return $this->transaction(function () use ($id, $version, $kind, $name, $email, $address, $details): string {
            $a = $this->actor(true);
            $name = trim($name); $email = trim($email); $address = trim($address);
            if (!in_array($kind, ['person', 'organisation'], true) || $name === '' || strlen($name) > 200
                || strlen($email) > 254 || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) || strlen($address) > 1000) {
                throw new DomainException('Bitte Art, Name, E-Mail und Anschrift prüfen.');
            }
            if ($id === null) {
                $id = bin2hex(random_bytes(16));
                $this->db->prepare('INSERT INTO contacts (id,club_id,kind,name,email,address) VALUES (?,?,?,?,?,?)')
                    ->execute([$id,$a['club_id'],$kind,$name,$email,$address]);
                $version = 1;
            } else {
                $existing = $this->contact($id);
                if ($existing['kind'] !== $kind) throw new DomainException('Profilart kann nach Anlage nicht geändert werden.');
                $q = $this->db->prepare('UPDATE contacts SET name=?,email=?,address=?,version=version+1 WHERE club_id=? AND id=? AND version=?');
                $q->execute([$name,$email,$address,$a['club_id'],$id,$version]);
                if ($q->rowCount() !== 1) throw new DomainException('Das Profil wurde inzwischen geändert. Bitte neu laden und Änderungen vergleichen.');
                ++$version;
            }
            if ($details!==null) {
                $phone=trim((string)($details['phone']??''));$birthday=trim((string)($details['birthday']??''));
                $notes=trim((string)($details['notes']??''));$honorary=!empty($details['honorary'])?1:0;
                if(strlen($phone)>80||strlen($notes)>10000)throw new DomainException('Telefon oder Notiz ist zu lang.');
                if($birthday!==''){self::date($birthday);if($birthday>date('Y-m-d'))throw new DomainException('Geburtstag darf nicht in der Zukunft liegen.');}
                $this->db->prepare('UPDATE contacts SET phone=?,birthday=?,notes=?,honorary=? WHERE club_id=? AND id=?')
                    ->execute([$phone,$birthday!==''?$birthday:null,$notes,$honorary,$a['club_id'],$id]);
            }
            if ($details!==null && array_key_exists('legacy_member_number',$details)) {
                $legacy=trim((string)$details['legacy_member_number']);
                if($legacy!==''&&!preg_match('/^[\pL\pN ._\/-]{1,80}$/uD',$legacy))throw new DomainException('Alte Mitgliedsnummer ist ungültig (höchstens 80 Zeichen).');
                $this->db->prepare('UPDATE contacts SET legacy_member_number=? WHERE club_id=? AND id=?')->execute([$legacy,$a['club_id'],$id]);
            }
            if($details!==null&&array_key_exists('custom_fields',$details))MemberCustomFields::save($this->db,$a['club_id'],$id,$details['custom_fields'],(int)($details['custom_fields_version']??-1));
            $this->audit($a, 'contact.saved', $id, $version);
            return $id;
        });
    }
    public function createOrganisationOnce(array $data,string $key,callable $guard,?callable $afterCreate=null,array $requestExtra=[]):array
    {
        return $this->transaction(function()use($data,$key,$guard,$afterCreate,$requestExtra):array{
            $a=$this->actor(true);$guard();
            if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new DomainException('Vorgangskennung ungültig.');
            $fields=[];foreach(['name','email','address'] as $field){if(isset($data[$field])&&!is_string($data[$field]))throw new DomainException('Firmenangaben prüfen.');$fields[$field]=trim($data[$field]??'');}
            if(isset($data['phone'])&&!is_string($data['phone']))throw new DomainException('Telefonnummer prüfen.');if(trim($data['phone']??'')!=='')$fields['phone']=trim($data['phone']);
            $hash=hash('sha256',json_encode($requestExtra?[$fields,$requestExtra]:$fields,JSON_THROW_ON_ERROR));
            $q=$this->db->prepare('SELECT request_hash,contact_id FROM supplier_contact_requests WHERE club_id=? AND actor_id=? AND request_key=?');$q->execute([$a['club_id'],$a['id'],$key]);$previous=$q->fetch();
            if($previous){if(!hash_equals($previous['request_hash'],$hash))throw new DomainException('Diese Firmenanlage wurde bereits mit anderen Angaben gespeichert. Bitte den gespeicherten Kontakt prüfen.');return $this->contact($previous['contact_id']);}
            $id=$this->saveContact(null,0,'organisation',$fields['name'],$fields['email'],$fields['address'],['phone'=>$fields['phone']??'']);
            if($afterCreate)$afterCreate($this->contact($id));
            $this->db->prepare('INSERT INTO supplier_contact_requests VALUES(?,?,?,?,?)')->execute([$a['club_id'],$a['id'],$key,$hash,$id]);
            return $this->contact($id);
        });
    }
    public function createMemberContact(string $kind,string $name,string $email,string $address,array $details,array $membership,?bool $sendMail=false):string
    {
        return $this->transaction(function()use($kind,$name,$email,$address,$details,$membership,$sendMail){
            $contact=$this->saveContact(null,0,$kind,$name,$email,$address,$details);
            $this->addMembership($contact,(string)($membership['number']??''),(string)($membership['status']??'aktiv'),(string)($membership['start']??''),$sendMail,['expected'=>'','target'=>(string)($membership['family']??''),'name'=>(string)($membership['family_name']??'')],(string)($membership['start_precision']??'day'));
            return $contact;
        });
    }
    public function importMembers(callable $reviewedRows,?callable $afterCreate=null):array
    {
        return $this->transaction(function()use($reviewedRows,$afterCreate){$this->actor(true);$ids=[];$byLine=[];$rows=$reviewedRows();foreach($rows as $row){if($row['state']==='existing')continue;if(!in_array($row['state'],['new','convert'],true))throw new DomainException('Importzeile nicht freigegeben.');$r=$row['record'];$id=$row['target'];
            if($row['state']==='new')$id=$this->saveContact(null,0,'person',$r['name'],$r['email'],$r['address'],['phone'=>$r['phone'],'birthday'=>$r['birthday'],'legacy_member_number'=>$r['legacy']]);
            if($row['state']==='convert'&&$r['legacy']!==''){$current=$this->contact($id);if(($current['legacy_member_number']??'')!==''&&$current['legacy_member_number']!==$r['legacy'])throw new DomainException('Alte Mitgliedsnummer inzwischen geändert.');$this->db->prepare('UPDATE contacts SET legacy_member_number=?,version=version+1 WHERE club_id=? AND id=?')->execute([$r['legacy'],$current['club_id'],$id]);}
            $membership=$this->addMembership($id,$r['number'],$r['status']==='passiv'?'passiv':'aktiv',$r['start'],$r['end']===''?false:null,null,$r['start_precision']);if($r['end']!=='')$this->endMembership($membership,1,$r['end']);$ids[]=$id;$byLine[$row['line']]=$id;
        }if($afterCreate!==null)$afterCreate($rows,$byLine);return $ids;});
    }
    public function addMembership(string $contactId, string $number, string $status, string $start, ?bool $sendAccessMail = null, ?array $familyChoice = null, string $startPrecision = 'day'): string
    {
        return $this->transaction(function () use ($contactId,$number,$status,$start,$sendAccessMail,$familyChoice,$startPrecision): string {
            $a = $this->actor(true); $this->contact($contactId);
            if(!in_array($startPrecision,['day','year'],true))throw new DomainException('Datumsgenauigkeit prüfen.');if($startPrecision==='year'){if(!preg_match('/^(18|19|20)[0-9]{2}-01-01$/D',$start)||(int)substr($start,0,4)>(int)date('Y'))throw new DomainException('Eintrittsjahr prüfen.');}
            self::date($start);$id=bin2hex(random_bytes(16));
            if($number==='')$number=Numbering::allocate($this->db,$a['club_id'],'member',$start,$id);
            if (!preg_match('/^[\pL\pN ._\/-]{1,80}$/uD', $number) || !in_array($status,['aktiv','passiv'],true)) {
                throw new DomainException('Mitgliedsnummer oder Status ungültig.');
            }
            self::date($start);
            $q = $this->db->prepare('SELECT 1 FROM memberships WHERE club_id=? AND member_number=?');
            $q->execute([$a['club_id'],$number]);
            if ($q->fetchColumn()) throw new DomainException('Diese Mitgliedsnummer ist bereits vergeben.');
            $this->db->prepare('INSERT INTO memberships (id,club_id,contact_id,member_number,status,starts_on) VALUES (?,?,?,?,?,?)')
                ->execute([$id,$a['club_id'],$contactId,$number,$status,$start]);
            if($familyChoice!==null)$this->assignFamilyLocked($contactId,(string)($familyChoice['expected']??''),(string)($familyChoice['target']??''),(string)($familyChoice['name']??''));
            $this->db->prepare('UPDATE memberships SET starts_precision=? WHERE club_id=? AND id=?')->execute([$startPrecision,$a['club_id'],$id]);
            DocumentCatalog::register($this->db,$a['club_id'],'contact',$contactId);$this->audit($a,'membership.created',$id,1);
            if($sendAccessMail!==null)ManualMemberAccounts::enqueue($this->db,$a,$contactId,$sendAccessMail);
            return $id;
        });
    }
    public function changeMembershipStart(string $contactId,string $id,int $version,string $start,string $precision='day'):void
    {
        $this->transaction(function()use($contactId,$id,$version,$start,$precision):void{
            $a=$this->actor(true);$this->contact($contactId);self::date($start);
            if(!in_array($precision,['day','year'],true)||($precision==='year'&&(!preg_match('/^(18|19|20)[0-9]{2}-01-01$/D',$start)||(int)substr($start,0,4)>(int)date('Y'))))throw new DomainException('Eintrittsdatum und Datumsgenauigkeit prüfen.');
            $q=$this->db->prepare('UPDATE memberships SET starts_on=?,starts_precision=?,version=version+1 WHERE club_id=? AND contact_id=? AND id=? AND version=? AND (ends_on IS NULL OR ends_on>=?)');
            $q->execute([$start,$precision,$a['club_id'],$contactId,$id,$version,$start]);
            if($q->rowCount()!==1)throw new DomainException('Eintritt nicht gespeichert: Das Datum darf nicht nach dem Austritt liegen. Bitte auch den aktuellen Stand neu laden.');
            $this->audit($a,'membership.start.changed',$id,$version+1);
        });
    }
    public function endMembership(string $id, int $version, string $end): void
    {
        $this->transaction(function () use ($id,$version,$end): void {
            $a = $this->actor(true); self::date($end);
            $previous=$this->db->prepare('SELECT status FROM memberships WHERE club_id=? AND id=? AND version=? AND ends_on IS NULL FOR UPDATE');
            $previous->execute([$a['club_id'],$id,$version]);$previousStatus=$previous->fetchColumn();
            $q = $this->db->prepare('UPDATE memberships SET status=\'ehemalig\',ends_on=?,version=version+1 WHERE club_id=? AND id=? AND version=? AND ends_on IS NULL AND starts_on <= ?');
            $q->execute([$end,$a['club_id'],$id,$version,$end]);
            if ($q->rowCount() !== 1) throw new DomainException('Austritt nicht möglich: Zeitraum, Berechtigung oder Version prüfen.');
            if(in_array($previousStatus,['aktiv','passiv'],true))$this->audit($a,'membership.ended.'.$previousStatus,$id,$version+1);
            $this->audit($a,'membership.ended',$id,$version+1);
        });
    }
    public function history(): array
    {
        $a = $this->actor(true);
        $q = $this->db->prepare('SELECT * FROM audit_events WHERE club_id=? ORDER BY sequence DESC LIMIT 100');
        $q->execute([$a['club_id']]);
        return $q->fetchAll();
    }
    public function canRead(): bool
    {
        try {$this->actor();return true;}catch(DomainException){return false;}
    }
    public function contactPage(array $filters = [], int $page = 1, int $size = 25): array
    {
        $a=$this->actor();$filters=self::filters($filters);
        [$base,$params]=$this->contactProjection($a,$filters['as_of']);$where=['1=1'];
        if($filters['q']!==''){$where[]='(instr(lower(name),lower(?))>0 OR instr(lower(email),lower(?))>0 OR instr(member_number,?)>0 OR instr(lower(legacy_member_number),lower(?))>0)';array_push($params,$filters['q'],$filters['q'],$filters['q'],$filters['q']);}
        $scope=['current'=>"member_status IN ('aktiv','passiv','mitglied')",'contacts'=>"member_status='kontakt'",'members'=>"member_status!='kontakt'",'all'=>'1=1','birthdays'=>'birthday IS NOT NULL'];
        $where[]=$scope[$filters['list']];
        if($filters['status']!==''){$where[]='member_status=?';$params[]=$filters['status'];}
        if($filters['kind']!==''){$where[]='kind=?';$params[]=$filters['kind'];}
        $sql=' FROM ('.$base.') profiles WHERE '.implode(' AND ',$where);
        $q=$this->db->prepare('SELECT COUNT(*)'.$sql);$q->execute($params);$count=(int)$q->fetchColumn();
        $size=max(10,min(100,$size));$window=Pagination::window($count,max(1,$page),$size);$pages=$window['pages'];$page=$window['page'];
        $sort=['name'=>'name','number'=>'member_number','status'=>'member_status','birthday'=>"substr(birthday,6)"][$filters['sort']];
        $q=$this->db->prepare('SELECT *'.$sql.' ORDER BY '.$sort.' '.$filters['direction'].', id LIMIT '.$size.' OFFSET '.(($page-1)*$size));$q->execute($params);
        return ['rows'=>$q->fetchAll(),'total'=>$count,'page'=>$page,'pages'=>$pages,'size'=>$size,'filters'=>$filters];
    }
    /** Read membership periods at a date; names and contact details remain the current permitted profile. */
    private function contactProjection(array $actor,string $day):array
    {
        $access="c.archived_at IS NULL AND c.club_id=? AND (?='members' OR c.id=?)";
        $params=[$actor['club_id'],$actor['role'],$actor['contact_id']];
        if($day==='')return ["SELECT c.*, (SELECT m.member_number FROM memberships m WHERE m.club_id=c.club_id AND m.contact_id=c.id ORDER BY m.starts_on DESC,m.id DESC LIMIT 1) AS member_number,
            COALESCE((SELECT m.status FROM memberships m WHERE m.club_id=c.club_id AND m.contact_id=c.id ORDER BY m.starts_on DESC,m.id DESC LIMIT 1),'kontakt') AS member_status
            FROM contacts c WHERE ".$access,$params];
        // The old 'ehemalig' value destroyed the previous type. Only a recorded original can restore it.
        $sql="SELECT c.*,m.member_number,CASE
            WHEN m.id IS NULL THEN 'kontakt'
            WHEN m.ends_on IS NOT NULL AND m.ends_on<point.as_of THEN 'ehemalig'
            WHEN m.starts_precision='year' AND LEFT(m.starts_on,4)=LEFT(point.as_of,4) AND RIGHT(point.as_of,5)<'12-31' THEN 'ungewiss'
            WHEN m.status IN ('aktiv','passiv') THEN m.status
            ELSE COALESCE((SELECT SUBSTRING_INDEX(e.action,'.',-1) FROM audit_events e
                WHERE e.club_id=m.club_id AND e.object_id=m.id AND e.object_version<=m.version
                AND e.action IN ('membership.ended.aktiv','membership.ended.passiv') ORDER BY e.sequence DESC LIMIT 1),'mitglied') END AS member_status
            FROM contacts c CROSS JOIN (SELECT ? AS as_of) point
            LEFT JOIN memberships m ON m.club_id=c.club_id AND m.contact_id=c.id AND m.id=(SELECT prior.id FROM memberships prior
                WHERE prior.club_id=c.club_id AND prior.contact_id=c.id AND prior.starts_on<=point.as_of ORDER BY prior.starts_on DESC,prior.id DESC LIMIT 1)
            WHERE ".$access;
        return [$sql,[$day,...$params]];
    }
    public static function memberStatusLabels():array
    {
        return ['aktiv'=>'Aktiv','passiv'=>'Passiv','ehemalig'=>'Ehemalig','kontakt'=>'Kontakt','mitglied'=>'Mitglied · Art unbekannt','ungewiss'=>'Eintritt am Stichtag ungewiss'];
    }
    public static function filters(array $data): array
    {
        foreach(['q','list','status','kind','sort','direction','as_of']as$key)if(isset($data[$key])&&!is_string($data[$key]))throw new DomainException('Ungültige Listenfilter.');
        $f=['q'=>trim($data['q']??''),'list'=>$data['list']??'all','status'=>$data['status']??'','kind'=>$data['kind']??'','sort'=>$data['sort']??'name','direction'=>strtoupper($data['direction']??'ASC'),'as_of'=>$data['as_of']??''];
        if(strlen($f['q'])>150||!in_array($f['list'],['all','current','contacts','members','birthdays'],true)
          ||!in_array($f['status'],['',...array_keys(self::memberStatusLabels())],true)||!in_array($f['kind'],['','person','organisation'],true)
          ||!in_array($f['sort'],['name','number','status','birthday'],true)||!in_array($f['direction'],['ASC','DESC'],true))throw new DomainException('Ungültige Listenfilter.');
        if($f['as_of']!==''){self::date($f['as_of']);if($f['as_of']<'1800-01-01'||$f['as_of']>'2099-12-31')throw new DomainException('Stichtag zwischen 1800 und 2099 wählen.');}
        return $f;
    }
    public function saveList(string $name,array $filters): string
    {
        return $this->transaction(function()use($name,$filters):string{
            $a=$this->actor(true);$name=trim($name);
            if($name===''||strlen($name)>100)throw new DomainException('Bitte einen Listennamen bis 100 Zeichen eingeben.');
            $q=$this->db->prepare('SELECT 1 FROM saved_lists WHERE club_id=? AND name=?');$q->execute([$a['club_id'],$name]);
            if($q->fetchColumn())throw new DomainException('Eine Liste mit diesem Namen existiert bereits.');
            $id=bin2hex(random_bytes(16));
            $this->db->prepare('INSERT INTO saved_lists VALUES (?,?,?,?,?)')->execute([$id,$a['club_id'],$name,json_encode(self::filters($filters),JSON_THROW_ON_ERROR),$a['id']]);
            $this->audit($a,'list.created',$id,1);return $id;
        });
    }
    public function savedLists(): array
    {
        $a=$this->actor(true);$q=$this->db->prepare('SELECT * FROM saved_lists WHERE club_id=? ORDER BY name');$q->execute([$a['club_id']]);return $q->fetchAll();
    }
    public function deleteList(string $id): void
    {
        $this->transaction(function()use($id):void{
            $a=$this->actor(true);$q=$this->db->prepare('DELETE FROM saved_lists WHERE club_id=? AND id=?');$q->execute([$a['club_id'],$id]);
            if($q->rowCount()!==1)throw new DomainException('Liste nicht verfügbar.');$this->audit($a,'list.deleted',$id,1);
        });
    }
    public function exportMemberWorkbook(string $scope,string $search):string
    {
        return $this->transaction(function()use($scope,$search):string{$a=$this->actor(true);$records=MemberWorkbook::records($this->db,$this->actorId,$scope,$search);$bytes=MemberWorkbook::create($records);$current=$this->actor(true);if($current['club_id']!==$a['club_id'])throw new DomainException('Vereinszugang seit der Auswahl geändert.');$this->audit($a,'members.excel.exported',bin2hex(random_bytes(16)),1);return$bytes;});
    }
    public function exportContacts(array $ids,array $columns,string $format='csv',string $asOf=''): string
    {
        $a=$this->actor(true);
        $labels=['name'=>'Name','email'=>'E-Mail','address'=>'Anschrift','phone'=>'Telefon','birthday'=>'Geburtstag'];
        $asOf=self::filters(['as_of'=>$asOf])['as_of'];
        if($asOf!=='')$labels+=['member_number'=>'Mitgliedsnummer','member_status'=>'Mitgliedschaft am Stichtag','as_of'=>'Stichtag'];
        if(!in_array($format,['csv','xlsx'],true)||!array_is_list($ids)||!array_is_list($columns)||array_filter($ids,fn($id)=>!is_string($id))||array_filter($columns,fn($column)=>!is_string($column))||count($ids)<1||count($ids)>100||count($ids)!==count(array_unique($ids))||!$columns||count($columns)!==count(array_unique($columns))||array_diff($columns,array_keys($labels)))throw new DomainException('Bitte 1 bis 100 Profile, das Dateiformat und gültige Spalten wählen.');
        $headers=array_map(fn($c)=>$labels[$c],$columns);$rows=[];
        foreach($ids as $id){
            if($asOf==='')$c=$this->contact($id);
            else{[$projection,$args]=$this->contactProjection($a,$asOf);$query=$this->db->prepare('SELECT * FROM ('.$projection.') snapshot WHERE id=?');$query->execute([...$args,$id]);$c=$query->fetch();if(!$c)throw new DomainException('Profil nicht verfügbar.');$c['member_status']=self::memberStatusLabels()[$c['member_status']];$c['as_of']=$asOf;}
            $rows[]=array_map(fn($column)=>(string)($c[$column]??''),$columns);
        }
        if($format==='xlsx'){require_once dirname(__DIR__, 1).'/Communication/ContactSpreadsheet.php';$bytes=ContactSpreadsheet::create($headers,$rows);}
        else{$stream=fopen('php://temp','r+');try{fputcsv($stream,$headers,';','"','',"\r\n");foreach($rows as $row){foreach($row as &$value)if(preg_match('/^[\s]*[=+@-]/u',$value)||preg_match('/^[\t\r\n]/',$value))$value="'".$value;unset($value);fputcsv($stream,$row,';','"','',"\r\n");}rewind($stream);$bytes="\xEF\xBB\xBF".stream_get_contents($stream);}finally{fclose($stream);}}
        $this->audit($a,'contacts.exported',bin2hex(random_bytes(16)),1);return $bytes;
    }
    public function canWriteCalendar():bool{return $this->canWrite()||BoardAccess::allowed($this->db,$this->actorId);}
    private function calendarWriter():array{return BoardAccess::allowed($this->db,$this->actorId)?BoardAccess::require($this->db,$this->actorId):$this->actor(true);}
    public function calendarContacts(string $kind=''):array{$a=$this->calendarWriter();if(in_array($kind,['assembly','board'],true))return CalendarInvitations::meetingPeople($this->db,$this->actorId,$kind);return DocumentCatalog::query($this->db,'SELECT id,name FROM contacts WHERE archived_at IS NULL AND club_id=? ORDER BY name,id LIMIT 1000',[$a['club_id']])->fetchAll();}
    /** Explicit complete group selection; IDs only, with the same current meeting rules. */
    public function calendarSelectionGroup(string $kind):array
    {
        if(!in_array($kind,['event','external','assembly','board','other'],true))throw new DomainException('Terminart prüfen.');
        $a=$this->calendarWriter();$where='c.club_id=? AND c.archived_at IS NULL';$args=[$a['club_id']];
        if(in_array($kind,['assembly','board'],true)){
            BoardAccess::require($this->db,$this->actorId);
            if($kind==='assembly'){$where.=" AND c.kind='person' AND EXISTS(SELECT 1 FROM memberships m WHERE m.club_id=c.club_id AND m.contact_id=c.id AND m.status IN('aktiv','passiv') AND m.starts_on<=? AND (m.ends_on IS NULL OR m.ends_on>=?))";array_push($args,date('Y-m-d'),date('Y-m-d'));}
            else{$allowed=array_column(CalendarInvitations::meetingEligibleContacts($this->db,$a['club_id'],'board'),'id');if(!$allowed)$where.=' AND 1=0';else{$where.=' AND c.id IN ('.implode(',',array_fill(0,count($allowed),'?')).')';array_push($args,...$allowed);}}
        }
        $q=$this->db->prepare('SELECT c.id FROM contacts c WHERE '.$where.' ORDER BY c.name,c.id LIMIT 10001');$q->execute($args);$ids=$q->fetchAll(\PDO::FETCH_COLUMN);
        $current=$this->calendarWriter();if($a['id']!==$current['id']||$a['club_id']!==$current['club_id'])throw new DomainException('Teilnehmer nach geänderten Zugangsrechten erneut auswählen.');
        if(in_array($kind,['assembly','board'],true))BoardAccess::require($this->db,$this->actorId);
        if(count($ids)>10000)throw new DomainException('Mehr als 10.000 Teilnehmer bitte in getrennten Terminen auswählen.');
        return $ids;
    }
    public function canReadCalendar():bool
    {
        try{$this->calendarActor();return true;}catch(DomainException){return false;}
    }
    private function calendarActor():array
    {
        if(BoardAccess::allowed($this->db,$this->actorId)){$a=Permissions::actor($this->db,$this->actorId);$a['role']='members';return $a;}
        return $this->actor();
    }
    public function appointments(string $from,string $to): array
    {
        $a=$this->calendarActor();self::date($from);self::date($to);
        if($from>$to)throw new DomainException('Ungültiger Zeitraum.');
        $q=$this->db->prepare("SELECT e.* FROM appointments e WHERE club_id=? AND starts_at<? AND ends_at>=? AND (?='members' OR EXISTS (SELECT 1 FROM appointment_attendees t WHERE t.club_id=e.club_id AND t.appointment_id=e.id AND t.contact_id=?)) ORDER BY starts_at,id");
        $tz=new \DateTimeZone('Europe/Berlin');$utc=new \DateTimeZone('UTC');
        $fromUtc=(new \DateTimeImmutable($from.' 00:00:00',$tz))->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
        $toUtc=(new \DateTimeImmutable($to.' 00:00:00',$tz))->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
        $q->execute([$a['club_id'],$toUtc,$fromUtc,$a['role'],$a['contact_id']]);
        $events = array_merge($q->fetchAll(), $this->websiteCalendar?->appointments($a['club_id'],$fromUtc,$toUtc) ?? []);
        usort($events, static fn(array $left,array $right): int => [$left['starts_at'],$left['id']] <=> [$right['starts_at'],$right['id']]);
        return $events;
    }
    /** Small current dashboard event metadata, without descriptions or participant bodies. */
    public function dashboardAppointments(string $from,string $to):array
    {
        self::date($from);self::date($to);if($from>=$to)throw new DomainException('Ungültiger Zeitraum.');
        return$this->overviewRead(function()use($from,$to):array{
            $a=$this->calendarActor();$zone=new \DateTimeZone('Europe/Berlin');$utc=new \DateTimeZone('UTC');
            $until=(new \DateTimeImmutable($to.' 00:00:00',$zone))->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');$now=max(gmdate('Y-m-d\TH:i:s\Z'),(new \DateTimeImmutable($from.' 00:00:00',$zone))->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'));
            $q=$this->db->prepare("SELECT e.id,e.title,e.starts_at,e.ends_at,e.location FROM appointments e WHERE e.club_id=? AND e.activity_state<>'cancelled' AND e.starts_at<? AND e.ends_at>=? AND (?='members' OR EXISTS(SELECT 1 FROM appointment_attendees t WHERE t.club_id=e.club_id AND t.appointment_id=e.id AND t.contact_id=?".$this->overviewLock().")) ORDER BY e.starts_at,e.id".$this->overviewLock());$q->execute([$a['club_id'],$until,$now,$a['role'],$a['contact_id']]);$rows=$q->fetchAll();
            foreach($this->websiteCalendar?->choiceIndex($a['club_id'],true,true)??[]as$row)if(!$row['cancelled']&&$row['starts_at']<$until&&$row['ends_at']>=$now)$rows[]=array_intersect_key($row,array_flip(['id','title','starts_at','ends_at','location']));
            usort($rows,static fn(array $left,array $right):int=>[$left['starts_at'],$left['id']]<=>[$right['starts_at'],$right['id']]);return ['rows'=>array_slice($rows,0,4),'total'=>count($rows),'from'=>$from,'to'=>$to];
        });
    }
    public function hasDashboardAppointment():bool
    {
        return$this->overviewRead(function():array{
            $a=$this->calendarActor();$q=$this->db->prepare("SELECT e.id FROM appointments e WHERE e.club_id=? AND e.activity_state<>'cancelled' AND (?='members' OR EXISTS(SELECT 1 FROM appointment_attendees t WHERE t.club_id=e.club_id AND t.appointment_id=e.id AND t.contact_id=?".$this->overviewLock().")) ORDER BY e.id LIMIT 1".$this->overviewLock());$q->execute([$a['club_id'],$a['role'],$a['contact_id']]);if($q->fetchColumn())return ['exists'=>true];
            foreach($this->websiteCalendar?->choiceIndex($a['club_id'],true)??[]as$row)if(!$row['cancelled'])return ['exists'=>true];return ['exists'=>false];
        })['exists'];
    }
    public function socialEventAppointments(array $query=[],int $pageSize=20): array
    {
        return \Kulturhus\Verein\Events\Calendar\AppointmentListing::forActor($this->db,$this->calendarActor(),$query,$pageSize);
    }
    /** Same private/public calendar references, with small choice DTOs and no event bodies. */
    public function appointmentChoicePage(array $input=[]):array
    {
        $actor=$this->calendarActor();$query=$input['query']??'';$number=$input['page']??1;$archive=$input['archive']??false;
        if(!is_string($query)||preg_match('//u',$query)!==1||mb_strlen($query,'UTF-8')>150||preg_match('/[\x00-\x1f\x7f]/',$query)||(!is_string($number)&&!is_int($number))||!preg_match('/\A[1-9][0-9]{0,8}\z/D',(string)$number)||!is_bool($archive))throw new DomainException('Veranstaltungssuche und Seite prüfen.');
        $rows=DocumentCatalog::query($this->db,"SELECT e.id,e.club_id,e.title,e.starts_at,e.ends_at FROM appointments e WHERE e.club_id=? AND (?='members' OR EXISTS(SELECT 1 FROM appointment_attendees t WHERE t.club_id=e.club_id AND t.appointment_id=e.id AND t.contact_id=?))",[$actor['club_id'],$actor['role'],$actor['contact_id']])->fetchAll();
        if($this->websiteCalendar!==null)$rows=array_merge($rows,$this->websiteCalendar->choiceIndex($actor['club_id']));
        $today=(new \DateTimeImmutable('today',new \DateTimeZone('Europe/Berlin')))->getTimestamp();$search=trim($query);$matches=[];
        foreach($rows as$row){$time=(new \DateTimeImmutable($row['starts_at'],new \DateTimeZone('UTC')))->getTimestamp();if(($time<$today)!==$archive||($search!==''&&mb_stripos($row['title'],$search,0,'UTF-8')===false))continue;$row['choice_time']=$time;$matches[]=$row;}
        usort($matches,static fn(array $left,array $right):int=>$archive?[$right['choice_time'],$right['id']]<=>[$left['choice_time'],$left['id']]:[$left['choice_time'],$left['id']]<=>[$right['choice_time'],$right['id']]);
        $page=Pagination::slice($matches,(int)$number,6);foreach($page['rows']as &$row)unset($row['choice_time']);unset($row);
        $current=$this->calendarActor();if($current['club_id']!==$actor['club_id']||$current['id']!==$actor['id']||$current['role']!==$actor['role']||($current['contact_id']??null)!==($actor['contact_id']??null))throw new DomainException('Veranstaltungsauswahl nach geänderten Zugangsrechten erneut öffnen.');return $page;
    }
    public function appointment(string $id): array
    {
        if (str_starts_with($id, 'website:')) {
            $a=$this->calendarActor();
            if (!$this->websiteCalendar) throw new DomainException('Veranstaltung nicht verfügbar.');
            return $this->websiteCalendar->appointment($a['club_id'],$id);
        }
        $a=$this->calendarActor();$q=$this->db->prepare("SELECT * FROM appointments e WHERE club_id=? AND id=? AND (?='members' OR EXISTS (SELECT 1 FROM appointment_attendees t WHERE t.club_id=e.club_id AND t.appointment_id=e.id AND t.contact_id=?))".($this->db->inTransaction()&&$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR SHARE':''));
        $q->execute([$a['club_id'],$id,$a['role'],$a['contact_id']]);$event=$q->fetch();
        if(!$event)throw new DomainException('Termin nicht verfügbar.');
        $q=$this->db->prepare("SELECT t.contact_id,t.response,c.name FROM appointment_attendees t JOIN contacts c ON c.club_id=t.club_id AND c.id=t.contact_id WHERE t.club_id=? AND t.appointment_id=? AND (?='members' OR t.contact_id=?) ORDER BY c.name");
        $q->execute([$a['club_id'],$id,$a['role'],$a['contact_id']]);$event['attendees']=$q->fetchAll();return $event;
    }
    public function saveAppointment(?string $id,int $version,array $data,array $attendees): string
    {
        if ($id !== null && str_starts_with($id, 'website:')) throw new DomainException('Datum und Uhrzeit dieser Veranstaltung bitte in Eventbrite ändern.');
        return $this->transaction(function()use($id,$version,$data,$attendees):string{
            $a=$this->calendarWriter();$title=trim((string)($data['title']??''));$location=trim((string)($data['location']??''));$description=trim((string)($data['description']??''));$category=(string)($data['category']??'verein');
            if($title===''||strlen($title)>200||strlen($location)>300||strlen($description)>10000||!in_array($category,['verein','vorstand','kultur'],true)||count($attendees)>10000)throw new DomainException('Bitte Titel, Kategorie und Termindaten prüfen.');
            $start=self::localTime((string)($data['start']??''));$end=self::localTime((string)($data['end']??''));
            if($end<=$start)throw new DomainException('Das Ende muss nach dem Beginn liegen.');
            foreach(array_unique($attendees) as $contact)if(!is_string($contact)||!DocumentCatalog::query($this->db,'SELECT 1 FROM contacts WHERE club_id=? AND id=? AND archived_at IS NULL',[$a['club_id'],$contact])->fetchColumn())throw new DomainException('Teilnehmer nicht verfügbar.');
            $old=$id!==null?$this->appointment($id):[];
            $state=(string)($data['activity_state']??$old['activity_state']??'planned');$kind=(string)($data['activity_kind']??$old['activity_kind']??'event');$summary=trim((string)($data['report_summary']??$old['report_summary']??''));$source=trim((string)($data['source_reference']??$old['source_reference']??''));
            if(!in_array($state,['planned','completed','cancelled'],true)||!in_array($kind,['event','external','assembly','board','other'],true)||strlen($summary)>5000||($source!==''&&!preg_match('~^https://www\.facebook\.com/events/[0-9]+/$~D',$source)))throw new DomainException('Veranstaltungsstatus, Berichtstext oder Facebook-Quelle prüfen.');
            if(in_array($old['activity_kind']??'',['assembly','board'],true))BoardAccess::require($this->db,$this->actorId);
            if(in_array($kind,['assembly','board'],true)){
                BoardAccess::require($this->db,$this->actorId);
                $eligible=array_column(CalendarInvitations::meetingEligibleContacts($this->db,$a['club_id'],$kind),'id');
                if(array_diff($attendees,$eligible))throw new DomainException($kind==='board'?'Bitte nur bestätigte aktuelle Vorstandsmitglieder auswählen.':'Bitte nur aktuelle Mitglieder auswählen. Ausgetretene Personen und Kontakte gehören nicht zur Mitgliederversammlung.');
            }
            if($state==='completed'&&$end>gmdate('Y-m-d\TH:i:s\Z'))throw new DomainException('Zukünftige Termine können noch nicht als durchgeführt bestätigt werden.');
            if($source!==''&&DocumentCatalog::query($this->db,'SELECT 1 FROM appointments WHERE club_id=? AND source_reference=? AND id!=?',[$a['club_id'],$source,$id??''])->fetchColumn())throw new DomainException('Diese Facebook-Veranstaltung ist bereits erfasst.');
            if($id===null){$id=bin2hex(random_bytes(16));$version=1;$this->db->prepare('INSERT INTO appointments (id,club_id,title,starts_at,ends_at,location,description,category) VALUES (?,?,?,?,?,?,?,?)')->execute([$id,$a['club_id'],$title,$start,$end,$location,$description,$category]);}
            else{$q=$this->db->prepare('UPDATE appointments SET title=?,starts_at=?,ends_at=?,location=?,description=?,category=?,version=version+1 WHERE club_id=? AND id=? AND version=?');$q->execute([$title,$start,$end,$location,$description,$category,$a['club_id'],$id,$version]);if($q->rowCount()!==1)throw new DomainException('Termin wurde inzwischen geändert oder ist nicht verfügbar.');++$version;}
            $q=$this->db->prepare('SELECT contact_id FROM appointment_attendees WHERE club_id=? AND appointment_id=?');$q->execute([$a['club_id'],$id]);
            foreach($q->fetchAll(PDO::FETCH_COLUMN) as $old)if(!in_array($old,$attendees,true))$this->db->prepare('DELETE FROM appointment_attendees WHERE club_id=? AND appointment_id=? AND contact_id=?')->execute([$a['club_id'],$id,$old]);
            foreach(array_unique($attendees) as $contact)$this->db->prepare('INSERT IGNORE INTO appointment_attendees (club_id,appointment_id,contact_id) VALUES (?,?,?)')->execute([$a['club_id'],$id,$contact]);
            $this->db->prepare('UPDATE event_gema SET event_title=?,event_date=? WHERE club_id=? AND event_id=?')->execute([$title,substr((string)$data['start'],0,10),$a['club_id'],$id]);
            $this->db->prepare('UPDATE appointments SET activity_state=?,activity_kind=?,report_summary=?,source_reference=? WHERE club_id=? AND id=?')->execute([$state,$kind,$summary,$source,$a['club_id'],$id]);
            $this->audit($a,'appointment.saved',$id,$version);return $id;
        });
    }
    public function respond(string $id,string $response,?int $expectedVersion=null): void
    {
        if (str_starts_with($id, 'website:')) throw new DomainException('Für diese Veranstaltung gilt die Anmeldung über die Website.');
        $this->transaction(function()use($id,$response,$expectedVersion):void{
            $a=$this->actor();$event=$this->appointment($id);
            if($expectedVersion!==null&&(int)$event['version']!==$expectedVersion)throw new DomainException('Der Termin wurde geändert. Die aktuellen Angaben vor der Rückmeldung prüfen.');
            if(!in_array($response,['ja','nein','vielleicht'],true)||!$a['contact_id'])throw new DomainException('Keine eigene Einladung verfügbar.');
            $q=$this->db->prepare('UPDATE appointment_attendees SET response=? WHERE club_id=? AND appointment_id=? AND contact_id=?');$q->execute([$response,$a['club_id'],$id,$a['contact_id']]);
            if($q->rowCount()!==1)throw new DomainException('Keine eigene Einladung verfügbar.');$this->audit($a,'appointment.response',$id,(int)$event['version']);
        });
    }
    public function deleteAppointment(string $id,int $version): void
    {
        if (str_starts_with($id, 'website:')) throw new DomainException('Diese Veranstaltung wird automatisch von der Website übernommen. Bitte dort oder in Eventbrite bearbeiten.');
        $this->transaction(function()use($id,$version):void{
            $a=$this->calendarWriter();$old=$this->appointment($id);if(in_array($old['activity_kind']??'',['assembly','board'],true))BoardAccess::require($this->db,$this->actorId);$q=$this->db->prepare('DELETE FROM appointments WHERE club_id=? AND id=? AND version=?');$q->execute([$a['club_id'],$id,$version]);
            if($q->rowCount()!==1)throw new DomainException('Termin wurde inzwischen geändert oder ist nicht verfügbar.');$this->audit($a,'appointment.deleted',$id,$version);
        });
    }
    private static function localTime(string $value): string
    {
        $tz=new \DateTimeZone('Europe/Berlin');$d=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,$tz);
        if(!$d||$d->format('Y-m-d\TH:i')!==$value)throw new DomainException('Ungültige lokale Uhrzeit (gegebenenfalls Zeitumstellung).');
        $transitions=$tz->getTransitions($d->getTimestamp()-86400,$d->getTimestamp()+86400);
        for($i=1;$i<count($transitions);++$i){$delta=$transitions[$i-1]['offset']-$transitions[$i]['offset'];$t=$transitions[$i]['ts'];if($delta>0&&$d->getTimestamp()>=$t-$delta&&$d->getTimestamp()<$t+$delta)throw new DomainException('Diese Uhrzeit ist bei der Zeitumstellung doppeldeutig. Bitte eine eindeutige Uhrzeit wählen.');}
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
    private function audit(array $actor, string $action, string $id, int $version): void
    {
        if($action==='contact.saved')DocumentCatalog::register($this->db,$actor['club_id'],'contact',$id);
        elseif(in_array($action,['appointment.saved','appointment.deleted'],true))DocumentCatalog::register($this->db,$actor['club_id'],'appointment',$id);
        $this->db->prepare('INSERT INTO audit_events (club_id,actor_id,action,object_id,object_version,happened_at) VALUES (?,?,?,?,?,?)')
            ->execute([$actor['club_id'],$actor['id'],$action,$id,$version,gmdate('Y-m-d\TH:i:s\Z')]);
    }
    private function transaction(callable $fn): mixed
    {
        if($this->insideTransaction)return $fn();
        return \Kulturhus\Verein\Infrastructure\Database\Transactions::run($this->db,function()use($fn):mixed{
            $this->insideTransaction=true;
            try{return $fn();}finally{$this->insideTransaction=false;}
        });
    }
    private static function date(string $value): void
    {
        if(!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D',$value))throw new DomainException('Bitte ein gültiges Kalenderdatum eingeben.');
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('Europe/Berlin'));
        if (!$d || $d->format('Y-m-d') !== $value) throw new DomainException('Bitte ein gültiges Kalenderdatum eingeben.');
    }
}
