<?php declare(strict_types=1);
namespace Kulturhus\Native\Status;
use Kulturhus\Native\Shared\Db;
/** Dedicated status-only job, using the installation's existing private worker configuration. */
final class Worker
{
    public static function run(string $file):array {
        if(PHP_SAPI!=='cli'||!str_starts_with($file,'/')||is_link($file)||!is_file($file)||(fileperms($file)&0077))throw new \DomainException('Private Statuskonfiguration erforderlich.');
        $private=dirname($file);$path=$private.'/operations.lock';
        if(is_link($path))throw new \DomainException('Private Wartungssperre prüfen.');
        $mask=umask(0077);try{$gate=@fopen($path,'c+');}finally{umask($mask);}
        if(!$gate)throw new \DomainException('Wartungssperre nicht verfügbar.');
        try{
            if(!flock($gate,LOCK_SH|LOCK_NB)||is_file($private.'/upgrade-hold')||is_file($private.'/restore-hold'))return ['status'=>'maintenance','checked'=>0];
            return self::execute($file);
        }finally{flock($gate,LOCK_UN);fclose($gate);}
    }
    private static function execute(string $file):array {
        $config=require $file;if(!is_array($config)||!is_string($config['dsn']??null)||!str_starts_with($config['dsn'],'mysql:')||!is_string($config['club_id']??null)||$config['club_id']==='')throw new \DomainException('Private Vereinskonfiguration prüfen.');
        $environment=[];
        if(isset($config['environment_file'])){$path=$config['environment_file'];if(!is_string($path)||!str_starts_with($path,'/')||is_link($path)||!is_file($path)||(fileperms($path)&0077))throw new \DomainException('Private Umgebung prüfen.');$environment=json_decode((string)file_get_contents($path),true,64,JSON_THROW_ON_ERROR);if(!is_array($environment)||($environment['VEREIN_CLUB']??null)!==$config['club_id'])throw new \DomainException('Umgebung gehört nicht zum Verein.');}
        foreach($environment as$name=>$value)if($name!=='KH_NATIVE_CI'&&(preg_match('/^(VEREIN_|KH_NATIVE_|KULTURHUS_)/D',(string)$name)||in_array($name,['APP_URL','APP_SECRET'],true))&&is_string($value)&&!str_contains($value,"\0"))putenv($name.'='.$value);
        $key=$config['key_file']??'';if(!is_string($key))throw new \DomainException('Privaten Schlüssel prüfen.');putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$key);
        $db=new \PDO($config['dsn'],$config['username']??'',$config['password']??'',[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC,\PDO::ATTR_EMULATE_PREPARES=>false]);
        $row=Db::run($db,'SELECT automatic_enabled,updated_by FROM native_status_settings WHERE club_id=?',[$config['club_id']])->fetch();if(!$row||!$row['automatic_enabled'])return ['status'=>'disabled','checked'=>0];
        $lock='dvm-status-worker-'.substr(hash('sha256',$config['club_id']),0,40);if((int)Db::run($db,'SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1)return ['status'=>'busy','checked'=>0];
        try{
            $actor=\Kulturhus\Verein\Permissions::actor($db,$row['updated_by']);if($actor['club_id']!==$config['club_id'])throw new \DomainException('Status-Verantwortung geändert.');
            $monitor=Factory::environment($db,$row['updated_by'])->monitor();$states=[];
            foreach($monitor->snapshot()as$service)if($service['can_check']){if(in_array($service['reason'],['authorization_required','provider_request_rejected','reauthentication_required','account_mismatch','smtp_authentication_required','imap_authentication_required'],true))continue;$current=Db::run($db,'SELECT automatic_enabled,updated_by FROM native_status_settings WHERE club_id=?',[$config['club_id']])->fetch();if(!$current||!$current['automatic_enabled']||$current['updated_by']!==$row['updated_by'])break;$states[]=$monitor->check($service['id']);}
            return ['status'=>'completed','checked'=>count($states),'services'=>array_map(static fn($row)=>['id'=>$row['id'],'state'=>$row['state']],$states)];
        }finally{Db::run($db,'SELECT RELEASE_LOCK(?)',[$lock]);}
    }
}
