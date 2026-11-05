<?php declare(strict_types=1);
namespace Kulturhus\Native\System;
use Kulturhus\Native\Integration\{ManagerBridge,ManagerClub};

/** Dedicated local-maintenance CLI. Its configuration cannot select a provider or business mode. */
final class Worker
{
    public static function run(string $file):array
    {
        if(PHP_SAPI!=='cli'||basename($file)!=='worker.php')throw new \DomainException('Ursprüngliche private Worker-Konfiguration erforderlich.');$private=new PrivateFiles(dirname($file));if($private->file('worker.php')!==$file)throw new \DomainException('Ursprünglichen Workerpfad prüfen.');
        $lock=$private->root.'/operations.lock';if(is_link($lock))throw new \DomainException('Wartungssperre prüfen.');if(file_exists($lock))$private->file('operations.lock');$mask=umask(0077);try{$handle=fopen($lock,'c+');}finally{umask($mask);}if(!$handle)throw new \DomainException('Wartungssperre nicht verfügbar.');
        try{
            $stat=fstat($handle);if(!$stat||($stat['mode']&0170000)!==0100000||($stat['mode']&0077)||(function_exists('posix_geteuid')&&$stat['uid']!==posix_geteuid()))throw new \DomainException('Eigene private Wartungssperre prüfen.');
            if(!flock($handle,LOCK_SH|LOCK_NB)||$private->maintenance())return ['status'=>'maintenance','runs'=>0,'completed'=>0,'failed'=>0];
            $config=require $file;if(!is_array($config)||!is_string($config['dsn']??null)||!str_starts_with($config['dsn'],'mysql:')||!is_string($config['club_id']??null)||$config['club_id']==='')throw new \DomainException('Private ursprüngliche MySQL-/Vereinskonfiguration prüfen.');
            if(isset($config['environment_file'])){if($config['environment_file']!==$private->root.'/environment.json')throw new \DomainException('Feste private Umgebung erforderlich.');$environment=$private->json('environment.json');if(($environment['VEREIN_CLUB']??null)!==$config['club_id'])throw new \DomainException('Systemumgebung gehört nicht zum ursprünglichen Verein.');}
            if(($config['auth_key_file']??null)!==$private->root.'/auth.key'||($config['manager_key_file']??null)!==$private->root.'/private.key')throw new \DomainException('Ursprüngliche private Schlüssel verwenden.');foreach(['auth.key','private.key']as$name)if(filesize($private->file($name))!==32)throw new \DomainException('Ursprüngliche vollständige private Schlüssel erforderlich.');putenv('KH_NATIVE_AUTH_KEY_FILE='.$config['auth_key_file']);putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$config['manager_key_file']);
            $db=new \PDO($config['dsn'],$config['username']??'',$config['password']??'',[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC,\PDO::ATTR_EMULATE_PREPARES=>false]);$nativeClub=ManagerBridge::clubKey($config['club_id']);if(ManagerClub::id($db,$nativeClub)!==$config['club_id'])throw new \DomainException('Ursprünglicher Systemverein fehlt.');
            return (new Runner($db,$nativeClub,$private))->tick();
        }finally{flock($handle,LOCK_UN);fclose($handle);}
    }
}
