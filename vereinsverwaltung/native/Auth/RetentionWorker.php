<?php declare(strict_types=1);
namespace Kulturhus\Native\Auth;

use Kulturhus\Native\Shared\{Db,OperationGate};

/** Host-operator authority is possession of the current owned private deployment, never a club role. */
final class RetentionWorker
{
    public static function run(string $file,bool $instanceScope):array
    {
        if(PHP_SAPI!=='cli'||!$instanceScope||!function_exists('posix_geteuid')||basename($file)!=='worker.php')throw new \DomainException('Private Instanzbereinigung ausdrücklich mit --instance-scope anfordern.');
        $private=dirname($file);
        if(!str_starts_with($private,'/')||realpath($private)!==$private||is_link($private)||!is_dir($private)||(fileperms($private)&0077)||fileowner($private)!==posix_geteuid())throw new \DomainException('Ursprüngliche eigene private Instanz erforderlich.');
        $gate=OperationGate::shared($private);
        try{
            self::holds($private);$originals=[];
            foreach(['worker.php','environment.json','auth.key','private.key']as$name)$originals[$name]=self::original($private.'/'.$name);
            $config=require $file;$environment=json_decode((string)file_get_contents($private.'/environment.json'),true,64,JSON_THROW_ON_ERROR);
            if(!is_array($config)||!is_array($environment)||($config['environment_file']??null)!==$private.'/environment.json'||($config['auth_key_file']??null)!==$private.'/auth.key'||($config['manager_key_file']??null)!==$private.'/private.key'||($config['key_file']??null)!==$private.'/private.key'||($environment['VEREIN_PRIVATE_ROOT']??null)!==$private||($environment['VEREIN_CLUB']??null)!==($config['club_id']??null)||!is_string($config['club_id']??null)||$config['club_id']==='')throw new \DomainException('Ursprüngliche Instanzkonfiguration und Schlüsselbindungen prüfen.');
            foreach(['dsn'=>'VEREIN_DATABASE','username'=>'VEREIN_DATABASE_USER','password'=>'VEREIN_DATABASE_PASSWORD']as$key=>$name)if(!is_string($config[$key]??null)||!is_string($environment[$name]??null)||!hash_equals($environment[$name],$config[$key]))throw new \DomainException('Ursprüngliche private Datenbankverbindung verwenden.');
            if(!str_starts_with($config['dsn'],'mysql:')||!preg_match('/(?:\Amysql:|;)dbname=([a-zA-Z0-9_]+)(?:;|\z)/D',$config['dsn'],$database))throw new \DomainException('Ursprüngliche MySQL-Instanz erforderlich.');
            foreach(['auth.key','private.key']as$name)if(filesize($private.'/'.$name)!==32)throw new \DomainException('Vollständige ursprüngliche Instanzschlüssel erforderlich.');
            $db=new \PDO($config['dsn'],$config['username'],$config['password'],[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC,\PDO::ATTR_EMULATE_PREPARES=>false]);
            if($db->query('SELECT DATABASE()')->fetchColumn()!==$database[1])throw new \DomainException('Ursprüngliche Datenbankzuordnung prüfen.');
            $assert=static function()use($private,$originals):void{self::holds($private);foreach($originals as$name=>$hash)if(!hash_equals($hash,self::original($private.'/'.$name)))throw new \DomainException('Instanzautorität während der Bereinigung verändert.');};
            $assert();$cutoff=time()-Retention::GRACE_SECONDS;
            $count=Db::atomic($db,static function()use($db,$cutoff,$assert):int{
                $assert();$ids=Db::run($db,'SELECT scope_hash FROM native_auth_limits WHERE expires_at<=? ORDER BY expires_at,scope_hash LIMIT 100 FOR UPDATE',[$cutoff])->fetchAll(\PDO::FETCH_COLUMN);
                if(!$ids)return 0;$assert();$marks=implode(',',array_fill(0,count($ids),'?'));$deleted=Db::run($db,'DELETE FROM native_auth_limits WHERE expires_at<=? AND scope_hash IN ('.$marks.')',[$cutoff,...$ids])->rowCount();$assert();return $deleted;
            });
            return ['scope'=>'instance','expired_limits'=>$count,'maximum'=>100,'external_actions'=>0];
        }finally{$gate->close();}
    }
    private static function holds(string $private):void
    {foreach(['upgrade-hold','restore-hold','recovery-hold']as$name)if(file_exists($private.'/'.$name)||is_link($private.'/'.$name))throw new \DomainException('Instanzbereinigung während einer Wartung gesperrt.');}
    private static function original(string $file):string
    {
        clearstatcache(true,$file);
        if(is_link($file)||!is_file($file)||(fileperms($file)&0077)||fileowner($file)!==posix_geteuid()||filesize($file)>1048576)throw new \DomainException('Aktuelles eigenes privates Instanzoriginal erforderlich.');
        $hash=hash_file('sha256',$file);if(!is_string($hash))throw new \DomainException('Privates Instanzoriginal nicht verfügbar.');return $hash;
    }
}
