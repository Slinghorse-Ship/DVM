<?php declare(strict_types=1);
if(($argv[1]??'')==='grant-race'){
    require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/mysql-bootstrap.php';
    $job=json_decode(base64_decode($argv[2]),true,32,JSON_THROW_ON_ERROR);
    $db=\Kulturhus\Verein\Tests\MySqlFixture::existing($job['dsn']);
    try{(new \Kulturhus\Verein\EventVouchers($db,'admin'))->grant($job['event'],[$job['person']],$job['benefits'],bin2hex(random_bytes(16)));echo 'granted';}
    catch(DomainException){echo 'denied';}exit;
}
require __DIR__.'/event-vouchers.php';
use Kulturhus\Verein\{EventVouchers,EventVoucherTickets,EventVoucherMail,Registry};
use Kulturhus\Verein\Tests\MySqlFixture;
$s=new EventVouchers($db,'admin');$r=new Registry($db,'admin');
$regrantEvent=$s->open($r->saveAppointment(null,0,array_replace($eventData,['title'=>'Synthetic replacement test']),[]),$key());
$benefit=[['label'=>'Getränke','kind'=>'drink','unit'=>'piece','amount'=>'2']];
$row=fn(string $id)=>array_values(array_filter($s->vouchers($regrantEvent),static fn($v)=>$v['id']===$id))[0];
$id=$s->grant($regrantEvent,[$p],$benefit,$key())[0];$v=$row($id);$oldCodes=array_column($v['tickets'],'code');
$deleteKey=$key();$move=$s->change($regrantEvent,$id,1,'revoke','','Synthetic deletion',$deleteKey,$v['tickets'][0]['id']);
ok($s->change($regrantEvent,$id,1,'revoke','','Synthetic deletion',$deleteKey,$v['tickets'][0]['id'])===$move,'delete retry has no second effect');
denied(fn()=>$s->grant($regrantEvent,[$p],$benefit,$key()),'remaining active sibling prevents duplicate grant');
$s->change($regrantEvent,$id,2,'revoke','','Synthetic deletion',$key(),$v['tickets'][1]['id']);
$deleted=$row($id);ok($deleted['state']==='revoked'&&(int)$deleted['remaining']===0,'last individual deletion closes parent entitlement');
ok(EventVoucherTickets::fullyRevoked($db,$deleted),'all deleted original tickets evidenced');
$newKey=$key();$replacement=$s->grant($regrantEvent,[$p],$benefit,$newKey)[0];$new=$row($replacement);
ok($replacement!==$id&&!array_intersect($oldCodes,array_column($new['tickets'],'code')),'replacement has new identities and QR codes');
ok($s->grant($regrantEvent,[$p],$benefit,$newKey)===[$replacement],'replacement double click never issues twice');
denied(fn()=>$s->grant($regrantEvent,[$p],$benefit,$key()),'new active replacement prevents another grant');
denied(fn()=>$s->change($regrantEvent,$id,3,'redeem','1','',$key(),$v['tickets'][0]['id']),'old revoked QR remains unredeemable after replacement');
ok($row($id)===$deleted,'replacement preserves revoked originals and history');
$mailPreview=(new EventVoucherMail($db,'admin'))->preview($regrantEvent,$p);
ok(count($mailPreview['snapshot']['vouchers'])===2&&!array_intersect($oldCodes,array_column($mailPreview['snapshot']['vouchers'],'code')),'personal PDF/mail preview contains replacement tickets only');

