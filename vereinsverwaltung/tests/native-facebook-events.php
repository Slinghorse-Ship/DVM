<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db,SecretBox};
use Kulturhus\Native\Events\{Workflow,Facebook,Eventbrite,SourceImporter,Sync};
use Kulturhus\Native\Integration\Accounts;
use Kulturhus\Native\Cms\{Pages,Events};
use Kulturhus\Native\Web\{Manager,WebsiteWorkspace};
use Kulturhus\Native\Web\Website\FacebookLinkPanel;

$db=TestSupport::db();TestSupport::seed($db);Kulturhus\Native\Communication\Schema::install($db);Kulturhus\Native\Cms\Schema::install($db);
$checks=0;$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;};
$deny=static function(callable $action,string $label)use($check):void{try{$action();}catch(DomainException|InvalidArgumentException){$check(true,$label);return;}throw new RuntimeException($label);};
$access=new Access($db,'admin');$club=$access->club();$keyBytes=random_bytes(32);$box=new SecretBox($keyBytes);$accounts=new Accounts($db,$box);
$credentials=['page_id'=>'123','instagram_id'=>'','api_version'=>'v24.0','token'=>'synthetic-fb-event-only-token'];$accounts->save($access,'meta',$credentials,0);
$workflow=new Workflow($db,$access);$policy=['source'=>'facebook','tickets'=>'native','payment'=>'sumup','automatic_publication'=>false];$workflow->save($policy,0);
$pages=new Pages($db,$access);$internal=$pages->save(['title'=>'Eigenes Website-Original','slug'=>'veranstaltungen/intern','template'=>'event','event_start'=>'2050-10-25T19:00','event_timezone'=>'Europe/Berlin','body'=>'Original des Vereins']);$pages->publish($internal['id'],$internal['version']);
$raw=['id'=>'9001','name'=>'Manueller Facebook-Termin','description'=>'Von der Facebook-Seite übernommen','start_time'=>'2050-10-25T19:00:00+01:00','place'=>['name'=>'Vereinssaal','location'=>['street'=>'Testweg 1','zip'=>'12345','city'=>'Testort']],'ticket_uri'=>'https://example.invalid/tickets'];
$compact=$raw;$compact['start_time']='2050-10-25T19:00:00+0100';
$check(Facebook::normalize($compact)===Facebook::normalize($raw),'real Graph basic offset preserves the exact canonical event and source fingerprint');
$withEnd=$raw;$withEnd['end_time']='2050-10-25T21:30:00+01:00';$compact['end_time']='2050-10-25T21:30:00+0100';
$check(Facebook::normalize($compact)===Facebook::normalize($withEnd),'Graph basic end offset preserves exact event duration');
$negative=$raw;$negative['start_time']='2050-10-25T19:00:00-0330';
$check(Facebook::normalize($negative)['starts_at']==='2050-10-25T22:30:00Z','negative non-whole-hour Graph offset is retained');
foreach(['2050-10-25T19:00:00+01','2050-10-25T19:00:00+0100junk','prefix2050-10-25T19:00:00+0100','2050-02-30T19:00:00+0100']as$invalid){$badOffset=$raw;$badOffset['start_time']=$invalid;$deny(fn()=>Facebook::normalize($badOffset),'provider normalization rejects malformed or impossible timestamps');}
$deny(fn()=>\Kulturhus\Native\Communication\Content::time($compact['start_time']),'shared user scheduling parser remains canonical');
$rows=[$raw];$calls=[];$http=static function($method,$path,$headers,$body)use(&$rows,&$calls):array{$calls[]=[$method,$path,$body];if($method!=='GET'||!str_starts_with($path,'/v24.0/123/events?')||$headers!==['Authorization: Bearer synthetic-fb-event-only-token']||$body!==null)throw new RuntimeException('Unexpected provider contract.');return ['status'=>200,'body'=>['data'=>$rows]];};
$facebook=new Facebook($db,$access,$box,$http);$result=$facebook->sync();$id=$result[0]['id'];$page=$pages->get($id);
$check($result[0]['status']==='draft'&&$page['title']===$raw['name'],'Facebook event becomes one private CMS draft');
$check($page['event_start_utc']==='2050-10-25T18:00:00Z'&&$page['event_end']==='','explicit Facebook offset retained and absent end never invented');
$check($page['event_location']==='Vereinssaal'&&$page['event_address']==='Testweg 1 12345 Testort','place and address mapped without participants or inbox data');
$snapshot=static function()use($db):array{$out=[];foreach(['khcms_pages','khcms_revisions','khcms_events','native_event_sources','native_event_source_aliases']as$table)$out[$table]=$db->query('SELECT * FROM '.$table.' ORDER BY 1,2')->fetchAll();return $out;};
$before=$snapshot();$check($facebook->sync()[0]===['id'=>$id,'status'=>'unchanged']&&$snapshot()===$before,'unchanged sync preserves every exact event and source row');
$check(count((new Events($db,$club))->listing()['rows'])===1,'own public website event remains independent of Facebook draft');
$bad=$raw;$bad['id']='9002';$bad['start_time']='invalid';$rows=[$raw,$bad];$deny(fn()=>$facebook->sync(),'malformed final Facebook row rejects complete batch');$check($snapshot()===$before,'malformed batch has no partial writes');
$rows=[$raw,$raw];$deny(fn()=>$facebook->sync(),'overlapping Facebook identities rejected');
foreach([
 ['status'=>403,'body'=>['error'=>['message'=>'do not leak token']]],
 ['status'=>200,'body'=>['data'=>'invalid']],
 ['status'=>200,'body'=>['data'=>[],'paging'=>['next'=>'https://evil.invalid/?access_token=secret']]],
 ['status'=>200,'body'=>['data'=>[],'paging'=>'invalid']],
 ['status'=>200,'body'=>['data'=>[],'paging'=>['next'=>[]]]]
]as$response)$deny(fn()=>(new Facebook($db,$access,$box,static fn()=>$response))->sync(),'incomplete or rejected provider response cannot import');
$check($snapshot()===$before,'all malformed pagination leaves previous rows untouched');
$pagesBefore=$db->query('SELECT * FROM khcms_pages ORDER BY id')->fetchAll();$second=$raw;$second['id']='9003';$paginationCalls=[];
$paged=new Facebook($db,$access,$box,static function($method,$path)use(&$paginationCalls,$raw,$second):array{$paginationCalls[]=$path;if(count($paginationCalls)===1)return ['status'=>200,'body'=>['data'=>[$raw],'paging'=>['next'=>'https://evil.invalid/?access_token=never-follow','cursors'=>['after'=>'opaque+/cursor=']]]];if(!str_contains($path,'after=opaque%2B%2Fcursor%3D'))throw new RuntimeException('Cursor not encoded.');return ['status'=>200,'body'=>['data'=>[$second]]];});
$pagedResult=$paged->sync();$check(count($pagedResult)===2&&count($paginationCalls)===2&&!str_contains(implode(' ',$paginationCalls),'evil.invalid'),'bounded complete pagination reconstructs same-origin paths and never follows credential URL');
$check($pagedResult[0]['id']===$id&&$pagedResult[1]['status']==='draft','paginated sync retains old identity and creates only second event');
$rows=[$raw];$p=$pages->get($id);$p['title']='Eigene Korrektur';$pages->save($p,$p['version']);$raw['name']='Facebook-Änderung';$rows=[$raw];
$check($facebook->sync()[0]['status']==='conflict'&&$pages->get($id)['title']==='Eigene Korrektur','local event edit preserved against provider overwrite');
$p=$pages->get($id);$pages->publish($id,$p['version']);$publishedTitle=(new Events($db,$club))->publicId($id)['title'];$raw['is_canceled']=true;$rows=[$raw];
$check($facebook->sync()[0]['status']==='cancelled-conflict','Facebook cancellation retracts published locally edited event and retains conflict');
$notice=(new Events($db,$club))->publicId($id);$check($notice['event_status']==='cancelled'&&$notice['title']===$publishedTitle,'cancellation notice uses exact original published text');
$raw['is_canceled']=false;$rows=[$raw];$facebook->sync();$check((new Events($db,$club))->publicId($id)===null,'revival never silently republishes cancelled local conflict');
$ebRaw=['id'=>'1008','name'=>['text'=>'Eventbrite-Vorgänger'],'description'=>['text'=>'Original des anderen Anbieters'],'start'=>['utc'=>'2050-10-25T18:00:00Z','timezone'=>'Europe/Berlin'],'end'=>['utc'=>'2050-10-25T20:00:00Z'],'venue'=>['name'=>'Saal'],'status'=>'live','url'=>'https://www.eventbrite.de/e/1008'];
$eb=new Eventbrite($db,$access);$old=$eb->import([Eventbrite::normalize($ebRaw)])[0]['id'];$oldPage=$pages->get($old);$pageBytes=$db->query('SELECT * FROM khcms_pages ORDER BY id')->fetchAll();$revisionBytes=$db->query('SELECT * FROM khcms_revisions ORDER BY page_id,revision')->fetchAll();
$facebook->link('9004',$old,$oldPage['version']);
$check($db->query('SELECT * FROM khcms_pages ORDER BY id')->fetchAll()===$pageBytes&&$db->query('SELECT * FROM khcms_revisions ORDER BY page_id,revision')->fetchAll()===$revisionBytes,'explicit provider association leaves all original pages and revisions byte exact');
$linked=$raw;$linked['id']='9004';$linked['name']='Gleicher Termin aus Facebook';$linked['is_canceled']=false;$rows=[$linked];$linkedResult=$facebook->sync();
$check($linkedResult[0]['id']===$old&&$pages->get($old)['slug']===$oldPage['slug'],'Facebook takeover preserves original Eventbrite website id and URL');
$check((int)Db::run($db,'SELECT COUNT(*) FROM native_event_source_aliases WHERE club_id=? AND page_id=?',[$club,$old])->fetchColumn()===2,'both provider identities retained for switch back');
$policy['source']='eventbrite';$state=$workflow->save($policy,1);$back=$eb->import([Eventbrite::normalize($ebRaw)],$club,$state['version']);
$check($back[0]['id']===$old&&$pages->get($old)['slug']===$oldPage['slug'],'switching back resolves earlier Eventbrite identity without duplicating website event');
$check(Db::run($db,'SELECT source FROM native_event_sources WHERE club_id=? AND page_id=?',[$club,$old])->fetchColumn()==='eventbrite','current source correctly follows explicit selected provider');
$policy['source']='facebook';$workflow->save($policy,2);$rows=[$linked];$check($facebook->sync()[0]['id']===$old,'second Facebook switch reuses its original alias');
$before=$snapshot();$currentPage=$pages->get($old);$facebook->link('9004',$old,$currentPage['version']);$check($snapshot()===$before,'repeated same association is a read-only idempotent confirmation');
$deny(fn()=>$facebook->link('9004',$internal['id'],$pages->get($internal['id'])['version']),'one external id cannot attach to another existing event');
$deny(fn()=>$facebook->link('9005',$old,$currentPage['version']),'different Facebook event cannot silently replace an existing association');
$deny(fn()=>$facebook->link('9006',$internal['id'],0),'stale original page version rejected');
$deny(fn()=>(new Facebook($db,new Access($db,'reader'),$box,$http))->sync(),'reader cannot import');
$deny(fn()=>(new Facebook($db,new Access($db,'outsider'),$box,$http))->link('9004',$old,$currentPage['version']),'other club cannot link original');
$deny(fn()=>(new SourceImporter($db,$access,'facebook'))->import([]),'Facebook import cannot bypass current provider and account contract');
$before=$snapshot();$changePolicy=new Facebook($db,$access,$box,static function()use($workflow,$policy):array{$next=$policy;$next['source']='local';$workflow->save($next,3);return ['status'=>200,'body'=>['data'=>[]]];});
$deny(fn()=>$changePolicy->sync(),'workflow change during actual provider callback blocks commit');$check($snapshot()===$before,'late workflow change preserves all CMS original rows');
$workflow->save($policy,4);$credentials['token']='replacement-synthetic-token';
$accountChange=new Facebook($db,$access,$box,static function()use($accounts,$access,$credentials):array{$accounts->save($access,'meta',$credentials,1);return ['status'=>200,'body'=>['data'=>[]]];});
$deny(fn()=>$accountChange->sync(),'same-account token revision change detected during callback');$check($snapshot()===$before,'changed token cannot commit stale batch');
$credentials['token']='synthetic-fb-event-only-token';$accounts->save($access,'meta',$credentials,2);
$policy['automatic_publication']=true;$state=$workflow->save($policy,5);$auto=$raw;$auto['id']='9010';$auto['name']='Automatisch öffentlich';$auto['is_canceled']=false;$rows=[$auto];$autoResult=$facebook->sync();
$check($autoResult[0]['status']==='published'&&(new Events($db,$club))->publicId($autoResult[0]['id'])['title']==='Automatisch öffentlich','chosen automatic Facebook import publishes through normal website rights and index');
$before=$snapshot();Db::run($db,"DELETE FROM native_grants WHERE actor_id='admin' AND module='website' AND capability='publish'");$auto['id']='9011';$rows=[$auto];$deny(fn()=>$facebook->sync(),'revoked current publication role denies automatic new event');$check($snapshot()===$before,'publication denial rolls all page and alias writes back');
Db::run($db,"INSERT INTO native_grants VALUES('admin','website','publish')");
$credentialRow=Db::run($db,"SELECT * FROM native_private_settings WHERE club_id=? AND purpose='api-meta'",[$club])->fetch();
$credentials['page_id']='456';$accounts->save($access,'meta',$credentials,3);
$newAccount=new Facebook($db,$access,$box,static fn()=>['status'=>200,'body'=>['data'=>[$linked]]]);$deny(fn()=>$newAccount->sync(),'old source binding rejects a different current page account');
Db::run($db,'UPDATE native_private_settings SET version=?,ciphertext=?,updated_by=? WHERE club_id=? AND purpose=?',[$credentialRow['version'],$credentialRow['ciphertext'],$credentialRow['updated_by'],$club,'api-meta']);
$deny(fn()=>Facebook::normalize(array_replace($raw,['start_time'=>'2050-02-30T19:00:00Z'])),'invalid calendar date rejected');
$deny(fn()=>Facebook::normalize(array_replace($raw,['end_time'=>'2050-10-25T17:00:00Z'])),'end before start rejected');
$deny(fn()=>Facebook::normalize(array_replace($raw,['is_canceled'=>'true'])),'nonboolean cancellation rejected');
$deny(fn()=>Facebook::normalize(array_replace($raw,['ticket_uri'=>'http://example.invalid'])),'non-HTTPS ticket link rejected');
$panel=(new FacebookLinkPanel($db,$access,'/unused','synthetic-event-csrf'))->render($pages->get($old));$check(str_contains($panel,'name="facebook_event_id"')&&str_contains($panel,'name="link_confirm"'),'compact explicit native association form uses id and confirmation');
$check((new FacebookLinkPanel($db,new Access($db,'reader'),'/unused','csrf'))->render($pages->get($old))==='','reader receives no writable association form');
$listing=(new WebsiteWorkspace($db,$access,'csrf'))->events();$check(str_contains($listing,'facebook-events-sync')&&!str_contains($listing,'operation" value="eventbrite-sync'),'event list offers only currently selected Facebook sync');
$check(array_reduce($calls,static fn($ok,$call)=>$ok&&$call[0]==='GET',true),'every actual Meta transport call is read-only');
$browser=getenv('DVM_EVENT_WORKFLOW_BROWSER')==='1';
if($browser){
    $root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/event-workflow-browser';mkdir($root.'/web',0700,true);
    $key=$root.'/private.key';file_put_contents($key,$keyBytes);chmod($key,0600);putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$key);
    $rawBrowser=['id'=>'9020','name'=>'Ein neuer eigener Browser-Termin','start_time'=>'2050-10-25T19:00:00+01:00'];
    $data=['id'=>$old,'raw'=>$rawBrowser,'csrf'=>bin2hex(random_bytes(24)),'media'=>$root.'/media'];$dataFile=$root.'/data.json';file_put_contents($dataFile,json_encode($data,JSON_THROW_ON_ERROR));chmod($dataFile,0600);putenv('DVM_EVENT_WORKFLOW_DATA='.$dataFile);
    $protected=$snapshot();$privateBefore=$db->query('SELECT * FROM native_private_settings ORDER BY club_id,purpose')->fetchAll();
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
    $server=proc_open([...TestSupport::phpCommand(),'-S','127.0.0.1:'.$port,'-t',$root.'/web',__DIR__.'/support/event-workflow-router.php'],[0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']],$pipes,dirname(__DIR__,2));
    if(!is_resource($server))throw new RuntimeException('Own isolated HTTP server unavailable.');fclose($pipes[0]);
    try{
        $ready=false;for($attempt=0;$attempt<50;$attempt++){$connection=@fsockopen('127.0.0.1',$port,$errno,$error,0.1);if(is_resource($connection)){fclose($connection);$ready=true;break;}usleep(50000);}if(!$ready)throw new RuntimeException('Own event server not ready.');
        $browserFile=$root.'/browser.json';file_put_contents($browserFile,json_encode(['origin'=>'http://127.0.0.1:'.$port,'id'=>$old],JSON_THROW_ON_ERROR));chmod($browserFile,0600);putenv('DVM_EVENT_WORKFLOW_FIXTURE='.$browserFile);
        $node=proc_open(['node',__DIR__.'/event-workflow-browser.mjs'],[0=>STDIN,1=>STDOUT,2=>STDERR],$nodePipes,dirname(__DIR__,2));if(!is_resource($node)||proc_close($node)!==0)throw new RuntimeException('Actual event workflow browser failed.');
        $new=Db::run($db,"SELECT page_id FROM native_event_sources WHERE club_id=? AND source='facebook' AND external_id='9020'",[$club])->fetchColumn();
        $check(is_string($new)&&$new!==$old&&(new Pages($db,$access))->get($new)['published_revision']===null,'actual native HTTP creates exactly one new private Facebook event');
        foreach($protected as$table=>$rows){$keyColumn=$table==='khcms_pages'?'id':'page_id';$actual=Db::run($db,'SELECT * FROM '.$table.' WHERE '.$keyColumn.'<>? ORDER BY 1,2',[$new])->fetchAll();$check($actual===$rows,'actual browser preserves every prior complete row in '.$table);}
        $check($db->query('SELECT * FROM native_private_settings ORDER BY club_id,purpose')->fetchAll()===$privateBefore,'HTTP choices and source imports preserve exact encrypted connections');
        $state=$workflow->get();$check($state['source']==='facebook'&&$state['tickets']==='native'&&$state['payment']==='sumup'&&!$state['automatic_publication'],'real JS and no-JS form submissions retain final provider choice');
    }finally{proc_terminate($server);proc_close($server);}
}
echo json_encode(['checks'=>$checks,'status'=>'passed','live_transport'=>false],JSON_THROW_ON_ERROR)."\n";
