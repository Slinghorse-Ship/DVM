<?php declare(strict_types=1);
namespace Kulturhus\Native\Demo;

/** Only the private server-side entry chooses a demo database and storage root. */
final class Configuration
{
    public const ROLES=['members','treasurer','editor','member'];

    public static function read(string $path): array
    {
        self::privateFile($path);
        $data=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new \DomainException('Private Demo-Konfiguration ungültig.');
        $data=self::validate($data);
        if($path!==$data['private_root'].'/demo.json')throw new \DomainException('Demo-Konfiguration gehört nicht zum privaten Verzeichnis.');
        return $data;
    }

    public static function validate(#[\SensitiveParameter] array $data): array
    {
        if(($data['format']??null)!==1||!preg_match('/\A[a-f0-9]{24}\z/D',$data['instance']??''))throw new \DomainException('Eindeutige Demo-Instanz erforderlich.');
        $origin=$data['origin']??'';$url=is_string($origin)?parse_url($origin):false;
        if(!$url||($url['scheme']??'')!=='https'||!isset($url['host'])||count(array_diff(array_keys($url),['scheme','host']))||filter_var($url['host'],FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)===false||!str_contains($url['host'],'.')||filter_var($url['host'],FILTER_VALIDATE_IP)!==false||$origin!=='https://'.strtolower($url['host']))throw new \DomainException('Feste HTTPS-Domain ohne Pfad oder Zugangsdaten erforderlich.');
        $root=$data['private_root']??'';self::absolute($root);
        if(!is_dir($root)||is_link($root)||(fileperms($root)&0077)!==0||realpath($root)!==$root)throw new \DomainException('Eigener privater Demoordner mit Modus 0700 erforderlich.');
        $db=$data['database']??null;
        if(!is_array($db)||!is_string($db['host']??null)||!preg_match('/\A[A-Za-z0-9.-]+\z/D',$db['host'])||!is_int($db['port']??null)||$db['port']<1||$db['port']>65535||!preg_match('/\A(?:[A-Za-z0-9_]+_)?(?:dvm_demo_[a-f0-9]{12}|dvmdemo)\z/D',$db['name']??'')||strlen($db['name'])>64||!is_string($db['username']??null)||$db['username']===''||!is_string($db['password']??null)||$db['password']==='')throw new \DomainException('Eigene Demo-MySQL-Verbindung erforderlich.');
        foreach(['username','password'] as $key)if(str_contains($db[$key],"\0"))throw new \DomainException('Ungültige Demo-Zugangskonfiguration.');
        return array_intersect_key($data,array_flip(['format','instance','origin','private_root','database']));
    }

    public static function connect(#[\SensitiveParameter] array $data): \PDO
    {
        $data=self::validate($data);$db=$data['database'];
        $pdo=new \PDO('mysql:host='.$db['host'].';port='.$db['port'].';dbname='.$db['name'].';charset=utf8mb4',$db['username'],$db['password'],[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC,\PDO::ATTR_EMULATE_PREPARES=>false]);
        self::restrictedDatabase($pdo,$db['name']);
        return $pdo;
    }

    public static function restrictedDatabase(\PDO $db,string $name): void
    {
        if((string)$db->query('SELECT DATABASE()')->fetchColumn()!==$name)throw new \DomainException('Demo-Datenbank stimmt nicht überein.');
        $partial=(int)$db->query('SELECT @@GLOBAL.partial_revokes')->fetchColumn();
        $expected=$partial?$name:strtr($name,['_'=>'\\_','%'=>'\\%','\\'=>'\\\\']);
        $scoped=false;
        foreach($db->query('SHOW GRANTS')->fetchAll(\PDO::FETCH_COLUMN) as $grant){
            if(preg_match('/\bWITH GRANT OPTION\b/i',$grant))throw new \DomainException('Demo-Benutzer darf keine Rechte weitergeben.');
            if(preg_match('/\AGRANT USAGE ON \*\.\* TO /D',$grant))continue;
            if(!preg_match('/\AGRANT ([A-Z ,]+) ON `((?:[^`]|``)+)`\.\* TO /D',$grant,$match)||str_replace('``','`',$match[2])!==$expected)throw new \DomainException('Demo-Benutzer darf ausschließlich die eigene Demo-Datenbank erreichen.');
            $scoped=true;
        }
        if(!$scoped)throw new \DomainException('Eigene Demo-Datenbankrechte fehlen.');
    }

    public static function absolute(string $path): void
    {
        if($path===''||$path==='/'||!str_starts_with($path,'/')||str_ends_with($path,'/')||str_contains($path,'\\')||preg_match('~[\x00-\x1f]|(?:^|/)\.\.?(/|$)|//~',$path))throw new \DomainException('Absoluter privater Demopfad erforderlich.');
        for($part=$path;$part!=='/';$part=dirname($part))if(is_link($part))throw new \DomainException('Symbolische Links im Demopfad sind nicht erlaubt.');
    }

    public static function privateFile(string $path): void
    {
        self::absolute($path);
        if(!is_file($path)||is_link($path)||(fileperms($path)&0077)!==0)throw new \DomainException('Eigene private Demodatei mit Modus 0600 erforderlich.');
    }

    public static function create(string $path,#[\SensitiveParameter] string $bytes): void
    {
        self::absolute($path);$mask=umask(0077);
        try{$file=fopen($path,'xb');}finally{umask($mask);}
        if(!$file)throw new \DomainException('Demo-Datei bereits belegt.');
        try{if(fwrite($file,$bytes)!==strlen($bytes)||!fflush($file)||!fsync($file))throw new \RuntimeException('Demo-Datei nicht vollständig gespeichert.');}finally{fclose($file);}
    }
}
