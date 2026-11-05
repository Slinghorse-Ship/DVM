<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/mysql-bootstrap.php';
use Kulturhus\Verein\{AssetBooks,AssetDisposal,Documents,Database,SettingsSchema};
use Kulturhus\Verein\Tests\MySqlFixture;
if(($argv[1]??'')==='--writer'){
    $job=json_decode(base64_decode($argv[2]),true,64,JSON_THROW_ON_ERROR);$db=MySqlFixture::existing($job['dsn']);$books=new AssetBooks($db,'admin');
    try{if($job['action']==='save')$books->save($job['item'],1,$job['input']);else $books->recordYear($job['item'],1,2025,$job['action']==='disposal'?$job['disposal']:[]);echo 'saved';}
    catch(DomainException){echo 'rejected';}exit;
}
$db=MySqlFixture::connect();Database::migrate($db);$n=0;
function check(bool $ok,string $message):void{global $n;if(!$ok)throw new RuntimeException($message);$n++;echo "PASS $message\n";}
$db->exec("INSERT INTO clubs VALUES('a','Synthetic asset concurrency');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','treasurer',1)");
$tax=SettingsSchema::defaults('tax');$tax['accounting_basis']='balance';
$db->prepare('INSERT INTO club_settings VALUES(?,?,?,?,?)')->execute(['a','tax',json_encode($tax),1,gmdate('c')]);
$input=['basis'=>'1000','residual'=>'0','months'=>'60','acquired_on'=>'2025-07-01','sphere'=>'4','account'=>'06300','reason'=>'Synthetic initial plan'];
$books=new AssetBooks($db,'admin');
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
$doc=(new Documents($db,'admin'))->upload('scrap.png',$png,['title'=>'Synthetic disposal proof','kind'=>'invoice','scope'=>'finance'],bin2hex(random_bytes(16)));
$disposal=['disposal_kind'=>'scrap','disposal_date'=>'2025-09-15','disposal_document'=>$doc,'disposal_reason'=>'Synthetic disposal without proceeds','disposal_confirmed'=>'1'];
foreach([['save','year'],['save','save'],['year','year'],['year','disposal'],['disposal','disposal']] as $index=>$actions){
    $item='race-'.$index;$db->prepare('INSERT INTO inventory_items(club_id,id,code,name,kind) VALUES(?,?,?,?,?)')->execute(['a',$item,$item,'Synthetic asset '.$index,'reusable']);
    $books->save($item,0,$input);$processes=[];
    $db->beginTransaction();$db->query("SELECT id FROM clubs WHERE id='a' FOR UPDATE")->fetchColumn();
    foreach($actions as $j=>$action){
        $job=['dsn'=>MySqlFixture::dsn($db),'item'=>$item,'action'=>$action,'input'=>array_replace($input,['months'=>'72','reason'=>'Synthetic edit '.$j]),'disposal'=>$disposal];
        $proc=proc_open([PHP_BINARY,__FILE__,'--writer',base64_encode(json_encode($job))],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($proc))throw new RuntimeException('Synthetic concurrent process failed to start');
        fclose($pipes[0]);$processes[]=[$proc,$pipes];
    }
    usleep(250000);$db->commit();$results=[];
    foreach($processes as [$proc,$pipes]){
        $results[]=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        check(proc_close($proc)===0&&$error==='','concurrent asset writer exits cleanly');
    }
    sort($results);check($results===['rejected','saved'],'exactly one conflicting asset operation commits');
    $stored=$books->get($item);
    foreach($stored['years'] as $year){[$plan,$event]=AssetDisposal::unpack(json_decode($year['snapshot'],true));check((int)$year['plan_version']===$stored['version']&&$plan===$stored['data'],'confirmed year retains current immutable plan');}
    check(count($stored['history'])===$stored['version'],'no conflicting edit erases asset history');
}
check(!MySqlFixture::foreignKeyErrors($db),'asset references intact after concurrent requests');
echo "$n asset concurrency checks passed; synthetic MySQL only\n";
