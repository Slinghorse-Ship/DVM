<?php declare(strict_types=1);
namespace Kulturhus\Verein\Tests;

// The portable PHP bundle has no distribution-owned /var/lib/php/sessions.
// Child controller tests inherit this explicit isolated runner and private directory.
if (getenv('VEREIN_TEST_MYSQL') === 'isolated-regression-only' && session_status() === PHP_SESSION_NONE) {
    $sessionRoot = dirname(__DIR__, 2) . '/.local/test-results/php-sessions';
    if (!is_dir($sessionRoot) && !@mkdir($sessionRoot, 0700, true) && !is_dir($sessionRoot)) {
        throw new \RuntimeException('Private test session directory unavailable.');
    }
    if (is_link($sessionRoot) || !chmod($sessionRoot, 0700)) throw new \RuntimeException('Private test sessions unavailable.');
    session_save_path($sessionRoot);
}

/** Disposable databases on the isolated test-only Docker service, never the runtime DSN. */
if(!class_exists(MySqlFixture::class,false)){
final class MySqlFixture
{
    private static array $databases=[];
    private static function host():string
    {
        $host=getenv('VEREIN_TEST_MYSQL_HOST')?:'mysql-test';
        if($host==='127.0.0.1'&&getenv('VEREIN_TEST_MYSQL_PORT')==='13372')return $host;
        if($host!=='mysql-test')throw new \RuntimeException('Isolated test-only MySQL host required');
        return $host;
    }
    private static function port():string
    {
        $port=getenv('VEREIN_TEST_MYSQL_PORT')?:'3306';
        if(!in_array($port,['3306','13371','13372'],true))throw new \RuntimeException('Isolated MySQL test port required.');
        return ';port='.$port;
    }
    /** Only for constructing deliberately malformed historical fixtures, never application use. */
    public static function enforceChecks(\PDO $db,bool $enforce):void
    {
        self::dsn($db);if($db->inTransaction())throw new \RuntimeException('DDL outside test transaction required');
        foreach($db->query("SELECT TABLE_NAME,CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_TYPE='CHECK'")->fetchAll() as $c){
            foreach($c as $identifier)if(!preg_match('/^[a-zA-Z0-9_]+$/D',$identifier))throw new \RuntimeException('Invalid constraint identifier');
            $db->exec('ALTER TABLE `'.$c['TABLE_NAME'].'` ALTER CHECK `'.$c['CONSTRAINT_NAME'].'` '.($enforce?'ENFORCED':'NOT ENFORCED'));
        }
    }
    public static function snapshot(\PDO $db):string
    {
        $state=[];foreach($db->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table)$state[$table]=self::rows($db,$table);
        return hash('sha256',serialize($state));
    }
    public static function dsn(\PDO $db):string
    {
        $name=(string)$db->query('SELECT DATABASE()')->fetchColumn();
        if(!preg_match('/^kh_test_[a-f0-9]{24}$/D',$name))throw new \RuntimeException('Not an isolated database');
        return 'mysql:host='.self::host().self::port().';dbname='.$name.';charset=utf8mb4';
    }
    public static function existing(string $dsn):\PDO
    {
        if(getenv('VEREIN_TEST_MYSQL')!=='isolated-regression-only'||!preg_match('/^mysql:host=(mysql-test|127\.0\.0\.1)(?:;port=(3306|13371|13372))?;dbname=kh_test_[a-f0-9]{24};charset=utf8mb4$/D',$dsn,$match)||$match[1]!==self::host()||';port='.($match[2]??'3306')!==self::port())throw new \RuntimeException('Isolated database required');
        require_once __DIR__.'/../src/Infrastructure/Database/Database.php';
        return \Kulturhus\Verein\Database::connect($dsn,'root','isolated-regression-only');
    }
    public static function rows(\PDO $db,string $table):array
    {
        if(!preg_match('/^[a-z0-9_]+$/D',$table))throw new \RuntimeException('Invalid test table');
        $q=$db->prepare("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION");$q->execute([$table]);$keys=$q->fetchAll(\PDO::FETCH_COLUMN);
        if(!$keys)throw new \RuntimeException('Test snapshot requires explicit primary key');
        return $db->query('SELECT * FROM `'.$table.'` ORDER BY `'.implode('`,`',$keys).'`')->fetchAll();
    }
    public static function restoreBackup(\PDO $source): \PDO
    {
        require_once dirname(__DIR__).'/src/Infrastructure/Database/MySqlBackup.php';
        $destination=self::connect();$name=$source->query('SELECT DATABASE()')->fetchColumn();$target=$destination->query('SELECT DATABASE()')->fetchColumn();
        if(!preg_match('/^kh_test_[a-f0-9]{24}$/D',$name)||!preg_match('/^kh_test_[a-f0-9]{24}$/D',$target))throw new \RuntimeException('Test database required');
        $dump=sys_get_temp_dir().'/kh-mysql-backup-'.bin2hex(random_bytes(12)).'.sql';$oldUser=getenv('VEREIN_DATABASE_USER');$oldPass=getenv('VEREIN_DATABASE_PASSWORD');
        try{
            putenv('VEREIN_DATABASE_USER=root');putenv('VEREIN_DATABASE_PASSWORD=isolated-regression-only');
            \Kulturhus\Verein\MySqlBackup::create($source,self::dsn($source),$dump);
            if(self::host()==='127.0.0.1'){
                require_once dirname(__DIR__).'/native/bootstrap.php';
                \Kulturhus\Native\Installer\SqlSnapshot::import($destination,$dump);
                return $destination;
            }
            $client=getenv('VEREIN_TEST_MYSQL_CLIENT')?:'/usr/bin/mysql';
            if(!str_starts_with($client,'/')||!is_file($client)||!is_executable($client))throw new \RuntimeException('Isolated test MySQL client missing.');
            $p=proc_open([$client,'--host=mysql-test','--port='.substr(self::port(),6),'--user=root','--database='.$target],[0=>['file',$dump,'r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,['MYSQL_PWD'=>'isolated-regression-only']);
            if(!is_resource($p))throw new \RuntimeException('Isolated test MySQL restore could not start.');
            $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            if(proc_close($p)!==0)throw new \RuntimeException('MySQL test restore failed: '.$error);
            return $destination;
        }finally{if(is_file($dump))unlink($dump);putenv($oldUser===false?'VEREIN_DATABASE_USER':'VEREIN_DATABASE_USER='.$oldUser);putenv($oldPass===false?'VEREIN_DATABASE_PASSWORD':'VEREIN_DATABASE_PASSWORD='.$oldPass);}
    }
    public static function integrity(\PDO $db): string
    {
        foreach($db->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table){
            foreach($db->query('CHECK TABLE `'.str_replace('`','``',$table).'`')->fetchAll() as $result){
                if($result['Msg_type']==='error'||($result['Msg_type']==='status'&&$result['Msg_text']!=='OK'))return 'error';
            }
        }
        return 'ok';
    }
    public static function foreignKeyErrors(\PDO $db): array
    {
        $groups=[];
        foreach($db->query('SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION') as $r)$groups[$r['TABLE_NAME'].'.'.$r['CONSTRAINT_NAME']][]=$r;
        $errors=[];
        foreach($groups as $key=>$cols){
            $joins=[];$nonnull=[];
            foreach($cols as $r){$joins[]='p.`'.$r['REFERENCED_COLUMN_NAME'].'`=c.`'.$r['COLUMN_NAME'].'`';$nonnull[]='c.`'.$r['COLUMN_NAME'].'` IS NOT NULL';}
            $r=$cols[0];$sql='SELECT COUNT(*) FROM `'.$r['TABLE_NAME'].'` c WHERE '.implode(' AND ',$nonnull).' AND NOT EXISTS(SELECT 1 FROM `'.$r['REFERENCED_TABLE_NAME'].'` p WHERE '.implode(' AND ',$joins).')';
            if((int)$db->query($sql)->fetchColumn()>0)$errors[]=$key;
        }
        return $errors;
    }
    public static function connect(string $fixture=':memory:'): \PDO
    {
        if(getenv('VEREIN_TEST_MYSQL')!=='isolated-regression-only')throw new \RuntimeException('Isolated MySQL test runner required.');
        require_once __DIR__.'/../src/Infrastructure/Database/Database.php';
        $admin=new \PDO('mysql:host='.self::host().self::port().';charset=utf8mb4','root','isolated-regression-only',[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $key=$fixture===':memory:'?bin2hex(random_bytes(16)):$fixture;
        if(!isset(self::$databases[$key])){
            $name='kh_test_'.bin2hex(random_bytes(12));
            $admin->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            self::$databases[$key]=$name;
            register_shutdown_function(static function()use($admin,$name):void{
                if(!preg_match('/^kh_test_[a-f0-9]{24}$/D',$name))return;
                try{$admin->exec('DROP DATABASE `'.$name.'`');}catch(\Throwable $e){fwrite(STDERR,"Test database cleanup failed: ".$name."\n");}
            });
        }
        return \Kulturhus\Verein\Database::connect('mysql:host='.self::host().self::port().';dbname='.self::$databases[$key].';charset=utf8mb4','root','isolated-regression-only');
    }
}
}
