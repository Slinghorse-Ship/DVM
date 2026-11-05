<?php declare(strict_types=1);
namespace Kulturhus\Verein\DocumentWorkflow;

/** Durable creation receipts belong to the creating actor; no constructor or read path installs a schema. */
trait WorkflowCreations
{
    public const CREATION_UNCONFIRMED='Speicherergebnis konnte nicht bestätigt werden. Anlagevorgänge prüfen, bevor du erneut anlegst.';
    private bool $ownCreationTransaction=false;

    public function creationRequests(mixed $page=1):array
    {
        $actor=$this->context(true);
        return $this->metadataPage('SELECT request_key,kind,result_id,result_version,title,created_at FROM dwf_creation_requests WHERE club_id=? AND created_by=? ORDER BY created_at DESC,request_key DESC','SELECT COUNT(*) total FROM dwf_creation_requests WHERE club_id=? AND created_by=?',[$actor['club_id'],$actor['id']],$page,static function(array $row):array{$row['result_version']=(int)$row['result_version'];return $row;});
    }

    public function creationReceipt(string $key):?array
    {
        $this->creationKey($key);$actor=$this->context(true);
        $row=$this->one('SELECT * FROM dwf_creation_requests WHERE club_id=? AND created_by=? AND request_key=?',[$actor['club_id'],$actor['id'],$key]);
        if($row===null)return null;
        if(!is_string($row['original_json'])||!is_string($row['original_sha256'])||!is_string($row['intent_json'])||!is_string($row['intent_sha256'])||!hash_equals($row['original_sha256'],hash('sha256',$row['original_json']))||!hash_equals($row['intent_sha256'],hash('sha256',$row['intent_json'])))throw new WorkflowException('Ursprünglichen Anlegevorgang durch den Betrieb prüfen lassen.');
        $intent=$this->decode($row['intent_json']);$original=$this->decode($row['original_json']);
        if(($intent['kind']??null)!==$row['kind']||($original['id']??null)!==$row['result_id']||($original['club_id']??null)!==$actor['club_id']||(int)$row['result_version']!==1||($original['version']??null)!==1||($original[$row['kind']==='folder'?'name':'title']??null)!==$row['title'])throw new WorkflowException('Ursprünglichen Anlegevorgang durch den Betrieb prüfen lassen.');
        return ['request_key'=>$key,'kind'=>$row['kind'],'result_id'=>$row['result_id'],'result_version'=>(int)$row['result_version'],'title'=>$row['title'],'created_at'=>$row['created_at'],'intent_sha256'=>$row['intent_sha256'],'intent'=>$intent,'original_sha256'=>$row['original_sha256'],'original'=>$original];
    }

    /** The same original intent pins the first accepted source even after later template publications. */
    private function createOriginalOnce(string $kind,array $intent,string $key,callable $create):string
    {
        $this->creationKey($key);$json=$this->creationJson(['kind'=>$kind,'input'=>$intent]);$hash=hash('sha256',$json);
        try{
            return $this->mutate(function(array $actor)use($kind,$key,$json,$hash,$create):string{
                // This unique insert serialises equal keys; the placeholder is never committed on its own.
                $this->run('INSERT INTO dwf_creation_requests (club_id,created_by,request_key,kind,intent_json,intent_sha256,created_at) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE request_key=request_key',[$actor['club_id'],$actor['id'],$key,$kind,$json,$hash,$this->now()]);
                $row=$this->one('SELECT kind,intent_sha256,result_id FROM dwf_creation_requests WHERE club_id=? AND created_by=? AND request_key=? FOR UPDATE',[$actor['club_id'],$actor['id'],$key]);
                if($row['kind']!==$kind||!hash_equals($row['intent_sha256'],$hash))throw WorkflowException::conflict('Dieses Anlegeformular wurde bereits mit anderen Angaben gespeichert. Anlagevorgang prüfen.');
                if($row['result_id']!==null)return $this->creationReceipt($key)['result_id'];
                $this->ownCreationTransaction=true;
                try{$id=$create();}finally{$this->ownCreationTransaction=false;}
                $original=match($kind){
                    'document'=>$this->document($id,1),
                    'template'=>$this->template($id),
                    'folder'=>$this->normalize($this->one('SELECT * FROM dwf_folders WHERE club_id=? AND id=?',[$actor['club_id'],$id])),
                };
                $originalJson=$this->json($original);$title=$kind==='folder'?$original['name']:$original['title'];
                $this->run('UPDATE dwf_creation_requests SET result_id=?,result_version=1,title=?,original_json=?,original_sha256=? WHERE club_id=? AND created_by=? AND request_key=?',[$id,$title,$originalJson,hash('sha256',$originalJson),$actor['club_id'],$actor['id'],$key]);
                return $id;
            });
        }catch(\PDOException $failure){
            // A committed transaction may lose its acknowledgement. Read its receipt; never replay a write here.
            try{$receipt=$this->creationReceipt($key);}catch(\Throwable){$receipt=null;}
            if($receipt!==null&&$receipt['kind']===$kind&&hash_equals($receipt['intent_sha256'],$hash))return $receipt['result_id'];
            throw WorkflowException::unconfirmedCreation(self::CREATION_UNCONFIRMED,$failure);
        }
    }

    private function creationKey(string $key):void
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new WorkflowException('Anlegeformular erneut öffnen.');
    }
    private function creationJson(array $value):string
    {
        $canonical=function(mixed $part)use(&$canonical):mixed{
            if(is_array($part)){$list=array_is_list($part);if(!$list)ksort($part,SORT_STRING);foreach($part as$key=>$child)$part[$key]=$canonical($child);return $list?$part:(object)$part;}
            if($part!==null&&!is_scalar($part))throw new WorkflowException('Anlegeangaben prüfen.');
            return $part;
        };
        try{return json_encode($canonical($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);}
        catch(\JsonException){throw new WorkflowException('Anlegeangaben prüfen.');}
    }
}
