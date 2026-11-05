<?php declare(strict_types=1);
namespace Kulturhus\Native;
use PDO;
use Kulturhus\Native\Shared\{Db,Schema};
/** Synthetic fixtures only. Never points at the persistent application database. */
final class TestSupport
{
    public static function config(): array
    {
        $file = getenv('KH_NATIVE_TEST_CONFIG');
        if (!$file || !is_file($file)) throw new \RuntimeException('Isolierte Testkonfiguration fehlt.');
        $c = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (($c['host']??'') !== '127.0.0.1' || ($c['port']??0)!==13371 || !preg_match('/^kh_native_[a-f0-9]{24}$/D', $c['database']??'')) throw new \RuntimeException('Keine isolierte lokale MySQL-Datenbank.');
        return $c;
    }
    public static function db(): PDO
    {
        $c = self::config();
        $db = new PDO('mysql:host=127.0.0.1;port=13371;dbname='.$c['database'].';charset=utf8mb4', $c['username'], $c['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        // This factory accepts only disposable loopback CI schemas. Do not fill
        // the test server's binary logs with repeated fixtures and cold-body data.
        $db->exec('SET SESSION sql_log_bin=0');
        $db->exec("SET time_zone='+00:00'");
        return $db;
    }
    /** Retain only the caller's selected test evidence, outside disposable fixtures. */
    public static function evidenceDirectory(): string
    {
        $config=self::config();$label=$config['label']??'';
        if(($config['owner']??'')!=='isolated-native-ci'||!is_string($label)||!preg_match('/^[a-z][a-z0-9-]{0,100}$/D',$label))throw new \RuntimeException('Eigener isolierter Prüfnachweis erforderlich.');
        $configured=getenv('DVM_BROWSER_ARTIFACT_DIR');
        $base=is_string($configured)&&$configured!==''?$configured:sys_get_temp_dir().'/dvm-native-browser-evidence';
        if(!is_dir($base)&&!mkdir($base,0700,true))throw new \RuntimeException('Privater Nachweisordner fehlt.');
        if(is_link($base)||!is_writable($base)||(fileperms($base)&0077)!==0)throw new \RuntimeException('Nachweise benötigen einen eigenen privaten Ordner.');
        $base=realpath($base);if($base===false)throw new \RuntimeException('Privater Nachweispfad fehlt.');
        $path=$base.'/'.$label.'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(8));
        if(!mkdir($path,0700))throw new \RuntimeException('Eigener Nachweisordner konnte nicht angelegt werden.');
        return $path;
    }
    /** Reuse the parent's INI and load PDO-MySQL only if a fresh CLI needs it. */
    public static function phpCommand(): array
    {
        static $command = null;
        if ($command !== null) return $command;
        $command = [PHP_BINARY];
        $ini = php_ini_loaded_file();
        if (is_string($ini) && $ini !== '') array_push($command, '-c', $ini);
        array_push($command, '-d', 'max_input_vars=8192');
        $probe = proc_open([...$command, '-r', 'exit(extension_loaded("pdo_mysql") ? 0 : 1);'],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        if (!is_resource($probe)) throw new \RuntimeException('PHP-Testprozess nicht verfügbar.');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $status = proc_close($probe);
        if ($output !== '' || $errors !== '' || !in_array($status, [0,1], true))
            throw new \RuntimeException('PHP-Testlaufzeit nicht verfügbar.');
        if ($status === 1) array_push($command, '-d', 'extension=pdo_mysql');
        return $command;
    }
    public static function seed(PDO $db): array
    {
        Schema::install($db);
        foreach (['admin'=>'Lokale Administration','editor'=>'Lokale Redaktion','reader'=>'Lokales Mitglied','outsider'=>'Anderer Testverein'] as $id=>$name) {
            Db::run($db, 'INSERT IGNORE INTO native_users(id,club_id,name) VALUES(?,?,?)',[$id,$id==='outsider'?'synthetic-b':'synthetic-a',$name]);
            foreach (['website','newsletter','social','press','chat'] as $module) {
                $caps = match($id) { 'reader'=>['read'], 'editor'=>['read','edit'], default=>['read','edit','publish','manage'] };
                foreach ($caps as $cap) Db::run($db,'INSERT IGNORE INTO native_grants(actor_id,module,capability) VALUES(?,?,?)',[$id,$module,$cap]);
            }
        }
        return array_combine(['admin','editor','reader','outsider'],['admin','editor','reader','outsider']);
    }
}
