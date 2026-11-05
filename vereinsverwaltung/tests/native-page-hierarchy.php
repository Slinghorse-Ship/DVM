<?php declare(strict_types=1);
require dirname(__DIR__) . '/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access, Db};
use Kulturhus\Native\Cms\{Schema, Pages, PageHierarchy, DataModel, Conflict};
use Kulturhus\Native\Web\Manager;
use Kulturhus\Native\Web\Website\{PageHierarchyPanel, PageChildrenPanel};

$db = TestSupport::db();
if (($argv[1] ?? '') === 'race') {
    file_put_contents($argv[5] . '.ready', 'ready');
    $until = microtime(true) + 10;
    while (!is_file($argv[6])) {
        if (microtime(true) > $until) exit(3);
        usleep(10000);
    }
    try {
        (new PageHierarchy($db, new Access($db, 'admin')))->place($argv[2], (int)$argv[3], $argv[4], 15);
        echo 'saved';
    } catch (Conflict) { echo 'conflict'; }
    exit();
}
TestSupport::seed($db); Schema::install($db);
$access = new Access($db, 'admin'); $pages = new Pages($db, $access); $hierarchy = new PageHierarchy($db, $access);
$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); ++$checks; echo "PASS $label\n"; }
function rejects(callable $fn, string $class, string $label): void {
    try { $fn(); } catch (Throwable $e) { check($e instanceof $class, $label); return; }
    throw new RuntimeException($label);
}
$left = $pages->save(['title'=>'Left original', 'slug'=>'hierarchy-left', 'nav_visible'=>true]);
$left = $pages->publish($left['id'], $left['version']);
$right = $pages->save(['title'=>'Right original', 'slug'=>'hierarchy-right', 'nav_visible'=>true]);
$right = $pages->publish($right['id'], $right['version']);
$source = $pages->save(['title'=>'Source original', 'slug'=>'hierarchy-source', 'parent_id'=>$left['id'], 'nav_order'=>10, 'body'=>'Exact original <&> zero 0', 'nav_visible'=>true]);
$source = $pages->publish($source['id'], $source['version']);
$child = $pages->save(['title'=>'Retained child', 'slug'=>'hierarchy-child', 'parent_id'=>$source['id']]);
$child = $pages->publish($child['id'], $child['version']);
$public = $pages->publicPage($source['slug']); $navigation = $pages->navigation(); $generation = $pages->generation();
$originalChild = $pages->get($child['id']); $originalRevisions = $pages->revisions($source['id']);
$placed = $hierarchy->place($source['id'], $source['version'], $right['id'], -20);
check($placed['id'] === $source['id'] && $placed['version'] === $source['version'] + 1, 'placement retains identity and appends one private revision');
check($placed['parent_id'] === $right['id'] && $placed['nav_order'] === -20, 'actual parent and order saved together');
check($placed['slug'] === $source['slug'] && $placed['body'] === $source['body'] && $placed['nav_visible'] === $source['nav_visible'], 'address, original text and visibility retained');
check($pages->get($child['id']) === $originalChild, 'descendant identity, content, version and parent remain exact');
check($pages->publicPage($source['slug']) === $public && $pages->navigation() === $navigation && $pages->generation() === $generation, 'private move preserves public original, navigation and generation');
check(array_slice($pages->revisions($source['id']), 1) === $originalRevisions, 'all previous original revisions remain exact');
check($hierarchy->place($placed['id'], $placed['version'], $right['id'], -20) === $placed, 'identical placement creates no unnecessary revision');
rejects(fn()=>$hierarchy->place($placed['id'], $source['version'], $left['id'], 0), Conflict::class, 'stale source cannot overwrite current placement');
rejects(fn()=>$hierarchy->place($placed['id'], $placed['version'], $placed['id'], 0), InvalidArgumentException::class, 'self-parent rejected');
rejects(fn()=>$hierarchy->place($placed['id'], $placed['version'], $child['id'], 0), InvalidArgumentException::class, 'moving under retained descendant cannot create a cycle');
$otherPages = new Pages($db, new Access($db, 'outsider'));
$other = $otherPages->save(['title'=>'Other club', 'slug'=>'hierarchy-other']);
rejects(fn()=>$hierarchy->place($placed['id'], $placed['version'], $other['id'], 0), Conflict::class, 'foreign destination unavailable');
rejects(fn()=>(new PageHierarchy($db, new Access($db,'outsider')))->place($placed['id'], $placed['version'], null, 0), RuntimeException::class, 'foreign source unavailable');
rejects(fn()=>(new PageHierarchy($db,new Access($db,'reader')))->place($placed['id'], $placed['version'], null, 0), DomainException::class, 'read-only role cannot move');
$archived = $pages->save(['title'=>'Archived', 'slug'=>'hierarchy-archived']);
$archived = $pages->archive($archived['id'], $archived['version']);
rejects(fn()=>$hierarchy->place($placed['id'], $placed['version'], $archived['id'], 0), Conflict::class, 'archived destination rejected');
rejects(fn()=>$hierarchy->place($archived['id'], $archived['version'], null, 9), Conflict::class, 'archived source must be explicitly restored first');
foreach ([['bad',1,null,0],[$placed['id'],0,null,0],[$placed['id'],$placed['version'],'bad',0],[$placed['id'],$placed['version'],null,100001],[$placed['id'],$placed['version'],null,-100001]] as $invalid) {
    rejects(fn()=>$hierarchy->place(...$invalid), InvalidArgumentException::class, 'malformed hierarchy input rejected');
}
check($pages->get($placed['id']) === $placed, 'all rejected operations leave saved source exact');
$db->beginTransaction(); $hierarchy->place($placed['id'],$placed['version'],null,3); $db->rollBack();
check($pages->get($placed['id']) === $placed, 'outer transaction rollback preserves hierarchy and revisions');
$rooted = (new PageHierarchy($db,new Access($db,'editor')))->place($placed['id'],$placed['version'],null,30);
check($rooted['parent_id'] === null && $rooted['nav_order'] === 30, 'editor may move to top level without publication rights');
check($pages->publicPage($source['slug']) === $public, 'editor move cannot change published original');

