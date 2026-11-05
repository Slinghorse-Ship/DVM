<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Schema,Pages,PageTrash,PageReferences,PageCopies,TreeCopies,PageHierarchy,DataModel,Conflict,PageSummary,ReferenceCatalog,PublicationSchedule};
use Kulturhus\Native\Web\{Manager,Website\PageTrashPanel};
$db=TestSupport::db();
if (($argv[1]??'')==='race') {
    file_put_contents($argv[5].'.ready','ready');$until=microtime(true)+10;
    while(!is_file($argv[6])) {if(microtime(true)>$until)exit(3);usleep(10000);}
    $access=new Access($db,'admin');
    try {
        if($argv[4]==='retire'){(new PageTrash($db,$access))->move($argv[2],(int)$argv[3]);echo 'trashed';}
        else{(new Pages($db,$access))->save(['title'=>'Concurrent child','slug'=>'trash-race-child','parent_id'=>$argv[2]]);echo 'linked';}
    }catch(Conflict){echo 'conflict';}
    exit();
}
TestSupport::seed($db);Schema::install($db);
$a=new Access($db,'admin');$pages=new Pages($db,$a);$trash=new PageTrash($db,$a);$checks=0;
function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);++$checks;echo "PASS $label\n";}
function rejects(callable $fn,string $class,string $label):void{try{$fn();}catch(Throwable $e){check($e instanceof $class,$label.' ('.$e::class.')');return;}throw new RuntimeException($label);}
$source=$pages->save(['title'=>'Exact original','slug'=>'trash-original','body_html'=>'<p>Original <strong>0 &amp; 🎶</strong></p>','nav_visible'=>true]);
$source=$pages->publish($source['id'],$source['version']);$public=$pages->publicPage($source['slug']);$generation=$pages->generation();
$originalJson=Db::run($db,'SELECT draft_json FROM khcms_pages WHERE id=?',[$source['id']])->fetchColumn();$history=$pages->revisions($source['id']);
$plan=(new PublicationSchedule($db,$a))->plan($source['id'],$source['version'],time()+3600);
$moved=$trash->move($source['id'],$source['version']);
check($moved['trashed']&&$moved['id']===$source['id']&&$moved['version']===$source['version']+1,'trash retains identity and appends one revision');
check(Db::run($db,'SELECT draft_json FROM khcms_pages WHERE id=?',[$source['id']])->fetchColumn()===$originalJson,'trash keeps exact saved original bytes');
check($pages->publicPage($source['slug'])===null&&$pages->generation()===$generation+1,'trash retracts public projection and invalidates cache once');
check(array_slice($pages->revisions($source['id']),1)===$history,'all historical original revisions retained');
check(Db::run($db,'SELECT status FROM khcms_publication_schedule WHERE id=?',[$plan['id']])->fetchColumn()==='cancelled','old approved publication plan cancelled atomically');
check($pages->count()===0&&$pages->list()===[]&&$pages->search([])===[]&&$pages->tree()===[],'trash excluded from ordinary lists, counted search and tree');
check((new PageSummary($db,$a))->read()['total']===0&&(new ReferenceCatalog($db,$a))->listing('page')['total']===0,'overview and reference picker exclude retired page');
check(!str_contains((new Kulturhus\Native\Web\Website\PageSidebar($db,$a))->render(),'data-page-directory="'.$source['id'].'"'),'standalone directory excludes retired original');
check($trash->listing()['total']===1&&count($trash->listing()['rows'])===1,'dedicated trash list retains original');
foreach([fn()=>$pages->save($moved,$moved['version']),fn()=>$pages->publish($moved['id'],$moved['version']),fn()=>$pages->unpublish($moved['id'],$moved['version']),fn()=>$pages->archive($moved['id'],$moved['version']),fn()=>$pages->restore($moved['id'],1,$moved['version']),fn()=>(new PageCopies($db,$a))->create($moved['id'],$moved['version'],['title'=>'Copy','slug'=>'trash-copy','basis'=>'draft']),fn()=>(new TreeCopies($db,$a))->create($moved['id'],$moved['version'],['title'=>'Copy','slug'=>'trash-copy','basis'=>'draft']),fn()=>(new PageHierarchy($db,$a))->place($moved['id'],$moved['version'],null,1),fn()=>(new PublicationSchedule($db,$a))->plan($moved['id'],$moved['version'],time()+3600)]as $fn)rejects($fn,Conflict::class,'retired page cannot edit, publish, restore history, copy, move or plan');
rejects(fn()=>$trash->move($moved['id'],$moved['version']),Conflict::class,'repeat trash cannot append another revision');
rejects(fn()=>$trash->restore($moved['id'],$source['version']),Conflict::class,'stale restore rejected');
rejects(fn()=>(new PageTrash($db,new Access($db,'reader')))->restore($moved['id'],$moved['version']),DomainException::class,'reader cannot restore');
rejects(fn()=>(new PageTrash($db,new Access($db,'outsider')))->restore($moved['id'],$moved['version']),RuntimeException::class,'other club cannot restore original');
rejects(fn()=>$pages->save(['title'=>'Reuse','slug'=>$source['slug']]),Conflict::class,'retired address stays reserved');
$restored=(new PageTrash($db,new Access($db,'editor')))->restore($moved['id'],$moved['version']);
check(!$restored['trashed']&&$restored['published_revision']===null&&$pages->publicPage($source['slug'])===null,'editor restores private draft without republishing');
check(Db::run($db,'SELECT draft_json FROM khcms_pages WHERE id=?',[$source['id']])->fetchColumn()===$originalJson,'restore keeps exact original content');
check($pages->count()===1&&$trash->listing()['total']===0,'restored original returns to active counted listings');
rejects(fn()=>(new PageTrash($db,new Access($db,'editor')))->move($restored['id'],$restored['version']),DomainException::class,'offline retirement requires publication authority');
$child=$pages->save(['title'=>'Saved child','slug'=>'trash-child','parent_id'=>$source['id']]);
rejects(fn()=>$trash->move($restored['id'],$restored['version']),Conflict::class,'saved child blocks retiring parent');
$restored=$pages->publish($restored['id'],$restored['version']);
$child=$pages->publish($child['id'],$child['version']);

