<?php declare(strict_types=1);
require_once __DIR__.'/mysql-bootstrap.php';require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,ClubChat,ChatPolls};
if(($argv[1]??'')==='worker'){
    $path=$argv[2]??'';
    $db=\Kulturhus\Verein\Tests\MySqlFixture::existing($path);
    try{(new ChatPolls($db,'peer'))->vote($argv[3],$argv[4],(int)$argv[5],0,bin2hex(random_bytes(16)));echo 'accepted';}catch(DomainException){echo 'conflict';}
    exit;
}
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect();$path=\Kulturhus\Verein\Tests\MySqlFixture::dsn($db);$workers=[];
try{
    Database::migrate($db);$db->exec("INSERT INTO clubs VALUES('a','Synthetic');INSERT INTO actors(id,club_id,role) VALUES('owner','a','member'),('peer','a','member')");
    $chat=(new ClubChat($db,'owner'))->create('Race',['peer']);$poll=(new ChatPolls($db,'owner'))->create($chat,'A oder B?',['A','B'],'',bin2hex(random_bytes(16)));
    $db->beginTransaction();
    foreach([1,2] as $option){$pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'worker',$path,$chat,$poll,(string)$option],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Worker failed');fclose($pipes[0]);$workers[]=[$process,$pipes];}
    usleep(300000);$db->commit();$results=[];
    foreach($workers as [$process,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit!==0)throw new RuntimeException($err);$results[]=$out;}$workers=[];
    sort($results);if($results!==['accepted','conflict'])throw new RuntimeException('Concurrent vote outcome incorrect: '.json_encode($results));
    $stored=$db->query('SELECT * FROM club_chat_poll_votes')->fetchAll();if(count($stored)!==1||(int)$stored[0]['version']!==1)throw new RuntimeException('A stale concurrent choice overwrote the first vote.');
    echo "PASS two simultaneous first votes serialize to one accepted answer and one conflict\n";
}finally{
    foreach($workers as [$process,$pipes]){foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);if(is_resource($process)){proc_terminate($process);proc_close($process);}}
    $db=null;
}
