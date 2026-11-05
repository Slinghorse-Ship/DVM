<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;
use PDO;
use Throwable;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Integration\ManagerAccess;

/** An exact event selection and its personal immutable draft receipt commit together. */
final class EventDraftRequests
{
    public function __construct(private PDO $db,private Access $access,private string $mediaRoot,private string $module='social')
    {if(!in_array($module,['social','press'],true))throw new DomainException('Veranstaltungsziel prüfen.');}
    private function purpose():string{return 'event-'.$this->module;}
    private function authority():string{return $this->access instanceof ManagerAccess?'manager':'native';}
    private static function json(array $data):string{return json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
    public static function capture(array $input):array
    {
        $fields=['actor_id','club_id','authority','module','source','event_id','revision','internal_confirm','locale'];foreach(['format','label']as$optional)if(array_key_exists($optional,$input))$fields[]=$optional;$keys=array_keys($input);$expected=$fields;sort($keys);sort($expected);
        if($keys!==$expected)throw new DomainException('Ursprüngliche Veranstaltungsauswahl vollständig prüfen.');
        $out=[];foreach($fields as$field){$value=$input[$field];if(!is_string($value)||$value===''||strlen($value)>200||preg_match('//u',$value)!==1||preg_match('/[\x00-\x1f\x7f]/',$value))throw new DomainException('Ursprüngliche Veranstaltungsauswahl prüfen.');$out[$field]=$value;}
        if(!in_array($out['module'],['social','press'],true)||!in_array($out['source'],['website','manager'],true)||!in_array($out['authority'],['native','manager'],true)||!in_array($out['internal_confirm'],['0','1'],true)||!in_array($out['locale'],['de','en'],true)||strlen($out['actor_id'])>64||strlen($out['club_id'])>64)throw new DomainException('Ursprünglichen Veranstaltungszugang prüfen.');
        if(isset($out['format'])&&(!in_array($out['format'],['post','story','reel'],true)||($out['module']==='press'&&$out['format']!=='post')))throw new DomainException('Veranstaltungsformat prüfen.');
        if(isset($out['label']))Content::text($out['label'],100);
        Content::id($out['event_id']);
        if(!preg_match('/\A[1-9][0-9]{0,9}\z/D',$out['revision'])||(int)$out['revision']>=4294967295)throw new DomainException('Ursprüngliche Veranstaltungsfassung prüfen.');
        if($out['source']==='website'&&$out['internal_confirm']!=='0')throw new DomainException('Ursprüngliche Veranstaltungsbestätigung prüfen.');
        return $out;
    }
    private function actor(bool $write=false):array
    {$actor=$this->access->require($this->module,'read');if($write){$edit=$this->access->require($this->module,'edit');if($edit['id']!==$actor['id']||$edit['club_id']!==$actor['club_id'])throw new DomainException('Person oder Vereinszuordnung geändert.');}return$actor;}
    public function context():array
    {$actor=$this->actor();return['actor_id'=>$actor['id'],'club_id'=>$actor['club_id'],'authority'=>$this->authority(),'module'=>$this->module];}
    private function same(array $actor,bool $write=false):void
    {$current=$this->actor($write);if($current['id']!==$actor['id']||$current['club_id']!==$actor['club_id'])throw new DomainException('Person oder Vereinszuordnung geändert.');}
    private function pin(array $input,array $actor):void
    {if($input['actor_id']!==$actor['id']||$input['club_id']!==$actor['club_id']||$input['module']!==$this->module||$input['authority']!==$this->authority())throw new DomainException('Person oder Vereinszuordnung geändert.');}
    private function ready(string $key):void
    {
        if($this->db->inTransaction())throw new DomainException('Veranstaltungsübernahme benötigt einen eigenen Speichervorgang.');
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key)||!EditionSaveRequests::installed($this->db)||(new EventDrafts($this->db,$this->access,$this->module))->installed()!==true)throw new DomainException('Ursprüngliches Veranstaltungsformular öffnen.');
    }
    private function row(array $actor,string $key):array|false
    {return Db::run($this->db,'SELECT * FROM native_communication_save_requests WHERE club_id=? AND module=? AND actor_id=? AND request_key=?',[$actor['club_id'],$this->purpose(),$actor['id'],$key])->fetch();}
    private function inputHash(array $input):string{return hash('sha256',$this->purpose()."\0".self::json($input));}
    private function decode(array $row):array
    {
        if(!hash_equals($row['snapshot_sha256'],hash('sha256',$row['snapshot_json'])))throw new DomainException('Ursprüngliche Veranstaltungsübernahme prüfen lassen.');
        $s=json_decode($row['snapshot_json'],true,64,JSON_THROW_ON_ERROR);$input=self::capture($s['input']??[]);
        if(($s['format']??null)!=='dvm-event-draft-original/1'||($s['module']??null)!==$this->purpose()||($s['request_key']??null)!==$row['request_key']||$input['module']!==$this->module||$input['actor_id']!==$row['actor_id']||$input['club_id']!==$row['club_id']||!hash_equals($row['input_sha256'],$this->inputHash($input))||($s['item_id']??null)!==$row['item_id']||($s['version']??null)!==(int)$row['item_version']||$s['version']!==1||($s['approved']??null)!==true||($s['created_at']??null)!==(new \DateTimeImmutable($row['created_at'],new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'))throw new DomainException('Ursprüngliche Veranstaltungsübernahme prüfen lassen.');
        $workspace=new Workspace($this->db,$this->access,$this->module,$this->mediaRoot);$draft=$workspace->revision($row['item_id'],1);$origin=(new EventDrafts($this->db,$this->access,$this->module))->origin($row['item_id']);
        if(($s['snapshot']??null)!==$draft||($s['origin']??null)!==$origin||$origin===null||$origin['page_id']!==$input['event_id']||$origin['revision']!==(int)$input['revision']||$origin['source_kind']!==$input['source'])throw new DomainException('Ursprüngliche Veranstaltungsübernahme prüfen lassen.');
        return ['found'=>true,'key'=>$row['request_key'],'input'=>$input,'id'=>$row['item_id'],'version'=>1,'snapshot'=>$draft,'origin'=>$origin,'created_at'=>$s['created_at'],'sha256'=>$row['snapshot_sha256']];
    }
    public function review(string $key):array
    {
        $this->ready($key);$actor=$this->actor();$row=$this->row($actor,$key);$result=$row?$this->decode($row):['found'=>false];
        if($row&&$result['input']['authority']!==$this->authority())throw new DomainException('Ursprüngliche Veranstaltungsübernahme nicht verfügbar.');$this->same($actor);return$result;
    }
    private function bound(array $original,array $input):array
    {if($original['input']!==$input)throw new DomainException('Veranstaltungskennung gehört zu einer anderen ursprünglichen Auswahl.');return$original;}
    public function check(string $key,array $input):array
    {$input=self::capture($input);$this->pin($input,$this->actor());$known=$this->review($key);return$known['found']?$this->bound($known,$input):$known;}
    public function history(int $page=1):array
    {
        if($this->db->inTransaction())throw new DomainException('Veranstaltungsübernahme benötigt einen eigenen Speichervorgang.');
        $actor=$this->actor();if($page<1||$page>1000000||!EditionSaveRequests::installed($this->db))throw new DomainException('Veranstaltungsseite prüfen.');
        $where=" WHERE club_id=? AND module=? AND actor_id=? AND JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.input.authority'))=? AND JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.format'))='dvm-event-draft-original/1'";$args=[$actor['club_id'],$this->purpose(),$actor['id'],$this->authority()];
        $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_communication_save_requests'.$where,$args)->fetchColumn();$pages=max(1,(int)ceil($total/6));if($page>$pages)throw new DomainException('Veranstaltungsseite prüfen.');
        $rows=Db::run($this->db,'SELECT request_key,item_id,created_at,JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,\'$.snapshot.title\')) AS title FROM native_communication_save_requests'.$where.' ORDER BY created_at DESC,request_key DESC LIMIT 6 OFFSET '.(($page-1)*6),$args)->fetchAll();$this->same($actor);return compact('rows','total','pages','page');
    }
    public function assertContext(array $input):void
    {$this->pin(self::capture($input),$this->actor());}
    public function another(string $key,array $input):array
    {
        $input=self::capture($input);$actor=$this->actor(true);$this->pin($input,$actor);$known=$this->check($key,$input);
        if(!$known['found'])throw new DomainException('Zuerst den ursprünglichen Vorgang prüfen. Ein weiterer Entwurf benötigt eine bestätigte Übernahme.');
        $this->same($actor,true);return ['key'=>bin2hex(random_bytes(16)),'input'=>$input,'previous'=>$known];
    }
    public function save(string $key,array $input):array
    {
        $this->ready($key);$input=self::capture($input);$actor=$this->actor(true);$this->pin($input,$actor);$known=$this->review($key);if($known['found'])return$this->bound($known,$input);
        $lock='event-draft-'.substr(hash('sha256',self::json([$this->db->query('SELECT DATABASE()')->fetchColumn(),$this->purpose(),$actor['club_id'],$actor['id'],$key])),0,48);
        if((int)Db::run($this->db,'SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1)throw new DomainException('Veranstaltungsübernahme läuft möglicherweise noch. Ursprünglichen Vorgang prüfen.');
        try{
            try{
                Db::atomic($this->db,function()use($key,$input,$actor):void{
                    $this->same($actor,true);$row=$this->row($actor,$key);if($row){$this->bound($this->decode($row),$input);return;}
                    $service=new EventDrafts($this->db,$this->access,$this->module);$draft=$service->create($input['event_id'],(int)$input['revision'],$input['source'],$input['internal_confirm']==='1',$input['locale'],$input['format']??'post',$input['label']??'');
                    $snapshot=(new Workspace($this->db,$this->access,$this->module,$this->mediaRoot))->revision($draft['id'],1);$origin=$service->origin($draft['id']);$now=new \DateTimeImmutable('now',new \DateTimeZone('UTC'));
                    $json=self::json(['format'=>'dvm-event-draft-original/1','module'=>$this->purpose(),'request_key'=>$key,'input'=>$input,'approved'=>true,'item_id'=>$draft['id'],'version'=>1,'snapshot'=>$snapshot,'origin'=>$origin,'created_at'=>$now->format('Y-m-d\TH:i:s.u\Z')]);
                    Db::run($this->db,'INSERT INTO native_communication_save_requests(club_id,module,actor_id,request_key,input_sha256,item_id,item_version,snapshot_json,snapshot_sha256,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[$actor['club_id'],$this->purpose(),$actor['id'],$key,$this->inputHash($input),$draft['id'],1,$json,hash('sha256',$json),$now->format('Y-m-d H:i:s.u')]);$this->decode($this->row($actor,$key));$this->same($actor,true);
                });
                if($this->db->inTransaction()){$this->db->rollBack();throw new DomainException('Veranstaltungsübernahme nicht bestätigt. Ursprünglichen Vorgang prüfen.');}
                $known=$this->review($key);if(!$known['found'])throw new DomainException('Veranstaltungsübernahme nicht bestätigt. Ursprünglichen Vorgang prüfen.');return$this->bound($known,$input);
            }catch(Throwable $error){if(!$this->db->inTransaction()){$this->same($actor,true);$known=$this->review($key);if($known['found'])return$this->bound($known,$input);}throw$error;}
        }finally{try{Db::run($this->db,'SELECT RELEASE_LOCK(?)',[$lock]);}catch(Throwable){}}
    }
}
