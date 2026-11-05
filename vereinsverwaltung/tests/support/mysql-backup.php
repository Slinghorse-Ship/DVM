<?php declare(strict_types=1);
namespace Kulturhus\Verein\Tests;
require_once __DIR__.'/../mysql-bootstrap.php';

/** Import the actual decrypted archive payload into a fresh test database. */
function restoreArchivedMysql(string $dump): \PDO
{
    if(!is_file($dump)||filesize($dump)===0)throw new \RuntimeException('Nonempty archived MySQL dump required');
    $db=MySqlFixture::connect();
    MySqlFixture::existing(MySqlFixture::dsn($db));
    $name=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    $process=proc_open(['/usr/bin/mysql','--host=mysql-test','--user=root','--database='.$name],
        [0=>['file',$dump,'r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,['MYSQL_PWD'=>'isolated-regression-only']);
    if(!is_resource($process))throw new \RuntimeException('MySQL archive restore process unavailable');
    $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($process)!==0)throw new \RuntimeException('Archived MySQL restore failed: '.$error);
    return $db;
}
