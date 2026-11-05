<?php declare(strict_types=1);
/** Allocates only NEW, per-chat synthetic schemas. Does not drop any schema. */
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__,2);
$label = $argv[1] ?? '';
if (!preg_match('/^[a-z][a-z0-9-]{0,35}$/D',$label)) throw new RuntimeException('Fixture-Bezeichnung fehlt.');
$source = json_decode(file_get_contents($root.'/.local/mysql-r71-test/connection.json'), true,512,JSON_THROW_ON_ERROR);
if (($source['host']??'')!=='127.0.0.1'||($source['port']??0)!==13371) throw new RuntimeException('Lokaler Testserver erforderlich.');
$db = new PDO('mysql:host=127.0.0.1;port=13371;charset=utf8mb4','root',$source['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$actual = strtolower(rtrim(str_replace('\\','/',(string)$db->query('SELECT @@datadir')->fetchColumn()),'/'));
$expected = strtolower(str_replace('\\','/',realpath($root.'/.local/mysql-r71-test/data')));
if ($actual !== $expected || (int)$db->query('SELECT @@port')->fetchColumn() !== 13371) throw new RuntimeException('Falscher Testserver.');
$dir = $root.'/.local/native-cms-integration';
if (!is_dir($dir) && !mkdir($dir,0700,true)) throw new RuntimeException('Fixture-Verzeichnis nicht verfügbar.');
$path = $dir.'/'.$label.'.json';
$handle = @fopen($path,'x');
if (!$handle) throw new RuntimeException('Registrierte Fixture existiert bereits; keine Neuinitialisierung.');
$name = 'kh_native_'.bin2hex(random_bytes(12));
try {
    $db->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    fwrite($handle,json_encode(['host'=>'127.0.0.1','port'=>13371,'database'=>$name,'username'=>'root','password'=>$source['password'],'owner'=>'cms-chat-integration-20261007','label'=>$label,'created_at'=>gmdate('c')],JSON_THROW_ON_ERROR));
    fclose($handle);
    echo 'Fixture registriert: '.$path.PHP_EOL;
} catch (Throwable $e) { fclose($handle); throw new RuntimeException('Fixture konnte nicht vollständig angelegt werden; manuell prüfen.'); }
