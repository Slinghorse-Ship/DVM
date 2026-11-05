<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

/** Private minutes store. Members only receive currently published content. */
final class Minutes
{
    public const MAX_PDF_BYTES=10485760;
    public function __construct(private PDO $db,private string $actorId){}

    private function actor(bool $write=false):array
    {
        $a=Permissions::actor($this->db,$this->actorId);
        if($a['role']!=='editor'&&BoardAccess::superuser($this->db,$this->actorId))$a['minutes_access']='write';
        if(!in_array($a['minutes_access'],$write?['write']:['read','write'],true))throw new DomainException('Keine ausreichende Berechtigung für Protokolle.');
        return $a;
    }
    public function canRead():bool{try{$this->actor();return true;}catch(DomainException){return false;}}
    public function canWrite():bool{try{$this->actor(true);return true;}catch(DomainException){return false;}}
    private function q(string $sql,array $values=[]):\PDOStatement{$q=$this->db->prepare($sql);$q->execute($values);return $q;}
    private function tx(callable $fn):mixed
    {
        $this->db->beginTransaction();
        try{$result=$fn();$this->db->commit();return $result;}catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private static function text(mixed $value,string $label,int $limit,bool $required=false):string
    {
        if(!is_string($value)||strlen($value)>$limit||!preg_match('//u',$value)||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))throw new DomainException($label.' ist ungültig oder zu lang.');
        $value=trim($value);if($required&&$value==='')throw new DomainException($label.' ausfüllen.');return $value;
    }
    private static function date(mixed $value):string
    {
        $value=self::text($value,'Sitzungsdatum',10,true);$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if(!$date||$date->format('Y-m-d')!==$value)throw new DomainException('Sitzungsdatum prüfen.');return $value;
    }

