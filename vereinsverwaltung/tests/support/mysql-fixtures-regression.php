<?php declare(strict_types=1);
require_once __DIR__.'/../mysql-bootstrap.php';
require_once __DIR__.'/../../src/bootstrap.php';
use Kulturhus\Verein\Tests\MySqlFixture;

// Run only in the disposable PHP container, with /workspace/.local backed by tmpfs.
$db=MySqlFixture::connect();
\Kulturhus\Verein\Database::migrate($db);
foreach(['VEREIN_MODE'=>'synthetic','KULTURHUS_DISABLE_MAIL'=>'1','VEREIN_DATABASE'=>'','VEREIN_AUTH'=>'',
    'VEREIN_DATABASE_USER'=>'root','VEREIN_DATABASE_PASSWORD'=>'isolated-regression-only',
    'KULTURHUS_DEMO_DATABASE'=>MySqlFixture::dsn($db)] as $key=>$value)putenv($key.'='.$value);
require __DIR__.'/mysql-environment.php';
$private=dirname(__DIR__,3).'/.local';
if(!is_dir($private)||!is_writable($private))throw new RuntimeException('Writable disposable .local mount required');
if(!is_dir($private.'/vereinsverwaltung/sessions'))mkdir($private.'/vereinsverwaltung/sessions',0700,true);
$checks=0;$failures=[];
function fixtureCheck(bool $ok,string $label):void
{
    global $checks;if(!$ok)throw new RuntimeException($label);++$checks;echo "PASS $label\n";
}
function fixtureChild(string $name,array $args=[]):string
{
    $p=proc_open([PHP_BINARY,__DIR__.'/'.$name,...$args],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($p))throw new RuntimeException('Fixture child unavailable: '.$name);
    $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($p)!==0||trim($error)!=='')throw new RuntimeException($name.': '.$error);
    return trim($output);
}
function fixtureReadback(string $name,string $path):array
{
    $first=json_decode(fixtureChild($name,[$path]),true,512,JSON_THROW_ON_ERROR);
    $second=json_decode(fixtureChild($name,[$path]),true,512,JSON_THROW_ON_ERROR);
    fixtureCheck(is_array($first)&&$first===$second,$name.' stable MySQL readback');return $first;
}
$memberPath='';
foreach([
    'member-profile-fixture.php'=>['member-profile-readback.php','account-settings-readback.php','notification-settings-readback.php','role-editor-readback.php','mailing-recipients-readback.php'],
    'inventory-fields-fixture.php'=>['inventory-fields-readback.php'],
    'inventory-flows-fixture.php'=>['inventory-flows-readback.php'],
    'inventory-remove-fixture.php'=>['inventory-flows-readback.php'],
    'chat-ui-fixture.php'=>['chat-ui-control.php'],
    'bank-workflow-fixture.php'=>[], 'finance-workflow-fixture.php'=>[], 'manual-payment-fixture.php'=>[],
    'member-changes-fixture.php'=>[], 'calendar-meeting-fixture.php'=>[], 'donation-collections-fixture.php'=>[],
    'donation-source-fixture.php'=>[], 'tutorials-ui-fixture.php'=>[], 'volunteer-general-fixture.php'=>[], 'volunteer-touch-fixture.php'=>[],
] as $generator=>$readers){
    try{
        $path=fixtureChild($generator);$fixture=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        $q=$db->prepare('SELECT COUNT(*) FROM clubs WHERE id=?');$q->execute([$fixture['club']]);
        fixtureCheck((int)$q->fetchColumn()===1,$generator.' persists synthetic tenant across processes');
        if($generator==='member-profile-fixture.php')$memberPath=$path;
        foreach($readers as $reader)fixtureReadback($reader,$path);
        if($generator==='chat-ui-fixture.php'){
            $before=fixtureReadback('chat-ui-control.php',$path);
            fixtureCheck(count($before['club_chat_messages'])===208,'chat pagination fixture retains all 205 history messages and three initial messages');
            fixtureChild('chat-ui-control.php',[$path,'peer-message']);$after=fixtureReadback('chat-ui-control.php',$path);
            fixtureCheck(count($after['club_chat_messages'])===209,'synthetic peer message persists through separate MySQL connection');
        }
    }catch(Throwable $e){$failures[]=$generator.': '.$e->getMessage();fwrite(STDERR,'FAIL '.end($failures)."\n");}
}
if($memberPath!=='')foreach(['finance-intake-fixture.php','finance-ui-regression-fixture.php','simple-ui-fixture.php'] as $name){
    try{
        fixtureChild($name,[$memberPath,...($name==='simple-ui-fixture.php'?['seed']:[])]);
        fixtureCheck(true,$name.' extends existing MySQL fixture');
        if($name==='simple-ui-fixture.php')fixtureReadback($name,$memberPath);
    }catch(Throwable $e){$failures[]=$name.': '.$e->getMessage();fwrite(STDERR,'FAIL '.end($failures)."\n");}
}
$readonly=MySqlFixture::existing(MySqlFixture::dsn($db));$readonly->exec('START TRANSACTION READ ONLY');$rejected=false;
try{$readonly->exec("INSERT INTO clubs VALUES('readback-must-not-write','Invalid')");}catch(PDOException $e){$rejected=($e->errorInfo[1]??null)===1792;}
fixtureCheck($rejected,'read-only MySQL transaction rejects writes');$readonly->rollBack();
foreach(['mysql:host=localhost;dbname=kh_test_'.str_repeat('a',24).';charset=utf8mb4','mysql:host=mysql-test;dbname=production;charset=utf8mb4'] as $invalid){
    $rejected=false;try{MySqlFixture::existing($invalid);}catch(RuntimeException){$rejected=true;}fixtureCheck($rejected,'non-isolated DSN rejected before connection');
}
fixtureCheck(MySqlFixture::foreignKeyErrors($db)===[]&&MySqlFixture::integrity($db)==='ok','all support fixtures preserve MySQL integrity and foreign keys');
echo "$checks support MySQL checks passed; ".count($failures)." failures; browser HTTP and native ProcessWire not exercised\n";
exit($failures?1:0);
