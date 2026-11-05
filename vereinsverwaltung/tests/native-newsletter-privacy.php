<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
require dirname(__DIR__).'/src/bootstrap.php';
require __DIR__.'/support/original-database-hash.php';

use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db,SecretBox};
use Kulturhus\Native\Communication\{NewsletterGateway,Subscribers};
use Kulturhus\Native\Cms\Media;
use Kulturhus\Native\Integration\ManagerBridge;

$config = TestSupport::config();
if (PHP_SAPI !== 'cli' || getenv('KH_NATIVE_CI') !== '1'
    || ($config['owner'] ?? '') !== 'isolated-native-ci'
    || ($config['label'] ?? '') !== 'native-newsletter-privacy') throw new RuntimeException('Own registered synthetic suite required.');
$db = TestSupport::db();
Kulturhus\Native\Installer\SchemaRegistry::install($db);
TestSupport::seed($db);
$baseline = getenv('DVM_NEWSLETTER_PRIVACY_BASELINE') === '1';
$proof = getenv('DVM_NEWSLETTER_PRIVACY_PROOF') ?: dirname((string)getenv('KH_NATIVE_TEST_CONFIG')).'/newsletter-privacy-evidence';
// Default evidence stays in this runner's private fixture on Cloud and Actions alike.
if (!is_string($proof) || $proof === '' || is_link($proof) || !is_dir(dirname($proof))) throw new RuntimeException('Own proof directory required.');
if (!is_dir($proof) && !mkdir($proof,0700,true)) throw new RuntimeException('Own proof directory unavailable.');
$root = dirname((string)getenv('KH_NATIVE_TEST_CONFIG')).'/newsletter-privacy-'.bin2hex(random_bytes(8));
$nativeRuntime = dirname((string)getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$config['database'];
foreach ([$root,$root.'/integrated',$root.'/integrated/media',$root.'/integrated/sessions',$root.'/foreign-media',$nativeRuntime,$nativeRuntime.'/media',$nativeRuntime.'/sessions'] as $dir) {
    if (file_exists($dir) || !mkdir($dir,0700)) throw new RuntimeException('Own empty runtime required.');
}
$key = $root.'/private.key'; file_put_contents($key,random_bytes(32));chmod($key,0600);
$box = SecretBox::fromFile($key);
$png = $root.'/foreign-original.png'; $image=imagecreatetruecolor(20,15);imagepng($image,$png);unset($image);
$foreign=(new Media($db,new Access($db,'outsider'),$root.'/foreign-media'))->upload($png,'Foreign original.png','Foreign original');
$managerClub='own-newsletter-privacy-'.bin2hex(random_bytes(4));$managerCms=ManagerBridge::clubKey($managerClub);
Db::run($db,'INSERT INTO clubs(id,name) VALUES(?,?)',[$managerClub,'Own newsletter privacy']);
foreach(['admin','reader']as$role){
    Db::run($db,'INSERT INTO native_users(id,club_id,name) VALUES(?,?,?)',['own-newsletter-'.$role,$managerCms,'Own newsletter privacy']);
    Db::run($db,'INSERT INTO native_grants(actor_id,module,capability) SELECT ?,module,capability FROM native_grants WHERE actor_id=?',['own-newsletter-'.$role,$role]);
}
$apps=[
    'native'=>['club'=>'synthetic-a','manager_club'=>'','admin'=>'admin','reader'=>'reader','runtime'=>$nativeRuntime],
    'integrated'=>['club'=>$managerCms,'manager_club'=>$managerClub,'admin'=>'own-newsletter-admin','reader'=>'own-newsletter-reader','runtime'=>$root.'/integrated'],
];
foreach($apps as$app=>&$entry){
    $entry['identity']=['name'=>'Own newsletter privacy','origin'=>'https://newsletter.example.invalid','address'=>'Own synthetic street','logo_id'=>''];
    Db::run($db,'INSERT INTO native_club_settings(club_id,version,payload,updated_by,updated_at) VALUES(?,1,?,?,UTC_TIMESTAMP())',[$entry['club'],json_encode($entry['identity'],JSON_THROW_ON_ERROR),$entry['admin']]);
    $subscribers=new Subscribers($db,new Access($db,$entry['admin']));
    $subscribers->recordWrittenConsent($app.'-confirmed@example.invalid','Own confirmed',['events'],'Own written original consent');
    $pendingToken='';$subscribers->requestConsent($app.'-pending@example.invalid','Own pending',['events'],'Own pending consent',static function(string$t)use(&$pendingToken):void{$pendingToken=$t;});
    $entry['pending_hash']=hash('sha256',$pendingToken);
}
unset($entry);
$tables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
$originalKeys=[];
foreach($tables as$table){
    $columns=Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);
    if(!$columns)throw new RuntimeException('Original table requires stable key.');
    $originalKeys[$table]=['columns'=>$columns,'values'=>Db::run($db,'SELECT `'.implode('`,`',$columns).'` FROM `'.$table.'`')->fetchAll(PDO::FETCH_NUM)];
}
$originalFilter=static function(string$table)use($originalKeys):array{
    $e=$originalKeys[$table];if(!$e['values'])return[' WHERE 0=1',[]];$args=[];foreach($e['values']as$v)array_push($args,...$v);
    return[' WHERE (`'.implode('`,`',$e['columns']).'`) IN ('.implode(',',array_fill(0,count($e['values']),'('.implode(',',array_fill(0,count($e['columns']),'?')).')')).')',$args];
};
$originalHash=ownOriginalDatabaseHash($db,$tables,$originalFilter);
$keyHash=hash_file('sha256',$key);$foreignFileHash=hash_file('sha256',$png);
$n=0;$worlds=[];$server=null;$trigger=false;
$check=static function(bool$ok,string$label)use(&$n):void{if(!$ok)throw new RuntimeException('FAIL '.$label);++$n;echo'PASS '.$label."\n";};
$rowHash=static function(string$club)use($db):string{
    $values=[];
    foreach(['khn_newsletter_subscribers','khn_newsletter_tokens','khn_newsletter_consent_events','native_delivery_jobs']as$table)$values[$table]=Db::run($db,'SELECT * FROM `'.$table.'` WHERE club_id=? ORDER BY 1,2',[$club])->fetchAll();
    return hash('sha256',json_encode($values,JSON_THROW_ON_ERROR));
};
$xpath=static function(string$html):DOMXPath{$d=new DOMDocument();@$d->loadHTML('<?xml encoding="UTF-8">'.$html);return new DOMXPath($d);};
$removeOwn=static function(string$dir):void{
    if(!is_dir($dir)||is_link($dir))return;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as$p){if($p->isDir()&&!$p->isLink())rmdir($p->getPathname());else unlink($p->getPathname());}rmdir($dir);
};
final class OwnNewsletterPrivacyTrace extends PDO
{
    public array $statements=[];
    public function prepare(string$query,array$options=[]):PDOStatement|false
    {
        $this->statements[]=$query;
        return parent::prepare($query,$options);
    }
}
try{
    foreach($apps as$app=>$entry){
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('Own HTTP port required.');
        $port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);$origin='http://127.0.0.1:'.$port;
        $fixture=$entry+['app'=>$app,'origin'=>$origin,'key'=>$key];$file=$root.'/'.$app.'.json';file_put_contents($file,json_encode($fixture,JSON_THROW_ON_ERROR));chmod($file,0600);
        putenv('DVM_NEWSLETTER_PRIVACY_FIXTURE='.$file);
        $log=$proof.'/'.$app.'.server.log';
        $server=proc_open([...TestSupport::phpCommand(),'-d','display_errors=0','-d','log_errors=1','-S','127.0.0.1:'.$port,'-t',$entry['runtime'],__DIR__.'/support/newsletter-privacy-router.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__,2));
        if(!is_resource($server))throw new RuntimeException('Own actual app server required.');fclose($pipes[0]);
        for($i=0;$i<100;$i++){$ready=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1);if($ready){fclose($ready);break;}usleep(50000);}
        $http=static function(string$method='GET',array$post=[],string$path='/newsletter/')use($origin,$root,$app):array{
            $c=curl_init($origin.$path);$headers=[];curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_NOBODY=>$method==='HEAD',CURLOPT_TIMEOUT=>20,CURLOPT_COOKIEFILE=>$root.'/'.$app.'.cookies',CURLOPT_COOKIEJAR=>$root.'/'.$app.'.cookies',CURLOPT_HEADERFUNCTION=>static function($c,string$l)use(&$headers):int{$headers[]=trim($l);return strlen($l);}]);
            if($method==='POST')curl_setopt($c,CURLOPT_POSTFIELDS,http_build_query($post));$body=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);if(!is_string($body))throw new RuntimeException('Actual own HTTP failed.');return['status'=>$status,'body'=>$body,'headers'=>$headers];
        };
        $get=$http();$csrf=$xpath($get['body'])->evaluate('string(//input[@name="csrf"]/@value)');$check($get['status']===200&&strlen($csrf)===64,$app.' actual app establishes anonymous newsletter CSRF');
        $check((bool)array_filter($get['headers'],static fn($h)=>strtolower($h)==='cache-control: no-store'),$app.' public response is never cached');
        $input=['csrf'=>$csrf,'name'=>'Own private name','purposes'=>['events'],'consent'=>'1','website'=>''];
        $post=static fn(string$kind,array$replace=[])=>$http('POST',array_replace($input,['email'=>$app.'-'.$kind.'@example.invalid'],$replace));
        $clearRates=static fn()=>Db::run($db,'DELETE FROM native_newsletter_rates WHERE club_id=?',[$entry['club']]);
        foreach(['foreign-logo','write-fault']as$scenario){
            $clearRates();
            if($scenario==='foreign-logo'){
                $identity=$entry['identity'];$identity['logo_id']=$foreign['id'];
                Db::run($db,'UPDATE native_club_settings SET payload=? WHERE club_id=?',[json_encode($identity,JSON_THROW_ON_ERROR),$entry['club']]);
            }else{
                $db->exec("CREATE TRIGGER own_newsletter_privacy_insert BEFORE INSERT ON khn_newsletter_subscribers FOR EACH ROW BEGIN IF NEW.email LIKE '%@example.invalid' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='OWN_UNSAFE recipient@example.invalid token=abcdef SECRET_SOURCE'; END IF; END");$trigger=true;
                $db->beginTransaction();$faultSeen=false;
                try{Db::run($db,"INSERT INTO khn_newsletter_subscribers(club_id,id,email,name,status,purposes,consent_evidence,version,created_at,updated_at) VALUES(?,?,?,'Own fault','pending','[]','Own fault',1,UTC_TIMESTAMP(),UTC_TIMESTAMP())",[$entry['club'],bin2hex(random_bytes(16)),'fault-probe@example.invalid']);}catch(PDOException$e){$faultSeen=$e->getCode()==='45000'&&str_contains($e->getMessage(),'OWN_UNSAFE');}finally{if($db->inTransaction())$db->rollBack();}
                $check($faultSeen,$app.' own SQL trigger proves an actual write PDOException before HTTP');
            }
            $before=$rowHash($entry['club']);$responses=[];$logBefore=(string)file_get_contents($log);
            foreach(['confirmed','pending','new-'.$scenario]as$kind)$responses[$kind]=$post($kind);
            foreach($responses as$kind=>$response)file_put_contents($proof.'/'.$app.'-'.$scenario.'-'.$kind.'.html',$response['body']);
            $worlds[$app][$scenario]=array_map(static fn($r)=>['status'=>$r['status'],'sha256'=>hash('sha256',$r['body']),'generic'=>str_contains($r['body'],'Bestätigungsmail'),'error'=>str_contains($r['body'],'role="alert"')],$responses);
            $same=count(array_unique(array_map(static fn($r)=>$r['status'].'|'.hash('sha256',$r['body']),$responses)))===1;
            $check($baseline?!$same:$same,$app.' '.$scenario.($baseline?' original response divergence actually reproduced':' same HTTP status and exact HTML for confirmed pending new'));
            $check($rowHash($entry['club'])===$before,$app.' '.$scenario.' original subscriber token consent and outbox bytes unchanged');
            $rates=Db::run($db,'SELECT count FROM native_newsletter_rates WHERE club_id=? ORDER BY count',[$entry['club']])->fetchAll(PDO::FETCH_COLUMN);sort($rates);
            $check(array_map('intval',$rates)===[1,1,1,3],$app.' '.$scenario.' only exact three IP and three email budget increments committed');
            $afterLog=(string)file_get_contents($log);$newLog=substr($afterLog,strlen($logBefore));
            $check(!str_contains($newLog,'OWN_UNSAFE')&&!str_contains($newLog,'recipient@example.invalid')&&!str_contains($newLog,'SECRET_SOURCE'),$app.' operational log contains no injected recipient token or exception secret');
            if(!$baseline)$check(substr_count($newLog,'newsletter_signup_preparation_failed')===2,$app.' each failed preparation records one closed redacted event');
            if($trigger){$db->exec('DROP TRIGGER own_newsletter_privacy_insert');$trigger=false;}
            Db::run($db,'UPDATE native_club_settings SET payload=? WHERE club_id=?',[json_encode($entry['identity'],JSON_THROW_ON_ERROR),$entry['club']]);
        }
        $clearRates();
        if(!$baseline){
            $before=ownOriginalDatabaseHash($db,$tables);$invalids=[['email'=>'not-email'],['consent'=>'0'],['purposes'=>[]],['purposes'=>[['events']]],['email'=>['invalid']],['name'=>['invalid']],['website'=>['invalid']]];
            foreach($invalids as$bad){$r=$post('invalid',$bad);$check($r['status']===200&&$xpath($r['body'])->query('//*[@role="alert"]')->length===1,$app.' malformed input keeps actual correctable feedback');}
            $r=$post('csrf',['csrf'=>'wrong']);$check($r['status']===403&&$xpath($r['body'])->evaluate('string(//input[@name="email"]/@value)')===$app.'-csrf@example.invalid',$app.' CSRF rejection keeps input and remains403');
            $check(ownOriginalDatabaseHash($db,$tables)===$before,$app.' malformed inputs and CSRF cause no database write at all');
            $r=$post('bot',['website'=>'bot']);$check($r['status']===200&&str_contains($r['body'],'Bestätigungsmail')&&!str_contains($r['body'],'role="alert"'),$app.' honeypot has neutral success');
            $check(ownOriginalDatabaseHash($db,$tables)===$before,$app.' honeypot causes no rate subscriber token or job writes');
            $r=$post('happy');$check($r['status']===200&&!str_contains($r['body'],'token=')&&!str_contains($r['body'],'happy@example.invalid'),$app.' happy public result contains no private DOI or recipient');
            $subscriber=Db::run($db,'SELECT * FROM khn_newsletter_subscribers WHERE club_id=? AND email=?',[$entry['club'],$app.'-happy@example.invalid'])->fetch();
            $job=Db::run($db,"SELECT * FROM native_delivery_jobs WHERE club_id=? AND kind='confirm'",[$entry['club']])->fetch();$payload=$box->open('job:'.$entry['club'].':'.$job['id'],$job['ciphertext']);preg_match('/token=([a-f0-9]{64})/',$payload['text'],$match);
            $token=Db::run($db,"SELECT * FROM khn_newsletter_tokens WHERE club_id=? AND subscriber_id=? AND kind='confirm'",[$entry['club'],$subscriber['id']])->fetch();
            $check($subscriber['status']==='pending'&&isset($match[1])&&$token['token_hash']===hash('sha256',$match[1])&&$job['status']==='pending'&&(int)$job['attempts']===0,$app.' genuine private random DOI is hashed and encrypted with no send attempt');
            $before=$rowHash($entry['club']);$confirmed=$post('confirmed');$check($confirmed['body']===$r['body']&&$rowHash($entry['club'])===$before,$app.' confirmed response is identical and creates no new DOI');
            $clearRates();
            for($i=0;$i<8;$i++)$post('confirmed');
            $before=$rowHash($entry['club']);$a=$post('confirmed');$b=$post('exhausted');
            $check($a['body']===$b['body']&&$a['status']===$b['status']&&$rowHash($entry['club'])===$before,$app.' exhausted public IP gives same result before any subscriber or outbox mutation');
            $clearRates();
            for($i=0;$i<3;$i++)$post('normalized');
            $before=$rowHash($entry['club']);$a=$post('confirmed');$b=$post('normalized',['email'=>'  '.strtoupper($app.'-normalized@example.invalid').'  ']);
            $check($a['body']===$b['body']&&$a['status']===$b['status']&&$rowHash($entry['club'])===$before,$app.' normalized exhausted email budget is neutral and issues no new DOI');
            $before=$rowHash($entry['club']);$http('HEAD');$http('GET');$check($rowHash($entry['club'])===$before,$app.' actual GET HEAD preserve every existing DOI');
            $clearRates();
            $trace=new OwnNewsletterPrivacyTrace('mysql:host=127.0.0.1;port=13371;dbname='.$config['database'].';charset=utf8mb4',$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
            $tracedGateway=new NewsletterGateway($trace,$entry['club'],$box,$entry['runtime'].'/media');
            for($i=0;$i<8;$i++)$tracedGateway->signup($app.'-confirmed@example.invalid','Own confirmed',['events'],'192.0.2.251',true);
            $trace->statements=[];$before=$rowHash($entry['club']);
            $tracedGateway->signup($app.'-trace-exhausted@example.invalid','Own untouched',['events'],'192.0.2.251',true);
            $check(count($trace->statements)===2&&!array_filter($trace->statements,static fn($sql)=>!str_contains($sql,'native_newsletter_rates'))&&$rowHash($entry['club'])===$before,$app.' actual exhausted-IP short circuit runs only its rate INSERT SELECT before recipient brand and outbox');
            $trace->statements=[];$tracedGateway->signup($app.'-trace-bot@example.invalid','Own untouched',['events'],'192.0.2.252',true,'bot');
            $check($trace->statements===[]&&$rowHash($entry['club'])===$before,$app.' real honeypot service runs zero database statements');
            $clearRates();
            for($i=0;$i<3;$i++)$tracedGateway->signup($app.'-confirmed@example.invalid','Own confirmed',['events'],'192.0.2.253',true);
            $trace->statements=[];$tracedGateway->signup('  '.strtoupper($app.'-confirmed@example.invalid').'  ','Own confirmed',['events'],'192.0.2.253',true);
            $check(count($trace->statements)===4&&!array_filter($trace->statements,static fn($sql)=>!str_contains($sql,'native_newsletter_rates')),$app.' actual normalized-email short circuit never looks up recipient or private brand');
            unset($tracedGateway,$trace);
        }
        $clearRates();
        proc_terminate($server);proc_close($server);$server=null;
    }
    $check(ownOriginalDatabaseHash($db,$tables,$originalFilter)===$originalHash,'every prior subscriber pending capability foreign media account grant and setting byte is unchanged');
    $check(hash_file('sha256',$key)===$keyHash&&hash_file('sha256',$png)===$foreignFileHash,'private encryption key and foreign source image bytes unchanged');
    $check($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN)===$tables,'all original table inventory retained');
    $check((int)$db->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='own_newsletter_privacy_insert'")->fetchColumn()===0,'own SQL fault trigger removed after test');
    $result=['suite'=>'native-newsletter-privacy','checks'=>$n,'baseline'=>$baseline,'apps'=>$worlds,'original_tables'=>count($tables),'external_transports'=>0,'keys_unchanged'=>true];
    file_put_contents($proof.'/result.json',json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n");echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
}finally{
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    if($trigger)$db->exec('DROP TRIGGER own_newsletter_privacy_insert');
    $removeOwn($root);$removeOwn($nativeRuntime);
}
