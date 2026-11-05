<?php declare(strict_types=1);
require dirname(__DIR__) . '/native/bootstrap.php';
require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/support/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Cms\{Pages, Schema};
use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Verein\{Database, MembershipApplications};

$db = TestSupport::db();
Database::migrate($db); $seed = TestSupport::seed($db); Schema::install($db); Kulturhus\Native\Communication\Schema::install($db);
$club = 'own-family-' . bin2hex(random_bytes(6)); $native = ManagerBridge::clubKey($club);
Db::run($db, 'INSERT INTO clubs(id,name) VALUES(?,?)', [$club, 'Eigene synthetische Familienprüfung']);
Db::run($db, "INSERT INTO actors(id,club_id,role,is_superuser) VALUES(?,?,'members',1)", [$club . '-staff', $club]);
Database::ensureContributionRates($db);
foreach (['admin','reader'] as $role) {
    Db::run($db, 'INSERT INTO native_users(id,club_id,name) VALUES(?,?,?)', [$club . '-' . $role, $native, 'Eigene Familienprüfung']);
    Db::run($db, "INSERT INTO native_grants(actor_id,module,capability) SELECT ?,module,capability FROM native_grants WHERE actor_id=? AND module='website'", [$club . '-' . $role, $seed[$role]]);
}
Db::run($db,'INSERT INTO native_club_settings(club_id,version,payload,updated_by,updated_at) VALUES(?,1,?,?,UTC_TIMESTAMP())',[$native,json_encode(['name'=>'Sport & Gemeinschaft <Verein>','address'=>'Private synthetic address','origin'=>'https://membership.example.invalid','logo_id'=>''],JSON_THROW_ON_ERROR),$club.'-admin']);
$pages = new Pages($db, new Access($db, $club . '-admin'));
foreach (['start','mitglied-werden'] as $slug) {
    $page = $pages->save(['slug'=>$slug,'title'=>'Eigene Familienprüfung','body'=>'Synthetischer Originaltext Ä']);
    $pages->publish($page['id'], 1);
}
$intake = new MembershipApplications($db, $club, $club . '-staff');
$settings = $intake->settings()['config']; $settings['enabled'] = true; $intake->saveSettings($settings, 0);
$root = dirname(__DIR__, 2) . '/.local/native-membership-family-' . bin2hex(random_bytes(8));
mkdir($root . '/sessions', 0700, true); mkdir($root . '/media', 0700);
mkdir($root . '/web', 0700);
file_put_contents($root . '/media/own-original.txt', "Immutable synthetic original Ä\n");
$originalFile = hash_file('sha256', $root . '/media/own-original.txt');
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$socket) throw new RuntimeException('Own HTTP port unavailable.');
$port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1); fclose($socket);
$origin = 'http://127.0.0.1:' . $port;
$types = ['single','family','family_member']; $keys = [];
foreach ($types as $type) $keys[$type] = bin2hex(random_bytes(16));
$browserCases = [];
foreach ([true,false] as $js) foreach (['family','family_member'] as $type) $browserCases[] = ['js'=>$js,'type'=>$type,'key'=>bin2hex(random_bytes(16))];
$fixture = ['origin'=>$origin,'runtime'=>$root,'club'=>$club,'reader'=>$club.'-reader','cases'=>$browserCases];
$fixtureFile = $root . '/browser.json'; file_put_contents($fixtureFile, json_encode($fixture, JSON_THROW_ON_ERROR)); chmod($fixtureFile, 0600);
$tables = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
$original = [];
foreach ($tables as $table) {
    $columns = Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);
    if (!$columns) throw new RuntimeException('Stable original keys required.');
    $original[$table] = ['columns'=>$columns,'values'=>Db::run($db,'SELECT `'.implode('`,`',$columns).'` FROM `'.$table.'`')->fetchAll(PDO::FETCH_NUM)];
}
$filter = static function(string $table) use ($original): array {
    $entry = $original[$table]; if (!$entry['values']) return [' WHERE 0=1',[]];
    $args = []; foreach ($entry['values'] as $values) array_push($args, ...$values);
    return [' WHERE (`'.implode('`,`',$entry['columns']).'`) IN ('.implode(',',array_fill(0,count($entry['values']),'('.implode(',',array_fill(0,count($entry['columns']),'?')).')')).')',$args];
};
$originalHash = ownOriginalDatabaseHash($db, $tables, $filter);
$count = 0; $server = null; $pipes = [];
$check = static function(bool $ok, string $label) use (&$count): void {
    if (!$ok) throw new RuntimeException($label); $count++; echo 'PASS '.$label."\n";
};
$http = static function(string $method='GET', array $post=[]) use ($root, $origin): array {
    $curl = curl_init($origin . '/mitglied-werden/');
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_TIMEOUT=>15,CURLOPT_COOKIEJAR=>$root.'/cookies',CURLOPT_COOKIEFILE=>$root.'/cookies']);
    if ($method==='POST') curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($post));
    $body = curl_exec($curl); $status = curl_getinfo($curl,CURLINFO_RESPONSE_CODE); curl_close($curl);
    if (!is_string($body)) throw new RuntimeException('Own membership HTTP unavailable.');
    return [$status, $body];
};
try {
    putenv('DVM_PUBLIC_FORM_FIXTURE='.$fixtureFile);
    $server = proc_open([...TestSupport::phpCommand(),'-S','127.0.0.1:'.$port,'-t',$root.'/web',__DIR__.'/support/public-form-http-router.php'],[0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']],$pipes,dirname(__DIR__,2));
    if (!is_resource($server)) throw new RuntimeException('Own HTTP server unavailable.'); fclose($pipes[0]);
    for ($i=0;$i<100;$i++) { $ready=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1); if ($ready) { fclose($ready); break; } usleep(50000); }
    [$status,$html] = $http();
    $check($status===200,'actual published public membership route opens');
    $check(str_contains($html,'Sport &amp; Gemeinschaft &lt;Verein&gt;')&&!str_contains($html,'Kulturhus')&&!str_contains($html,'Jaderberg')&&!str_contains($html,'Wattenmeer'),'actual current club identity reaches public introduction and generic application copy');
    $check(!str_contains($html,'Private synthetic address'),'membership introduction exposes no private identity address');
    $dom = new DOMDocument(); @$dom->loadHTML($html); $xpath = new DOMXPath($dom);
    $csrf = $xpath->evaluate('string(//input[@name="csrf"]/@value)');
    $check(strlen($csrf)===64,'actual anonymous session supplies its own CSRF');
    $details = $xpath->query('//details[@data-family-fields]')->item(0);
    $check($details!==null&&!$details->hasAttribute('hidden'),'native family disclosure remains reachable without JavaScript');
    $check($xpath->evaluate('string(//form[contains(@class,"kh-membership-form")]/@action)')==='#membership-application','actual native submission returns to the application anchor');
    foreach ($types as $type) {
        $input = ['csrf'=>$csrf,'request_key'=>$keys[$type],'configuration_version'=>'1','first_name'=>'Eigene Ä','last_name'=>'Familienprüfung '.$type,'birthday'=>'1990-01-02','email'=>$keys[$type].'@example.invalid','address'=>'Synthetischer Testweg 1','membership_type'=>$type,'family_members'=>$type==='family'?"Weitere Ä Person\nZweite Person":'','family_reference'=>$type==='family_member'?'Eigener Beitragszahler Ä':'','consent'=>'1'];
        $before = (int) Db::run($db,'SELECT COUNT(*) FROM application_confirmations')->fetchColumn();
        [$status,$errorHtml] = $http('POST',array_replace($input,['configuration_version'=>'0']));
        $errorText = html_entity_decode($errorHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $check($status===200&&str_contains($errorText,'Antragsbedingungen wurden geändert'),'actual '.$type.' submission rejects stale configuration (HTTP '.$status.')');
        $check(str_contains($errorText,'Eigene Ä')&&str_contains($errorText,'Familienprüfung '.$type),'actual '.$type.' error retains original typed names');
        $check((int)Db::run($db,'SELECT COUNT(*) FROM application_confirmations')->fetchColumn()===$before,'actual '.$type.' stale submission creates no request');
        [$status] = $http('POST',array_replace($input,['csrf'=>'wrong'])); $check($status===403,'actual '.$type.' invalid session is rejected before intake');
        if ($type==='family_member') {
            [$status,$errorHtml] = $http('POST',array_replace($input,['family_reference'=>'']));
            $check($status===200&&str_contains($errorHtml,'Beitragszahlers angeben'),'actual native family member requires the payer reference');
            $errorDom = new DOMDocument(); @$errorDom->loadHTML($errorHtml);
            $check((new DOMXPath($errorDom))->query('//details[@data-family-fields and @open]')->length===1,'actual family correction keeps its native details open');
        }
        [$status,$success] = $http('POST',$input);
        $check($status===200&&str_contains(html_entity_decode($success, ENT_QUOTES | ENT_HTML5, 'UTF-8'),'Bitte bestätige deinen Mitgliedsantrag'),'actual '.$type.' request awaits personal email confirmation');
        $row = Db::run($db,'SELECT * FROM application_confirmations WHERE club_id=? AND request_key=?',[$club,$keys[$type]])->fetch();
        $stored = json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR);
        $check($stored['membership_type']===$type&&$stored['family_members']===$input['family_members']&&$stored['family_reference']===$input['family_reference'],'actual '.$type.' request retains exact original family answers');
        $check($row['application_id']===null&&(int)$row['newsletter']===0&&(int)$row['configuration_version']===1,'actual '.$type.' request creates no board membership or newsletter consent');
        $http('POST',$input);
        $check(Db::run($db,'SELECT * FROM application_confirmations WHERE id=?',[$row['id']])->fetch()===$row,'actual '.$type.' retry leaves its original confirmation untouched');
    }
    if (getenv('DVM_MEMBERSHIP_BROWSER')==='1') {
        $process = proc_open(['node',__DIR__.'/membership-family-browser.mjs'],[0=>STDIN,1=>STDOUT,2=>STDERR],$browserPipes,dirname(__DIR__,2));
        if (!is_resource($process)||proc_close($process)!==0) throw new RuntimeException('Actual family browser contracts failed.');
    }
    $check(ownOriginalDatabaseHash($db,$tables,$filter)===$originalHash,'every earlier row, setting, account, publication and revision retains exact original bytes');
    $check($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN)===$tables,'actual intake changes no database schema');
    $allowed = array_values($keys);
    if (getenv('DVM_MEMBERSHIP_BROWSER')==='1') array_push($allowed,...array_column($browserCases,'key'));
    foreach ($tables as $table) {
        $expected = count($original[$table]['values']) + ($table==='application_confirmations'?count($allowed):0);
        $check((int)Db::run($db,'SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()===$expected,'exact original row count retained: '.$table);
    }
    $requests = Db::run($db,'SELECT * FROM application_confirmations WHERE club_id=?',[$club])->fetchAll();
    $check(count($requests)===count($allowed)&&!array_diff(array_column($requests,'request_key'),$allowed),'only exact fresh selected synthetic confirmation requests were created');
    if (getenv('DVM_MEMBERSHIP_BROWSER')==='1') foreach ($browserCases as $case) {
        $row = Db::run($db,'SELECT * FROM application_confirmations WHERE club_id=? AND request_key=?',[$club,$case['key']])->fetch();
        $payload = json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR);
        $check($payload['first_name']==='Eigene Vorschau Ä'&&$payload['last_name']==='Familienprüfung '.$case['type']&&$payload['email']===$case['key'].'@example.invalid'&&$payload['membership_type']===$case['type'],'actual corrected browser request retains exact selected personal originals');
        // Native form submission serializes textarea line endings as CRLF.
        $check($payload['family_members']===($case['type']==='family'?"Weitere Ä Person\r\nZweite eigene Person":'')&&$payload['family_reference']===($case['type']==='family_member'?'Eigener Beitragszahler Ä':''),'actual browser intake retains exact Unicode family wire bytes and original payer reference');
        $check((int)$row['configuration_version']===1&&(int)$row['consent']===1&&(int)$row['newsletter']===0&&$row['application_id']===null,'actual browser confirmation has current deliberate consent and no premature admission');
    }
    $check(array_all($requests,static fn(array $row):bool=>is_string($row['mail_token'])&&strlen($row['mail_token'])===64&&hash_equals($row['token_hash'],hash('sha256',$row['mail_token']))&&(int)$row['expires_at']-(int)$row['created_at']===172800),'every actual pending request has its original independent 48 hour confirmation token');
    $check(hash_file('sha256',$root.'/media/own-original.txt')===$originalFile,'original stored media bytes remain unchanged');
    echo json_encode(['suite'=>'native-membership-family','checks'=>$count,'pending_requests'=>count($allowed),'browser'=>getenv('DVM_MEMBERSHIP_BROWSER')==='1','external_requests'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as$file) $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname()); rmdir($root);
}
