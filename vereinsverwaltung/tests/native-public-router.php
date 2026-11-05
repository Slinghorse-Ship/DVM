<?php
declare(strict_types=1);
require dirname(__DIR__) . '/native/bootstrap.php';
require dirname(__DIR__) . '/src/bootstrap.php';
use Kulturhus\Native\Cms\{Media, Pages, Schema};
use Kulturhus\Native\Integration\{ManagerBridge, PublicSite};
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Native\TestSupport;
use Kulturhus\Verein\{Database, MembershipApplications};

$db = TestSupport::db(); Database::migrate($db); $seed = TestSupport::seed($db); Schema::install($db);
$club = 'public-router-' . bin2hex(random_bytes(5)); $nativeClub = ManagerBridge::clubKey($club);
Db::run($db, 'INSERT INTO clubs(id,name) VALUES(?,?)', [$club, 'Synthetische Routerprüfung']);
Db::run($db, "INSERT INTO actors(id,club_id,role,is_superuser) VALUES(?,?,'members',1)", [$club . '-staff', $club]);
Database::ensureContributionRates($db);
foreach (['admin', 'reader'] as $role) {
    Db::run($db, 'INSERT INTO native_users(id,club_id,name) VALUES(?,?,?)', [$club . '-' . $role, $nativeClub, 'Router ' . $role]);
    Db::run($db, 'INSERT INTO native_grants(actor_id,module,capability) SELECT ?,module,capability FROM native_grants WHERE actor_id=? AND module=\'website\'', [$club . '-' . $role, $seed[$role]]);
}
$admin = new Access($db, $club . '-admin'); $reader = new Access($db, $club . '-reader'); $pages = new Pages($db, $admin);
$root = dirname(__DIR__, 2) . '/.local/native-public-router-tests/' . $club; mkdir($root, 0700, true);
$media = new Media($db, $admin, $root . '/media');
$image = imagecreatetruecolor(20, 20); imagepng($image, $root . '/synthetic.png'); unset($image);
$asset = $media->upload($root . '/synthetic.png', 'synthetic.png', 'Synthetisches Testbild');
$draftAsset = $media->upload($root . '/synthetic.png', 'draft.png', 'Privates Entwurfsbild');
$ids = [];
foreach ([
    ['slug'=>'start','title'=>'Öffentliches Kulturhus','excerpt'=>'Synthetische Website'],
    ['slug'=>'aktuelles','title'=>'Aktuelles'],
    ['slug'=>'aktuelles/testbeitrag','title'=>'Öffentlicher Testbeitrag','template'=>'news','news_date'=>'2026-10-08','body_html'=>'<p>Veröffentlichter Artikel <a href="/site/start">Eigener Inhaltslink</a></p>','media_ids'=>[$asset['id']]],
    ['slug'=>'mitglied-werden','title'=>'Mitglied werden'], ['slug'=>'satzung','title'=>'Synthetische Satzung','body_html'=>'<h2>§ 4 <strong>Mitgliedschaft</strong></h2><p>Über die Aufnahme entscheidet der Vorstand.</p><h3>§ 4 Wiederholung</h3>'],
    ['slug'=>'datenloeschung','title'=>'Synthetische Datenlöschung','body'=>'Ungültiger synthetischer Testtext','nav_visible'=>false],
    ['slug'=>'nutzungsbedingungen','title'=>'Synthetische Nutzungsbedingungen','body'=>'Ungültiger synthetischer Testtext','nav_visible'=>false],
    ['slug'=>'geheim','title'=>'Privater Entwurf'], ['slug'=>'galerie','title'=>'Galerie','template'=>'gallery'],
] as $input) {
    $p = $pages->save($input); $ids[$p['slug']] = $p['id'];
    if ($p['slug'] !== 'geheim') $pages->publish($p['id'], 1);
}
$published = $pages->get($ids['aktuelles/testbeitrag']); $published['title'] = 'Geheim geänderter Titel'; $published['body_html'] = '<p>Privater Text</p>'; $published['media_ids'] = [$draftAsset['id']]; $pages->save($published, 2);
$staff = new MembershipApplications($db, $club, $club . '-staff'); $settings = $staff->settings()['config']; $settings['enabled'] = true; $settings['notice_text']='Über die Aufnahme entscheidet der Vorstand gemäß § 4 der Satzung.'; $staff->saveSettings($settings, 0);
mkdir($root . '/sessions', 0700);
putenv('KH_NATIVE_MANAGER_SESSION_ROOT=' . $root . '/sessions');
\Kulturhus\Native\Integration\ManagerSession::start();
$checks = 0;
$check = static function(bool $ok, string $label) use (&$checks): void { if (!$ok) throw new RuntimeException('FAIL: '.$label); $checks++; };
$call = static function(string $uri, string $method = 'GET', array $post = [], ?Access $access = null) use ($db, $reader, $root, $club): array {
    header_remove(); http_response_code(200); $_SERVER['REQUEST_URI'] = $uri; $_SERVER['REQUEST_METHOD'] = $method; unset($_SERVER['HTTP_IF_NONE_MATCH']);
    parse_str((string) parse_url($uri, PHP_URL_QUERY), $_GET); $_POST = $post; ob_start();
    try { $handled = PublicSite::serve($db, $access ?? $reader, $root.'/media', $root, $club, 'http://127.0.0.1:8194'); $body = ob_get_contents(); }
    finally { ob_end_clean(); }
    // DOM serialization may emit equivalent HTML entities for Unicode characters.
    return [$handled, http_response_code(), str_starts_with($body, '<!DOCTYPE html>') ? html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $body];
};
[$handled, $status, $html] = $call('/');
file_put_contents($root . '/home-response.html', $html);
$check($handled && $status === 200 && str_contains($html, 'Öffentliches Kulturhus'), 'root serves published home');
$check(str_contains($html, 'href="/vereinsverwaltung/"') && !str_contains($html, 'href="/?view=website"'), 'explicit real manager link');
$check(str_contains($html, 'href="/aktuelles/"') && str_contains($html, 'href="/aktuelles/testbeitrag/"'), 'layout links use original URLs');
$check(!str_contains($html, 'Geheim geänderter Titel'), 'homepage does not use draft title');
foreach(['datenloeschung'=>'Datenlöschung','nutzungsbedingungen'=>'Nutzungsbedingungen']as$slug=>$label){
    $check(str_contains($html,'href="/'.$slug.'/">'.$label.'</a>'),'published legal footer uses actual public route '.$slug);
    preg_match('~<nav id="nav"[^>]*>(.*?)</nav>~s',$html,$mainMenu);$check(!str_contains($mainMenu[1],'/'.$slug.'/'),'footer page stays hidden from main navigation '.$slug);
    foreach(['/'.$slug.'/','/site/'.$slug]as$uri){[, $status,$body]=$call($uri);$check($status===200&&str_contains($body,'Ungültiger synthetischer Testtext'),'actual published legal route '.$uri);}
}
foreach(['/search?q=Öffentlicher','/mitglied-werden/','/aktuelles/testbeitrag/']as$uri){[, $status,$body]=$call($uri);$check($status===200&&str_contains($body,'href="/datenloeschung/">Datenlöschung</a>')&&str_contains($body,'href="/nutzungsbedingungen/">Nutzungsbedingungen</a>'),'shared legal footer is present on actual distinct renderer '.$uri);}
$privateLegal=$pages->get($ids['datenloeschung']);$privateLegal['slug']='private-legal-draft';$privateLegal['body']='PRIVATE-LEGAL-DRAFT';$privateLegal=$pages->save($privateLegal,$privateLegal['version']);
[,,$body]=$call('/');$check(str_contains($body,'href="/datenloeschung/"')&&!str_contains($body,'private-legal-draft')&&!str_contains($body,'PRIVATE-LEGAL-DRAFT'),'footer reads published original even after private legal address edit');
foreach (['/aktuelles/', '/site/aktuelles', '/site/aktuelles/', '/aktuelles/testbeitrag/', '/site/aktuelles/testbeitrag'] as $uri) {
    [, $status, $html] = $call($uri); $check($status===200 && str_contains($html,'Öffentlicher Testbeitrag') && !str_contains($html,'Privater Text'), 'published route ' . $uri);
}
[,,$html] = $call('/aktuelles/testbeitrag/');
$check((bool)preg_match('~<a[^>]+href="/site/start"[^>]*>Eigener Inhaltslink</a>~', $html), 'stored RichText links remain unchanged');
$check(str_contains($html, 'href="http://127.0.0.1:8194/aktuelles/testbeitrag/"'), 'original article canonical');
[,,$html] = $call('/'); $check(str_contains($html,'href="http://127.0.0.1:8194/"'),'root canonical');
$check(substr_count($html,'href="/newsletter/"')===3&&str_contains($html,'newsletter-callout')&&!str_contains($html,'href="/site/newsletter"'),'actual public home exposes all three built-in signup entries without a newsletter CMS page');
foreach (['/site/geheim','/aktuelles/nicht-vorhanden','/site/../secret','/feeds/unknown'] as $uri) { [, $status] = $call($uri); $check($status===404,'missing/private public route '.$uri); }
foreach (['/vereinsverwaltung/','/login/','/social-credit','/media-preview/'.$asset['id'].'/thumb','/chat-file/a/1'] as $uri) { [$handled,, $html] = $call($uri); $check(!$handled && $html==='','manager route is not claimed '.$uri); }
[, $status, $imageBody] = $call('/media/'.$asset['id'].'/thumb'); $check($status===200 && str_starts_with($imageBody,"\x89PNG"),'published media served');
[, $status] = $call('/media/'.$draftAsset['id'].'/thumb'); $check($status===404,'draft media unavailable');
foreach (['/feeds/all.rss.xml','/feeds/alle/','/feeds/events.ics','/feeds/termine/','/feeds/events.json','/feeds/veranstaltungen/','/robots.txt','/sitemap.xml'] as $uri) { [, $status, $body]=$call($uri); $check($status===200&&$body!==''&&!str_contains($body,'Geheim geänderter Titel'),'public projection '.$uri); }
[,,$body]=$call('/sitemap.xml'); $check(str_contains($body,'http://127.0.0.1:8194/aktuelles/testbeitrag/')&&!str_contains($body,'/site/'),'sitemap original URLs');
[,,$body]=$call('/search?q=Öffentlicher'); $check(str_contains($body,'Öffentlicher Testbeitrag')&&!str_contains($body,'Privater Text'),'public search snapshot');
[,,$body]=$call('/suche/?q=Privater'); $check(str_contains($body,'Keine veröffentlichten Inhalte'),'search excludes draft');
[, $status]=$call('/search?q[]=bad'); $check($status===400,'array search rejected');
[, $status]=$call('/aktuelles/?page=-1'); $check($status===400,'invalid page rejected');
[, $status, $body]=$call('/verein/satzung/'); $check($status===200&&str_contains($body,'Synthetische Satzung'),'legacy statute alias');
$check(substr_count($body,'id="paragraf-4"')===1&&str_contains($body,'<h2 id="paragraf-4">§ 4 <strong>Mitgliedschaft</strong></h2>'),'published statute exposes one stable anchor for its actual membership paragraph');
[, $status, $body]=$call('/mitglied-werden/');$check($status===200&&str_contains($body,'href="/verein/satzung/#paragraf-4"'),'application links to the actual statute route and matching paragraph');
foreach (['/','/aktuelles/','/feeds/all.rss.xml','/media/'.$asset['id'].'/thumb','/assets/public.js'] as $uri) { [, $status]=$call($uri,'POST'); $check($status===405,'POST read-only route rejected '.$uri); }
foreach (['/','/aktuelles/','/feeds/all.rss.xml','/media/'.$asset['id'].'/thumb','/assets/public.js','/mitglied-werden/'] as $uri) { [, $status, $body]=$call($uri,'HEAD'); $check($status===200&&$body==='','HEAD has no body '.$uri); }
foreach (PublicSite::assets() as $uri=>$file) { $check(is_file($file),'asset exists '.$uri); }
[, $status, $body]=$call('/mitglied-werden/'); $check($status===200&&str_contains($body,'name="csrf"')&&str_contains($body,'48,00'),'real manager membership form');
[, $status]=$call('/mitglied-werden/','POST',['csrf'=>'wrong']); $check($status===403,'membership invalid CSRF denied');
$q=$db->prepare('SELECT COUNT(*) FROM application_confirmations WHERE club_id=?');$q->execute([$club]);$check((int)$q->fetchColumn()===0,'CSRF denial creates no application');
$post=['csrf'=>$_SESSION['csrf'],'request_key'=>bin2hex(random_bytes(16)),'configuration_version'=>'1','first_name'=>'Synthetisch','last_name'=>'Routertest','birthday'=>'1990-01-02','email'=>'router@example.invalid','address'=>'Testweg 1','membership_type'=>'single','consent'=>'1'];
[, $status, $body]=$call('/mitglied-werden/','POST',$post); $check($status===200&&str_contains($body,'Bitte bestätige deinen Mitgliedsantrag'),'POST uses manager intake');
$q->execute([$club]);$check((int)$q->fetchColumn()===1,'request stored under original manager club');
$q->execute([$nativeClub]);$check((int)$q->fetchColumn()===0,'request never stored under hashed CMS club');
$call('/site/mitglied-werden','POST',$post);$q->execute([$club]);$check((int)$q->fetchColumn()===1,'POST alias idempotent');
$check(count($staff->applications())===0,'no board application before confirmation');
[, $status] = $call('/', 'GET', [], new Access($db, $seed['reader'])); $check($status===403,'mismatched CMS club denied');
$program=$pages->save(['slug'=>'veranstaltungen','title'=>'Programm']);$pages->publish($program['id'],$program['version']);
$event=$pages->save(['slug'=>'veranstaltungen/routertermin','title'=>'Öffentliches Terminoriginal','template'=>'event','event_start'=>'2050-01-12T19:00','event_end'=>'2050-01-12T21:00:00Z','event_address'=>'Routerweg 2','event_ticket_url'=>'https://example.invalid/tickets']);$event=$pages->publish($event['id'],$event['version']);
$privateEvent=$pages->save(['slug'=>'veranstaltungen/privater-routertermin','title'=>'PRIVATE-EVENT-ROUTER','template'=>'event','event_start'=>'2050-01-13T19:00']);
$legacyEvent=$pages->save(['slug'=>'veranstaltungen/legacy-event-2050','title'=>'Published earlier social event','template'=>'event','event_start'=>'2050-01-14T19:00']);$pages->publish($legacyEvent['id'],$legacyEvent['version']);
foreach(['GET','HEAD']as$method){[$handled,$status,$body]=$call('/veranstaltungen/legacy---event--2050/?utm_source=instagram%2Ffeed',$method);$check($handled&&$status===308&&$body==='','published repeated-hyphen event redirects without rendering '.$method);}
[, $status]=$call('/veranstaltungen/legacy---event--2050/','POST');$check($status===405,'earlier social event alias never accepts a write');
[, $status]=$call('/veranstaltungen/privater--routertermin/');$check($status===404,'private canonical event never gains an earlier-link redirect');
[, $status]=$call('/veranstaltungen/unknown---event--2050/');$check($status===404,'unknown earlier event link never invents a target');
[$handled]=$call('/account--private/');$check(!$handled,'earlier event compatibility does not relax unrelated path syntax');
foreach(['/veranstaltungen/','/site/veranstaltungen'] as $uri){[, $status,$body]=$call($uri.'?jahr=2050&q=Terminoriginal');$check($status===200&&str_contains($body,'Öffentliches Terminoriginal')&&!str_contains($body,'PRIVATE-EVENT-ROUTER'),'programme original route and filtered site alias '.$uri);}
foreach(['/veranstaltungen/routertermin/','/site/veranstaltungen/routertermin'] as $uri){[, $status,$body]=$call($uri);$check($status===200&&str_contains($body,'Routerweg 2')&&str_contains($body,'Termin speichern'),'event details preserve public route '.$uri);}
foreach(['/feeds/termine/','/feeds/events.ics'] as $uri){[, $status,$body]=$call($uri.'?event='.$event['id']);$check($status===200&&substr_count($body,"BEGIN:VEVENT\r\n")===1&&str_contains($body,'DTEND:20500112T210000Z'),'single original event calendar '.$uri);}
[, $status,$body]=$call('/feeds/termine/?event='.$event['id'],'HEAD');$check($status===200&&$body==='','single calendar HEAD remains body-free');
[, $status]=$call('/feeds/termine/?event='.$privateEvent['id']);$check($status===404,'private calendar original unavailable');
[, $status]=$call('/feeds/termine/?event[]=bad');$check($status===400,'array calendar identity rejected');
[, $status]=$call('/veranstaltungen/?q[]=bad');$check($status===400,'array programme query rejected');
[, $status]=$call('/veranstaltungen/?seite=-1');$check($status===400,'invalid programme pagination rejected');
$state=['manager_club'=>$club,'reader'=>$club.'-reader','runtime'=>$root,'media_root'=>$root.'/media','public_media'=>$asset['id'],'draft_media'=>$draftAsset['id'],'native_asset_hash'=>substr(hash_file('sha256',dirname(__DIR__).'/native/Integration/assets/manager.js'),0,16)];
file_put_contents(dirname($root).'/current.json',json_encode($state,JSON_THROW_ON_ERROR));
if(getenv('DVM_PUBLIC_ROUTER_HTTP')==='1'){
    $socket=stream_socket_server('tcp://127.0.0.1:8194',$errno,$error);if(!$socket)throw new RuntimeException('Own public HTTP harness port unavailable.');fclose($socket);
    if(!is_dir($root.'/web'))mkdir($root.'/web',0700);$environment=getenv();$environment['KH_NATIVE_PUBLIC_TEST_PORT']='8194';$log=fopen($root.'/http-server.log','ab');
    $http=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),'-S','127.0.0.1:8194','-t',$root.'/web',__DIR__.'/native-public-router-http.php'],[0=>['pipe','r'],1=>$log,2=>$log],$pipes,null,$environment);
    if(!is_resource($http))throw new RuntimeException('Own public HTTP harness unavailable.');fclose($pipes[0]);
    try{
        $ready=false;for($attempt=0;$attempt<50;$attempt++){$probe=@fsockopen('127.0.0.1',8194,$errno,$error,.1);if($probe){fclose($probe);$ready=true;break;}usleep(100000);}if(!$ready)throw new RuntimeException('Own public HTTP harness did not start.');
        $node=proc_open(['node',__DIR__.'/native-public-router-http.cjs'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$nodePipes);if(!is_resource($node))throw new RuntimeException('Own public HTTP assertions unavailable.');fclose($nodePipes[0]);
        $output=stream_get_contents($nodePipes[1]);$failure=stream_get_contents($nodePipes[2]);fclose($nodePipes[1]);fclose($nodePipes[2]);if(proc_close($node)!==0)throw new RuntimeException('Public HTTP assertions failed: '.$failure);echo$output;
    }finally{proc_terminate($http);proc_close($http);fclose($log);}
}
$result=['suite'=>'native-public-router','passed'=>$checks,'transport'=>'none','fixture'=>'isolated combined manager/native database, random club'];
file_put_contents(dirname($root).'/service-result.json',json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
echo json_encode($result,JSON_PRETTY_PRINT).PHP_EOL;