// Metadata-only validation includes descendant depth, rather than just the moved root.
$deep = null;
for ($i=0; $i<99; ++$i) $deep = $pages->save(['title'=>'Deep '.$i,'slug'=>'hierarchy-depth-'.$i,'parent_id'=>$deep['id']??null]);
rejects(fn()=>$hierarchy->place($rooted['id'],$rooted['version'],$deep['id'],0), InvalidArgumentException::class, 'retained child would exceed 100 total levels');
$leaf = $pages->save(['title'=>'Depth leaf','slug'=>'hierarchy-leaf']);
$leaf = $hierarchy->place($leaf['id'],$leaf['version'],$deep['id'],0);
check($leaf['parent_id'] === $deep['id'], 'exact 100-level placement remains supported');

$large = $pages->save(['title'=>'Large original','slug'=>'hierarchy-large']);
for ($i=0; $i<499; ++$i) $pages->save(['title'=>'Child '.$i,'slug'=>'hierarchy-large-'.$i,'parent_id'=>$large['id']]);
$large = $hierarchy->place($large['id'],$large['version'],$left['id'],40);
check($large['parent_id'] === $left['id'] && $pages->count([['field'=>'parent_id','value'=>$large['id']]]) === 499, 'exact 500-page subtree moves without rewriting its children');
$pages->save(['title'=>'Overflow child','slug'=>'hierarchy-large-overflow','parent_id'=>$large['id']]);
rejects(fn()=>$hierarchy->place($large['id'],$large['version'],$right['id'],0), InvalidArgumentException::class, '501-page subtree fails without partial placement');
$large = $hierarchy->place($large['id'],$large['version'],$left['id'],-1);
check($large['nav_order'] === -1, 'order-only edit works for a larger unchanged hierarchy');

$model = new DataModel($db,$access);
$field = $model->saveField(['key'=>'hierarchy_note','label'=>'Note','type'=>'text']);
$template = $model->saveTemplate(['key'=>'hierarchy_type','label'=>'Own type','renderer'=>'standard','fields'=>[$field['key']]]);
$custom = $pages->save(['title'=>'Own fields','slug'=>'hierarchy-custom','template'=>$template['key'],'custom_fields'=>[$field['key']=>'Exact own field']]);
$custom = $hierarchy->place($custom['id'],$custom['version'],$left['id'],7);
check($custom['custom_fields'][$field['key']] === 'Exact own field', 'custom template and saved own fields retained');
$model->saveField($field + [],$field['version']);
rejects(fn()=>$hierarchy->place($custom['id'],$custom['version'],$right['id'],0), Conflict::class, 'changed field model cannot be silently replaced by placement');

// Real concurrent workers: the club mutex and original version permit only one move.
$race = $pages->save(['title'=>'Race original','slug'=>'hierarchy-race']);
$dir = dirname(getenv('KH_NATIVE_TEST_CONFIG')); $barrier=$dir.'/hierarchy-go'; $workers=[];
foreach ([$left,$right] as $i=>$target) {
    $ready=$dir.'/hierarchy-'.$i;
    $process=proc_open([...TestSupport::phpCommand(),__FILE__,'race',$race['id'],(string)$race['version'],$target['id'],$ready,$barrier],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Race process unavailable');
    fclose($pipes[0]); $workers[]=[$process,$pipes,$ready];
}
$until=microtime(true)+10;
while (!is_file($workers[0][2].'.ready') || !is_file($workers[1][2].'.ready')) { if(microtime(true)>$until)throw new RuntimeException('Race setup timeout');usleep(10000); }
file_put_contents($barrier,'go');$outcomes=[];
foreach($workers as [$process,$pipes,$ready]) { $outcomes[]=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($process)===0&&$err==='','actual concurrent hierarchy worker completes');unlink($ready.'.ready'); }
unlink($barrier);sort($outcomes);
check($outcomes===['conflict','saved'] && $pages->get($race['id'])['version']===$race['version']+1, 'concurrent placement makes one revision and one conflict');