    /** Rebuilds allowed markup; no supplied attributes or active content survive. */
    public static function sanitize(string $html):string
    {
        $html=self::text($html,'Protokolltext',100000);
        if($html==='')return '';
        if(!class_exists(\DOMDocument::class))throw new DomainException('HTML-Verarbeitung für Protokolle ist nicht verfügbar.');
        $dom=new \DOMDocument('1.0','UTF-8');$dom->resolveExternals=false;$dom->substituteEntities=false;
        $previous=libxml_use_internal_errors(true);
        try{$loaded=$dom->loadHTML('<?xml encoding="UTF-8"><!DOCTYPE html><html><body>'.$html.'</body></html>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}
        finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
        if(!$loaded)throw new DomainException('Protokolltext konnte nicht verarbeitet werden.');
        $body=$dom->getElementsByTagName('body')->item(0);if(!$body)return '';
        $allowed=['p','br','h2','h3','strong','em','u','ul','ol','li','blockquote','hr'];
        $drop=['script','style','iframe','object','embed','svg','math','template','noscript','link','meta','base','form','input','textarea','select','option','button','frame','frameset','video','audio','canvas','img','source','picture'];
        $nodes=0;
        $walk=function(\DOMNode $node,int $depth)use(&$walk,&$nodes,$allowed,$drop):string{
            if(++$nodes>10000||$depth>50)throw new DomainException('Protokolltext ist zu stark verschachtelt.');
            if($node instanceof \DOMText)return htmlspecialchars($node->nodeValue??'',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            if(!$node instanceof \DOMElement)return '';
            $name=strtolower($node->tagName);if(in_array($name,$drop,true))return '';
            $tag=match($name){'div'=>'p','b'=>'strong','i'=>'em',default=>$name};
            $children='';foreach($node->childNodes as $child)$children.=$walk($child,$depth+1);
            if($name==='div')foreach($node->childNodes as $child)if($child instanceof \DOMElement&&in_array(strtolower($child->tagName),['p','div','h2','h3','ul','ol','blockquote','hr'],true))return $children;
            if(!in_array($tag,$allowed,true))return $children;
            if(in_array($tag,['br','hr'],true))return '<'.$tag.'>';
            return '<'.$tag.'>'.$children.'</'.$tag.'>';
        };
        $result='';foreach($body->childNodes as $node)$result.=$walk($node,0);
        if(strlen($result)>100000)throw new DomainException('Bereinigter Protokolltext ist zu lang.');
        return trim($result);
    }

    private function raw(string $id):array
    {
        $a=$this->actor();
        $r=$this->q('SELECT m.*,f.name AS file_name,f.mime,f.sha256,length(f.bytes) AS file_size FROM minutes m LEFT JOIN minutes_files f ON f.club_id=m.club_id AND f.minutes_id=m.id WHERE m.club_id=? AND m.id=? AND (CAST(? AS SIGNED)=1 OR m.state=\'published\')',[$a['club_id'],$id,(int)($a['minutes_access']==='write')])->fetch();
        if(!$r)throw new DomainException('Protokoll nicht verfügbar.');return $r;
    }
    public function entry(string $id):array{$r=$this->raw($id);unset($r['request_key'],$r['request_hash']);return $r;}
    /** Read the current document created by this actor's exact original save intention. */
    public function creationResult(string $requestKey):?array
    {
        $a=$this->actor(true);
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$requestKey))throw new DomainException('Ursprüngliche Protokoll-Vorgangskennung prüfen.',422);
        $id=$this->q('SELECT id FROM minutes WHERE club_id=? AND created_by=? AND request_key=?',[$a['club_id'],$a['id'],$requestKey])->fetchColumn();
        return $id===false?null:$this->entry((string)$id);
    }
    public function listing(array $filters=[]):array
    {
        $a=$this->actor();$state=self::text($filters['state']??($a['minutes_access']==='write'?'all':'published'),'Status',20);
        if(!in_array($state,['all','draft','published','archived'],true))throw new DomainException('Protokollstatus prüfen.');
        $search=self::text($filters['q']??'','Suche',150);$year=self::text($filters['year']??'','Jahr',4);
        if($year!==''&&!preg_match('/^[0-9]{4}$/D',$year))throw new DomainException('Jahr prüfen.');
        $where=['m.club_id=?'];$values=[$a['club_id']];
        if($a['minutes_access']!=='write')$where[]="m.state='published'";
        if($state!=='all'){$where[]='m.state=?';$values[]=$state;}
        if($search!==''){$where[]='instr(lower(m.title),lower(?))>0';$values[]=$search;}
        if($year!==''){$where[]='substr(m.meeting_on,1,4)=?';$values[]=$year;}
        $rows=$this->q('SELECT m.id,m.title,m.meeting_on,m.source_kind,m.state,m.version,m.created_at,m.created_by,m.updated_at,m.updated_by,m.published_at,m.published_by,f.name AS file_name,f.mime,length(f.bytes) AS file_size FROM minutes m LEFT JOIN minutes_files f ON f.club_id=m.club_id AND f.minutes_id=m.id WHERE '.implode(' AND ',$where).' ORDER BY m.meeting_on DESC,m.created_at DESC,m.id LIMIT 1001',$values)->fetchAll();
        if(count($rows)>1000)throw new DomainException('Mehr als 1.000 Protokolle. Bitte Jahr oder Suche eingrenzen.');return $rows;
    }
    public function file(string $id):array
    {
        $a=$this->actor();$r=$this->q("SELECT f.name,f.mime,f.bytes,f.sha256 FROM minutes_files f JOIN minutes m ON m.club_id=f.club_id AND m.id=f.minutes_id WHERE m.club_id=? AND m.id=? AND m.source_kind='pdf' AND (CAST(? AS SIGNED)=1 OR m.state='published')",[$a['club_id'],$id,(int)($a['minutes_access']==='write')])->fetch();
        if(!$r)throw new DomainException('Protokolldatei nicht verfügbar.');return $r;
    }
    private static function upload(array $upload):array
    {
        $name=self::text($upload['name']??'','PDF-Dateiname',240,true);$bytes=$upload['bytes']??null;
        if(preg_match('/[\\\\\/:\r\n]/',$name)||strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='pdf')throw new DomainException('Eine PDF-Datei mit gültigem Dateinamen auswählen.');
        if(!is_string($bytes)||strlen($bytes)<100||strlen($bytes)>self::MAX_PDF_BYTES||!preg_match('/^%PDF-[12]\.[0-9]/D',$bytes)||!preg_match('/%%EOF\s*$/D',substr($bytes,-1024)))throw new DomainException('Eine vollständige PDF-Datei bis 10 MB auswählen.');
        if(class_exists(\finfo::class)&&((new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes))!=='application/pdf')throw new DomainException('Datei ist kein PDF.');
        return ['name'=>$name,'mime'=>'application/pdf','bytes'=>$bytes,'sha256'=>hash('sha256',$bytes)];
    }
    private function audit(array $a,string $id,string $action,int $version,array $details):void
    {
        $now=gmdate('Y-m-d\TH:i:s\Z');$this->q('INSERT INTO minutes_history(club_id,minutes_id,actor_id,action,version,happened_at,details) VALUES(?,?,?,?,?,?,?)',[$a['club_id'],$id,$a['id'],$action,$version,$now,json_encode($details,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
        $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'minutes.'.$action,$id,$version,$now]);
    }
    public function save(string $id,int $version,array $data,string $requestKey,?array $upload=null):string
    {
        $this->actor(true);if(!preg_match('/^[a-f0-9]{32}$/D',$requestKey))throw new DomainException('Formular neu öffnen.');
        $title=self::text($data['title']??'','Titel',200,true);$date=self::date($data['meeting_on']??'');
        $kind=self::text($data['source_kind']??'','Inhaltstyp',10,true);if(!in_array($kind,['editor','pdf'],true))throw new DomainException('Editor oder PDF auswählen.');
        $body=$kind==='editor'?self::sanitize(self::text($data['body_html']??'','Protokolltext',100000)):'';
        if($kind==='editor'&&$upload!==null)throw new DomainException('Editor und PDF nicht gleichzeitig verwenden.');
        $file=$upload===null?null:self::upload($upload);
        return $this->tx(function()use($id,$version,$title,$date,$kind,$body,$file,$requestKey){
            $a=$this->actor(true);$hash=hash('sha256',json_encode([$a['id'],$title,$date,$kind,$body,$file?$file['name']:null,$file?$file['sha256']:null],JSON_THROW_ON_ERROR));
            if($id===''){
                if($version!==0)throw new DomainException('Neue Protokolle ohne alte Version anlegen.');
                $previous=$this->q('SELECT id,request_hash FROM minutes WHERE club_id=? AND request_key=?',[$a['club_id'],$requestKey])->fetch();
                if($previous){if(!hash_equals($previous['request_hash'],$hash))throw new DomainException('Vorgangskennung mit anderen Eingaben bereits verwendet.');return $previous['id'];}
                $old=null;$target=bin2hex(random_bytes(16));
            }else{
                $old=$this->raw($id);$target=$id;
                if((int)$old['version']!==$version)throw new DomainException('Protokoll wurde inzwischen geändert. Bitte neu laden.',409);
                if($old['state']!=='draft')throw new DomainException('Protokoll vor dem Bearbeiten in den Entwurf zurücknehmen.');
            }
            if($kind==='pdf'&&$file===null&&(!$old||$old['source_kind']!=='pdf'||!$old['sha256']))throw new DomainException('PDF-Datei auswählen.');
            $now=gmdate('Y-m-d\TH:i:s\Z');
            if($old){
                $changed=$this->q('UPDATE minutes SET title=?,meeting_on=?,source_kind=?,body_html=?,version=version+1,updated_at=?,updated_by=? WHERE club_id=? AND id=? AND version=?',[$title,$date,$kind,$body,$now,$a['id'],$a['club_id'],$target,$version]);
                if($changed->rowCount()!==1)throw new DomainException('Protokoll wurde inzwischen geändert. Bitte neu laden.',409);
            }
            else $this->q("INSERT INTO minutes(club_id,id,title,meeting_on,source_kind,body_html,state,version,created_at,created_by,updated_at,updated_by,request_key,request_hash) VALUES(?,?,?,?,?,?,'draft',1,?,?,?,?,?,?)",[$a['club_id'],$target,$title,$date,$kind,$body,$now,$a['id'],$now,$a['id'],$requestKey,$hash]);
            if($kind==='editor')$this->q('DELETE FROM minutes_files WHERE club_id=? AND minutes_id=?',[$a['club_id'],$target]);
            elseif($file){$q=$this->db->prepare('INSERT INTO minutes_files(club_id,minutes_id,name,mime,bytes,sha256) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),mime=VALUES(mime),bytes=VALUES(bytes),sha256=VALUES(sha256)');foreach([$a['club_id'],$target,$file['name'],$file['mime']] as $i=>$value)$q->bindValue($i+1,$value);$q->bindValue(5,$file['bytes'],PDO::PARAM_LOB);$q->bindValue(6,$file['sha256']);$q->execute();}
            $this->audit($a,$target,$old?'updated':'created',$version+1,['title'=>$title,'meeting_on'=>$date,'source_kind'=>$kind,'body_sha256'=>$body!==''?hash('sha256',$body):null,'file_sha256'=>$kind==='pdf'?($file['sha256']??$old['sha256']):null]);
            return $target;
        });
    }
    public function transition(string $id,int $version,string $action):void
    {
        $this->actor(true);$this->tx(function()use($id,$version,$action){
            $a=$this->actor(true);$r=$this->raw($id);if((int)$r['version']!==$version)throw new DomainException('Protokoll wurde inzwischen geändert. Bitte neu laden.',409);
            $next=match($action){'publish'=>$r['state']==='draft'?'published':null,'withdraw'=>$r['state']==='published'?'draft':null,'archive'=>in_array($r['state'],['draft','published'],true)?'archived':null,'restore'=>$r['state']==='archived'?'draft':null,default=>null};
            if($next===null)throw new DomainException('Diese Protokollaktion ist im aktuellen Status nicht möglich.');
            if($next==='published'){
                if($r['source_kind']==='editor'&&!preg_match('/[^\s\x{00a0}]/u',html_entity_decode(strip_tags($r['body_html']),ENT_QUOTES|ENT_HTML5,'UTF-8')))throw new DomainException('Vor der Veröffentlichung einen Protokolltext schreiben.');
                if($r['source_kind']==='pdf'&&!$r['sha256'])throw new DomainException('Vor der Veröffentlichung eine PDF-Datei hinterlegen.');
            }
            $now=gmdate('Y-m-d\TH:i:s\Z');$published=$next==='published';
            // published_at is cleared on withdrawal; history retains the first release.
            // Corrections/re-publication must not notify again or replay to later opt-ins.
            $firstPublication=$published&&!$this->q("SELECT 1 FROM minutes_history WHERE club_id=? AND minutes_id=? AND action='publish' LIMIT 1",[$a['club_id'],$id])->fetchColumn();
            $changed=$this->q('UPDATE minutes SET state=?,version=version+1,updated_at=?,updated_by=?,published_at=?,published_by=? WHERE club_id=? AND id=? AND version=?',[$next,$now,$a['id'],$published?$now:null,$published?$a['id']:null,$a['club_id'],$id,$version]);
            if($changed->rowCount()!==1)throw new DomainException('Protokoll wurde inzwischen geändert. Bitte neu laden.',409);
            $this->audit($a,$id,$action,$version+1,['before'=>$r['state'],'after'=>$next]);
            if($firstPublication)ManagerNotifications::minutesAvailable($this->db,$a['club_id'],$id);
            elseif(!$published)ManagerNotifications::minutesRemoved($this->db,$a['club_id'],$id);
        });
    }
    public function history(string $id):array{$this->actor(true);$this->raw($id);return $this->q('SELECT actor_id,action,version,happened_at,details FROM minutes_history WHERE club_id=? AND minutes_id=? ORDER BY sequence DESC',[$this->actor()['club_id'],$id])->fetchAll();}
}
