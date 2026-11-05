<?php declare(strict_types=1);
require dirname(__DIR__) . '/native/bootstrap.php';
require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/support/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access, Db, Identity, SecretBox};
use Kulturhus\Native\Cms\Pages;
use Kulturhus\Native\Communication\{Schema, Subscribers};
use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Verein\Database;

$db = TestSupport::db(); Database::migrate($db); TestSupport::seed($db); Schema::install($db); Kulturhus\Native\Cms\Schema::install($db);
$managerClub = 'own-newsletter-' . bin2hex(random_bytes(6)); $club = ManagerBridge::clubKey($managerClub);
Db::run($db,'INSERT INTO clubs(id,name) VALUES(?,?)',[$managerClub,'Eigene Newsletterprüfung Ä']);
foreach (['admin','reader'] as $role) {
    Db::run($db,'INSERT INTO native_users(id,club_id,name) VALUES(?,?,?)',[$managerClub.'-'.$role,$club,'Eigene Newsletterprüfung']);
    Db::run($db,"INSERT INTO native_grants(actor_id,module,capability) SELECT ?,module,capability FROM native_grants WHERE actor_id=? AND module IN ('website','newsletter')",[$managerClub.'-'.$role,$role]);
}
$access = new Access($db,$managerClub.'-admin');
(new Identity($db,$access))->save(['name'=>'Eigene Newsletterprüfung Ä','origin'=>'https://newsletter.example.invalid','address'=>'Synthetischer Testweg'],0);
$pages = new Pages($db,$access);
foreach (['start','datenschutz'] as $slug) { $p=$pages->save(['slug'=>$slug,'title'=>'Eigene synthetische Seite','body'=>'Originaltext Ä']); $pages->publish($p['id'],1); }
$originalSubscriber = (new Subscribers($db,$access))->recordWrittenConsent('prior-original@example.invalid','Eigenes früheres Original',['news'],'Earlier synthetic written consent');
$foreignToken = '';
(new Subscribers($db,new Access($db,'outsider')))->requestConsent('foreign-original@example.invalid','Fremdes Original',['events'],'Synthetic foreign original',static function(string $token)use(&$foreignToken):void{$foreignToken=$token;});
$root = dirname(__DIR__,2).'/.local/native-newsletter-forms-'.bin2hex(random_bytes(8));
foreach (['sessions','media','web'] as $dir) mkdir($root.'/'.$dir,0700,true);
file_put_contents($root.'/private.key',random_bytes(32)); chmod($root.'/private.key',0600);
$box = SecretBox::fromFile($root.'/private.key');
$image=imagecreatetruecolor(48,32);imagefill($image,0,0,imagecolorallocate($image,25,110,175));imagepng($image,$root.'/media/original.png');unset($image);
$fileHashes = [hash_file('sha256',$root.'/private.key'),hash_file('sha256',$root.'/media/original.png')];
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('Own HTTP port required.');
$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);$origin='http://127.0.0.1:'.$port;
$fixture=['origin'=>$origin,'runtime'=>$root,'club'=>$managerClub,'reader'=>$managerClub.'-reader','secret_file'=>$root.'/private.key'];
$fixtureFile=$root.'/browser.json';file_put_contents($fixtureFile,json_encode($fixture,JSON_THROW_ON_ERROR));chmod($fixtureFile,0600);
$tables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);$keys=[];
foreach($tables as$table){$columns=Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);if(!$columns)throw new RuntimeException('Stable original keys required.');$keys[$table]=['columns'=>$columns,'values'=>Db::run($db,'SELECT `'.implode('`,`',$columns).'` FROM `'.$table.'`')->fetchAll(PDO::FETCH_NUM)];}
$filter=static function(string $table)use($keys):array{$entry=$keys[$table];if(!$entry['values'])return[' WHERE 0=1',[]];$args=[];foreach($entry['values']as$values)array_push($args,...$values);return[' WHERE (`'.implode('`,`',$entry['columns']).'`) IN ('.implode(',',array_fill(0,count($entry['values']),'('.implode(',',array_fill(0,count($entry['columns']),'?')).')')).')',$args];};
$originalHash=ownOriginalDatabaseHash($db,$tables,$filter);$n=0;$server=null;
$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException($label);$n++;echo 'PASS '.$label."\n";};
$http=static function(string $path='/newsletter/',string $method='GET',array $post=[])use($origin,$root):array{
    $curl=curl_init($origin.$path);$headers=[];curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_NOBODY=>$method==='HEAD',CURLOPT_TIMEOUT=>15,CURLOPT_COOKIEFILE=>$root.'/cookies',CURLOPT_COOKIEJAR=>$root.'/cookies',CURLOPT_HEADERFUNCTION=>static function($handle,string $line)use(&$headers):int{$headers[]=trim($line);return strlen($line);}]);
    if($method==='POST')curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($post));$body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);if(!is_string($body))throw new RuntimeException('Own newsletter HTTP required.');return[$status,$body,$headers];
};
$xpath=static function(string $html):DOMXPath{$dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);return new DOMXPath($dom);};
try{
    putenv('DVM_PUBLIC_FORM_FIXTURE='.$fixtureFile);
    $server=proc_open([...TestSupport::phpCommand(),'-S','127.0.0.1:'.$port,'-t',$root.'/web',__DIR__.'/support/public-form-http-router.php'],[0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']],$pipes,dirname(__DIR__,2));if(!is_resource($server))throw new RuntimeException('Own newsletter server required.');fclose($pipes[0]);
    for($i=0;$i<100;$i++){$ready=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1);if($ready){fclose($ready);break;}usleep(50000);}
    [$status,$html,$headers]=$http();$doc=$xpath($html);$csrf=$doc->evaluate('string(//input[@name="csrf"]/@value)');
    $check($status===200&&strlen($csrf)===64,'actual anonymous signup has its own session and native form');
    $check($doc->query('//link[contains(@href,"bootstrap-6.0.0-alpha.1.min.css")]')->length===1,'actual newsletter uses the local alpha base once');
    $check($doc->evaluate('string(//link[@rel="canonical"]/@href)')===$origin.'/newsletter/','actual newsletter canonical uses the trusted form origin');
    $check(in_array('Cache-Control: no-store',$headers,true)&&in_array('Referrer-Policy: no-referrer',$headers,true),'actual form protects caching and capability referrers');
    $input=['csrf'=>$csrf,'email'=>'fresh-owned@example.invalid','name'=>'Fresh Ä <marker>','purposes'=>['events'],'consent'=>'1'];
    foreach([['purposes'=>[]],['purposes'=>[['events']]],['email'=>['invalid']],['name'=>['invalid']],['website'=>['invalid']],['consent'=>'0']]as$bad){
        $before=ownOriginalDatabaseHash($db,$tables);[$status,$error]=$http('/newsletter/','POST',array_replace($input,$bad));
        $check($status===200&&$xpath($error)->query('//*[@role="alert"]')->length===1,'actual malformed signup presents a correctable error');
        $check(ownOriginalDatabaseHash($db,$tables)===$before,'actual invalid signup writes no row, token, rate, consent or job');
    }
    [$status,$error]=$http('/newsletter/','POST',array_replace($input,['csrf'=>'wrong']));
    $check($status===403&&$xpath($error)->evaluate('string(//input[@name="email"]/@value)')===$input['email'],'actual rejected CSRF retains the original address');
    [$status,$html]=$http('/newsletter/','POST',$input);
    $check($status===200&&str_contains(html_entity_decode($html,ENT_QUOTES|ENT_HTML5,'UTF-8'),'erhältst du eine Bestätigungsmail'),'actual valid signup queues a private confirmation');
    $subscriber=Db::run($db,'SELECT * FROM khn_newsletter_subscribers WHERE club_id=? AND email=?',[$club,$input['email']])->fetch();
    $check($subscriber&&$subscriber['status']==='pending'&&$subscriber['name']===$input['name']&&json_decode($subscriber['purposes'],true)===['events'],'actual native signup stores exact chosen originals pending DOI');
    $job=Db::run($db,"SELECT * FROM native_delivery_jobs WHERE club_id=? AND kind='confirm'",[$club])->fetch();
    $mail=$box->open('job:'.$club.':'.$job['id'],$job['ciphertext']);
    preg_match('~/newsletter/bestaetigen/\?token=([a-f0-9]{64})~',$mail['text'],$confirm);preg_match('~/newsletter/abmelden/\?token=([a-f0-9]{64})~',$mail['text'],$unsubscribe);
    $check(isset($confirm[1],$unsubscribe[1])&&$job['status']==='pending'&&(int)$job['attempts']===0,'original private mail contains both capabilities without a transport attempt');
    $confirmPath='/newsletter/bestaetigen/?token='.$confirm[1];$unsubscribePath='/newsletter/abmelden/?token='.$unsubscribe[1];
    foreach([$confirmPath,$unsubscribePath,'/newsletter-confirm?token='.$confirm[1],'/newsletter-unsubscribe?token='.$unsubscribe[1]]as$path){
        $before=ownOriginalDatabaseHash($db,$tables);[$status,$html]=$http($path);$check($status===200&&$xpath($html)->query('//form')->length===1,'actual original capability GET only presents its form');
        $check(!str_contains($xpath($html)->evaluate('string(//link[@rel="canonical"]/@href)'),$confirm[1])&&!str_contains($xpath($html)->evaluate('string(//link[@rel="canonical"]/@href)'),$unsubscribe[1]),'actual canonical never exposes the private capability');
        [$status,$body]=$http($path,'HEAD');$check($status===200&&$body==='','actual capability HEAD returns no body');
        $check(ownOriginalDatabaseHash($db,$tables)===$before,'actual GET and HEAD preserve every original capability and consent byte');
    }
    [$status]=$http($confirmPath,'POST',['csrf'=>'wrong','token'=>$confirm[1]]);$check($status===403,'confirmation requires its current own session');
    $before=ownOriginalDatabaseHash($db,$tables);$http('/newsletter/bestaetigen/','POST',['csrf'=>$csrf,'token'=>$foreignToken]);$check(ownOriginalDatabaseHash($db,$tables)===$before,'foreign original capability cannot mutate another club');
    [$status,$html]=$http($confirmPath,'POST',['csrf'=>$csrf,'token'=>$confirm[1]]);$check($status===200&&str_contains($html,'Anmeldung bestätigt.'),'actual native confirmation accepts the exact fresh capability');
    $confirmed=Db::run($db,'SELECT * FROM khn_newsletter_subscribers WHERE club_id=? AND id=?',[$club,$subscriber['id']])->fetch();$check($confirmed['status']==='confirmed','actual confirmation activates only its selected new subscriber');
    $before=ownOriginalDatabaseHash($db,$tables);$http($confirmPath,'POST',['csrf'=>$csrf,'token'=>$confirm[1]]);$check(ownOriginalDatabaseHash($db,$tables)===$before,'actual confirmation replay writes nothing');
    [$status,$html]=$http($unsubscribePath,'POST',['List-Unsubscribe'=>'One-Click','token'=>$unsubscribe[1]]);$check($status===200&&str_contains($html,'Abmeldung wurde bearbeitet'),'actual one-click withdrawal retains its native mail-client contract');
    $withdrawn=Db::run($db,'SELECT * FROM khn_newsletter_subscribers WHERE club_id=? AND id=?',[$club,$subscriber['id']])->fetch();$check($withdrawn['status']==='unsubscribed'&&$withdrawn['name']===''&&$withdrawn['consent_evidence']===''&&$withdrawn['purposes']==='[]','actual withdrawal removes only the selected new personal data');
    $before=ownOriginalDatabaseHash($db,$tables);$http($unsubscribePath,'POST',['List-Unsubscribe'=>'One-Click','token'=>$unsubscribe[1]]);$check(ownOriginalDatabaseHash($db,$tables)===$before,'actual one-click withdrawal replay is idempotent');
    if(getenv('DVM_NEWSLETTER_BROWSER')==='1'){
        putenv('DVM_NEWSLETTER_APP_ORIGIN='.$origin);$before=ownOriginalDatabaseHash($db,$tables);
        $process=proc_open(['node',__DIR__.'/newsletter-forms-app-browser.mjs'],[0=>STDIN,1=>STDOUT,2=>STDERR],$nodePipes,dirname(__DIR__,2));if(!is_resource($process)||proc_close($process)!==0)throw new RuntimeException('Actual newsletter browser failed.');
        $check(ownOriginalDatabaseHash($db,$tables)===$before,'actual mobile newsletter correction browser writes no database row');
    }
    $check(ownOriginalDatabaseHash($db,$tables,$filter)===$originalHash,'every prior original subscriber, foreign token, account, setting, page and publication is unchanged');
    $check($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN)===$tables,'actual newsletter HTTP changes no schema');
    $allowed=['khn_newsletter_subscribers','khn_newsletter_tokens','khn_newsletter_consent_events','native_newsletter_rates','native_newsletter_blocks','native_delivery_jobs'];
    foreach($tables as$table)if(!in_array($table,$allowed,true))$check((int)Db::run($db,'SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()===count($keys[$table]['values']),'original table row count retained: '.$table);
    foreach($allowed as$table){[$where,$args]=$filter($table);$newWhere=$where===' WHERE 0=1'?'':' WHERE NOT ('.substr($where,7).')';$rows=Db::run($db,'SELECT * FROM `'.$table.'`'.$newWhere,$args)->fetchAll();foreach($rows as$row){$check($row['club_id']===$club,'every fresh newsletter row belongs only to the selected test club');if(isset($row['subscriber_id']))$check($row['subscriber_id']===$subscriber['id'],'every fresh consent or token belongs to the exact new subscriber');}}
    $check(hash_file('sha256',$root.'/private.key')===$fileHashes[0]&&hash_file('sha256',$root.'/media/original.png')===$fileHashes[1],'private key and every original photo byte remain intact');
    echo json_encode(['suite'=>'native-newsletter-forms','checks'=>$n,'new_subscribers'=>1,'browser'=>getenv('DVM_NEWSLETTER_BROWSER')==='1','external_requests'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
}finally{if(is_resource($server)){proc_terminate($server);proc_close($server);}$files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($files as$file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($root);}
