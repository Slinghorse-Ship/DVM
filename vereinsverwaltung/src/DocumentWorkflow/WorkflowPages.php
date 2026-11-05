<?php declare(strict_types=1);
namespace Kulturhus\Verein\DocumentWorkflow;

/** Bounded original metadata for the manager; existing complete-original APIs remain available. */
trait WorkflowPages
{
    public const PAGE_SIZE=25;

    public function documentPage(array $filters=[],mixed $page=1):array
    {
        $actor=$this->context();
        $this->keys($filters,['folder_id','status','assignee_id','template_id','search'],'Filter');
        [$where,$parameters,$accepted]=$this->documentCriteria($filters,$actor);
        $columns='id,club_id,folder_id,template_id,template_version,template_title,title,version,status,assignee_id,reviewer_id,created_by,created_at,updated_at';
        $result=$this->metadataPage('SELECT '.$columns.' FROM dwf_documents WHERE '.$where.' ORDER BY updated_at DESC,id DESC','SELECT COUNT(*) total FROM dwf_documents WHERE '.$where,$parameters,$page,static function(array $row):array{
            $row['version']=(int)$row['version'];$row['revision']=$row['version'];$row['template_version']=(int)$row['template_version'];return $row;
        });
        $result['filters']=$accepted;return $result;
    }

    public function templatePage(mixed $page=1):array
    {
        $actor=$this->context();
        return $this->metadataPage('SELECT id,club_id,title,description,version,published_version,status,created_by,created_at,updated_at FROM dwf_templates WHERE club_id=? ORDER BY title,id','SELECT COUNT(*) total FROM dwf_templates WHERE club_id=?',[$actor['club_id']],$page,static function(array $row):array{
            foreach(['version','published_version']as$key)$row[$key]=(int)$row[$key];return $row;
        });
    }

    /** Complete small selection labels, without any field definitions or original values. */
    public function templateOptions():array
    {
        $actor=$this->context();
        return array_map(static function(array $row):array{$row['version']=(int)$row['version'];$row['published_version']=(int)$row['published_version'];return $row;},$this->all('SELECT id,title,status,version,published_version FROM dwf_templates WHERE club_id=? ORDER BY title,id',[$actor['club_id']]));
    }

    public function templateVersionPage(string $id,mixed $page=1):array
    {
        $actor=$this->context();$this->requireMetadataOriginal('template',$id,$actor);
        return $this->metadataPage("SELECT template_id id,version,JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.title')) title,published_by,published_at FROM dwf_template_versions WHERE club_id=? AND template_id=? ORDER BY version DESC",'SELECT COUNT(*) total FROM dwf_template_versions WHERE club_id=? AND template_id=?',[$actor['club_id'],$id],$page,static function(array $row):array{$row['version']=(int)$row['version'];return $row;});
    }

    /** Open one exact publication only when requested; earlier fields and provenance stay complete. */
    public function templateVersion(string $id,int $version):array
    {
        $actor=$this->context();$this->requireMetadataOriginal('template',$id,$actor);
        if($version<1||$version>4294967294)throw new WorkflowException('Die Vorlagenversion ist ungültig.');
        $row=$this->one('SELECT snapshot_json,published_by,published_at FROM dwf_template_versions WHERE club_id=? AND template_id=? AND version=?',[$actor['club_id'],$id,$version]);
        if($row===null)throw new WorkflowException('Die veröffentlichte Vorlagenversion wurde nicht gefunden.');
        return $this->decode($row['snapshot_json'])+['published_by'=>$row['published_by'],'published_at'=>$row['published_at']];
    }

    public function versionPage(string $id,mixed $page=1):array
    {
        $actor=$this->context();$this->requireMetadataOriginal('document',$id,$actor);
        return $this->metadataPage("SELECT revision,actor_id,action,created_at,JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.title')) title,JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.status')) status FROM dwf_document_versions WHERE club_id=? AND document_id=? ORDER BY revision DESC",'SELECT COUNT(*) total FROM dwf_document_versions WHERE club_id=? AND document_id=?',[$actor['club_id'],$id],$page,static function(array $row)use($id):array{$row['revision']=(int)$row['revision'];$row['version']=$row['revision'];$row['id']=$id;return $row;});
    }

    public function activityPage(string $id,mixed $page=1):array
    {
        $actor=$this->context();$this->requireMetadataOriginal('document',$id,$actor);
        return $this->metadataPage('SELECT id,actor_id,action,comment,revision,created_at FROM dwf_activities WHERE club_id=? AND document_id=? ORDER BY revision DESC,id DESC','SELECT COUNT(*) total FROM dwf_activities WHERE club_id=? AND document_id=?',[$actor['club_id'],$id],$page,static function(array $row):array{$row['revision']=(int)$row['revision'];$row['event']=$row['action'];return $row;});
    }

