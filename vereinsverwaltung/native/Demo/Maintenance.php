<?php declare(strict_types=1);
namespace Kulturhus\Native\Demo;

/** Optional short-lived CLI schema connection. No admin binding is persisted or used by HTTP. */
final class Maintenance extends \PDO
{
    private string $definer;
    public function __construct(#[\SensitiveParameter] array $configuration,#[\SensitiveParameter] array $credentials)
    {
        if(PHP_SAPI!=='cli')throw new \DomainException('Private einmalige Demo-Schemavorbereitung erforderlich.');
        $configuration=Configuration::validate($configuration);$target=$configuration['database'];
        if(!is_string($credentials['username']??null)||!is_string($credentials['password']??null))throw new \DomainException('Temporären MySQL-Einrichtungszugang privat übergeben.');
        parent::__construct('mysql:host='.$target['host'].';port='.$target['port'].';dbname='.$target['name'].';charset=utf8mb4',$credentials['username'],$credentials['password'],[self::ATTR_ERRMODE=>self::ERRMODE_EXCEPTION,self::ATTR_DEFAULT_FETCH_MODE=>self::FETCH_ASSOC,self::ATTR_EMULATE_PREPARES=>false]);
        if($this->query('SELECT DATABASE()')->fetchColumn()!==$target['name'])throw new \DomainException('Einrichtungszugang gehört nicht zur eigenen Demo-Datenbank.');
        $runtime=Configuration::connect($configuration);$identity=(string)$runtime->query('SELECT CURRENT_USER()')->fetchColumn();
        if(!preg_match('/\A([^@]+)@([^@]+)\z/D',$identity,$match))throw new \DomainException('Eindeutiger eingeschränkter Demo-Datenbankbenutzer erforderlich.');
        $this->definer=$this->quote($match[1]).'@'.$this->quote($match[2]);
    }
    public function exec(string $statement):int|false
    {
        $this->guard($statement);
        if(preg_match('/\ACREATE\s+TRIGGER\b/i',ltrim($statement)))$statement=preg_replace('/\ACREATE\s+TRIGGER\b/i','CREATE DEFINER='.$this->definer.' TRIGGER',ltrim($statement),1);
        return parent::exec($statement);
    }
    public function prepare(string $query,array $options=[]):\PDOStatement|false{$this->guard($query);return parent::prepare($query,$options);}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):\PDOStatement|false
    {
        $this->guard($query);
        return $fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$fetchModeArgs);
    }
    private function guard(string $sql):void
    {
        if(preg_match('/\b(?:sql_log_bin|log_bin_trust_function_creators|DEFINER)\b|\bSET\s+(?:GLOBAL|PERSIST)\b/i',$sql)&&!preg_match('/\A\s*(?:SELECT|SHOW)\b/i',$sql))throw new \DomainException('Demo-Schemavorbereitung verändert keine Serverkontrollen oder Benutzerrechte.');
        // Normal migrations can contain comments and several statements. Accept
        // those batches only as exact bytes of a shipped SQL migration, never
        // as caller-supplied multi-statement SQL.
        static $packaged=null;
        if($packaged===null){$packaged=[];foreach(glob(dirname(__DIR__,2).'/migrations/mysql/[0-9][0-9][0-9]_*.sql') as $file)$packaged[hash_file('sha256',$file)]=true;}
        if(isset($packaged[hash('sha256',$sql)]))return;
        if(preg_match('/\A\s*(?:SELECT|SHOW)\b/i',$sql))return;
        if(!preg_match('/\A\s*(?:CREATE\s+(?:TABLE|TRIGGER|(?:UNIQUE\s+)?INDEX)\b|ALTER\s+TABLE\b|DROP\s+(?:TABLE|TRIGGER|INDEX)\b|INSERT\s+(?:IGNORE\s+)?INTO\b|UPDATE\b|DELETE\s+FROM\b|SET\s+FOREIGN_KEY_CHECKS\s*=\s*[01]\s*;?\s*\z)/i',$sql)
            ||preg_match('/\b(?:sql_log_bin|log_bin_trust_function_creators|DEFINER)\b|@@\s*(?:GLOBAL|PERSIST)\b/i',$sql))throw new \DomainException('Demo-Schemavorbereitung verändert keine Serverkontrollen oder Benutzerrechte.');
        if(preg_match('/\b(?:BEFORE|AFTER)\s+(?:INSERT|UPDATE|DELETE)\s+ON\s+(?:`[^`]+`|[A-Za-z0-9_]+)\s*\./i',$sql)||preg_match('/\b(?:TABLE|INTO|UPDATE|REFERENCES|FROM|JOIN)\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?(?:`[^`]+`|[A-Za-z0-9_]+)\s*\./i',$sql))throw new \DomainException('Schemavorbereitung bleibt in der ausgewählten eigenen Demo-Datenbank.');
    }
}
