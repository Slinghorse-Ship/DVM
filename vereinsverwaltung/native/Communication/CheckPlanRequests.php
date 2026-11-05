<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;
use PDO;
use Throwable;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Integration\ManagerAccess;

/** Original schedule changes share the existing settings receipt store; reads never run a check. */
final class CheckPlanRequests
{
    public function __construct(private PDO $db,private Access $access){}
    private static function json(array $data):string{return json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
    public static function capture(array $input):array
    {
        $channel=$input['channel']??null;if(!is_string($channel)||!in_array($channel,['meta','stadtlandfunk'],true))throw new DomainException('Abrufkanal prüfen.');
        $keys=['actor_id','club_id','authority','channel','version','enabled',...$channel==='meta'?['interval_seconds']:['day_start','day_end']];
        $actual=array_keys($input);$expected=$keys;sort($actual);sort($expected);if($actual!==$expected)throw new DomainException('Ursprüngliche Abrufplaneingaben vollständig prüfen.');
        $out=[];foreach($keys as$key){$value=$input[$key];if(!is_string($value)||strlen($value)>200||preg_match('//u',$value)!==1||preg_match('/[\x00-\x1f\x7f]/',$value))throw new DomainException('Ursprüngliche Abrufplaneingaben prüfen.');$out[$key]=$value;}
        if($out['actor_id']===''||$out['club_id']===''||strlen($out['actor_id'])>128||strlen($out['club_id'])>128||!in_array($out['authority'],['native','manager'],true))throw new DomainException('Person oder Vereinszuordnung geändert.');
        if(!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$out['version'])||(int)$out['version']>2147483646)throw new DomainException('Ursprünglichen Abrufplanstand prüfen.');
        foreach(['enabled']as$key)if(!in_array($out[$key],['0','1'],true))throw new DomainException('Abrufplanauswahl prüfen.');return$out;
    }
    private static function normalized(array $input):array
    {return CheckSchedule::config($input['channel'],$input);}
    private static function section(string $channel):string
    {if(!in_array($channel,['meta','stadtlandfunk'],true))throw new DomainException('Abrufkanal prüfen.');return'social-check:'.$channel;}
    private static function key(string $key):void
    {if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Ursprünglichen Abrufplanvorgang auswählen.');}
    private function own():void
    {if($this->db->inTransaction())throw new DomainException('Abrufplanvorgang benötigt eine eigene aktuelle Verbindung.');}
    private function available():void
    {if(!Db::run($this->db,"SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='native_settings_requests'")->fetchColumn())throw new DomainException('Abrufplanbestätigungen benötigen das aktuelle installierte Paket.');}
    private function actor():array
    {$reader=$this->access->require('social','read');$manager=$this->access->require('social','manage');if($reader['id']!==$manager['id']||$reader['club_id']!==$manager['club_id'])throw new DomainException('Person oder Vereinszuordnung geändert.');return$manager;}
    private function same(array $actor):void
    {$now=$this->actor();if($now['id']!==$actor['id']||$now['club_id']!==$actor['club_id'])throw new DomainException('Person oder Vereinszuordnung geändert.');}
    private function pin(array $input,array $actor):void
    {if($input['actor_id']!==$actor['id']||$input['club_id']!==$actor['club_id']||$input['authority']!==$this->authority())throw new DomainException('Person oder Vereinszuordnung geändert.');}
    private function authority():string{return$this->access instanceof ManagerAccess?'manager':'native';}
    public function context():array
    {$actor=$this->actor();return['actor_id'=>$actor['id'],'club_id'=>$actor['club_id'],'authority'=>$this->authority()];}
    public function assertContext(array $input):void
    {$this->pin(self::capture($input),$this->actor());}
    public function history(string $channel,int $page=1):array
    {
        $this->own();$actor=$this->actor();$this->available();if($page<1||$page>1000000)throw new DomainException('Abrufplanseite prüfen.');
        $where=" WHERE club_id=? AND actor_id=? AND section=? AND JSON_UNQUOTE(JSON_EXTRACT(original_json,'$.authority'))=?";$args=[$actor['club_id'],$actor['id'],self::section($channel),$this->authority()];
        $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_settings_requests'.$where,$args)->fetchColumn();$pages=max(1,(int)ceil($total/6));if($page>$pages)throw new DomainException('Abrufplanseite prüfen.');
        $rows=Db::run($this->db,'SELECT request_key,version,created_at FROM native_settings_requests'.$where.' ORDER BY created_at DESC,request_key DESC LIMIT 6 OFFSET '.(($page-1)*6),$args)->fetchAll();$this->same($actor);return compact('rows','total','page','pages');
    }
    private function row(array $actor,string $channel,string $key,bool $lock=false):array|false
    {return Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND actor_id=? AND section=? AND request_key=?'.($lock?' FOR SHARE NOWAIT':''),[$actor['club_id'],$actor['id'],self::section($channel),$key])->fetch();}
    /** MySQL JSON objects may reorder keys; numeric values and window positions remain exact. */
    private static function sameConfig(mixed $actual,array $expected):bool
    {
        if(!is_array($actual)||count($actual)!==count($expected))return false;
        foreach($expected as$key=>$value){if(!array_key_exists($key,$actual))return false;if(is_array($value)){if(!self::sameConfig($actual[$key],$value))return false;}elseif($actual[$key]!==$value)return false;}
        return true;
    }
    private function decode(array $row):array
    {
        $input=json_decode($row['input_json'],true,32,JSON_THROW_ON_ERROR);$original=json_decode($row['original_json'],true,32,JSON_THROW_ON_ERROR);
        if(!is_array($input)||!is_array($original))throw new DomainException('Ursprüngliche Abrufplanbestätigung prüfen lassen.');$input=self::capture($input);$result=$original['result']??null;
        if(self::section($input['channel'])!==$row['section']||!hash_equals($row['input_sha256'],hash('sha256',$row['section']."\0".$row['input_json']))||!hash_equals($row['original_sha256'],hash('sha256',$row['original_json']))||($original['format']??null)!=='dvm-social-check-plan-original/1'||($original['request_key']??null)!==$row['request_key']||($original['club_id']??null)!==$row['club_id']||($original['actor_id']??null)!==$row['actor_id']||($original['section']??null)!==$row['section']||($original['input']??null)!==$input||$input['actor_id']!==$row['actor_id']||$input['club_id']!==$row['club_id']||$input['authority']!==($original['authority']??null)||!in_array($original['authority']??null,['native','manager'],true)||($original['approved']??null)!==true||!is_array($result)||($result['version']??null)!==(int)$row['version']||$result['version']!==(int)$input['version']+1||($result['enabled']??null)!==($input['enabled']==='1')||!self::sameConfig($result['config']??null,self::normalized($input))||($result['state']??null)!==($result['enabled']?'scheduled':'paused')||($original['created_at']??null)!==(new \DateTimeImmutable($row['created_at'],new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'))throw new DomainException('Ursprüngliche Abrufplanbestätigung prüfen lassen.');
        return['found'=>true,'key'=>$row['request_key'],'channel'=>$input['channel'],'input'=>$input,'result'=>$result,'authority'=>$original['authority'],'created_at'=>$original['created_at'],'sha256'=>$row['original_sha256']];
    }
    /** Absence is only an observation, never proof that a writer failed. */
    public function review(string $channel,string $key):array
    {
        $this->own();self::section($channel);self::key($key);$actor=$this->actor();$this->available();$row=$this->row($actor,$channel,$key);$result=$row?$this->decode($row):['found'=>false];if($row&&$result['authority']!==$this->authority())throw new DomainException('Ursprüngliche Abrufplanbestätigung nicht verfügbar.');$this->same($actor);return$result;
    }
    private function bound(array $original,array $input):array
    {if($original['input']!==$input||$original['authority']!==$this->authority())throw new DomainException('Abrufplankennung gehört zu anderen ursprünglichen Angaben.');return$original;}
    public function check(string $key,array $input):array
    {$input=self::capture($input);$this->pin($input,$this->actor());$result=$this->review($input['channel'],$key);return$result['found']?$this->bound($result,$input):$result;}
    public function save(string $key,array $input):array
    {
        $this->own();self::key($key);$input=self::capture($input);self::normalized($input);$actor=$this->actor();$this->pin($input,$actor);$this->available();$known=$this->review($input['channel'],$key);if($known['found'])return$this->bound($known,$input);
        $lock='dvm-social-plan-'.substr(hash('sha256',$actor['club_id']."\0".$key),0,40);if((int)Db::run($this->db,'SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1)throw new DomainException('Abrufplanvorgang läuft möglicherweise noch. Ursprünglichen Vorgang prüfen.');
        try{
            try{$result=Db::atomic($this->db,function()use($actor,$input,$key):array{
                $this->same($actor);$row=$this->row($actor,$input['channel'],$key,true);if($row)return$this->bound($this->decode($row),$input);
                if(Db::run($this->db,'SELECT 1 FROM native_settings_requests WHERE club_id=? AND request_key=? FOR SHARE NOWAIT',[$actor['club_id'],$key])->fetchColumn())throw new DomainException('Abrufplankennung gehört zu anderen ursprünglichen Angaben.');
                $now=new \DateTimeImmutable('now',new \DateTimeZone('UTC'));$plans=new CheckPlans($this->db);$config=$input;$plans->save($this->access,$input['channel'],$config,$input['enabled']==='1',(int)$input['version'],$now);$result=$plans->status($this->access,$input['channel']);
                $section=self::section($input['channel']);$json=self::json($input);$original=self::json(['format'=>'dvm-social-check-plan-original/1','request_key'=>$key,'club_id'=>$actor['club_id'],'actor_id'=>$actor['id'],'authority'=>$this->authority(),'section'=>$section,'input'=>$input,'approved'=>true,'result'=>$result,'created_at'=>$now->format('Y-m-d\TH:i:s.u\Z')]);
                Db::run($this->db,'INSERT INTO native_settings_requests VALUES(?,?,?,?,?,?,?,?,?,?)',[$actor['club_id'],$key,$actor['id'],$section,$result['version'],hash('sha256',$section."\0".$json),$json,hash('sha256',$original),$original,$now->format('Y-m-d H:i:s.u')]);$this->same($actor);return$this->decode($this->row($actor,$input['channel'],$key,true));
            });if($this->db->inTransaction()){$this->db->rollBack();throw new DomainException('Abrufplan nicht bestätigt. Ursprünglichen Vorgang prüfen.');}$known=$this->review($input['channel'],$key);if(!$known['found'])throw new DomainException('Abrufplan nicht bestätigt. Ursprünglichen Vorgang prüfen.');return$this->bound($known,$input);
            }catch(Throwable $failure){if(!$this->db->inTransaction()){$this->same($actor);$known=$this->review($input['channel'],$key);if($known['found'])return$this->bound($known,$input);}throw$failure;}
        }finally{try{Db::run($this->db,'SELECT RELEASE_LOCK(?)',[$lock]);}catch(Throwable){/* Connection closure also releases the owned advisory lock. */}}
    }
    /** Explicit current preparation never writes a plan or a receipt. */
    public function prepare(string $key,array $input):array
    {
        $this->own();$input=self::capture($input);self::normalized($input);$actor=$this->actor();$this->pin($input,$actor);$known=$this->review($input['channel'],$key);
        if($known['found']){
            if($input===$known['input'])return['original'=>$this->bound($known,$input),'prepared'=>null];
            if($input['version']!==$known['input']['version'])throw new DomainException('Abrufplankennung gehört zu anderen ursprünglichen Angaben.');
        }
        $originalInput=$input;$current=(new CheckPlans($this->db))->status($this->access,$input['channel']);$input['version']=(string)$current['version'];$input=self::capture($input);$this->same($actor);$latest=$this->review($input['channel'],$key);
        if($latest['found']&&!$known['found'])return['original'=>$this->bound($latest,$originalInput),'prepared'=>null];
        if($known['found']&&(!$latest['found']||$latest['sha256']!==$known['sha256']))throw new DomainException('Ursprüngliche Abrufplanbestätigung nicht verfügbar.');
        return['original'=>$known,'prepared'=>['key'=>bin2hex(random_bytes(16)),'input'=>$input]];
    }
}