$manager = new Manager($db,$access,$dir.'/media','csrf');
$generation = $pages->generation(); // Independent archive/model fixtures intentionally invalidate their own public projections.
$result=$manager->action('website',['csrf'=>'csrf','operation'=>'page-place','id'=>$rooted['id'],'version'=>(string)$rooted['version'],'hierarchy_parent'=>$right['id'],'hierarchy_position'=>'-40'],[]);
check($result['item']===$rooted['id']&&$result['view']==='website','controller retains correct original editor');
rejects(fn()=>$manager->action('website',['csrf'=>'wrong','operation'=>'page-place'],[]),DomainException::class,'controller retains CSRF requirement');
rejects(fn()=>$manager->action('website',['csrf'=>'csrf','operation'=>'page-place','id'=>$rooted['id'],'version'=>[],'hierarchy_parent'=>'','hierarchy_position'=>'0'],[]),InvalidArgumentException::class,'controller rejects forged version array');
$current=$pages->get($rooted['id']);$panel=new PageHierarchyPanel($db,$access,'csrf');
$html=$panel->render($current);
check(str_contains($html,'name="operation" value="page-place"')&&str_contains($html,'class="form-control"')&&str_contains($html,'btn btn-solid theme-primary'),'shared panel uses actual Bootstrap controls');
check((new PageHierarchyPanel($db,new Access($db,'reader'),'csrf'))->render($current)==='','read-only role sees no placement form');
$_SERVER['REQUEST_METHOD']='POST'; $_POST=['operation'=>'page-place','id'=>$current['id'],'version'=>(string)$rooted['version'],'hierarchy_parent'=>$left['id'],'hierarchy_position'=>'99'];
$retry=$panel->render($current);
check(str_contains($retry,' open')&&str_contains($retry,'name="version" value="'.$rooted['version'].'"')&&str_contains($retry,'value="99"'),'failed form stays open with original version and proposed order');
$_POST=[];$_SERVER['REQUEST_METHOD']='GET';$_GET=[];
$html=(new PageChildrenPanel($db,$access))->render($right);
check(strpos($html,'data-child-page="'.$current['id'].'"')!==false && str_contains($html,'Position -40'),'child panel presents saved hierarchy order');
$early=$pages->save(['title'=>'Zulu earlier','slug'=>'hierarchy-sort-early','parent_id'=>$right['id'],'nav_order'=>-100]);
$later=$pages->save(['title'=>'Alpha later','slug'=>'hierarchy-sort-later','parent_id'=>$right['id'],'nav_order'=>10]);
$ordered=$pages->search([['field'=>'parent_id','value'=>$right['id']]],25,0,[['field'=>'nav_order'],['field'=>'title','case_fold'=>true]]);
$owned = array_values(array_filter(array_column($ordered,'id'),static fn($id)=>in_array($id,[$early['id'],$current['id'],$later['id']],true)));
check($owned===[$early['id'],$current['id'],$later['id']],'numeric position takes precedence over title, including negatives');
$html=(new PageChildrenPanel($db,$access))->render($right);
check(strpos($html,$early['id'])<strpos($html,$current['id'])&&strpos($html,$current['id'])<strpos($html,$later['id']),'child panel uses the same actual numeric database order');
check($pages->publicPage($source['slug'])===$public && $pages->generation()===$generation,'entire private workflow retains public original and cache generation');
$news=$pages->save(['title'=>'Rendered news','slug'=>'aktuelles/hierarchy-render','template'=>'news','news_date'=>'2050-01-12','body'=>'Own exact content']);
$album=$pages->save(['title'=>'Rendered album','slug'=>'galerie/hierarchy-render','template'=>'gallery']);
check(str_contains((new Kulturhus\Native\Web\Articles($db,$access,$dir.'/media','csrf'))->manager($news['id']),'page-hierarchy-panel'),'real article editor receives initialized hierarchy panel');
check(str_contains((new Kulturhus\Native\Web\Gallery($db,$access,$dir.'/media','csrf'))->manager($album['id']),'page-hierarchy-panel'),'real album editor receives initialized hierarchy panel');
echo json_encode(['suite'=>'native-page-hierarchy','checks'=>$checks,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
