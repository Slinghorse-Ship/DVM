<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Communication\{Schema,PressContacts,PressRecipientCatalogue as Catalogue,Workspace};
use Kulturhus\Native\Web\Manager;
use Kulturhus\Native\Integration\EmbeddedManager;

$db=TestSupport::db();$browser=getenv('DVM_PRESS_RECIPIENTS_BROWSER')==='1';
if($browser){require dirname(__DIR__).'/src/bootstrap.php';Kulturhus\Verein\Database::migrate($db);}
TestSupport::seed($db);Schema::install($db);Kulturhus\Native\Cms\Schema::install($db);
$cfg=TestSupport::config();$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$cfg['database'];mkdir($root,0700,true);mkdir($root.'/media',0700);mkdir($root.'/web',0700);mkdir($root.'/sessions',0700);
file_put_contents($root.'/private.key',random_bytes(32));chmod($root.'/private.key',0600);
$access=new Access($db,'admin');$club=$access->club();$n=0;$server=null;
$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException($label);++$n;echo 'PASS '.$label.PHP_EOL;};
$deny=static function(callable $f,string $label)use($check):void{try{$f();}catch(DomainException|Kulturhus\Native\Cms\Conflict){$check(true,$label);return;}throw new RuntimeException($label);};
$dom=static function(string $html):DOMXPath{$d=new DOMDocument();@$d->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET);return new DOMXPath($d);};
try{
    $contacts=[];
    Db::atomic($db,function()use($db,$club,&$contacts):void{for($i=1;$i<=900;$i++){$id=bin2hex(random_bytes(16));$contacts[]=$id;Db::run($db,'INSERT INTO khn_press_contacts(club_id,id,email,name,outlet,active,version,updated_at) VALUES(?,?,?,?,?,1,1,UTC_TIMESTAMP())',[$club,$id,'kontakt'.$i.'@example.invalid',sprintf('Kontakt %04d',$i),sprintf('Redaktion %04d',$i)]);}});
    $service=new PressContacts($db,$access);$literal=$service->save(['email'=>'literal@example.invalid','name'=>'Eigener Suchkontakt','outlet'=>'Spezial %_!']);$inactive=$service->save(['email'=>'inactive@example.invalid','name'=>'Inaktives Original','outlet'=>'Eigene Redaktion','active'=>false]);
    $foreign=(new PressContacts($db,new Access($db,'outsider')))->save(['email'=>'foreign@example.invalid','name'=>'Foreign private contact','outlet'=>'Foreign secret']);
    $eligible=$service->activeIds([$contacts[0],$inactive['id'],$literal['id'],$foreign['id']]);
    $check(count($eligible)===2&&!array_diff([$contacts[0],$literal['id']],$eligible),'targeted eligibility reads only selected active own IDs, excluding inactive and foreign originals');
    $check($service->activeIds([])===[],'empty targeted eligibility needs no contact list');
    $deny(fn()=>$service->activeIds(array_slice($contacts,0,501)),'targeted eligibility shares the actual maximum of five hundred recipients');
    $other=TestSupport::db();$db->beginTransaction();Db::run($db,'SELECT active FROM khn_press_contacts WHERE club_id=? AND id=?',[$club,$contacts[0]])->fetchColumn();
    Db::run($other,'UPDATE khn_press_contacts SET active=0 WHERE club_id=? AND id=?',[$club,$contacts[0]]);
    $check($service->activeIds([$contacts[0]])===[],'actual concurrent contact revocation remains current inside an older repeatable-read transaction');$db->rollBack();Db::run($other,'UPDATE khn_press_contacts SET active=1 WHERE club_id=? AND id=?',[$club,$contacts[0]]);
    $first=Catalogue::page($db,$access,[]);$second=Catalogue::page($db,$access,['page'=>2]);
    $check($first['total']===901&&count($first['rows'])===6&&count($second['rows'])===6&&!array_intersect(array_column($first['rows'],'id'),array_column($second['rows'],'id')),'901 active contacts are read as six distinct metadata rows per page');
    $check(Catalogue::page($db,$access,['search'=>'Redaktion 0900'])['rows'][0]['id']===$contacts[899],'search includes the exact outlet beyond the initial page');
    foreach(['%_!','literal@example.invalid','Eigener Suchkontakt']as$query)$check(Catalogue::page($db,$access,['search'=>$query])['total']===1,'literal search matches only own contact: '.$query);
    $check(Catalogue::page($db,$access,['search'=>'Foreign secret'])['total']===0,'another club contact never enters search results');
    $check(Catalogue::page($db,$access,['page'=>1000000])['page']===151,'outdated valid page clamps to the actual final page');
    foreach([0,'01',[],1.5,1000001]as$page)$deny(fn()=>Catalogue::page($db,$access,['page'=>$page]),'invalid page cannot select an ambiguous recipient window');
    $seed=[$contacts[20],$contacts[2]];$visible=array_slice($contacts,0,6);
    $post=['press_picker_present'=>'1','recipient_ids'=>$seed,'press_visible'=>$visible,'press_choice'=>[$contacts[2],$contacts[0]]];
    $check(Catalogue::selection($post)===[$contacts[20],$contacts[2],$contacts[0]],'hidden original order survives page selection and new choices append once');
    $post['press_choice']=[];$check(Catalogue::selection($post)===[$contacts[20]],'unchecking visible rows never clears selections outside the window');
    $post['press_choice']=[$foreign['id']];$deny(fn()=>Catalogue::selection($post),'checkbox outside the submitted visible window is rejected');
    $deny(fn()=>Catalogue::page($db,$access,['recipient_ids'=>[$foreign['id']]]),'selected lookup verifies exact current club ownership');
    $selected=Catalogue::page($db,$access,['mode'=>'selected','recipient_ids'=>[$inactive['id'],$contacts[0]]]);
    $check($selected['total']===2&&(int)$selected['rows'][0]['active']===0,'selected inactive original remains visible for deliberate removal');
    $check(Catalogue::page($db,$access,['mode'=>'selected'])['rows']===[],'empty selection is a valid short window');
    $large=Catalogue::page($db,$access,['mode'=>'selected','page'=>84,'recipient_ids'=>array_slice($contacts,0,500)]);
    $check($large['total']===500&&count($large['rows'])===2&&$large['pages']===84,'five hundred selected originals remain bounded to six or fewer rows');
    $deny(fn()=>Catalogue::ids(array_slice($contacts,0,501)),'five hundred is the shared maximum selection');
    $work=new Workspace($db,$access,'press',$root.'/media');$item=$work->save(['title'=>'Original unsent release','body_html'=>'<p>Original body</p>','blocks'=>[['type'=>'text','text'=>'Original block']],'recipient_ids'=>[$inactive['id'],$contacts[0]]]);
    $manager=new Manager($db,$access,$root.'/media','own-press-csrf');$embedded=new EmbeddedManager($manager,$access);
    $tables=['khn_press_contacts','khn_press_items','khn_press_revisions','native_users','native_grants'];$original=ownOriginalDatabaseHash($db,$tables);
    $browse=['csrf'=>'own-press-csrf','operation'=>'communication-save','id'=>$item['id'],'version'=>'1','title'=>'','summary'=>'Unsaved introduction','body_html'=>'<p>Unsaved <strong>human text</strong></p>','kind'=>'release','template'=>'press-release','blocks_json'=>'[{"type":"text","text":""}]','recipient_ids'=>[$inactive['id'],$contacts[0]],'press_picker_present'=>'1','press_visible'=>array_slice($contacts,0,6),'press_choice'=>[$contacts[1]],'press_browse'=>'next','press_mode'=>'active','press_page'=>'1','press_query'=>''];
    $result=$manager->action('press',$browse,[]);$state=$result['compose_state'];
    $check($state['document']['title']===''&&$state['document']['version']==='1'&&$state['document']['body_html']===$browse['body_html']&&$state['document']['blocks_json']===$browse['blocks_json'],'browsing permits incomplete text and preserves the submitted original version and block draft');
    $check($state['document']['recipient_ids']===[$inactive['id'],$contacts[1]]&&$state['picker']['page']===2,'actual action changes only the unsaved selection and read cursor');
    $_GET=['section'=>'compose'];$html=$manager->main('press',$item['id'],PHP_INT_MAX,$state);$xpath=$dom($html);
    $check($xpath->query('//*[@data-press-picker]//input[@name="press_choice[]"]')->length===6&&$xpath->query('//input[@name="title" and @value=""]')->length===1,'actual composer renders six choices with the still incomplete title');
    $check($xpath->query('//textarea[@name="blocks_json"]')->item(0)?->textContent===$browse['blocks_json'],'incomplete block remains editable through a read-only round trip');
    $production=$dom($embedded->main('press',$item['id'],PHP_INT_MAX,$state));
    $check($production->query('//form/input[@name="action" and @value="native-module"]')->length>0&&$production->query('//*[@data-press-picker]//input[@name="press_choice[]"]')->length===6,'integrated original composer keeps canonical action and the same short selection');
    $check(ownOriginalDatabaseHash($db,$tables)===$original,'all search and composer reads retain every original contact, draft, revision and role row');
    $deny(fn()=>$manager->action('press',array_replace($browse,['csrf'=>'forged']),[]),'read-only browsing still requires the actual CSRF token');
    $deny(fn()=>$manager->action('social',$browse,[]),'browsing cannot cross communication modules');
    $deny(fn()=>$manager->action('press',array_replace($browse,['press_browse'=>['next']]),[]),'ambiguous search submitter is rejected');
    $reader=new Manager($db,new Access($db,'reader'),$root.'/media','own-press-csrf');$deny(fn()=>$reader->action('press',$browse,[]),'read-only role cannot enter editable composer round trips');
    Db::run($db,'DELETE FROM native_grants WHERE actor_id=? AND module=? AND capability=?',['admin','press','edit']);$deny(fn()=>$manager->action('press',$browse,[]),'cached manager honors current editing revocation');Db::run($db,'INSERT INTO native_grants VALUES(?,?,?)',['admin','press','edit']);
    $item=$work->save(['id'=>$item['id'],'title'=>'Current independent revision'],1);$again=$manager->action('press',$browse,[]);
    $check($again['compose_state']['document']['version']==='1'&&$work->get($item['id'])['version']===2,'search never substitutes a newer concurrency version into an old form');
    $save=$browse;unset($save['press_browse']);$save['title']='Old unsaved content';$save['blocks_json']='[]';$deny(fn()=>$manager->action('press',$save,[]),'explicit stale save still conflicts after read-only search');
    $new=$save;$new['id']='';$new['version']='0';$saved=$manager->action('press',$new,[]);$check($work->get($saved['item'])['recipient_ids']===[$inactive['id'],$contacts[1]],'only explicit save persists the original hidden selections and visible checkbox changes');
    $initial=$work->get($item['id']);$initialRevision=Db::run($db,'SELECT * FROM khn_press_revisions WHERE club_id=? AND item_id=? ORDER BY version',[$club,$item['id']])->fetchAll();
    if($browser){
        Kulturhus\Native\Integration\Schema::install($db);Kulturhus\Native\Chat\Schema::install($db);Kulturhus\Native\Auth\Schema::install($db);Kulturhus\Native\Shared\RuntimeSchema::install($db);
        Db::run($db,"INSERT INTO clubs(id,name) VALUES('press-browser','Own press browser club')");Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES('press-browser-admin','press-browser','member',1)");
        $bridge=new Kulturhus\Native\Integration\ManagerBridge($db,'press-browser-admin','native',static fn()=>null);$browserAccess=$bridge->access();$browserClub=$browserAccess->club();
        Db::run($db,'INSERT INTO khn_press_contacts(club_id,id,email,name,outlet,active,version,updated_at) SELECT ?,id,email,name,outlet,active,version,updated_at FROM khn_press_contacts WHERE club_id=?',[$browserClub,$club]);
        file_put_contents($root.'/auth.key',random_bytes(32));chmod($root.'/auth.key',0600);mkdir($root.'/manager-sessions',0700);
        putenv('KH_NATIVE_AUTH_KEY_FILE='.$root.'/auth.key');$auth=Kulturhus\Native\Auth\Runtime::service($db);$invite=$auth->createInvitation($browserAccess,Kulturhus\Native\Integration\ManagerBridge::actorKey('press-browser-admin','press-browser'),'press-browser@example.invalid');$auth->acceptInvitation($invite['token'],'Own press recipient browser password 2026!');$token=$auth->passwordLogin('press-browser@example.invalid','Own press recipient browser password 2026!','own-press-browser')['session_token'];
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
        $data=$root.'/browser.json';file_put_contents($data,json_encode(['root'=>$root,'origin'=>'http://127.0.0.1:'.$port,'token'=>$token,'actor'=>$browserAccess->user()['id'],'first'=>$contacts[0],'second'=>$contacts[6],'literal'=>$literal['id']],JSON_THROW_ON_ERROR));chmod($data,0600);putenv('DVM_PRESS_RECIPIENTS_FIXTURE='.$data);
        $server=proc_open([...TestSupport::phpCommand(),'-S','127.0.0.1:'.$port,'-t',$root.'/web',__DIR__.'/support/press-recipients-router.php'],[0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']],$pipes,dirname(__DIR__,2));if(!is_resource($server))throw new RuntimeException('Owned press server required.');fclose($pipes[0]);
        $ready=false;for($i=0;$i<100;$i++){$socket=@fsockopen('127.0.0.1',$port,$errno,$error,.1);if($socket){fclose($socket);$ready=true;break;}usleep(50000);}if(!$ready)throw new RuntimeException('Press server unavailable.');
        $beforeContacts=ownOriginalDatabaseHash($db,['khn_press_contacts']);
        $node=proc_open(['node',__DIR__.'/press-recipients-browser.mjs'],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes,dirname(__DIR__,2));if(!is_resource($node)||proc_close($node)!==0)throw new RuntimeException('Actual recipient browser failed.');
        $newRows=Db::run($db,'SELECT payload FROM khn_press_items WHERE club_id=?',[$browserClub])->fetchAll(PDO::FETCH_COLUMN);$check(count($newRows)===4,'two actual controllers save one explicit new draft with and without JavaScript');
        foreach($newRows as$payload){$p=json_decode($payload,true,64,JSON_THROW_ON_ERROR);$check($p['recipient_ids']===[$contacts[6],$literal['id']]&&$p['title']==='Unsaved human release'&&$p['body']==='Unsaved human body'&&in_array($p['body_html'],['Unsaved human body','<p>Unsaved human body</p>'],true),'actual saved draft retains human text and exact two chosen originals');}
        $check(ownOriginalDatabaseHash($db,['khn_press_contacts'])===$beforeContacts,'all original contacts remain exact after both complete real browser flows');
    }
    $check($work->get($item['id'])===$initial&&Db::run($db,'SELECT * FROM khn_press_revisions WHERE club_id=? AND item_id=? ORDER BY version',[$club,$item['id']])->fetchAll()===$initialRevision,'every prior original draft and revision remains unchanged');
    echo json_encode(['suite'=>'native-press-recipients','checks'=>$n,'browser'=>$browser,'live_transport'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
}finally{
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    if(is_dir($root)){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as$f)$f->isDir()&&!$f->isLink()?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($root);}
}
