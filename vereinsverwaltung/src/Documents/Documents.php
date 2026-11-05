<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;
require_once __DIR__.'/DocumentDeletion.php';
require_once __DIR__.'/TaxNoticeReads.php';
require_once __DIR__.'/DocumentSourceChoices.php';
require_once __DIR__.'/DocumentSourceOverview.php';
require_once dirname(__DIR__).'/Infrastructure/Media/CameraDocumentUploads.php';
require_once dirname(__DIR__, 1).'/Shared/Pagination.php';

final class Documents
{
    use DocumentDeletion;
    use TaxNoticeReads;
    use DocumentSourceChoices;
    use DocumentSourceOverview;
    public const KINDS=['receipt'=>'Eingangsbeleg','invoice'=>'Ausgangsbeleg','donation'=>'Spendennachweis','certificate'=>'Zuwendungsbescheinigung','membership'=>'Mitgliederdokument','inventory'=>'Inventarnachweis','photo'=>'Foto','other'=>'Vereinsdokument','record'=>'Interner Vorgangsnachweis','export'=>'Export','preview'=>'Dokumentvorschau'];
    private ?array $readActor=null;
    private ?array $readFolders=null;
    private bool $batchUpload=false;
    public function __construct(private PDO $db,private string $actorId) {}
    private function actor(): array {return $this->readActor??Permissions::actor($this->db,$this->actorId);}
    /** Reuse one permission/folder snapshot only within a single bulk read. */
    private function readContext(callable $read):mixed
    {
        if($this->readActor!==null)return $read();
        $this->readActor=Permissions::actor($this->db,$this->actorId);
        try{
            $this->readFolders=array_column($this->q('SELECT * FROM document_folders WHERE club_id=?',[$this->readActor['club_id']])->fetchAll(),null,'id');
            return $read();
        }finally{$this->readActor=null;$this->readFolders=null;}
    }
    public function canScope(string $scope,bool $write=false): bool
    {
        try{$a=$this->actor();if($a['role']==='editor')return false;$allowed=$write?['write']:['read','write'];return match($scope){
            'orga'=>in_array($a['documents_access'],$allowed,true),'finance'=>in_array($a['finance_access'],$allowed,true),'members'=>in_array($a['members_access'],$allowed,true),
            'inventory'=>in_array($a['inventory_access'],$allowed,true)||in_array($a['loans_access'],$allowed,true),'settings'=>(bool)$a['settings_access'],default=>false};
        }catch(DomainException){return false;}
    }
    public function canRead(): bool {if(BoardAccess::allowed($this->db,$this->actorId))return true;foreach(array_keys(DocumentCatalog::SCOPES) as $scope)if($this->canScope($scope))return true;return false;}
    public function canWrite(): bool {if(BoardAccess::allowed($this->db,$this->actorId))return true;foreach(array_keys(DocumentCatalog::SCOPES) as $scope)if($this->canScope($scope,true))return true;return false;}
    private function allow(array $scopes,bool $write=false,bool $admission=false):void {if($admission){BoardAccess::require($this->db,$this->actorId);$scopes=array_diff($scopes,['members']);}foreach($scopes as $scope)if(!$this->canScope($scope,$write))throw new DomainException('Keine ausreichende Berechtigung für dieses Dokument oder seine Zuordnungen.');}
    private function q(string $sql,array $args=[]): \PDOStatement {return DocumentCatalog::query($this->db,$sql,$args);}
    private static function text(mixed $v,int $max=200,bool $required=false):string {if(!is_string($v)||preg_match('//u',$v)!==1||mb_strlen($v,'UTF-8')>$max||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$v))throw new DomainException('Text ungültig oder zu lang.');$v=trim($v);if($required&&$v==='')throw new DomainException('Bitte alle Pflichtfelder ausfüllen.');return $v;}
    private static function date(mixed $v):string {$v=self::text($v,10,true);$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$d||$d->format('Y-m-d')!==$v)throw new DomainException('Dokumentdatum ist ungültig.');return $v;}
    private static function checkVersion(array $r,int $v):void {if((int)$r['version']!==$v)throw new DomainException('Dokument oder Ordner wurde inzwischen geändert. Bitte neu laden.');}
    private function tx(callable $fn):mixed {return $this->batchUpload?$fn():\Kulturhus\Verein\Infrastructure\Database\Transactions::run($this->db,$fn);}
    private function audit(string $id,string $action,int $version,array $data):void
    {
        $a=$this->actor();$now=gmdate('Y-m-d\TH:i:s\Z');$this->q('INSERT INTO document_history(club_id,document_id,actor_id,action,version,happened_at,details) VALUES(?,?,?,?,?,?,?)',[$a['club_id'],$id,$a['id'],$action,$version,$now,json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
        $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'document.'.$action,$id,$version,$now]);
    }
    private function rawFolder(string $id):array {$r=$this->readFolders!==null?($this->readFolders[$id]??null):$this->q('SELECT * FROM document_folders WHERE club_id=? AND id=?',[$this->actor()['club_id'],$id])->fetch();if(!$r)throw new DomainException('Ordner nicht verfügbar.');return $r;}
    public function folder(string $id):array {$r=$this->rawFolder($id);$this->allow([$r['scope']],false,(bool)$r['admission_only']);$r['path']=$this->path($id);return $r;}
    public function path(string $folder,bool $admission=false):string
    {
        $admission=$admission||(bool)$this->rawFolder($folder)['admission_only'];
        $parts=[];$seen=[];while($folder!==''){if(isset($seen[$folder])||count($parts)>30)throw new DomainException('Ungültige Ordnerstruktur.');$seen[$folder]=true;$r=$this->rawFolder($folder);$this->allow([$r['scope']],false,$admission||(bool)$r['admission_only']);array_unshift($parts,$r['name']);$folder=$r['parent_id']??'';}return '/'.implode('/',$parts);
    }
    public function folders():array {return $this->readContext(fn()=>$this->folderRows());}
    /** Metadata-only candidates use the same original ancestor checks as a document path. */
    public function readableFolderIds(bool $admission=false):array
    {
        return $this->readContext(function()use($admission){
            if($admission)BoardAccess::require($this->db,$this->actorId);
            $ids=[];foreach(array_keys($this->readFolders)as$id){$id=(string)$id;try{$this->path($id,$admission);$ids[]=$id;}catch(DomainException){continue;}}
            return $ids;
        });
    }
    public function writableFolders():array {return $this->readContext(fn()=>array_values(array_filter($this->folderRows(),fn($folder)=>$this->canScope($folder['scope'],true))));}
    private function folderRows():array
    {
        $rows=$this->q('SELECT * FROM document_folders WHERE club_id=? ORDER BY name',[$this->actor()['club_id']])->fetchAll();$out=[];foreach($rows as $r){try{$this->allow([$r['scope']],false,(bool)$r['admission_only']);$r['path']=$this->path($r['id']);$out[]=$r;}catch(DomainException){continue;}}usort($out,fn($a,$b)=>strnatcasecmp($a['path'],$b['path']));return $out;
    }
    public function saveFolder(string $id,int $version,string $name,string $parent,string $scope):string
    {
        return $this->tx(function()use($id,$version,$name,$parent,$scope){
            $a=$this->actor();$name=self::text($name,200,true);if($name!==trim($name," .")||$name==='.'||$name==='..'||preg_match('/[\\\\\/:*?"<>|\r\n]/u',$name))throw new DomainException('Ordnername darf keine Pfadtrenner oder reservierten Zeichen enthalten.');
            $old=$id?$this->rawFolder($id):null;$restricted=$old?DocumentProtection::folderRestricted($this->db,$a['club_id'],$id):false;if($restricted)BoardAccess::require($this->db,$this->actorId);if($old){self::checkVersion($old,$version);$scope=$old['scope'];}elseif($version!==0)throw new DomainException('Ungültige Ordnerversion.');
            if($parent!==''){$p=$this->folder($parent);$restricted=$restricted||DocumentProtection::folderRestricted($this->db,$a['club_id'],$parent);if(!$old)$scope=$p['scope'];$this->allow([$scope],true,$restricted);if($p['scope']!==$scope)throw new DomainException('Ordner können nur innerhalb desselben Berechtigungsbereichs verschoben werden.');$cursor=$parent;$n=0;while($cursor){if($cursor===$id||++$n>24)throw new DomainException('Ordner kann nicht in sich selbst oder seine Unterordner verschoben werden.');$cursor=$this->rawFolder($cursor)['parent_id']??'';}}
            $this->allow([$scope],true,$restricted);
            if($old&&$old['system_key']&&$old['parent_id']===null&&$parent!=='')throw new DomainException('Bereichswurzel muss auf oberster Ebene bleiben.');
            $target=$id?:bin2hex(random_bytes(16));if($this->q('SELECT 1 FROM document_folders WHERE club_id=? AND parent_id <=> ? AND name=? AND id<>?',[$a['club_id'],$parent?:null,$name,$target])->fetchColumn())throw new DomainException('Dieser Ordnername ist hier bereits vorhanden.');
            if($id)$this->q('UPDATE document_folders SET name=?,parent_id=?,admission_only=?,version=version+1 WHERE club_id=? AND id=?',[$name,$parent?:null,(int)$restricted,$a['club_id'],$id]);else $this->q('INSERT INTO document_folders(club_id,id,parent_id,name,scope,admission_only) VALUES(?,?,?,?,?,?)',[$a['club_id'],$target,$parent?:null,$name,$scope,(int)$restricted]);
            if($restricted)DocumentProtection::protectFolder($this->db,$a['club_id'],$target);
            $this->audit($target,'folder',$version+1,['name'=>$name,'parent'=>$parent,'scope'=>$scope]);return $target;
        });
    }
    public function source(string $type,string $id,bool $write=false):array
    {
        if(!isset(DocumentCatalog::TYPES[$type]))throw new DomainException('Unbekannte Zuordnung.');$club=$this->actor()['club_id'];
        $scope=match($type){'entry','goods','account'=>'finance','contact','application'=>'members','item','loan'=>'inventory','appointment'=>'orga'};$this->allow([$scope],$write,$type==='application');
        $r=$this->q('SELECT * FROM document_sources WHERE club_id=? AND source_type=? AND source_id=?',[$club,$type,$id])->fetch();
        if(!$r||!$r['available']||!DocumentCatalog::raw($this->db,$club,$type,$id))throw new DomainException('Zugeordneter Vorgang nicht verfügbar.');$r['path']=$this->path($r['folder_id']);return $r;
    }
    public function canSource(string $type,string $id,bool $write=false):bool {try{$this->source($type,$id,$write);return true;}catch(DomainException){return false;}}
    public function writableSources():array
    {
        return $this->readContext(function(){
            $rows=$this->q('SELECT * FROM document_sources WHERE club_id=? AND available=1 ORDER BY document_date DESC,label',[$this->actor()['club_id']])->fetchAll();
            return array_values(array_filter($rows,fn($source)=>$this->canSource($source['source_type'],$source['source_id'],true)));
        });
    }
    public function sources():array
    {
        return $this->readContext(function(){
            $eligible=[];foreach($this->sourceIndexRows()as$row)$eligible[$row['source_type'].':'.$row['source_id']]=true;
            $counts=$this->sourceDocumentCounts();$rows=$this->q('SELECT * FROM document_sources WHERE club_id=? AND available=1 ORDER BY document_date DESC,label',[$this->actor()['club_id']])->fetchAll();$out=[];
            foreach($rows as$row){$key=$row['source_type'].':'.$row['source_id'];if(!isset($eligible[$key]))continue;$row['document_count']=$counts[$key]??0;$out[]=$row;}return$out;
        });
    }

    private function linksFrom(mixed $links,bool $write,array $previous=[]):array
    {
        if(!is_array($links)||count($links)>30)throw new DomainException('Höchstens 30 Zuordnungen pro Dokument.');$out=[];foreach($links as $link){if(!is_string($link))throw new DomainException('Ungültige Dokumentzuordnung.');if($link==='')continue;[$type,$id]=array_pad(explode(':',$link,2),2,'');$retained=array_values(array_filter($previous,fn($l)=>$l['source_type']===$type&&$l['source_id']===$id&&!$l['available']));if($retained){$r=$retained[0];$this->allow([$r['scope']],$write,$type==='application');}else $r=$this->source($type,$id,$write);$out[$type.':'.$id]=$r;}return array_values($out);
    }
    private function kindScope(string $kind):?string {return match($kind){'receipt','invoice','donation','certificate'=>'finance','membership'=>'members','inventory'=>'inventory',default=>null};}
    private function placement(array $data,array $links,bool $write,array $oldScopes=[],bool $admission=false):array
    {
        $admission=$admission||(bool)array_filter($links,fn($link)=>$link['source_type']==='application');
        $kind=self::text($data['kind']??'other',30);if(!isset(self::KINDS[$kind]))throw new DomainException('Unbekannte Dokumentart.');
        $scopes=$oldScopes;foreach($links as $s)$scopes[]=$s['scope'];$typeScope=$this->kindScope($kind);if($typeScope)$scopes[]=$typeScope;
        $folder=self::text($data['folder']??'',100);$date=self::date($data['document_date']??date('Y-m-d'));$club=$this->actor()['club_id'];
        if($folder!==''){$f=$this->rawFolder($folder);$scopes[]=$f['scope'];$admission=$admission||(bool)$f['admission_only'];}else{
            if($links){$preferred=$typeScope?array_values(array_filter($links,fn($l)=>$l['scope']===$typeScope)):[];$folder=($preferred[0]??$links[0])['folder_id'];}else{
                $scope=$typeScope??self::text($data['scope']??'orga',20);$scopes[]=$scope;$this->allow([$scope],$write);
                $root=match($scope){'finance'=>in_array($kind,['donation','certificate'],true)?'Spenden':'Finanzen','members'=>'Mitglieder','inventory'=>'Inventar','settings'=>'Einstellungen',default=>'Orga'};
                $part=match($kind){'export'=>'Exporte','preview'=>'Vorschauen','certificate'=>'Zuwendungsbescheinigungen',default=>'Eingang - noch zuordnen'};
                $folder=DocumentCatalog::folder($this->db,$club,[$root,substr($date,0,4),$part],$scope);
            }
        }
        $admission=$admission||DocumentProtection::folderRestricted($this->db,$club,$folder);
        if(!$scopes)$scopes[]=$this->rawFolder($folder)['scope'];$scopes=array_values(array_unique($scopes));sort($scopes);$this->allow($scopes,$write,$admission);
        return [$folder,$date,$kind,$scopes,$admission];
    }
    public static function inspectFile(string $name,string $bytes,bool $generated=false):array
    {
        $name=self::text($name,240,true);if(preg_match('/[\\\\\/:\r\n]/',$name)||$name==='.'||$name==='..')throw new DomainException('Ungültiger Dateiname.');
        if($bytes===''||strlen($bytes)>($generated?15000000:10485760))throw new DomainException($generated?'Erzeugtes Dokument zu groß.':'Dateien dürfen höchstens 10 MB groß sein.');
        $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));if(in_array($ext,['heic','heif'],true)){require_once dirname(__DIR__, 1).'/Infrastructure/Media/HeicImage.php';return [$name,HeicImage::mime($bytes),hash('sha256',$bytes)];}$image=@getimagesizefromstring($bytes);$mime=$image['mime']??'';
        if(in_array($mime,['image/png','image/jpeg'],true)){if(!in_array($ext,['png','jpg','jpeg'],true)||$image[0]*$image[1]>24000000)throw new DomainException('Bildformat oder Bildgröße ungültig.');}
        elseif($ext==='pdf'&&str_starts_with($bytes,'%PDF-'))$mime='application/pdf';
        elseif($ext==='xml'){
            // Preserve original invoice XML bytes. Syntax validation is not a tax/schema validation.
            if(!class_exists(\XMLReader::class))throw new DomainException('Die Serverlaufzeit für die XML-Prüfung fehlt.');
            if(!preg_match('//u',$bytes)||str_contains($bytes,"\0")||preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i',$bytes))throw new DomainException('XML muss UTF-8 verwenden und darf keine DTD oder externen Entitäten enthalten.');
            $previous=libxml_use_internal_errors(true);
            $xml=new \XMLReader();
            try{libxml_clear_errors();if(!$xml->XML($bytes,null,LIBXML_NONET))throw new DomainException('XML-Datei ist nicht wohlgeformt.');$element=false;while($xml->read()){if($xml->nodeType===\XMLReader::ELEMENT)$element=true;}if(!$element||libxml_get_errors())throw new DomainException('XML-Datei ist nicht wohlgeformt.');}
            finally{$xml->close();libxml_clear_errors();libxml_use_internal_errors($previous);}
            $mime='application/xml';
        }
        elseif(in_array($ext,['txt','csv','json'],true)&&!str_contains($bytes,"\0")&&preg_match('//u',$bytes))$mime=['txt'=>'text/plain','csv'=>'text/csv','json'=>'application/json'][$ext];
        elseif(in_array($ext,['docx','xlsx','odt','ods','zip'],true)&&str_starts_with($bytes,"PK\x03\x04"))$mime='application/octet-stream';
        else throw new DomainException('Unterstützt: PDF, XML, PNG, JPEG, Text/CSV/JSON sowie DOCX, XLSX, ODT, ODS und ZIP. Dateiendung und Inhalt müssen zusammenpassen.');
        return [$name,$mime,hash('sha256',$bytes)];
    }
    private function putFile(string $id,int $revision,string $name,string $mime,string $bytes,string $sha,array $snapshot):void
    {
        $a=$this->actor();$q=$this->db->prepare('INSERT INTO document_files(club_id,document_id,revision,name,mime,bytes,sha256,created_at,created_by,snapshot) VALUES(?,?,?,?,?,?,?,?,?,?)');
        foreach([$a['club_id'],$id,$revision,$name,$mime] as $i=>$v)$q->bindValue($i+1,$v);$q->bindValue(6,$bytes,PDO::PARAM_LOB);$q->bindValue(7,$sha);$q->bindValue(8,gmdate('Y-m-d\TH:i:s\Z'));$q->bindValue(9,$a['id']);$q->bindValue(10,json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));$q->execute();
    }
    public function upload(string $name,string $bytes,array $data,string $key,?callable $afterStore=null,?CameraDocumentUploads $prepared=null):string
    {
        if($prepared!==null&&($prepared->name!==$name||$prepared->bytes!==$bytes))throw new DomainException('Vorbereitete Datei passt nicht zum Upload.');
        $prepared??=CameraDocumentUploads::fromBytes($name,$bytes);
        return $this->store($prepared->name,$prepared->bytes,$data,$key,false,$prepared->source===null?[]:['camera_upload'=>$prepared->source],null,$afterStore);
    }
    /** The calling domain owns commit/rollback; all normal upload checks still run. */
    public function uploadWithinTransaction(string $name,string $bytes,array $data,string $key,?CameraDocumentUploads $prepared=null):string
    {
        if(!$this->db->inTransaction())throw new DomainException('Gemeinsame Speicherung benötigt einen laufenden Vorgang.');
        $previous=$this->batchUpload;$this->batchUpload=true;
        try{return $this->upload($name,$bytes,$data,$key,null,$prepared);}finally{$this->batchUpload=$previous;}
    }
    public function uploadMany(array $files,array $data,string $key,?callable $beforeStore=null):array
    {
        if(!array_is_list($files)||count($files)<1||count($files)>10||!preg_match('/^[a-f0-9]{32}$/D',$key))throw new DomainException('Eine bis zehn Dateien auswählen.');
        $sourceTotal=0;$total=0;foreach($files as &$file){if(!is_array($file)||!is_string($file['name']??null)||!is_string($file['bytes']??null))throw new DomainException('Dateiliste ungültig.');if(($file['prepared']??null) instanceof CameraDocumentUploads&&($file['prepared']->name!==$file['name']||$file['prepared']->bytes!==$file['bytes']))throw new DomainException('Vorbereitete Datei passt nicht zum Upload.');$prepared=($file['prepared']??null) instanceof CameraDocumentUploads?$file['prepared']:CameraDocumentUploads::fromBytes($file['name'],$file['bytes']);if(($sourceTotal+=$prepared->source['bytes']??strlen($prepared->bytes))>CameraDocumentUploads::BATCH_INPUT_BYTES)throw new DomainException('Kameraoriginale zusammen bis 512 MiB auswählen.');$file['prepared']=$prepared;$file['name']=$prepared->name;$file['bytes']=$prepared->bytes;self::inspectFile($file['name'],$file['bytes']);$total+=strlen($file['bytes']);}unset($file);if($total>20971520)throw new DomainException('Dateien zusammen höchstens 20 MB.');
        return $this->tx(function()use($files,$data,$key,$beforeStore){if($beforeStore)$beforeStore();$this->batchUpload=true;try{$ids=[];foreach($files as $i=>$file){$fields=$data;foreach(['links','folder','scope'] as $assignment)if(array_key_exists($assignment,$file))$fields[$assignment]=$file[$assignment];if(array_key_exists('document_date',$file))$fields['document_date']=$file['document_date'];if(array_key_exists('title',$file)){$fields['title']=self::text($file['title'],300,true);}elseif(count($files)>1||trim($fields['title']??'')==='')$fields['title']=(trim($fields['title']??'')!==''?trim($fields['title']).' · ':'').$file['name'];$ids[]=$this->upload($file['name'],$file['bytes'],$fields,substr(hash('sha256',$key.':'.$i),0,32),null,$file['prepared']);}return $ids;}finally{$this->batchUpload=false;}});
    }
    /** Called only with server-generated bytes from an already authorized export/renderer. */
    public function generated(string $name,string $bytes,array $data,string $key,array $snapshot=[],?callable $beforeStore=null,?callable $afterStore=null):string {return $this->store($name,$bytes,$data,$key,true,$snapshot,$beforeStore,$afterStore);}
    /** A financial derivative retains every current source and ancestor restriction. */
    public function generatedForFinancialOriginal(string $sourceDocument,string $name,string $bytes,array $data,string $key,array $snapshot,?callable $beforeStore=null,?callable $afterStore=null):string
    {
        return $this->tx(function()use($sourceDocument,$name,$bytes,$data,$key,$snapshot,$beforeStore,$afterStore):string{
            try{Permissions::finance($this->db,$this->actorId,true);}catch(DomainException $failure){if($failure->getCode()===503||$failure->getMessage()==='Ein Speichervorgang läuft bereits. Bitte erneut versuchen.')throw $failure;throw new DomainException('Aktuelle Finanzschreibberechtigung erforderlich.',403,$failure);}
            $source=$this->currentFinancialOriginal($sourceDocument,1);
            if($source['trashed']||$source['document']['state']!=='active')throw new DomainException('Ursprüngliches Finanzdokument zuerst wiederherstellen.',409);
            $scopes=$source['document']['scopes'];$admission=(bool)$source['document']['admission_only'];
            foreach($source['folders']as$folder){$scopes[]=$folder['scope'];$admission=$admission||(bool)$folder['admission_only'];}
            $scopes[]='finance';$scopes=array_values(array_unique($scopes));sort($scopes);$this->allow($scopes,false,$admission);
            $data['folder']=$source['document']['folder_id'];$data['scope']='finance';
            return $this->store($name,$bytes,$data,$key,true,$snapshot,$beforeStore,$afterStore,$scopes,$admission);
        });
    }
    private function store(string $name,string $bytes,array $data,string $key,bool $generated,array $snapshot,?callable $beforeStore=null,?callable $afterStore=null,array $inheritedScopes=[],bool $inheritedAdmission=false):string
    {
        [$name,$mime,$sha]=self::inspectFile($name,$bytes,$generated);if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new DomainException('Vorgangskennung ungültig.');
        return $this->tx(function()use($name,$mime,$bytes,$sha,$data,$key,$generated,$snapshot,$beforeStore,$afterStore,$inheritedScopes,$inheritedAdmission){
            $a=$this->actor();try{$hash=hash('sha256',json_encode([$a['id'],$name,$generated?$snapshot:($snapshot===[]?$sha:[$sha,$snapshot]),$data,$generated],JSON_THROW_ON_ERROR));}catch(\JsonException){throw new DomainException('Dokumentangaben sind ungültig oder enthalten ungültigen UTF-8-Text.');}
            $previous=$this->q('SELECT * FROM document_requests WHERE club_id=? AND request_key=?',[$a['club_id'],$key])->fetch();if($previous){if(!hash_equals($previous['request_hash'],$hash))throw new DomainException('Vorgangskennung mit anderen Eingaben bereits verwendet.');$this->document($previous['document_id'],!$generated);return $previous['document_id'];}
            if($beforeStore)$beforeStore();
            if(!$generated&&in_array($data['kind']??'', ['record','export','preview'],true))throw new DomainException('Diese Dokumentart ist für erzeugte Dokumente reserviert.');
            $links=$this->linksFrom($data['links']??[],!$generated);
            if($generated&&($snapshot['kind']??'')==='letter'&&empty($data['folder'])&&!$links){
                $letterDate=self::date($data['document_date']??date('Y-m-d'));
                $this->allow(['orga']);
                $data['folder']=DocumentCatalog::folder($this->db,$a['club_id'],['Orga',substr($letterDate,0,4),'Ausgang - Briefe'],'orga');
            }
            [$folder,$date,$kind,$scopes,$admission]=$this->placement($data,$links,!$generated,$inheritedScopes,$inheritedAdmission);
            if($generated&&($kind==='preview'||($snapshot['kind']??'')==='letter')){$scopes=array_values(array_unique(array_merge($scopes,DocumentProtection::previewScopes($snapshot))));sort($scopes);$this->allow($scopes);}
            $title=self::text($data['title']??$name,300,true);$notes=self::text($data['notes']??'',4000);$id=bin2hex(random_bytes(16));$now=gmdate('Y-m-d\TH:i:s\Z');
            $this->q('INSERT INTO documents(club_id,id,folder_id,title,kind,document_date,scopes,origin,notes,created_at,created_by,admission_only) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$a['club_id'],$id,$folder,$title,$kind,$date,json_encode($scopes,JSON_THROW_ON_ERROR),$generated?'generated':'upload',$notes,$now,$a['id'],(int)$admission]);
            $this->putFile($id,1,$name,$mime,$bytes,$sha,$snapshot);foreach($links as $link)$this->q('INSERT INTO document_links VALUES(?,?,?,?)',[$a['club_id'],$id,$link['source_type'],$link['source_id']]);
            $this->q('INSERT INTO document_requests VALUES(?,?,?,?)',[$a['club_id'],$key,$hash,$id]);$this->audit($id,$generated?'generated':'uploaded',1,['name'=>$name,'sha256'=>$sha,'folder'=>$folder,'links'=>array_map(fn($l)=>$l['source_type'].':'.$l['source_id'],$links),'scopes'=>$scopes]);if($afterStore)$afterStore($id);
            if(!$generated)ManagerNotifications::invoiceReceived($this->db,$a['club_id'],$id);
            return $id;
        });
    }
    public function document(string $id,bool $write=false,bool $includeTrash=false):array
    {
        $r=$this->q("SELECT d.*,f.name,f.mime,f.sha256,f.revision,length(f.bytes) size,CASE WHEN d.origin='generated' AND d.kind='preview' THEN f.snapshot ELSE NULL END protection_snapshot FROM documents d JOIN document_files f ON f.club_id=d.club_id AND f.document_id=d.id AND f.revision=(SELECT MAX(revision) FROM document_files v WHERE v.club_id=d.club_id AND v.document_id=d.id) WHERE d.club_id=? AND d.id=?".($write&&$this->db->inTransaction()?' FOR UPDATE':''),[$this->actor()['club_id'],$id])->fetch();
        if($r){$r['trashed']=$this->q("SELECT action FROM document_history WHERE club_id=? AND document_id=? AND action IN ('trashed','trash-restored') ORDER BY sequence DESC LIMIT 1",[$r['club_id'],$id])->fetchColumn()==='trashed';if($r['trashed']&&!$includeTrash)throw new DomainException('Rechnung wurde gelöscht. Sie kann im Papierkorb wiederhergestellt werden.');}
        if(!$r)throw new DomainException('Dokument nicht verfügbar.');if($write&&$r['origin']==='generated'&&$r['kind']==='certificate'&&$this->q('SELECT 1 FROM donation_receipts WHERE club_id=? AND document_id=?',[$this->actor()['club_id'],$id])->fetchColumn())throw new DomainException('Ausgestellte Bescheinigungen bleiben unverändert. Korrekturen erfolgen durch Storno am Spendenbeleg.');$r['scopes']=json_decode($r['scopes'],true);
        if($r['origin']==='generated'&&$r['kind']==='preview'){$snapshot=json_decode($r['protection_snapshot']??'',true);$r['scopes']=array_values(array_unique(array_merge($r['scopes'],DocumentProtection::previewScopes(is_array($snapshot)?$snapshot:[]))));sort($r['scopes']);}
        unset($r['protection_snapshot']);$this->allow($r['scopes'],$write,(bool)$r['admission_only']);$r['path']=$this->path($r['folder_id'],(bool)$r['admission_only']);$r['links']=$this->links($id);$r['references']=$this->references($r);return $r;
    }
    /** Derived uses stay separate from user-editable document associations. */
    private function references(array $document):array
    {
        if(!$this->canScope('finance')||$document['origin']!=='upload'||$document['mime']!=='application/pdf'||!in_array('finance',$document['scopes'],true))return [];
        $payload=$this->q("SELECT payload FROM club_settings WHERE club_id=? AND section='tax'",[$this->actor()['club_id']])->fetchColumn();
        $tax=$payload?json_decode($payload,true):[];
        if(!is_array($tax)||($tax['notice_document_id']??'')!==$document['id'])return [];
        return [['type'=>'tax_notice','label'=>'Steuereinstellungen · Hinterlegter Steuerbescheid','route'=>['view'=>'settings','section'=>'tax'],'automatic'=>true]];
    }
    public function canEdit(string $id):bool {try{$this->document($id,true);return true;}catch(DomainException){return false;}}
    /** Atomic original-to-expense association, also usable inside Finance::createEntry's transaction. */
    public function linkExpense(string $id,int $version,string $entry,int $entryVersion=0,array $additional=[]):void
    {
        $work=function()use($id,$version,$entry,$entryVersion,$additional){
            $doc=$this->document($id,true);$a=$this->actor();$expense=(new Finance($this->db,$a['id']))->entry($entry);
            $expenseSource=$this->source('entry',$entry,true);
            if($expense['kind']!=='expense'||in_array($expense['payment_state'],['cancelled','reversed'],true))throw new DomainException('Bitte eine aktive Ausgabe auswählen.');
            if($entryVersion&&(int)$expense['version']!==$entryVersion)throw new DomainException('Die Ausgabe wurde inzwischen geändert. Bitte neu öffnen.');
            if($doc['state']!=='active'||$doc['origin']!=='upload'||$doc['kind']!=='receipt')throw new DomainException('Bitte einen aktiven hochgeladenen Eingangsbeleg auswählen.');
            $entries=array_values(array_filter($doc['links'],fn($link)=>$link['source_type']==='entry'));
            if(count($entries)===1&&$entries[0]['source_id']===$entry)return;
            if($entries)throw new DomainException('Die Datei ist bereits einem Finanzbeleg zugeordnet. Bitte die bestehende Zuordnung öffnen.');
            if(count($additional)>51)throw new DomainException('Zu viele Gegenstandszuordnungen.');$purposeSources=[];foreach($additional as $ref){if(!is_array($ref)||!in_array($ref['type']??'', ['item','appointment'],true))throw new DomainException('Rechnungszweck prüfen.');$purposeSources[]=$this->source($ref['type'],(string)($ref['id']??''),true);}
            self::checkVersion($doc,$version);$scopes=array_values(array_unique([...$doc['scopes'],'finance',...array_column($purposeSources,'scope')]));sort($scopes);$this->allow($scopes,true);
            $beforePlacement=['document_date'=>$doc['document_date'],'folder_id'=>$doc['folder_id']];
            $afterPlacement=['document_date'=>$expense['document_date'],'folder_id'=>$doc['folder_id']];
            // Move only the untouched automatic finance intake. A chosen, renamed or
            // relocated folder remains intentional; the finance source still links it.
            $folder=$this->rawFolder($doc['folder_id']);
            if($folder['scope']==='finance'&&preg_match('~^/Finanzen/([0-9]{4})/Eingang - noch zuordnen$~D',$doc['path'],$match)
                &&($folder['system_key']??'')==='auto:'.hash('sha256','finance/Finanzen/'.$match[1].'/Eingang - noch zuordnen'))$afterPlacement['folder_id']=$expenseSource['folder_id'];
            $this->q('INSERT INTO document_links(club_id,document_id,source_type,source_id) VALUES(?,?,?,?)',[$a['club_id'],$id,'entry',$entry]);
            foreach($purposeSources as $source)$this->q('INSERT IGNORE INTO document_links(club_id,document_id,source_type,source_id) VALUES(?,?,?,?)',[$a['club_id'],$id,$source['source_type'],$source['source_id']]);
            $this->q('UPDATE documents SET document_date=?,folder_id=?,scopes=?,version=version+1 WHERE club_id=? AND id=?',[$afterPlacement['document_date'],$afterPlacement['folder_id'],json_encode($scopes,JSON_THROW_ON_ERROR),$a['club_id'],$id]);
            $this->audit($id,'expense-linked',$version+1,['entry_id'=>$entry,'previous_version'=>$version,'scopes'=>$scopes,'purposes'=>$additional,'before'=>$beforePlacement,'after'=>$afterPlacement]);
        };
        if($this->db->inTransaction())$work();else $this->tx($work);
    }
    private function links(string $id):array {return $this->q('SELECT s.* FROM document_links l JOIN document_sources s ON s.club_id=l.club_id AND s.source_type=l.source_type AND s.source_id=l.source_id WHERE l.club_id=? AND l.document_id=? ORDER BY s.label',[$this->actor()['club_id'],$id])->fetchAll();}
    public function file(string $id,int $revision=0):array
    {
        $d=$this->document($id);$revision=$revision?:$d['revision'];$file=$this->q('SELECT * FROM document_files WHERE club_id=? AND document_id=? AND revision=?',[$this->actor()['club_id'],$id,$revision])->fetch();if(!$file)throw new DomainException('Dokumentversion nicht verfügbar.');return $file;
    }
    public function listing(array $filters=[]):array {return $this->readContext(fn()=>$this->listDocuments($filters));}
    /** Small sorted source keys, with canonical checks for generated protected previews. */
    public function libraryIndex(array $filters=[]):array
    {
        return $this->readContext(function()use($filters){
            $a=$this->actor();$term=self::text($filters['q']??'',150);$scope=self::text($filters['scope']??'',20);$folder=self::text($filters['folder']??'',100);
            if($scope!==''&&(!isset(DocumentCatalog::SCOPES[$scope])||!$this->canScope($scope)))throw new DomainException('Diese Dokumentansicht ist nicht verfügbar.');
            if($folder)$this->folder($folder);$type=$filters['source_type']??'';$source=$filters['source_id']??'';if($type)$this->source($type,$source);
            $allowed=[];foreach(array_keys(DocumentCatalog::SCOPES)as$key)if($this->canScope($key))$allowed[]=$key;
            $board=BoardAccess::allowed($this->db,$a['id']);$admission=$board?array_values(array_unique([...$allowed,'members'])):[];
            $normalFolders=$this->readableFolderIds();$admissionFolders=$board?$this->readableFolderIds(true):[];
            if($folder){$base=$this->path($folder);$within=function(bool$protected)use($folder,$base,$filters){return function(string$id)use($folder,$base,$filters,$protected):bool{return$id===$folder||(($filters['recursive']??'')==='1'&&str_starts_with($this->path($id,$protected),$base.'/'));};};$normalFolders=array_values(array_filter($normalFolders,$within(false)));$admissionFolders=array_values(array_filter($admissionFolders,$within(true)));}
            $args=[$a['club_id'],json_encode($allowed,JSON_THROW_ON_ERROR),json_encode($normalFolders,JSON_THROW_ON_ERROR),json_encode($admission,JSON_THROW_ON_ERROR),json_encode($admissionFolders,JSON_THROW_ON_ERROR)];
            $where=" WHERE d.club_id=? AND JSON_TYPE(d.scopes)='ARRAY' AND ((d.admission_only=0 AND JSON_CONTAINS(CAST(? AS JSON),CAST(d.scopes AS JSON)) AND JSON_CONTAINS(CAST(? AS JSON),JSON_QUOTE(d.folder_id))) OR (d.admission_only<>0 AND JSON_CONTAINS(CAST(? AS JSON),CAST(d.scopes AS JSON)) AND JSON_CONTAINS(CAST(? AS JSON),JSON_QUOTE(d.folder_id))))";
            $preview="(BINARY d.origin='generated' AND BINARY d.kind='preview')";
            $trash="COALESCE((SELECT BINARY h.action FROM document_history h WHERE h.club_id=d.club_id AND h.document_id=d.id AND h.action IN ('trashed','trash-restored') ORDER BY h.sequence DESC LIMIT 1),'')='trashed'";
            $state=$filters['state']??'active';$where.=' AND '.($state==='trash'?'('.$trash.')':'NOT ('.$trash.')');
            if(!in_array($state,['all','trash'],true)){$where.=' AND BINARY d.state=?';$args[]=$state;}
            if($term!==''){$where.=" AND instr(lower(CONCAT(d.title,' ',d.notes)),lower(?))>0";$args[]=$term;}
            if($scope!==''){$where.=' AND ('.$preview.' OR JSON_CONTAINS(d.scopes,JSON_QUOTE(?)))';$args[]=$scope;}
            if(($filters['kind']??'')!==''){$where.=' AND BINARY d.kind=?';$args[]=$filters['kind'];}
            if($type){$where.=' AND EXISTS(SELECT 1 FROM document_links l JOIN document_sources s ON s.club_id=l.club_id AND s.source_type=l.source_type AND s.source_id=l.source_id WHERE l.club_id=d.club_id AND l.document_id=d.id AND l.source_type=? AND l.source_id=?)';array_push($args,$type,$source);}
            if(($filters['unassigned']??'')==='1'){
                $where.=" AND BINARY d.origin<>'generated' AND NOT EXISTS(SELECT 1 FROM document_links l JOIN document_sources s ON s.club_id=l.club_id AND s.source_type=l.source_type AND s.source_id=l.source_id WHERE l.club_id=d.club_id AND l.document_id=d.id)";
                if($this->canScope('finance')){$payload=$this->q("SELECT payload FROM club_settings WHERE club_id=? AND section='tax'",[$a['club_id']])->fetchColumn();$tax=$payload?json_decode($payload,true):[];$notice=is_array($tax)?($tax['notice_document_id']??''):'';if(is_string($notice)&&$notice!==''){$where.=" AND NOT (d.id=? AND BINARY d.origin='upload' AND f.mime='application/pdf' AND JSON_CONTAINS(d.scopes,'\"finance\"'))";$args[]=$notice;}}
            }
            $q=$this->q('SELECT d.id,d.document_date,d.created_at,'.$preview.' AS protected_preview FROM documents d JOIN document_files f ON f.club_id=d.club_id AND f.document_id=d.id AND f.revision=(SELECT MAX(v.revision) FROM document_files v WHERE v.club_id=d.club_id AND v.document_id=d.id)'.$where.' ORDER BY d.document_date DESC,d.created_at DESC,d.id',$args);$index=[];
            foreach($q as$row){if($row['protected_preview']){try{$doc=$this->document($row['id'],false,$state==='trash');}catch(DomainException){continue;}if($scope!==''&&!in_array($scope,$doc['scopes'],true))continue;}$index[]=['id'=>$row['id'],'document_date'=>$row['document_date'],'created_at'=>$row['created_at']];}
            return $index;
        });
    }
    /** Permission-filtered views of the same document IDs, originals and version history. */
    public function page(array $filters=[],int $page=1,int $size=25):array
    {
        if($page<1||!in_array($size,[25,50,100],true))throw new DomainException('Ungültige Dokumentseite.');
        return Pagination::slice($this->listing($filters),$page,$size);
    }
    private function listDocuments(array $filters):array
    {
        $q=self::text($filters['q']??'',150);$scope=self::text($filters['scope']??'',20);if($scope!==''&&(!isset(DocumentCatalog::SCOPES[$scope])||!$this->canScope($scope)))throw new DomainException('Diese Dokumentansicht ist nicht verfügbar.');$folder=self::text($filters['folder']??'',100);if($folder)$this->folder($folder);$type=$filters['source_type']??'';$source=$filters['source_id']??'';if($type)$this->source($type,$source);
        $ids=$this->q('SELECT id FROM documents WHERE club_id=? AND (?=\'\' OR instr(lower(CONCAT(title,\' \',notes)),lower(?))>0) ORDER BY document_date DESC,created_at DESC,id',[$this->actor()['club_id'],$q,$q])->fetchAll();$rows=[];
        foreach($ids as $i){try{$r=$this->document($i['id'],false,($filters['state']??'')==='trash');}catch(DomainException){continue;}
            if(($filters['state']??'')==='trash'){if(!$r['trashed'])continue;$r['state']='trash';}
            if(($filters['state']??'active')!=='all'&&$r['state']!==($filters['state']??'active'))continue;
            if($scope!==''&&!in_array($scope,$r['scopes'],true))continue;
            if(($filters['kind']??'')!==''&&$r['kind']!==$filters['kind'])continue;if(($filters['unassigned']??'')==='1'&&($r['links']||$r['references']||$r['origin']==='generated'))continue;
            if($folder){$base=$this->path($folder);if($r['folder_id']!==$folder&&!(($filters['recursive']??'')==='1'&&str_starts_with($r['path'],$base.'/')))continue;}
            if($type&&!array_filter($r['links'],fn($l)=>$l['source_type']===$type&&$l['source_id']===$source))continue;$rows[]=$r;
        }return $rows;
    }
    public function update(string $id,int $version,array $data,?callable $beforeUpdate=null):void
    {
        $this->tx(function()use($id,$version,$data,$beforeUpdate){$a=$this->actor();$old=$this->document($id,true);self::checkVersion($old,$version);if($beforeUpdate)$beforeUpdate($old);$links=$this->linksFrom($data['links']??[],true,$old['links']);[$folder,$date,$kind,$scopes,$admission]=$this->placement($data,$links,true,$old['scopes'],(bool)$old['admission_only']);
            // Permissions can grow with associations, never shrink by unlinking/moving.
            if(($old['origin']==='generated'&&$kind!==$old['kind'])||($old['origin']!=='generated'&&in_array($kind,['record','export','preview'],true)))throw new DomainException('Die Art eines erzeugten Nachweises darf nicht durch eine andere Dokumentart ersetzt werden.');
            $title=self::text($data['title']??'',300,true);$notes=self::text($data['notes']??'',4000);$state=$data['state']??'active';if(!in_array($state,['active','archived'],true))throw new DomainException('Ungültiger Dokumentstatus.');$reason=self::text($data['reason']??'',1000);
            $linkKeys=static function(array $rows):array{$keys=array_map(static fn(array $row):string=>$row['source_type'].':'.$row['source_id'],$rows);sort($keys);return$keys;};
            if($reason===''){
                if($state!==$old['state']||$kind!==$old['kind']||$linkKeys($links)!==$linkKeys($old['links']))throw new DomainException('Bitte die Änderung von Status, Dokumentart oder Zuordnung kurz begründen.');
                $reason='Dokumentangaben geändert';
            }
            $this->q('UPDATE documents SET folder_id=?,title=?,kind=?,document_date=?,scopes=?,state=?,notes=?,admission_only=?,version=version+1 WHERE club_id=? AND id=?',[$folder,$title,$kind,$date,json_encode($scopes,JSON_THROW_ON_ERROR),$state,$notes,(int)$admission,$a['club_id'],$id]);$this->q('DELETE FROM document_links WHERE club_id=? AND document_id=?',[$a['club_id'],$id]);foreach($links as $l)$this->q('INSERT INTO document_links VALUES(?,?,?,?)',[$a['club_id'],$id,$l['source_type'],$l['source_id']]);
            $this->audit($id,'metadata',$version+1,['reason'=>$reason,'before'=>array_intersect_key($old,array_flip(['folder_id','title','kind','document_date','scopes','state','notes','links'])),'after'=>$data,'scopes'=>$scopes]);
        });
    }
    public function newVersion(string $id,int $version,string $name,string $bytes,string $reason,?CameraDocumentUploads $prepared=null):void
    {
        if($prepared!==null&&($prepared->name!==$name||$prepared->bytes!==$bytes))throw new DomainException('Vorbereitete Datei passt nicht zum Upload.');
        $prepared??=CameraDocumentUploads::fromBytes($name,$bytes);$name=$prepared->name;$bytes=$prepared->bytes;$snapshot=$prepared->source===null?[]:['camera_upload'=>$prepared->source];[$name,$mime,$sha]=self::inspectFile($name,$bytes);$reason=self::text($reason,1000,true);$this->tx(function()use($id,$version,$name,$mime,$bytes,$sha,$reason,$snapshot){$a=$this->actor();$d=$this->document($id,true);self::checkVersion($d,$version);if($d['origin']==='generated')throw new DomainException('Erzeugte Nachweise und Exporte bleiben unverändert. Bitte einen neuen Nachweis erstellen oder eine getrennte Datei hochladen.');if($d['state']!=='active')throw new DomainException('Archivierte Dokumente zuerst wieder aktivieren.');$this->putFile($id,$d['revision']+1,$name,$mime,$bytes,$sha,$snapshot);$this->q('UPDATE documents SET version=version+1 WHERE club_id=? AND id=?',[$a['club_id'],$id]);$this->audit($id,'version',$version+1,['reason'=>$reason,'revision'=>$d['revision']+1,'sha256'=>$sha]);});
    }
    public function versions(string $id):array {$this->document($id);return $this->q('SELECT revision,name,mime,sha256,length(bytes) size,created_at,created_by FROM document_files WHERE club_id=? AND document_id=? ORDER BY revision DESC',[$this->actor()['club_id'],$id])->fetchAll();}
    public function history(string $id):array {
        $this->document($id);$rows=$this->q('SELECT * FROM document_history WHERE club_id=? AND document_id=? ORDER BY sequence DESC',[$this->actor()['club_id'],$id])->fetchAll();
        // Recipient decisions remain a board-only record even for ordinary original-file readers.
        if(!BoardAccess::allowed($this->db,$this->actorId)){foreach($rows as&$row)if($row['action']==='sharing')$row['details']='{}';unset($row);}
        return $rows;
    }
}