$child=$pages->save(array_replace($child,['parent_id'=>null]),$child['version']);
$refs=(new PageReferences($db,$a))->incoming($source['id']);
check(count($refs)===1&&$refs[0]['basis']==='published','old published child edge remains a blocker after draft move');
rejects(fn()=>$trash->move($restored['id'],$restored['version']),Conflict::class,'old published parent edge cannot be broken');
$child=$pages->publish($child['id'],$child['version']);
$model=new DataModel($db,$a);$field=$model->saveField(['key'=>'original_link','label'=>'Link','type'=>'page-reference']);
$template=$model->saveTemplate(['key'=>'links','label'=>'Links','renderer'=>'standard','fields'=>[$field['key']]]);
$link=$pages->save(['title'=>'Typed original link','slug'=>'trash-linked','template'=>$template['key'],'custom_fields'=>[$field['key']=>$source['id']]]);
rejects(fn()=>$trash->move($restored['id'],$restored['version']),Conflict::class,'saved typed page reference blocks trash');
$link=$pages->publish($link['id'],$link['version']);
$link=$pages->save(array_replace($link,['custom_fields'=>[$field['key']=>null]]),$link['version']);
check((new PageReferences($db,$a))->incoming($source['id'])[0]['basis']==='published','immutable published field types govern dependencies');
rejects(fn()=>$trash->move($restored['id'],$restored['version']),Conflict::class,'published typed original blocks after private reference removal');
$link=$pages->publish($link['id'],$link['version']);
$moved=$trash->move($restored['id'],$restored['version']);
rejects(fn()=>$pages->save(['title'=>'New child','slug'=>'trash-forged-parent','parent_id'=>$source['id']]),Conflict::class,'cannot introduce a new parent link to trash');
rejects(fn()=>$pages->save(array_replace($link,['custom_fields'=>[$field['key']=>$source['id']]]),$link['version']),InvalidArgumentException::class,'cannot introduce a new typed link to trash');
$restored=$trash->restore($moved['id'],$moved['version']);
// Own links may remain in a retired original, but destinations must return before restoration.
$link=$pages->save(array_replace($link,['custom_fields'=>[$field['key']=>$source['id']]]),$link['version']);
$link=$trash->move($link['id'],$link['version']);
$moved=$trash->move($restored['id'],$restored['version']);
rejects(fn()=>$trash->restore($link['id'],$link['version']),Conflict::class,'restore waits for a retired reference destination');
check($pages->get($link['id'])===$link,'failed restore has no partial lifecycle change');
$restored=$trash->restore($moved['id'],$moved['version']);
$link=$trash->restore($link['id'],$link['version']);
check($link['custom_fields'][$field['key']]===$source['id'],'reference restored after destination returns');
// Old declarations survive changes to the current model instead of silently rewriting saved data.
$link=$trash->move($link['id'],$link['version']);
$model->saveField($field,$field['version']);
$link=$trash->restore($link['id'],$link['version']);
check($link['field_definitions'][$field['key']]['version']===$field['version'],'trash restore preserves historical field definition');
$archive=$pages->save(['title'=>'Archived original','slug'=>'trash-archived']);$archive=$pages->archive($archive['id'],$archive['version']);
$archive=$trash->move($archive['id'],$archive['version']);$archive=$trash->restore($archive['id'],$archive['version']);
check($archive['archived']&&$archive['published_revision']===null,'restoring archived original retains distinct archive state');
$db->beginTransaction();$trash->move($archive['id'],$archive['version']);$db->rollBack();
check($pages->get($archive['id'])===$archive,'outer transaction rollback restores page and trash state');
$manager=new Manager($db,$a,dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/media','csrf');
rejects(fn()=>$manager->action('website',['csrf'=>'wrong','operation'=>'page-trash'],[]),DomainException::class,'controller checks CSRF before retirement');
rejects(fn()=>$manager->action('website',['csrf'=>'csrf','operation'=>'page-trash','id'=>$archive['id'],'version'=>(string)$archive['version']],[]),InvalidArgumentException::class,'controller requires explicit checkbox');
rejects(fn()=>$manager->action('website',['csrf'=>'csrf','operation'=>'page-trash','id'=>$archive['id'],'version'=>[],'trash_confirm'=>'1'],[]),InvalidArgumentException::class,'controller rejects forged version array');
$result=$manager->action('website',['csrf'=>'csrf','operation'=>'page-trash','id'=>$archive['id'],'version'=>(string)$archive['version'],'trash_confirm'=>'1'],[]);
check($result['section']==='trash'&&Kulturhus\Native\Integration\Routes::result($result)['section']==='trash','both controllers return dedicated trash workspace');
$html=$manager->main('website',$archive['id']);
check(str_contains($html,'page-trash-panel')&&!str_contains($html,'value="page-save"')&&!str_contains($html,'value="page-publish"'),'retired editor offers restoration and no edit or publish form');
check(str_contains($html,'btn btn-solid theme-primary')&&str_contains($html,'Privat zurückholen'),'shared restored panel uses Bootstrap controls');
$_GET=['section'=>'trash'];$html=$manager->main('website','');$_GET=[];
check(str_contains($html,'data-trash-row="'.$archive['id'].'"'),'actual manager listing offers retained original');
foreach(['',str_repeat('f',32)] as $id)rejects(fn()=>$trash->move($id,0),InvalidArgumentException::class,'invalid identity or version rejected');
for($i=0;$i<26;++$i){$p=$pages->save(['title'=>'Trash page '.$i,'slug'=>'trash-page-'.$i]);$trash->move($p['id'],$p['version']);}
check($trash->listing()['total']===27&&count($trash->listing()['rows'])===25&&count($trash->listing(2)['rows'])===2,'trash metadata listing uses counted 25-item SQL pages');
check((new PageTrash($db,new Access($db,'outsider')))->listing()['total']===0,'trash listing is club scoped');
// Two actual workers compete to retire a page and introduce a new child.
$race=$pages->save(['title'=>'Concurrent original','slug'=>'trash-race']);
$dir=dirname(getenv('KH_NATIVE_TEST_CONFIG'));$barrier=$dir.'/trash-go';$workers=[];
foreach(['retire','link'] as $op){
    $ready=$dir.'/trash-'.$op;
    $proc=proc_open([...TestSupport::phpCommand(),__FILE__,'race',$race['id'],(string)$race['version'],$op,$ready,$barrier],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($proc))throw new RuntimeException('Concurrent test process unavailable');fclose($pipes[0]);$workers[]=[$proc,$pipes,$ready];
}
$until=microtime(true)+10;
while(!is_file($workers[0][2].'.ready')||!is_file($workers[1][2].'.ready')){if(microtime(true)>$until)throw new RuntimeException('Concurrent setup timeout');usleep(10000);}
file_put_contents($barrier,'go');$outcomes=[];
foreach($workers as[$proc,$pipes,$ready]){$outcomes[]=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($proc)===0&&$errors==='','actual concurrent dependency worker completes');unlink($ready.'.ready');}
unlink($barrier);sort($outcomes);
check(in_array($outcomes,[['conflict','linked'],['conflict','trashed']],true),'concurrent retirement and dependency creation cannot both commit');
check($pages->get($race['id'])['trashed']===in_array('trashed',$outcomes,true),'actual committed lifecycle agrees with concurrent result');
echo json_encode(['checks'=>$checks,'live_transport'=>false],JSON_THROW_ON_ERROR)."\n";