    /** The reviewer exclusion uses original identities, without decoding every historical body. */
    public function contentAuthors(string $id):array
    {
        $actor=$this->context();$this->requireMetadataOriginal('document',$id,$actor);
        return array_column($this->all("SELECT DISTINCT actor_id FROM dwf_document_versions WHERE club_id=? AND document_id=? AND action IN ('create','save') ORDER BY actor_id",[$actor['club_id'],$id]),'actor_id');
    }

    /** Identical original filters for complete reads and the bounded metadata projection. */
    private function documentCriteria(array $filters,array $actor):array
    {
        $where='club_id=?';$parameters=[$actor['club_id']];$accepted=[];
        foreach(['folder_id','template_id','assignee_id','status']as$key){
            if(!array_key_exists($key,$filters))continue;
            $value=$filters[$key];
            if(!is_string($value))throw new WorkflowException('Der Dokumentfilter ist ungültig.');
            if($key==='status'){
                if(!in_array($value,['draft','submitted','returned','approved'],true))throw new WorkflowException('Der Dokumentstatus ist ungültig.');
            }elseif($key==='assignee_id')$this->externalId($value,true);
            else $this->internalId($value,$key==='folder_id');
            $where.=' AND '.$key.'=?';$parameters[]=$value;$accepted[$key]=$value;
        }
        if(isset($filters['search'])&&$filters['search']!==''){
            $search=$this->text($filters['search'],240,'Suchtext');
            $where.=" AND title LIKE ? ESCAPE '='";$parameters[]='%'.str_replace(['=','%','_'],['==','=%','=_'],$search).'%';$accepted['search']=$search;
        }
        return [$where,$parameters,$accepted];
    }

    private function requireMetadataOriginal(string $kind,string $id,array $actor):void
    {
        $this->internalId($id);$table=$kind==='document'?'dwf_documents':'dwf_templates';
        if($this->one('SELECT id FROM '.$table.' WHERE club_id=? AND id=?',[$actor['club_id'],$id])===null)throw new WorkflowException($kind==='document'?'Das Dokument wurde nicht gefunden.':'Die Vorlage wurde nicht gefunden.');
    }

    private function metadataPage(string $query,string $count,array $parameters,mixed $page,callable $map):array
    {
        if((!is_string($page)&&!is_int($page))||!preg_match('/\A[1-9][0-9]{0,8}\z/D',(string)$page))throw new WorkflowException('Gültige Workflow-Listenseite angeben.');
        $number=(int)$page;
        return $this->readOriginalSnapshot(function()use($query,$count,$parameters,$number,$map):array{
            $total=(int)$this->one($count,$parameters)['total'];$pages=max(1,(int)ceil($total/self::PAGE_SIZE));$number=min($number,$pages);
            $rows=$this->all($query.' LIMIT '.self::PAGE_SIZE.' OFFSET '.(($number-1)*self::PAGE_SIZE),$parameters);
            return ['rows'=>array_map($map,$rows),'total'=>$total,'page'=>$number,'pages'=>$pages,'size'=>self::PAGE_SIZE];
        });
    }

    /** Keep large originals out of MySQL's sort rows; hydrate in the exact selected key order. */
    private function completeOrderedOriginals(string $table,string $where,array $parameters,string $order,?int $limit=null):array
    {
        return $this->readOriginalSnapshot(function()use($table,$where,$parameters,$order,$limit):array{
            $keys=array_column($this->all('SELECT id FROM '.$table.' WHERE '.$where.' ORDER BY '.$order.($limit!==null?' LIMIT '.$limit:''),$parameters),'id');
            if(!$keys)return [];
            $rows=[];
            foreach(array_chunk($keys,100)as$part){
                foreach($this->all('SELECT * FROM '.$table.' WHERE '.$where.' AND id IN ('.implode(',',array_fill(0,count($part),'?')).')',[...$parameters,...$part])as$row)$rows[$row['id']]=$this->normalize($row);
            }
            $result=[];foreach($keys as$id)if(isset($rows[$id]))$result[]=$rows[$id];return $result;
        });
    }

    private function readOriginalSnapshot(callable $read):mixed
    {
        $owned=!$this->pdo->inTransaction();if($owned)$this->pdo->beginTransaction();
        try{$result=$read();if($owned)$this->pdo->commit();return $result;}
        catch(\Throwable $failure){if($owned&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $failure;}
    }
}
