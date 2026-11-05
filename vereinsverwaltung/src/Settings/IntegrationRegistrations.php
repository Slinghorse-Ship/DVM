<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;

/** Technical registration metadata only; never changes credentials or activates transport. */
final class IntegrationRegistrations
{
    public function __construct(private PDO $db,private string $actor,private ?array $paths=null){}
    private function access():array
    {
        $a=Permissions::finance($this->db,$this->actor,true);
        if(empty($a['is_superuser'])||empty($a['settings_access'])||(ProductionRuntime::enabled()&&$a['club_id']!==ProductionRuntime::club()))throw new DomainException('Nur die technische Vereinsverwaltung darf Schnittstellenkennungen bearbeiten.');return $a;
    }
    public function canManage():bool{try{$this->access();return true;}catch(DomainException){return false;}}
    private function path(string $provider):string
    {
        $path=match($provider){'elster'=>$this->paths['elster']??TaxEricAdapter::runtimePath(),'fints'=>$this->paths['fints']??EnableBanking::root().'/fints.json',default=>throw new DomainException('Unbekannte Schnittstelle.')};
        if(!is_string($path)||!str_starts_with($path,'/')||preg_match('~(?:^|/)\.\.?(/|$)|[\\\\\x00-\x1f]~',$path))throw new DomainException('Privates Schnittstellenverzeichnis zuerst einrichten.');
        for($p=$path;$p!==dirname($p);$p=dirname($p))if(is_link($p))throw new DomainException('Schnittstellenkonfiguration darf keine Verknüpfung sein.');
        $web=realpath(($_SERVER['DOCUMENT_ROOT']??'')?:dirname(__DIR__,2).'/public');if($web&&($path===$web||str_starts_with($path,$web.'/')))throw new DomainException('Schnittstellenkennungen müssen außerhalb des Webroots liegen.');return $path;
    }
    private function read(string $path,array $actor):array
    {
        if(!file_exists($path))return ['bytes'=>'','data'=>[],'revision'=>hash('sha256','')];
        if(!is_file($path)||filesize($path)>2000000||(PHP_OS_FAMILY!=='Windows'&&(fileperms($path)&0077)))throw new DomainException('Private Schnittstellenkonfiguration ist nicht lesbar.');
        $bytes=(string)file_get_contents($path);try{$data=json_decode($bytes,true,64,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new DomainException('Vorhandene Schnittstellenkonfiguration ist ungültig.');}
        if(!is_array($data))throw new DomainException('Vorhandene Schnittstellenkonfiguration ist ungültig.');
        if(isset($data['club_id'])&&$data['club_id']!==$actor['club_id'])throw new DomainException('Schnittstellenkonfiguration gehört zu einem anderen Verein.');
        if($data!==[]&&array_is_list($data))throw new DomainException('Vorhandene Schnittstellenkonfiguration ist ungültig.');
        return ['bytes'=>$bytes,'data'=>$data,'revision'=>hash('sha256',$bytes)];
    }
    public function state(string $provider):array
    {
        $a=$this->access();$r=$this->read($this->path($provider),$a);$key=$provider==='elster'?'manufacturer_id':'product_id';$value=$r['data'][$key]??'';if(!is_string($value))throw new DomainException('Gespeicherte Schnittstellenkennung ist ungültig.');return ['provider'=>$provider,'value'=>$value,'revision'=>$r['revision']];
    }
    private static function directory(string $path):void
    {
        if(!is_dir($path)&&!mkdir($path,0700,true)&&!is_dir($path))throw new DomainException('Privates Schnittstellenverzeichnis konnte nicht erstellt werden.');
        if(PHP_OS_FAMILY!=='Windows'&&(fileperms($path)&0077))throw new DomainException('Schnittstellenverzeichnis benötigt private Dateirechte.');
    }
    private static function write(string $path,string $bytes):void
    {
        $f=@fopen($path,'xb');if(!$f)throw new DomainException('Schnittstellenkonfiguration konnte nicht gespeichert werden.');$complete=false;
        try{if(!chmod($path,0600))throw new DomainException('Private Dateirechte konnten nicht gesetzt werden.');$offset=0;while($offset<strlen($bytes)){$n=fwrite($f,substr($bytes,$offset));if(!$n)throw new DomainException('Schnittstellenkonfiguration konnte nicht vollständig gespeichert werden.');$offset+=$n;}if(!fflush($f)||(function_exists('fsync')&&!fsync($f)))throw new DomainException('Schnittstellenkonfiguration konnte nicht gesichert werden.');$complete=true;}finally{fclose($f);if(!$complete)unlink($path);}
    }
    public function save(string $provider,string $value,string $revision,bool $confirmed):array
    {
        $a=$this->access();$path=$this->path($provider);$value=trim($value);
        if(!$confirmed)throw new DomainException('Eigene Schnittstellenkennung vor dem Speichern bestätigen.');
        if(!preg_match($provider==='elster'?'/^[0-9]{5}$/D':'/^[A-Za-z0-9]{25}$/D',$value))throw new DomainException($provider==='elster'?'ELSTER-Hersteller-ID mit genau fünf Ziffern angeben.':'FinTS-Registrierungsnummer mit genau 25 Buchstaben oder Ziffern angeben.');
        if($this->db->inTransaction())throw new DomainException('Schnittstellenkennung außerhalb einer Buchung speichern.');self::directory(dirname($path));$lock=$path.'.registration.lock';if(is_link($lock))throw new DomainException('Schnittstellenkonfiguration ist gesperrt.');$handle=fopen($lock,'c+');if(!$handle)throw new DomainException('Schnittstellenkonfiguration ist gesperrt.');chmod($lock,0600);
        try{
            if(!flock($handle,LOCK_EX|LOCK_NB))throw new DomainException('Schnittstellenkonfiguration wird gerade bearbeitet.');$this->db->beginTransaction();
            try{
                $a=$this->access();$path=$this->path($provider);$old=$this->read($path,$a);$key=$provider==='elster'?'manufacturer_id':'product_id';
                if(!hash_equals($old['revision'],$revision))throw new DomainException('Schnittstellenkonfiguration wurde inzwischen geändert. Bitte neu laden.');
                if(($old['data'][$key]??'')===$value)return $this->state($provider);
                if($provider==='fints'&&(glob(dirname($path).'/dialog-*.enc')||$this->runningBankJob($a['club_id'])))throw new DomainException('Laufenden FinTS-Bankdialog zuerst beenden oder abbrechen.');
                $next=$old['data'];if(!$next&&$provider==='elster')$next=['version'=>1,'profiles'=>[],'production_enabled'=>false,'production_versions'=>[],'transport_disabled'=>true];$next[$key]=$value;$next['club_id']=$a['club_id'];
                $history=$path.'.registration-history';if(is_link($history))throw new DomainException('Schnittstellenverlauf ist nicht verfügbar.');self::directory($history);
                if($old['bytes']!==''){$backup=$history.'/'.$old['revision'].'.json';if(file_exists($backup)){if(is_link($backup)||!is_file($backup)||hash_file('sha256',$backup)!==$old['revision'])throw new DomainException('Ursprünglicher Schnittstellenverlauf weicht ab.');}else self::write($backup,$old['bytes']);}
                $bytes=json_encode($next,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$change=$history.'/'.bin2hex(random_bytes(16)).'.change.json';self::write($change,json_encode(['previous'=>$old['revision'],'next'=>hash('sha256',$bytes),'actor'=>$a['id'],'club_id'=>$a['club_id'],'created_at'=>gmdate('c')],JSON_THROW_ON_ERROR));$temp=$path.'.'.bin2hex(random_bytes(16)).'.tmp';self::write($temp,$bytes);
                try{if(!rename($temp,$path))throw new DomainException('Schnittstellenkennung konnte nicht aktiviert werden.');}finally{if(is_file($temp))unlink($temp);}
                return $this->state($provider);
            }finally{if($this->db->inTransaction())$this->db->rollBack();}
        }finally{flock($handle,LOCK_UN);fclose($handle);}
    }
    private function runningBankJob(string $club):bool
    {
        $q=$this->db->prepare('SELECT 1 FROM fints_sync_runs WHERE club_id=? AND finished_at IS NULL LIMIT 1');$q->execute([$club]);return (bool)$q->fetchColumn();
    }
}
