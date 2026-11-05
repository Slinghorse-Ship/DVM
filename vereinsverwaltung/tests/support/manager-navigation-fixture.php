<?php declare(strict_types=1);
namespace Kulturhus\Verein\Tests;

/** Native-loopback fixture, separately registered; never reuses the shared test database. */
final class ManagerNavigationFixture
{
    public static function root(): string { return dirname(__DIR__, 3); }
    public static function directory(): string { return self::root().'/.local/manager-navigation-fixture'; }
    public static function registration(): array
    {
        $file=self::directory().'/registration.json';
        if(!is_file($file))throw new \RuntimeException('Create the navigation fixture first.');
        $r=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
        if(($r['owner']??'')!=='manager-navigation' || !preg_match('/^kh_test_[a-f0-9]{24}$/D',$r['database']??'') || ($r['root']??'')!==self::root())throw new \RuntimeException('Wrong fixture owner.');
        return $r;
    }
    private static function credentials(): array
    {
        $c=json_decode(file_get_contents(self::root().'/.local/mysql-r71-test/connection.json'),true,512,JSON_THROW_ON_ERROR);
        if(($c['host']??'')!=='127.0.0.1'||($c['port']??0)!==13371)throw new \RuntimeException('Dedicated local MySQL server required.');
        return $c;
    }
    public static function admin(): \PDO
    {
        $c=self::credentials();
        $db=new \PDO('mysql:host=127.0.0.1;port=13371;charset=utf8mb4','root',$c['password'],[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC]);
        $expected=str_replace('\\','/',realpath(self::root().'/.local/mysql-r71-test/data')).'/';
        if((int)$db->query('SELECT @@port')->fetchColumn()!==13371||str_replace('\\','/',(string)$db->query('SELECT @@datadir')->fetchColumn())!==$expected)throw new \RuntimeException('Unrecognised MySQL data directory.');
        return $db;
    }
    public static function environment(): void
    {
        $r=self::registration(); $c=self::credentials();
        foreach(['VEREIN_AUTH','VEREIN_DATABASE','SMTP_DSN'] as $key)putenv($key);
        putenv('VEREIN_MODE=synthetic');putenv('KULTURHUS_LOCAL_DEMO=php');putenv('KULTURHUS_DISABLE_MAIL=1');
        putenv('KULTURHUS_DISABLE_WEBSITE=1');
        putenv('VEREIN_DATABASE_USER=root');putenv('VEREIN_DATABASE_PASSWORD='.$c['password']);
        $dsn='mysql:host=127.0.0.1;port=13371;dbname='.$r['database'].';charset=utf8mb4';
        putenv('KULTURHUS_DEMO_DATABASE='.$dsn);putenv('VEREIN_DATABASE_DSN='.$dsn);
    }
    public static function connect(): \PDO
    {
        self::admin();self::environment();
        require_once self::root().'/vereinsverwaltung/src/bootstrap.php';
        return \Kulturhus\Verein\Database::connect((string)getenv('KULTURHUS_DEMO_DATABASE'));
    }
    public static function readback(): array
    {
        $db=self::connect();$db->exec('START TRANSACTION READ ONLY');$counts=[];
        foreach(['clubs','contacts','documents','club_chats','club_chat_messages','club_outbox'] as $table)$counts[$table]=(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
        $counts['driver']=$db->getAttribute(\PDO::ATTR_DRIVER_NAME);$counts['port']=(int)$db->query('SELECT @@port')->fetchColumn();
        return $counts;
    }
    public static function enrich(): void
    {
        $db=self::connect();$file=self::directory().'/fixture.json';$r=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
        $event=(string)$db->query("SELECT id FROM appointments WHERE club_id='kulturhus-demo' ORDER BY starts_at DESC LIMIT 1")->fetchColumn();
        $r['voucher_event']=(new \Kulturhus\Verein\EventVouchers($db,'demo-manager'))->open($event,bin2hex(random_bytes(16)));
        file_put_contents($file,json_encode($r,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo "Synthetic scanner event prepared.\n";
    }
    public static function create(int $port): void
    {
        if($port<1024||$port>65535||in_array($port,[8091,8092,13371],true))throw new \RuntimeException('Separate loopback port required.');
        if(is_file(self::directory().'/registration.json'))throw new \RuntimeException('Fixture already registered; reuse it.');
        if(!is_dir(self::directory()))mkdir(self::directory(),0700,true);
        $name='kh_test_'.bin2hex(random_bytes(12));$db=self::admin();
        $db->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $r=['owner'=>'manager-navigation','database'=>$name,'root'=>self::root(),'created'=>gmdate('c'),'base'=>'http://127.0.0.1:'.$port.'/vereinsverwaltung/'];
        file_put_contents(self::directory().'/registration.json',json_encode($r,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
        self::environment();
        $argv=['init-demo.php','--demo'];require self::root().'/vereinsverwaltung/bin/init-demo.php';
        $db=self::connect();$r['chat']=(string)$db->query("SELECT id FROM club_chats WHERE club_id='kulturhus-demo' LIMIT 1")->fetchColumn();
        $r['contact']=(string)$db->query("SELECT id FROM contacts WHERE club_id='kulturhus-demo' AND kind='person' LIMIT 1")->fetchColumn();
        $r['project_hash']=hash('sha256',strtolower(str_replace('\\','/',self::root())));
        file_put_contents(self::directory().'/fixture.json',json_encode($r,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
        self::enrich();
        echo "Registered separate manager-navigation MySQL fixture.\n";
    }
}

if(PHP_SAPI==='cli'&&realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    ini_set('zend.exception_ignore_args','1');
    switch($argv[1]??''){
        case 'create': ManagerNavigationFixture::create((int)($argv[2]??0));break;
        case 'readback': echo json_encode(ManagerNavigationFixture::readback(),JSON_THROW_ON_ERROR);break;
        case 'enrich': ManagerNavigationFixture::enrich();break;
        default: throw new \RuntimeException('Use create <free-port> or readback. Fixture is retained until its owner explicitly cleans it up.');
    }
}
