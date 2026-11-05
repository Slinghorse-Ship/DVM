<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
require_once __DIR__.'/CatalogImportProcess.php';

/** Central maintenance only. No activation of catalogues, tax years or bookings. */
final class CatalogImports
{
    public const STATES=['unavailable'=>'Noch nicht veröffentlicht','downloaded'=>'PDF bereit','review_required'=>'Änderungen prüfen','reviewed_candidate'=>'Prüfpaket vollständig','incomplete_candidate'=>'Weitere Zuordnungen erforderlich'];
    public function __construct(private PDO $db,private string $actor,private ?\Closure $process=null){}
    private function access():array
    {
        $a=Permissions::actor($this->db,$this->actor);
        if(empty($a['is_superuser'])||$a['role']==='editor'||$a['finance_access']!=='write')throw new DomainException('Die Kontenplan-Aktualisierung ist der zentralen Administration vorbehalten.');
        return $a;
    }
    public function canManage():bool{try{$this->access();return true;}catch(DomainException){return false;}}
    private function query(string $sql,array $args=[]):\PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
    public function listing():array
    {
        $a=$this->access();return $this->query('SELECT id,from_year,target_year,version,state,updated_at FROM catalog_imports WHERE club_id=? ORDER BY updated_at DESC,id DESC LIMIT 30',[$a['club_id']])->fetchAll();
    }
    public function get(string $id):array
    {
        $a=$this->access();if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Kontenplan-Prüfung nicht verfügbar.');
        $row=$this->query('SELECT * FROM catalog_imports WHERE club_id=? AND id=?',[$a['club_id'],$id])->fetch();
        if(!$row||!isset(self::STATES[$row['state']]))throw new DomainException('Kontenplan-Prüfung nicht verfügbar.');
        $row['data']=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
        foreach(['version','from_year','target_year'] as $key)$row[$key]=(int)$row[$key];return $row;
    }
    private function locked(callable $fn):mixed
    {
        $a=$this->access();$lock='catalog-import-'.substr(hash('sha256',$a['club_id']),0,40);
        if((int)$this->query('SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1)throw new DomainException('Es läuft bereits eine Kontenplan-Prüfung. Bitte kurz warten.');
        try{return $fn($a);}finally{$this->query('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
    private function process(array $request,array $files):array
    {
        $out=$this->process?($this->process)($request,$files):CatalogImportProcess::run($request,$files);
        if(!isset(self::STATES[$out['result']['state']??''])||!is_array($out['files']??null)||!empty($out['result']['production_authorized']))throw new DomainException('Kontenplan-Prüfung lieferte kein gültiges Ergebnis.');
        $allowed=match($request['operation']){'fetch'=>['unavailable','downloaded'],'compare'=>['review_required'],'approve'=>['reviewed_candidate','incomplete_candidate'],default=>[]};
        if(!in_array($out['result']['state'],$allowed,true))throw new DomainException('Prüfschritt stimmt nicht mit dem Ergebnis überein.');
        $required=match($out['result']['state']){'downloaded'=>['original.pdf'],'review_required'=>['catalog.json','comparison.json','review.json','review.html'],'reviewed_candidate','incomplete_candidate'=>['package.zip','submitted-review.json'],default=>[]};
        if(array_diff($required,array_keys($out['files'])))throw new DomainException('Prüfpaket unvollständig.');
        if($out['result']['state']==='downloaded'){
            $pdf=$out['files']['original.pdf'];$source=$out['result']['source']??'';$url=is_string($source)?parse_url($source):false;
            if(!is_string($pdf)||!str_starts_with($pdf,'%PDF-')||!is_string($out['result']['pdf_sha256']??null)||!hash_equals(hash('sha256',$pdf),$out['result']['pdf_sha256'])
                ||!is_array($url)||($url['scheme']??'')!=='https'||!preg_match('/^(?:[a-z0-9-]+\.)*(?:datev\.de|datev-shop\.de)$/Di',$url['host']??'')
                ||isset($url['user'])||isset($url['pass'])||isset($url['port'])&&$url['port']!==443)throw new DomainException('Originaldatei und DATEV-Quelle stimmen nicht überein.');
        }
        return $out;
    }
    private function recheck(array $before):array
    {
        $after=$this->access();
        if($after['club_id']!==$before['club_id'])throw new DomainException('Die Vereinszuordnung wurde geändert. Bitte neu anmelden.');
        return $after;
    }
    private function store(array $actor,string $id,int $version,array $data,array $files,string $operation):void
    {
        foreach($files as $name=>$bytes){
            if(!in_array($name,CatalogImportProcess::FILES,true)||!is_string($bytes)||strlen($bytes)>8388608)throw new DomainException('Importdatei ungültig.');
            $this->query('INSERT INTO catalog_import_files VALUES(?,?,?,?,?,?)',[$actor['club_id'],$id,$version,$name,hash('sha256',$bytes),$bytes]);
        }
        $this->query('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$actor['club_id'],$actor['id'],'settings.catalog.'.$operation,$id,$version,gmdate('c')]);
    }
    public function start(string $id,int $from,int $year):string
    {
        return $this->locked(function($a)use($id,$from,$year):string{
            if(!preg_match('/^[a-f0-9]{32}$/D',$id)||!TaxCatalog::hasYear($from)||$year!==$from+1||$year>2100)throw new DomainException('Den unmittelbar folgenden Jahresstand auswählen.');
            $old=$this->query('SELECT from_year,target_year FROM catalog_imports WHERE club_id=? AND id=?',[$a['club_id'],$id])->fetch();
            if($old){if((int)$old['from_year']!==$from||(int)$old['target_year']!==$year)throw new DomainException('Diese Prüfung gehört zu einem anderen Jahr.');return $id;}
            $out=$this->process(['operation'=>'fetch','from_year'=>$from,'year'=>$year],[]);$a=$this->recheck($a);
            $this->db->beginTransaction();try{
                $this->query('INSERT INTO catalog_imports VALUES(?,?,?,?,?,?,?,?,?)',[$a['club_id'],$id,$from,$year,1,$out['result']['state'],json_encode($out['result'],JSON_THROW_ON_ERROR),$a['id'],gmdate('c')]);
                $this->store($a,$id,1,$out['result'],$out['files'],'fetch');$this->db->commit();
            }catch(\Throwable $e){$this->db->rollBack();throw $e;}return $id;
        });
    }
    public function advance(string $id,int $version,string $operation,?int $appendix=null,?string $review=null):void
    {
        $this->locked(function($a)use($id,$version,$operation,$appendix,$review):void{
            $row=$this->get($id);if($row['version']!==$version)throw new DomainException('Die Prüfung wurde inzwischen geändert. Bitte neu laden.');
            $states=match($operation){'fetch'=>['unavailable'],'compare'=>['downloaded','review_required','reviewed_candidate','incomplete_candidate'],'approve'=>['review_required'],default=>[]};
            if(!in_array($row['state'],$states,true))throw new DomainException('Dieser Prüfschritt ist nicht verfügbar.');
            $request=['operation'=>$operation,'from_year'=>$row['from_year'],'year'=>$row['target_year']];$files=[];
            if($operation==='compare'){
                if($appendix===null||$appendix<0||$appendix>20)throw new DomainException('Anzahl der Erläuterungsseiten am Ende des Originals angeben.');
                $request+=['appendix_pages'=>$appendix,'source'=>$row['data']['source'],'pdf_sha256'=>$row['data']['pdf_sha256']];$files['original.pdf']=$this->file($id,'original.pdf')['bytes'];
            }
            if($operation==='approve'){
                if(empty($row['data']['taxonomy_ready']))throw new DomainException('Die Jahresprüfung des Kontenvergleichs fehlt noch. Der Kontenplan bleibt unverändert.');
                if($review===null||strlen($review)>1048576)throw new DomainException('Die ausgefüllte Prüfdatei auswählen.');
                // Preserve empty JSON objects (decisions, gaps, successors). Casting
                // through PHP arrays would turn {} into [] and break signed reviews.
                try{$data=json_decode($review,false,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new DomainException('Prüfdatei ist nicht lesbar.');}
                if(!$data instanceof \stdClass)throw new DomainException('Prüfdatei ist nicht gültig.');
                $data->reviewer=$a['id'];$files['submitted-review.json']=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
                foreach(['catalog.json','comparison.json'] as $name)$files[$name]=$this->file($id,$name)['bytes'];
            }
            $out=$this->process($request,$files);$a=$this->recheck($a);$data=array_replace($row['data'],$out['result']);
            // A fresh comparison retires the current candidate, while all old
            // file revisions and audit events remain unchanged in history.
            if($operation==='compare')unset($data['coverage']);
            if($operation==='compare')$data['appendix_pages']=$appendix;
            $this->db->beginTransaction();try{
                $q=$this->query('UPDATE catalog_imports SET version=version+1,state=?,payload=?,actor_id=?,updated_at=? WHERE club_id=? AND id=? AND version=?',[$data['state'],json_encode($data,JSON_THROW_ON_ERROR),$a['id'],gmdate('c'),$a['club_id'],$id,$version]);
                if($q->rowCount()!==1)throw new DomainException('Die Prüfung wurde inzwischen geändert. Bitte neu laden.');
                $this->store($a,$id,$version+1,$data,$out['files'],$operation);$this->db->commit();
            }catch(\Throwable $e){$this->db->rollBack();throw $e;}
        });
    }
    public function file(string $id,string $name):array
    {
        $row=$this->get($id);if(!in_array($name,CatalogImportProcess::FILES,true))throw new DomainException('Prüfdatei nicht verfügbar.');
        if($name==='package.zip'&&!in_array($row['state'],['reviewed_candidate','incomplete_candidate'],true))throw new DomainException('Vergleich und Prüfdatei zuerst erneut bestätigen. Das frühere Paket ist nur noch im Prüfverlauf gespeichert.');
        $file=$this->query('SELECT bytes,sha256 FROM catalog_import_files WHERE club_id=? AND import_id=? AND revision<=? AND name=? ORDER BY revision DESC LIMIT 1',[$row['club_id'],$id,$row['version'],$name])->fetch();
        if(!$file||!hash_equals($file['sha256'],hash('sha256',$file['bytes'])))throw new DomainException('Prüfdatei nicht verfügbar oder verändert.');
        return ['name'=>$name,'bytes'=>$file['bytes']];
    }
}
