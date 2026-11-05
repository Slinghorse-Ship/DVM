<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;
use PDO;
use Throwable;
use Kulturhus\Native\Shared\{Access,Db};

/** One deliberate club policy change and its personal immutable receipt commit together. */
final class VideoPolicySaveRequests
{
    private const DEFINITIONS=[
        'quota'=>['module'=>'video-quota','history'=>'native_video_quota_history','column'=>'limit_bytes','factor'=>1048576,'max'=>VideoQuota::MAX_MIB,'digits'=>6],
        'retention'=>['module'=>'video-retain','history'=>'native_video_retention_history','column'=>'minimum_days','factor'=>1,'max'=>VideoRetention::MAX_DAYS,'digits'=>4],
    ];
    private array $definition;
    public function __construct(private PDO $db,private Access $access,private string $mediaRoot,private string $kind)
    {
        $this->definition=self::DEFINITIONS[$kind]??throw new DomainException('Videospeicherregel erneut öffnen.');
    }
    private function actor(bool $write=false):array
    {
        $actor=$this->access->require('social','read');if($write)$this->access->require('social','manage');return$actor;
    }
    private function sameActor(array $actor,bool $write=false):void
    {
        $current=$this->actor($write);if($current['id']!==$actor['id']||$current['club_id']!==$actor['club_id'])throw new DomainException('Speicherzugang geändert.');
    }
    private function context(string $item,string $key):void
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key)||!EditionSaveRequests::installed($this->db))throw new DomainException('Ursprüngliches Videospeicherformular öffnen.');
        if($this->db->inTransaction())throw new DomainException('Videospeicherregel braucht einen eigenen Speichervorgang.');Content::id($item);(new Workspace($this->db,$this->access,'social',$this->mediaRoot))->get($item);
    }
    private function row(array $actor,string $key):array|false
    {
        return Db::run($this->db,'SELECT * FROM native_communication_save_requests WHERE club_id=? AND module=? AND actor_id=? AND request_key=?',[$actor['club_id'],$this->definition['module'],$actor['id'],$key])->fetch();
    }
    private function lock(array $actor,string $key):string
    {
        $lock='video-policy:'.substr(hash('sha256',json_encode([$this->db->query('SELECT DATABASE()')->fetchColumn(),$this->kind,$actor['club_id'],$actor['id'],$key],JSON_THROW_ON_ERROR)),0,48);
        if((int)Db::run($this->db,'SELECT GET_LOCK(?,2)',[$lock])->fetchColumn()!==1)throw new DomainException('Videospeicherregel wird noch gespeichert. Gespeicherte Regel prüfen.');return$lock;
    }
    private function original(array $row,string $item):array
    {
        if($row['item_id']!==$item||!hash_equals($row['snapshot_sha256'],hash('sha256',$row['snapshot_json'])))throw new DomainException('Gespeicherten Videospeichernachweis prüfen.');
        try{$snapshot=json_decode($row['snapshot_json'],true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new DomainException('Gespeicherten Videospeichernachweis prüfen.');}$history=$snapshot['history']??null;
        if(($snapshot['kind']??null)!==$this->kind||($snapshot['module']??null)!==$this->definition['module']||!is_array($history)||($history['club_id']??null)!==$row['club_id']||($history['actor_id']??null)!==$row['actor_id']||($history['version']??null)!==(int)$row['item_version'])throw new DomainException('Gespeicherten Videospeichernachweis prüfen.');
        $actual=Db::run($this->db,'SELECT * FROM '.$this->definition['history'].' WHERE club_id=? AND version=?',[$row['club_id'],$history['version']])->fetch();if($actual!==$history)throw new DomainException('Gespeicherten Videospeichernachweis prüfen.');
        $raw=$history[$this->definition['column']];if(!is_int($raw)||$raw<0||$raw%$this->definition['factor']!==0||intdiv($raw,$this->definition['factor'])>$this->definition['max'])throw new DomainException('Gespeicherten Videospeichernachweis prüfen.');
        return['kind'=>$this->kind,'request_key'=>$row['request_key'],'item_id'=>$item,'version'=>$history['version'],'value'=>intdiv($raw,$this->definition['factor'])];
    }
    public function review(string $item,string $key):?array
    {
        $actor=$this->actor();$this->context($item,$key);$lock=$this->lock($actor,$key);
        try{$row=$this->row($actor,$key);$result=$row?$this->original($row,$item):null;$this->sameActor($actor);return$result;}finally{Db::run($this->db,'SELECT RELEASE_LOCK(?)',[$lock])->fetchColumn();}
    }
    public function save(string $item,mixed $value,int $version,string $key):array
    {
        $actor=$this->actor(true);$this->context($item,$key);$digits=$this->definition['digits']-1;
        if((!is_int($value)&&!is_string($value))||!preg_match('/\A(?:0|[1-9][0-9]{0,'.$digits.'})\z/D',(string)$value)||(int)$value>$this->definition['max']||$version<0||$version>=4294967295)throw new DomainException($this->kind==='quota'?'Videospeichergrenze als ganze MiB zwischen 0 und 102400 angeben.':'Videoaufbewahrung zwischen 0 und 3650 Tagen angeben.');
        $value=(int)$value;$hash=hash('sha256',json_encode([$this->kind,$item,$version,$value],JSON_THROW_ON_ERROR));$lock=$this->lock($actor,$key);
        try{
            $this->sameActor($actor,true);$row=$this->row($actor,$key);
            if($row){if(!hash_equals($row['input_sha256'],$hash))throw new DomainException('Diese Kennung gehört zur ursprünglichen Videospeicherregel. Gespeicherte Regel prüfen.');$result=$this->original($row,$item);$this->sameActor($actor,true);return$result;}
            try{
                $service=$this->kind==='quota'?new VideoQuota($this->db,$this->access):new VideoRetention($this->db,$this->access);
                $service->save($value,$version,function(array $saved)use($actor,$item,$key,$hash):void{
                    if(!$this->db->inTransaction())throw new \LogicException('Aktive Videospeichertransaktion fehlt.');$this->sameActor($actor,true);
                    $history=Db::run($this->db,'SELECT * FROM '.$this->definition['history'].' WHERE club_id=? AND version=?',[$actor['club_id'],$saved['version']])->fetch();if(!$history)throw new DomainException('Gespeicherten Videospeichernachweis prüfen.');
                    $snapshot=json_encode(['kind'=>$this->kind,'module'=>$this->definition['module'],'history'=>$history],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
                    Db::run($this->db,'INSERT INTO native_communication_save_requests(club_id,module,actor_id,request_key,input_sha256,item_id,item_version,snapshot_json,snapshot_sha256,created_at) VALUES(?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$actor['club_id'],$this->definition['module'],$actor['id'],$key,$hash,$item,$saved['version'],$snapshot,hash('sha256',$snapshot)]);
                });
                $row=$this->row($actor,$key);if(!$row)throw new DomainException('Gespeicherten Videospeichernachweis prüfen.');$result=$this->original($row,$item);$this->sameActor($actor,true);return$result;
            }catch(Throwable $error){if(!$this->db->inTransaction()){$this->sameActor($actor,true);$row=$this->row($actor,$key);if($row&&hash_equals($row['input_sha256'],$hash)){$result=$this->original($row,$item);$this->sameActor($actor,true);return$result;}}throw$error;}
        }finally{Db::run($this->db,'SELECT RELEASE_LOCK(?)',[$lock])->fetchColumn();}
    }
}
