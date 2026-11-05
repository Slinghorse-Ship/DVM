<?php declare(strict_types=1);
namespace Kulturhus\Verein\DocumentWorkflow;

use PDO;
use DomainException;

/** Operational instructions and their evidence, independent of assessment revisions. */
final class InspectionStore
{
    public const STATUSES=['open'=>'Offen','in_progress'=>'In Umsetzung','submitted'=>'Zur Abnahme','returned'=>'Nacharbeit','verified'=>'Abgenommen','cancelled'=>'Abgebrochen'];
    public const PAGE_SIZE=6;
    public function __construct(private PDO $db,private WorkflowSignatureAccess $access) {}
    public static function installed(PDO $db): bool
    {
        return (int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('dwf_inspections','dwf_inspection_versions')")->fetchColumn()===2;
    }
    public function actor(): array
    {
        $actor=$this->access->actor();
        if (!in_array($actor['access'],['read','write'],true) && !in_array($actor['id'],array_column($this->access->signatureMembers(),'id'),true)) throw new DomainException('Gefährdungsprüfung nicht verfügbar.');
        return $actor;
    }
    public function allows(): bool
    {
        try {$a=$this->actor();return $a['access']!=='none'||(bool)$this->query('SELECT 1 FROM dwf_inspections WHERE club_id=? AND assignee_id=? LIMIT 1',[$a['club_id'],$a['id']])->fetchColumn();} catch (DomainException) {return false;}
    }
    public function participants(): array { $a=$this->actor();$this->writer($a);return $this->access->signatureMembers(); }
    public static function state(array $input): array
    {
        $q=self::text($input['query']??'',240,false);$status=$input['status']??'active';$scope=$input['scope']??'all';$page=$input['page']??'1';
        if(!is_string($page)&&!is_int($page))throw new DomainException('Suchseite prüfen.');$page=(string)$page;
        if(!is_string($status)||(!isset(self::STATUSES[$status])&&!in_array($status,['active','overdue','all'],true))||!in_array($scope,['all','mine'],true)||!preg_match('/\A[1-9][0-9]{0,4}\z/D',$page)) throw new DomainException('Suchauswahl prüfen.');
        return ['query'=>$q,'status'=>$status,'scope'=>$scope,'page'=>(int)$page];
    }
    public function listing(array $input=[]): array
    {
        $a=$this->actor();$state=self::state($input);$where='club_id=?';$args=[$a['club_id']];
        if($a['access']==='none'||$state['scope']==='mine'){$where.=' AND assignee_id=?';$args[]=$a['id'];}
        if($state['status']==='active')$where.=" AND status NOT IN ('verified','cancelled')";
        elseif($state['status']==='overdue'){$where.=" AND status NOT IN ('verified','cancelled') AND deadline<>'' AND deadline<?";$args[]=self::today();}
        elseif($state['status']!=='all'){$where.=' AND status=?';$args[]=$state['status'];}
        if($state['query']!==''){$like='%'.str_replace(['=','%','_'],['==','=%','=_'],$state['query']).'%';$where.=" AND (title COLLATE utf8mb4_unicode_ci LIKE ? ESCAPE '=' OR instruction COLLATE utf8mb4_unicode_ci LIKE ? ESCAPE '=')";array_push($args,$like,$like);}
        $total=(int)$this->query('SELECT COUNT(*) FROM dwf_inspections WHERE '.$where,$args)->fetchColumn();$pages=max(1,(int)ceil($total/self::PAGE_SIZE));$state['page']=min($state['page'],$pages);$offset=($state['page']-1)*self::PAGE_SIZE;
        $rows=$this->query('SELECT id,version,title,LEFT(instruction,180) instruction,assignee_id,deadline,status,updated_at FROM dwf_inspections WHERE '.$where." ORDER BY deadline='',deadline,updated_at DESC,id LIMIT 6 OFFSET ".$offset,$args)->fetchAll(PDO::FETCH_ASSOC);
        return ['rows'=>$rows,'total'=>$total,'pages'=>$pages,'state'=>$state];
    }
    public function get(string $id): array
    {
        $a=$this->actor();$r=$this->row($id,$a);
        $r['rework']=$r['status']==='returned'?(string)$this->query("SELECT comment FROM dwf_inspection_versions WHERE club_id=? AND inspection_id=? AND action IN ('return','reopen') ORDER BY revision DESC LIMIT 1",[$a['club_id'],$id])->fetchColumn():'';
        return $r;
    }
    /** Names only for the bounded visible task selection; no roster disclosure to assignees. */
    public function names(array $taskIds,bool $withAvailability=false): array
    {
        if(count($taskIds)>7)throw new DomainException('Auftragsauswahl prüfen.');$a=$this->actor();$names=[];
        foreach(array_unique($taskIds)as$id){$r=$this->row($id,$a);$name=$this->query("SELECT COALESCE(NULLIF(c.name,''),a.id) FROM actors a LEFT JOIN contacts c ON c.club_id=a.club_id AND c.id=a.contact_id WHERE a.club_id=? AND a.id=?",[$a['club_id'],$r['assignee_id']])->fetchColumn();$names[$r['assignee_id']]=$withAvailability?['available'=>is_string($name),'label'=>is_string($name)?$name:'']:(is_string($name)?$name:'Person nicht mehr verfügbar');}
        return $names;
    }
    public function history(string $id,int $page=1): array
    {
        if($page<1||$page>99999)throw new DomainException('Verlaufsseite prüfen.');$row=$this->get($id);$a=$this->actor();
        $total=(int)$this->query('SELECT COUNT(*) FROM dwf_inspection_versions WHERE club_id=? AND inspection_id=?',[$a['club_id'],$id])->fetchColumn();$pages=max(1,(int)ceil($total/6));$page=min($page,$pages);
        $rows=$this->query('SELECT revision,action,actor_id,comment,snapshot_json,created_at FROM dwf_inspection_versions WHERE club_id=? AND inspection_id=? ORDER BY revision DESC LIMIT 6 OFFSET '.(($page-1)*6),[$a['club_id'],$row['id']])->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$r){$r['snapshot']=json_decode($r['snapshot_json'],true,64,JSON_THROW_ON_ERROR);unset($r['snapshot_json']);$name=$this->query("SELECT COALESCE(NULLIF(c.name,''),a.id) FROM actors a LEFT JOIN contacts c ON c.club_id=a.club_id AND c.id=a.contact_id WHERE a.club_id=? AND a.id=?",[$a['club_id'],$r['actor_id']])->fetchColumn();$r['actor_available']=is_string($name);$r['actor_name']=is_string($name)?$name:'Person nicht mehr verfügbar';}unset($r);
        return ['rows'=>$rows,'total'=>$total,'pages'=>$pages,'page'=>$page];
    }
    /** Optional source binds an exact assessment revision; creating a task never edits that assessment. */
    public function source(string $documentId,int $revision): array
    {
        $a=$this->actor();$this->writer($a);$document=(new WorkflowStore($this->db,$this->access))->document($documentId,$revision);
        $json=$this->query('SELECT snapshot_json FROM dwf_document_versions WHERE club_id=? AND document_id=? AND revision=?',[$a['club_id'],$documentId,$revision])->fetchColumn();
        if(!is_string($json))throw new DomainException('Beurteilungsfassung nicht verfügbar.');
        return ['id'=>$documentId,'revision'=>$revision,'title'=>$document['title'],'sha256'=>hash('sha256',$json)];
    }
    public function create(array $data,string $key,string $documentId='',int $revision=0): string
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Formular erneut öffnen.');
        return $this->mutate(function(array $a)use($data,$key,$documentId,$revision):string{
            $this->writer($a);$fields=$this->fields($data);$this->assignee($fields['assignee_id']);
            if(($documentId==='')!==($revision===0))throw new DomainException('Genaue Beurteilungsfassung auswählen.');
            $source=$documentId!==''?$this->source($documentId,$revision):[];
            $hash=hash('sha256',json_encode([$fields,$source],JSON_THROW_ON_ERROR));
            $old=$this->query('SELECT id,request_hash FROM dwf_inspections WHERE club_id=? AND created_by=? AND request_key=? FOR UPDATE',[$a['club_id'],$a['id'],$key])->fetch(PDO::FETCH_ASSOC);
            if($old){if(!hash_equals($old['request_hash'],$hash))throw new InspectionConflict('Dieses Formular wurde bereits mit anderen Angaben gespeichert.');return $old['id'];}
            $id=bin2hex(random_bytes(16));$now=gmdate('Y-m-d H:i:s.u');
            $r=['club_id'=>$a['club_id'],'id'=>$id,'version'=>1,...$fields,'status'=>'open','implementation'=>'','completed_on'=>'','source_json'=>json_encode($source,JSON_THROW_ON_ERROR),'created_by'=>$a['id'],'request_key'=>$key,'request_hash'=>$hash,'created_at'=>$now,'updated_at'=>$now];
            $this->query('INSERT INTO dwf_inspections ('.implode(',',array_keys($r)).') VALUES ('.implode(',',array_fill(0,count($r),'?')).')',array_values($r));
            $this->record($r,$a,'create','');return $id;
        });
    }
    public function update(string $id,int $version,array $data): void
    {
        $this->change($id,$version,'update','',function(array &$r,array $a)use($data):void{
            $this->writer($a);if(!in_array($r['status'],['open','in_progress','returned'],true))throw new DomainException('Vor einer Änderung zur Nacharbeit zurückgeben.');
            $fields=$this->fields($data);$this->assignee($fields['assignee_id']);
            if($r['assignee_id']!==$fields['assignee_id']){$r['status']='open';$r['implementation']='';$r['completed_on']='';}
            $r=array_replace($r,$fields);
        });
    }
    public function transition(string $id,int $version,string $action,array $data=[]): void
    {
        $comment=self::text($data['comment']??'',8000,false);
        $this->change($id,$version,$action,$comment,function(array &$r,array $a)use($action,$data,$comment):void{
            if(in_array($action,['start','submit'],true)){
                if($r['assignee_id']!==$a['id']||!in_array($r['status'],['open','in_progress','returned'],true))throw new DomainException('Nur die aktuell zuständige Person kann diesen Auftrag umsetzen.');
                if($action==='submit'){$r['implementation']=self::text($data['implementation']??null,8000,true);$r['completed_on']=self::date($data['completed_on']??null,true);if($r['completed_on']>self::today())throw new DomainException('Umsetzungstag darf nicht in der Zukunft liegen.');$r['status']='submitted';}
                else $r['status']='in_progress';
            }else{
                $this->writer($a);
                if(in_array($action,['verify','return'],true)&&$r['status']==='submitted'){
                    if($action==='return'&&$comment==='')throw new DomainException('Vorgabe für die Nacharbeit eintragen.');
                    $r['status']=$action==='verify'?'verified':'returned';
                }elseif($action==='reopen'&&$r['status']==='verified'&&$comment!=='')$r['status']='returned';
                elseif($action==='cancel'&&!in_array($r['status'],['verified','cancelled'],true)&&$comment!=='')$r['status']='cancelled';
                else throw new DomainException('Statuswechsel und Rückmeldung prüfen.');
            }
        });
    }
    public function deadlines(string $from,string $to): array
    {
        self::date($from,true);self::date($to,true);if($from>=$to||(new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days>366)throw new DomainException('Kalenderzeitraum prüfen.');
        $a=$this->actor();$where='club_id=? AND deadline>=? AND deadline<? AND status NOT IN (\'verified\',\'cancelled\')';$args=[$a['club_id'],$from,$to];
        if($a['access']==='none'){$where.=' AND assignee_id=?';$args[]=$a['id'];}
        return $this->query('SELECT id,title,deadline,status FROM dwf_inspections WHERE '.$where.' ORDER BY deadline,id',$args)->fetchAll(PDO::FETCH_ASSOC);
    }
    private function change(string $id,int $version,string $action,string $comment,callable $f): void
    {
        $this->mutate(function(array $a)use($id,$version,$action,$comment,$f):void{
            $r=$this->row($id,$a,true);if($version<1||(int)$r['version']!==$version)throw new InspectionConflict('Auftrag inzwischen geändert. Aktuellen Stand öffnen; deine Eingaben bleiben erhalten.');
            $f($r,$a);$r['version']=$version+1;$r['updated_at']=gmdate('Y-m-d H:i:s.u');unset($r['source']);
            $this->query('UPDATE dwf_inspections SET version=?,title=?,instruction=?,assignee_id=?,deadline=?,status=?,implementation=?,completed_on=?,updated_at=? WHERE club_id=? AND id=?',[$r['version'],$r['title'],$r['instruction'],$r['assignee_id'],$r['deadline'],$r['status'],$r['implementation'],$r['completed_on'],$r['updated_at'],$a['club_id'],$id]);
            $this->record($r,$a,$action,$comment);
        });
    }
    private function row(string $id,array $a,bool $lock=false): array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new DomainException('Prüfauftrag nicht verfügbar.');
        $r=$this->query('SELECT * FROM dwf_inspections WHERE club_id=? AND id=?'.($lock?' FOR UPDATE':''),[$a['club_id'],$id])->fetch(PDO::FETCH_ASSOC);
        if(!$r||($a['access']==='none'&&$r['assignee_id']!==$a['id']))throw new DomainException('Prüfauftrag nicht verfügbar.');
        $r['source']=json_decode($r['source_json'],true,64,JSON_THROW_ON_ERROR);return $r;
    }
    private function record(array $r,array $a,string $action,string $comment): void
    {
        unset($r['request_key'],$r['request_hash'],$r['source']);
        $this->query('INSERT INTO dwf_inspection_versions VALUES (?,?,?,?,?,?,?,?)',[$a['club_id'],$r['id'],$r['version'],$action,$a['id'],$comment,json_encode($r,JSON_THROW_ON_ERROR),$r['updated_at']]);
    }
    private function mutate(callable $f): mixed
    {
        if($this->db->inTransaction())throw new DomainException('Prüfauftrag benötigt einen eigenen Speichervorgang.');
        $this->db->beginTransaction();try{$a=$this->actor();$result=$f($a);$this->actor();$this->db->commit();return $result;}catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private function query(string $sql,array $args=[]): \PDOStatement {$q=$this->db->prepare($sql);$q->execute($args);return $q;}
    private function writer(array $a): void {if($a['access']!=='write')throw new DomainException('Schreibrecht für Gefährdungsprüfungen erforderlich.');}
    private function assignee(string $id): void {if(!in_array($id,array_column($this->access->signatureMembers(),'id'),true))throw new DomainException('Aktuelles Vereinsmitglied als zuständige Person auswählen.');}
    private function fields(array $data): array {return ['title'=>self::text($data['title']??null,240,true),'instruction'=>self::text($data['instruction']??null,8000,true),'assignee_id'=>self::text($data['assignee_id']??null,64,true),'deadline'=>self::date($data['deadline']??null,false)];}
    private static function text(mixed $v,int $limit,bool $required): string
    {
        if(!is_string($v)||!mb_check_encoding($v,'UTF-8')||mb_strlen($v)>$limit||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$v))throw new DomainException('Auftragsangaben prüfen.');
        $v=trim($v);if($required&&$v==='')throw new DomainException('Vorgabe, Titel und zuständige Person vollständig angeben.');return $v;
    }
    private static function date(mixed $v,bool $required): string
    {
        $v=self::text($v,10,$required);if($v==='')return '';$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$d||$d->format('Y-m-d')!==$v)throw new DomainException('Gültiges Kalenderdatum eintragen.');return $v;
    }
    private static function today(): string {return (new \DateTimeImmutable('now',new \DateTimeZone('Europe/Berlin')))->format('Y-m-d');}
}
