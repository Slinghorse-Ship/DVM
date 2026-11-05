<?php declare(strict_types=1);
namespace Kulturhus\Native\Demo;

/** Independent operator credentials and atomic enable state in private storage. */
final class Operator
{
    public function __construct(private array $configuration){$this->configuration=Configuration::validate($configuration);}

    public function initialize(#[\SensitiveParameter] string $password): void
    {
        if(PHP_SAPI!=='cli'||strlen($password)<16||strlen($password)>72)throw new \DomainException('Privates Betreiberpasswort mit 16 bis 72 Bytes erforderlich.');
        Configuration::create($this->path('operator.json'),json_encode(['format'=>1,'instance'=>$this->configuration['instance'],'password'=>password_hash($password,PASSWORD_DEFAULT),'version'=>bin2hex(random_bytes(16)),'enabled'=>false,'epoch'=>0,'attempts'=>[]],JSON_THROW_ON_ERROR)."\n");
    }

    public function status(): array
    {
        return $this->locked(static fn(array &$state):array=>['enabled'=>$state['enabled'],'epoch'=>$state['epoch']]);
    }

    /** This session record stays in the separate server-side operator session. */
    public function login(#[\SensitiveParameter] string $password,string $client,?int $now=null): array
    {
        $now??=time();$key=hash('sha256',$client);
        $result=$this->locked(static function(array &$state)use($password,$key,$now):?array{
            $state['attempts']=array_filter($state['attempts'],static fn(array $attempt):bool=>$attempt['since']>$now-300);
            if(($state['attempts'][$key]['count']??0)>=5)return null;
            if(!password_verify($password,$state['password'])){
                if(count($state['attempts'])>=128&&!isset($state['attempts'][$key])){uasort($state['attempts'],static fn($a,$b)=>$a['since']<=>$b['since']);array_shift($state['attempts']);}
                $attempt=$state['attempts'][$key]??['since'=>$now,'count'=>0];$attempt['count']++;$state['attempts'][$key]=$attempt;return null;
            }
            unset($state['attempts'][$key]);
            return ['version'=>$state['version'],'expires'=>$now+900,'id'=>bin2hex(random_bytes(32))];
        });
        if($result===null)throw new \DomainException('Betreiberanmeldung nicht bestätigt. Später erneut versuchen.');
        return $result;
    }

    public function authorized(array $session,?int $now=null): bool
    {
        $now??=time();return $this->locked(static fn(array &$state):bool=>self::accepts($state,$session,$now));
    }

    public function enable(bool $enabled,array $session,?int $now=null): array
    {
        $now??=time();return $this->locked(static function(array &$state)use($enabled,$session,$now):array{
            if(!self::accepts($state,$session,$now))throw new \DomainException('Aktuelle Betreiberanmeldung erforderlich.');
            if($state['enabled']!==$enabled){$state['enabled']=$enabled;if(!$enabled)$state['epoch']++;}
            return ['enabled'=>$state['enabled'],'epoch'=>$state['epoch']];
        });
    }

    public function reset(#[\SensitiveParameter] string $password): void
    {
        if(PHP_SAPI!=='cli'||strlen($password)<16||strlen($password)>72)throw new \DomainException('Privater serverseitiger Betreiberreset erforderlich.');
        $this->locked(static function(array &$state)use($password):void{$state['password']=password_hash($password,PASSWORD_DEFAULT);$state['version']=bin2hex(random_bytes(16));$state['attempts']=[];});
    }

    public function enableFromCli(bool $enabled):array
    {
        if(PHP_SAPI!=='cli')throw new \DomainException('Privater serverseitiger Betreiberschalter erforderlich.');
        return $this->locked(static function(array &$state)use($enabled):array{if($state['enabled']!==$enabled){$state['enabled']=$enabled;if(!$enabled)$state['epoch']++;}return ['enabled'=>$state['enabled'],'epoch'=>$state['epoch']];});
    }

    private static function accepts(array $state,array $session,int $now): bool
    {
        return is_string($session['version']??null)&&hash_equals($state['version'],$session['version'])&&is_int($session['expires']??null)&&$session['expires']>$now&&$session['expires']<=$now+900&&is_string($session['id']??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$session['id'])===1;
    }

    private function path(string $name): string{return $this->configuration['private_root'].'/'.$name;}

    private function locked(callable $operation): mixed
    {
        $path=$this->path('operator.json');Configuration::privateFile($path);
        $lockPath=$this->path('operator.lock');
        if(!file_exists($lockPath)){try{Configuration::create($lockPath,'');}catch(\DomainException){if(!is_file($lockPath))throw new \DomainException('Betreibersperre nicht verfügbar.');}}
        Configuration::privateFile($lockPath);$lock=fopen($lockPath,'r+');
        if(!$lock||!flock($lock,LOCK_EX))throw new \DomainException('Betreiberstatus nicht verfügbar.');
        try{
            $bytes=(string)file_get_contents($path);$state=json_decode($bytes,true,32,JSON_THROW_ON_ERROR);
            if(!is_array($state)||($state['format']??null)!==1||($state['instance']??null)!==$this->configuration['instance']||!is_bool($state['enabled']??null)||!is_int($state['epoch']??null)||$state['epoch']<0||!is_array($state['attempts']??null)||!is_string($state['version']??null)||!is_string($state['password']??null))throw new \DomainException('Privater Betreiberstatus gehört nicht zur Demo.');
            $result=$operation($state);$next=json_encode($state,JSON_THROW_ON_ERROR)."\n";
            if($bytes!==$next){
                $temporary=$this->path('operator-'.bin2hex(random_bytes(16)).'.tmp');Configuration::create($temporary,$next);
                try{if(!rename($temporary,$path))throw new \RuntimeException('Betreiberstatus nicht bestätigt.');}finally{if(is_file($temporary))unlink($temporary);}
            }
            return $result;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}
