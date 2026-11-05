<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;

final class AdmissionVotes
{
    public const OFFICES=['1. Vorstand','2. Vorstand','Schriftwart','Kassenwart'];
    public static function officeName(string $office):string{return $office==='Schriftführer'?'Schriftwart':$office;}
    public function __construct(private PDO $db,private string $actorId){}
    private function actor():array{return Permissions::actor($this->db,$this->actorId);}
    private function transaction(callable $fn):mixed{$this->db->beginTransaction();try{$v=$fn();$this->db->commit();return $v;}catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}}
    private function q(string $sql,array $args=[]):\PDOStatement{return DocumentCatalog::query($this->db,$sql,$args);}
    private function audit(array $a,string $action,string $id,int $version=1):void{$this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],$action,$id,$version,gmdate('c')]);}
    public function canVote():bool{$a=$this->actor();return $a['role']!=='editor'&&(bool)$this->q('SELECT 1 FROM admission_board WHERE club_id=? AND actor_id=? AND enabled=1',[$a['club_id'],$a['id']])->fetchColumn();}
    public function canAccess():bool{return BoardAccess::allowed($this->db,$this->actorId);}
    public function isSuperuser():bool{return BoardAccess::superuser($this->db,$this->actorId);}
    public function canManage():bool{return $this->canAccess();}
    private function manage():array{if(!$this->canManage())throw new DomainException('Vorstandsrechte dürfen nur von der Vereinsverwaltung eingerichtet werden.');return $this->actor();}
    public function board():array
    {
        $a=$this->actor();if(!$this->canManage()&&!$this->canVote())throw new DomainException('Kein Zugriff auf Aufnahmeabstimmungen.');
        // Keep review metadata outside the voting roster hash; confirming an unchanged
        // office must not invalidate a running admission vote.
        $checks=$this->q('SELECT * FROM admission_board_checks WHERE club_id=?',[$a['club_id']])->fetchAll();$byVersion=[];foreach($checks as $check)$byVersion[$check['actor_id']][(int)$check['board_version']]=$check;
        return array_map(static function(array $row)use($byVersion):array{$check=$byVersion[$row['actor_id']][(int)$row['version']]??null;if($check&&$check['office']!==self::officeName($row['office']))$check=null;return $row+['office_checked'=>$check!==null,'office_checked_by'=>$check['checked_by']??null,'office_checked_at'=>$check['checked_at']??null];},$this->roster($a['club_id'],false));
    }
    private function roster(string $club,bool $active=true):array
    {
        return $this->q("SELECT b.actor_id,b.office,b.enabled,b.version,a.contact_id,COALESCE(c.name,a.id) name FROM admission_board b JOIN actors a ON a.id=b.actor_id AND a.club_id=b.club_id LEFT JOIN contacts c ON c.club_id=a.club_id AND c.id=a.contact_id WHERE b.club_id=? AND a.role!='editor'".($active?' AND b.enabled=1 AND a.active=1':'').' ORDER BY b.actor_id',[$club])->fetchAll();
    }
    public function grant(string $user,int $version,string $office,bool $enabled,bool $confirmed):void
    {
        if(!$this->isSuperuser())throw new DomainException('Vorstandsrechte dürfen nur Superuser vergeben.');$club=$this->actor()['club_id'];
        \Kulturhus\Verein\Infrastructure\Database\Transactions::run($this->db,function()use($user,$version,$office,$enabled,$confirmed,$club){RoleAssignments::lockClub($this->db,$club);if(!$this->isSuperuser())throw new DomainException('Vorstandsrechte dürfen nur Superuser vergeben.');$a=$this->manage();if($a['club_id']!==$club)throw new DomainException('Vereinszuordnung inzwischen geändert.');$target=$this->q('SELECT id,club_id,role,active,contact_id FROM actors WHERE id=? AND club_id=? FOR UPDATE',[$user,$club])->fetch();if(!$target)throw new DomainException('Eigenes persönliches Konto auswählen.');$office=self::officeName(trim($office));
            $old=$this->q('SELECT * FROM admission_board WHERE club_id=? AND actor_id=? FOR UPDATE',[$a['club_id'],$user])->fetch();if((int)($old['version']??0)!==$version)throw new DomainException('Berechtigung inzwischen geändert. Bitte neu laden und das aktuelle Amt prüfen.');
            $officeChanged=$office!==self::officeName($old['office']??'');
            if(!$target['active']&&($enabled||($officeChanged&&$office!=='')))throw new DomainException('Bei gesperrtem Konto nur bestehendes Stimmrecht entziehen oder Vorstandsamt entfernen.');
            if($target['club_id']!==$a['club_id']||$target['role']==='editor'||($office!==''&&!in_array($office,self::OFFICES,true))||($enabled&&(!$confirmed||$office===''))||($officeChanged&&$office!==''&&!$confirmed)||($confirmed&&$office===''))throw new DomainException('Aktuelles Vorstandsamt bestätigen. Bei einem geänderten Amt bitte erneut ausdrücklich prüfen.');
            if($enabled&&$this->q('SELECT 1 FROM admission_board WHERE club_id=? AND enabled=1 AND office IN (?,?) AND actor_id!=? FOR SHARE',[$a['club_id'],$office,$office==='Schriftwart'?'Schriftführer':$office,$user])->fetchColumn())throw new DomainException('Dieses Vorstandsamt ist bereits einem stimmberechtigten Zugang zugeordnet.');
            if($enabled&&$target['contact_id']&&$this->q('SELECT 1 FROM admission_board b JOIN actors x ON x.id=b.actor_id WHERE b.club_id=? AND b.enabled=1 AND x.contact_id=? AND x.id!=? FOR SHARE',[$a['club_id'],$target['contact_id'],$user])->fetchColumn())throw new DomainException('Für diese Person besteht bereits ein stimmberechtigter Zugang.');
            $check=$old?$this->q('SELECT * FROM admission_board_checks WHERE club_id=? AND actor_id=? AND board_version=? AND office=? FOR SHARE',[$a['club_id'],$user,$version,$office])->fetch():false;
            $same=$old&&!$officeChanged&&(bool)$old['enabled']===$enabled;
            if($same&&$confirmed&&$check)return; // Preserve the original checker and time.
            if($same&&!$confirmed&&!$check)return;
            if(!$old&&$office===''&&!$enabled)return;
            // First explicit confirmation of a legacy unchanged permission adds evidence
            // without changing its version or the membership of a running vote.
            if(!$same||!$confirmed){++$version;$this->q('INSERT INTO admission_board VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE office=VALUES(office),enabled=VALUES(enabled),version=VALUES(version)',[$a['club_id'],$user,$office,(int)$enabled,$version]);$this->audit($a,'admission.board.permission',$user,$version);}
            if($confirmed){$this->q('INSERT INTO admission_board_checks(club_id,actor_id,board_version,office,checked_by,checked_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$user,$version,$office,$a['id'],gmdate('c')]);$this->audit($a,'admission.board.office-confirmed',$user,$version);}
        });
    }
    public function open(string $application,bool $complete):void
    {
        $this->transaction(function()use($application,$complete){$a=$this->manage();$r=$this->q("SELECT * FROM membership_applications WHERE club_id=? AND id=? AND status='pending'",[$a['club_id'],$application])->fetch();$roster=$this->roster($a['club_id']);
            if(!$r||!$complete||!$roster)throw new DomainException('Offenen Antrag und vollständige Liste des aktuellen Vorstands bestätigen.');
            if($this->q('SELECT 1 FROM admission_rounds WHERE club_id=? AND application_id=?',[$a['club_id'],$application])->fetchColumn())return;
            $json=json_encode($roster,JSON_THROW_ON_ERROR);$this->q('INSERT INTO admission_rounds VALUES(?,?,?,?,?,?,?)',[$a['club_id'],bin2hex(random_bytes(16)),$application,$json,hash('sha256',$json),gmdate('c'),$a['id']]);$this->audit($a,'admission.vote.opened',$application);ApplicationNotifications::enqueue($this->db,$a['club_id'],$application);
        });
    }
    public function applications():array
    {
        $a=$this->actor();if(!$this->canVote()&&!$this->canManage())throw new DomainException('Keine Berechtigung für Aufnahmeabstimmungen.');
        $rows=$this->q("SELECT a.id,a.answers,a.status,r.roster FROM membership_applications a LEFT JOIN admission_rounds r ON r.club_id=a.club_id AND r.application_id=a.id WHERE a.club_id=? ORDER BY a.submitted_at DESC LIMIT 200",[$a['club_id']])->fetchAll();return $this->canManage()?$rows:array_values(array_filter($rows,fn($r)=>$r['roster']===null||in_array($a['id'],array_column(json_decode($r['roster'],true),'actor_id'),true)));
    }
    public static function result(PDO $db,string $club,string $application):?array
    {
        $r=DocumentCatalog::query($db,'SELECT * FROM admission_rounds WHERE club_id=? AND application_id=?',[$club,$application])->fetch();if(!$r)return null;
        $roster=json_decode($r['roster'],true,512,JSON_THROW_ON_ERROR);$votes=DocumentCatalog::query($db,'SELECT * FROM admission_votes WHERE club_id=? AND round_id=? ORDER BY actor_id',[$club,$r['id']])->fetchAll();
        $state='pending';if(count($votes)===count($roster)&&count($roster)>0){$choices=array_values(array_unique(array_column($votes,'choice')));$state=count($choices)===1?($choices[0]==='yes'?'accepted':'rejected'):'discussion';}elseif(count(array_unique(array_column($votes,'choice')))>1)$state='discussion';
        $current=DocumentCatalog::query($db,"SELECT b.actor_id,b.office,b.enabled,b.version,a.contact_id,COALESCE(c.name,a.id) name FROM admission_board b JOIN actors a ON a.id=b.actor_id AND a.club_id=b.club_id LEFT JOIN contacts c ON c.club_id=a.club_id AND c.id=a.contact_id WHERE b.club_id=? AND a.role!='editor' AND b.enabled=1 AND a.active=1 ORDER BY b.actor_id",[$club])->fetchAll();
        if(!hash_equals($r['roster_hash'],hash('sha256',json_encode($current,JSON_THROW_ON_ERROR))))$state='discussion';
        return $r+['people'=>$roster,'votes'=>$votes,'result'=>$state];
    }
    public function detail(string $application):array
    {
        $a=$this->actor();if(!$this->canVote()&&!$this->canManage())throw new DomainException('Keine Berechtigung für diesen Antrag.');$r=self::result($this->db,$a['club_id'],$application);if(!$r){$app=$this->q("SELECT * FROM membership_applications WHERE club_id=? AND id=?",[$a['club_id'],$application])->fetch();if(!$app)throw new DomainException('Antrag nicht verfügbar.');return ['id'=>null,'people'=>$this->roster($a['club_id']),'votes'=>[],'result'=>'pending','application'=>$app];}
        if(!$this->canManage()&&!in_array($a['id'],array_column($r['people'],'actor_id'),true))throw new DomainException('Nicht an dieser Abstimmung beteiligt.');
        $app=$this->q('SELECT * FROM membership_applications WHERE club_id=? AND id=?',[$a['club_id'],$application])->fetch();return $r+['application'=>$app];
    }
    public function vote(string $application,string $choice,string $reason,bool $confirmed):void
    {
        $this->transaction(function()use($application,$choice,$reason,$confirmed){$a=$this->actor();if(!$this->canVote())throw new DomainException('Kein aktuelles Vorstandsstimmrecht.');$r=$this->detail($application);if(!$r['id'])throw new DomainException('Abstimmung noch nicht eröffnet.');$reason=trim($reason);
            if(!in_array($a['id'],array_column($r['people'],'actor_id'),true)||$r['application']['status']!=='pending')throw new DomainException('Keine offene Stimmabgabe für diesen Zugang.');
            if(!hash_equals($r['roster_hash'],hash('sha256',json_encode($this->roster($a['club_id']),JSON_THROW_ON_ERROR))))throw new DomainException('Die Vorstandsbesetzung wurde geändert. Diese Abstimmung muss in einer Vorstandsberatung geklärt werden.');
            if(!$confirmed||!in_array($choice,['yes','no'],true)||strlen($reason)>2000||($choice==='no'&&$reason===''))throw new DomainException('Stimme bestätigen; bei Ablehnung eine Begründung angeben.');
            if($a['contact_id']&&$r['application']['contact_id']===$a['contact_id'])throw new DomainException('Über den eigenen Antrag darf nicht abgestimmt werden.');
            $declaration=$choice==='yes'?'Ich stimme dem Beschluss zu, diesen Antrag aufzunehmen.':'Ich stimme dem Beschluss zu, diesen Antrag abzulehnen.';
            $existing=$this->q('SELECT choice,reason FROM admission_votes WHERE club_id=? AND round_id=? AND actor_id=?',[$a['club_id'],$r['id'],$a['id']])->fetch();if($existing){if($existing['choice']===$choice&&$existing['reason']===$reason)return;throw new DomainException('Stimme bereits verbindlich abgegeben.');}
            $this->q('INSERT INTO admission_votes VALUES(?,?,?,?,?,?,?)',[$a['club_id'],$r['id'],$a['id'],$choice,$reason,$declaration,gmdate('c')]);$this->audit($a,'admission.vote.'.$choice,$application);
        });
    }
    public function documents(string $application):array
    {
        $this->detail($application);$a=$this->actor();
        $rows=$this->q("SELECT d.id,d.title,d.scopes FROM document_links l JOIN documents d ON d.club_id=l.club_id AND d.id=l.document_id WHERE l.club_id=? AND l.source_type='application' AND l.source_id=? AND d.state='active' ORDER BY d.created_at",[$a['club_id'],$application])->fetchAll();
        return array_values(array_filter($rows,static fn($r)=>json_decode($r['scopes'],true)===['members']));
    }
    public function documentFile(string $application,string $document):array
    {
        if(!in_array($document,array_column($this->documents($application),'id'),true))throw new DomainException('Datei für diese Abstimmung nicht verfügbar.');$a=$this->actor();
        return $this->q('SELECT name,mime,bytes FROM document_files WHERE club_id=? AND document_id=? ORDER BY revision DESC LIMIT 1',[$a['club_id'],$document])->fetch()?:throw new DomainException('Datei fehlt.');
    }
}