// Exact former bug: two individually deleted tickets, parent still marked active.
foreach($new['tickets'] as $i=>$t)$s->change($regrantEvent,$replacement,$i+1,'revoke','','Synthetic legacy deletion',$key(),$t['id']);
$db->prepare("UPDATE event_vouchers SET state='active' WHERE club_id='a' AND id=?")->execute([$replacement]);
$legacy=$row($replacement);ok(EventVoucherTickets::fullyRevoked($db,$legacy),'legacy all-revoked parent recognised without rewriting it');
ok(!EventVoucherTickets::fullyRevoked($db,array_replace($legacy,['granted'=>3])),'missing original ticket does not count as deleted');
ok(!EventVoucherTickets::fullyRevoked($db,array_replace($legacy,['id'=>$key()])),'empty ticket history does not allow replacement');
$jobs=[];$job=['dsn'=>MySqlFixture::dsn($db),'event'=>$regrantEvent,'person'=>$p,'benefits'=>$benefit];
foreach([1,2] as $i){$proc=proc_open([PHP_BINARY,__FILE__,'grant-race',base64_encode(json_encode($job))],[0=>['file',PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('Race child unavailable');$jobs[]=[$proc,$pipes];}
$race=[];foreach($jobs as [$proc,$pipes]){$race[]=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($proc)!==0)throw new RuntimeException('Grant race failed '.$err);}sort($race);
ok($race===['denied','granted'],'concurrent replacement of legacy deletion creates exactly one entitlement');
ok($row($replacement)===$legacy,'legacy recognition does not mutate original deleted entitlement');
$state=MySqlFixture::snapshot($db);
denied(fn()=>$s->grant($regrantEvent,[$p,$p2],$benefit,$key()),'batch with existing active benefit stops atomically');
ok(MySqlFixture::snapshot($db)===$state,'failed batch issues nothing to another member');

$mixed=$s->grant($regrantEvent,[$p2],$benefit,$key())[0];$mv=$row($mixed);
$redeem=$s->change($regrantEvent,$mixed,1,'redeem','1','',$key(),$mv['tickets'][0]['id']);
$s->change($regrantEvent,$mixed,2,'revoke','','Synthetic remaining ticket deletion',$key(),$mv['tickets'][1]['id']);
ok(!EventVoucherTickets::fullyRevoked($db,$row($mixed))&&$row($mixed)['state']==='active','used-plus-deleted is not a fully deleted entitlement');
denied(fn()=>$s->grant($regrantEvent,[$p2],$benefit,$key()),'used-plus-deleted cannot accumulate replacement credit');
$s->reverse($regrantEvent,$redeem,'Synthetic correction',$key());
ok($row($mixed)['tickets'][0]['state']==='active'&&(int)$row($mixed)['remaining']===1,'redemption reversal still restores the exact original ticket');
denied(fn()=>$s->grant($regrantEvent,[$p2],$benefit,$key()),'reversed original remains protected against duplicate issue');
$spent=[['label'=>'Synthetic consumed','kind'=>'other','unit'=>'piece','amount'=>'1']];
$spentId=$s->grant($regrantEvent,[$p2],$spent,$key())[0];$s->change($regrantEvent,$spentId,1,'redeem','1','',$key());
denied(fn()=>$s->grant($regrantEvent,[$p2],$spent,$key()),'fully consumed entitlement cannot be issued again');
$euro=[['label'=>'Synthetic euro credit','kind'=>'other','unit'=>'eur','amount'=>'5']];
$euroId=$s->grant($regrantEvent,[$p2],$euro,$key())[0];$s->change($regrantEvent,$euroId,1,'redeem','5','',$key());
denied(fn()=>$s->grant($regrantEvent,[$p2],$euro,$key()),'spent euro credit also remains protected');
$s->change($regrantEvent,$euroId,2,'revoke','','Synthetic explicit cancellation',$key());
ok($s->grant($regrantEvent,[$p2],$euro,$key())[0]!==$euroId,'explicitly cancelled euro credit retains previous reissue behaviour');
denied(fn()=>(new EventVouchers($db,'member'))->grant($regrantEvent,[$p],$benefit,$key()),'member cannot authorise replacement');
denied(fn()=>(new EventVouchers($db,'foreign'))->grant($regrantEvent,[$p],$benefit,$key()),'other club cannot replace vouchers');
ok(MySqlFixture::foreignKeyErrors($db)===[],'replacement retains relational integrity');
echo "PASS $n combined voucher/replacement checks; synthetic only, no external sending\n";
