<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Schema,Pages,DataModel,TemplatePolicy,Conflict,Media,WebsiteDocuments,PageReferences,ReferenceCatalog,PageTrash,PagePurge,Articles,PublishedAlbums,Navigation,EventIndex};
use Kulturhus\Native\Web\Website\PagePolicyPanel;
$db=TestSupport::db();TestSupport::seed($db);Schema::install($db);$admin=new Access($db,'admin');$reader=new Access($db,'reader');$editor=new Access($db,'editor');$model=new DataModel($db,$admin);$pages=new Pages($db,$admin);$checks=0;
function check(bool $okay,string $label):void{global $checks;if(!$okay)throw new RuntimeException($label);++$checks;echo "PASS $label\n";}
function rejects(callable $operation,string $class,string $label):void{try{$operation();}catch(Throwable $error){check($error instanceof $class,$label.' ('.$error::class.')');return;}throw new RuntimeException($label);}
function selected(PDO $db,Access $access,bool $public=false):array{[$where,$args]=TemplatePolicy::readFilter($db,$access,'p',$public);return Db::run($db,'SELECT p.id FROM khcms_pages p WHERE '.$where.' ORDER BY p.id',$args)->fetchAll(PDO::FETCH_COLUMN);}
$ordinary=$pages->save(['title'=>'Ordinary original','slug'=>'policy-ordinary']);
$ordinary=$pages->publish($ordinary['id'],$ordinary['version']);
[$plain]=TemplatePolicy::readFilter($db,$reader);check(!str_contains($plain,'RECURSIVE')&&selected($db,$reader)===[$ordinary['id']],'unconfigured websites use a small club predicate and retain ordinary visibility');
$restricted=$model->saveTemplate(['key'=>'policy_private','label'=>'Private administration','renderer'=>'standard','fields'=>[],'policy'=>['read'=>'manage','edit'=>'manage','publish'=>'manage','public'=>false]]);
$private=$pages->save(['title'=>'Private original','slug'=>'policy-private','template'=>'policy_private']);
$private=$pages->publish($private['id'],$private['version']);
$child=$pages->save(['title'=>'Inherited child','slug'=>'policy-private/child','parent_id'=>$private['id']]);
$child=$pages->publish($child['id'],$child['version']);
check(in_array($private['id'],selected($db,$admin),true)&&in_array($child['id'],selected($db,$admin),true),'current administrator can read directly restricted and inherited originals');
check(!in_array($private['id'],selected($db,$reader),true)&&!in_array($child['id'],selected($db,$reader),true),'recursive SQL filter removes restricted template and inheriting child before listing');
check(!in_array($private['id'],selected($db,$admin,true),true)&&!in_array($child['id'],selected($db,$admin,true),true),'private flags exclude direct and inherited originals even from an administrator public projection');
rejects(fn()=>TemplatePolicy::assertCapability($db,$editor,$private['id'],'read'),DomainException::class,'direct source guard denies hidden original without revealing it');
rejects(fn()=>TemplatePolicy::assertAllReadable($db,$editor,[$ordinary['id'],$private['id']]),DomainException::class,'tree selection fails completely when any chosen source is unreadable');
check(TemplatePolicy::assertAllReadable($db,$admin,[$ordinary['id'],$private['id']])===null,'whole selected tree is accepted only when every original remains readable');
$escape=$model->saveTemplate(['key'=>'policy_independent','label'=>'Independent published child','renderer'=>'standard','fields'=>[],'policy'=>['inherit'=>false]]);
$separate=$pages->save(['title'=>'Explicit independent child','slug'=>'policy-independent','template'=>'policy_independent','parent_id'=>$private['id']]);
$separate=$pages->publish($separate['id'],$separate['version']);
check(in_array($separate['id'],selected($db,$reader),true)&&in_array($separate['id'],selected($db,$reader,true),true),'explicit administrator template configuration can stop inherited restrictions');
check(selected($db,new Access($db,'outsider'))===[],'SQL filter always retains its own current club boundary');
[$where,$args]=TemplatePolicy::readFilter($db,$reader);$count=(int)Db::run($db,'SELECT COUNT(*) FROM khcms_pages p WHERE '.$where,$args)->fetchColumn();$bounded=Db::run($db,'SELECT p.id FROM khcms_pages p WHERE '.$where.' ORDER BY p.id LIMIT 1',$args)->fetchAll(PDO::FETCH_COLUMN);
check($count===2&&count($bounded)===1&&!in_array($bounded[0],[$private['id'],$child['id']],true),'count and bounded first page apply authorization inside SQL');
$draftSwitch=$pages->save(['title'=>'Published ordinary template','slug'=>'policy-published-original']);$draftSwitch=$pages->publish($draftSwitch['id'],$draftSwitch['version']);
$draftSwitch=$pages->save(array_replace($draftSwitch,['template'=>'policy_private']),$draftSwitch['version']);
check(in_array($draftSwitch['id'],selected($db,$reader,true),true)&&!in_array($draftSwitch['id'],selected($db,$reader),true),'public visibility uses published original template while private editing uses current draft template');
Db::run($db,"INSERT INTO native_grants(actor_id,module,capability) VALUES('editor','website','manage')");check(in_array($private['id'],selected($db,$editor),true),'current additional grant is recognized without resetting sessions or originals');
Db::run($db,"DELETE FROM native_grants WHERE actor_id='editor' AND module='website' AND capability='manage'");check(!in_array($private['id'],selected($db,$editor),true),'grant revocation immediately removes original from SQL projection');

$family=$model->saveTemplate(['key'=>'policy_parent','label'=>'Parent family','renderer'=>'standard','fields'=>[],'policy'=>['children'=>['policy_parent']]]);
$parent=$pages->save(['title'=>'Family parent','slug'=>'policy-family','template'=>'policy_parent']);
$input=(new DataModel($db,$admin))->normalizePage(['title'=>'Wrong child','slug'=>'policy-family/wrong','parent_id'=>$parent['id']]);
rejects(fn()=>TemplatePolicy::assertChange($db,$admin,bin2hex(random_bytes(16)),$input),Conflict::class,'new child must satisfy the selected parent template family');
$valid=(new DataModel($db,$admin))->normalizePage(['title'=>'Valid child','slug'=>'policy-family/valid','template'=>'policy_parent','parent_id'=>$parent['id']]);TemplatePolicy::assertChange($db,$admin,bin2hex(random_bytes(16)),$valid);check(true,'mutually compatible parent and child family is accepted');
$descendant=$pages->save($valid);$familyBefore=$model->template('policy_parent');$familyHistory=$model->templateRevisions('policy_parent');
rejects(fn()=>$model->saveTemplate(array_replace($family,['policy'=>['children'=>[]]]),$family['version']),Conflict::class,'model policy update cannot strand an existing child hierarchy');
check($model->template('policy_parent')===$familyBefore&&$model->templateRevisions('policy_parent')===$familyHistory,'failed family policy keeps exact prior definition and revision history');
rejects(fn()=>$model->saveTemplate(['key'=>'policy_unknown','label'=>'Unknown family','renderer'=>'standard','fields'=>[],'policy'=>['parents'=>['does_not_exist']]]),InvalidArgumentException::class,'unknown family destination is rejected before an original revision is created');
$noRoot=$model->saveTemplate(['key'=>'policy_no_root','label'=>'Child only','renderer'=>'standard','fields'=>[],'policy'=>['root'=>false]]);
$rootInput=(new DataModel($db,$admin))->normalizePage(['title'=>'Missing parent','slug'=>'policy-no-parent','template'=>'policy_no_root']);
rejects(fn()=>TemplatePolicy::assertChange($db,$admin,bin2hex(random_bytes(16)),$rootInput),Conflict::class,'child-only template requires a real permitted parent');
$pageInput=$ordinary;$pageInput['page_policy']=['public'=>false];rejects(fn()=>TemplatePolicy::assertChange($db,$editor,$ordinary['id'],$pageInput,$ordinary),DomainException::class,'editor cannot change page-specific access or make an independent override');
rejects(fn()=>TemplatePolicy::pagePolicy(['children'=>[]]),InvalidArgumentException::class,'per-page override cannot replace template family constraints');

$blind=$model->saveTemplate(['key'=>'policy_blind','label'=>'Protected reading with ordinary action levels','renderer'=>'standard','fields'=>[],'policy'=>['read'=>'manage','edit'=>'edit','publish'=>'publish','public'=>false]]);
$blindPage=$pages->save(['title'=>'No blind writes','slug'=>'policy-blind','template'=>'policy_blind']);
Db::run($db,"INSERT INTO native_users(id,club_id,name) VALUES('publisher','synthetic-a','Own isolated publisher')");foreach(['read','edit','publish'] as $cap)Db::run($db,"INSERT INTO native_grants(actor_id,module,capability) VALUES('publisher','website',?)",[$cap]);$publisher=new Access($db,'publisher');
rejects(fn()=>TemplatePolicy::assertCapability($db,$publisher,$blindPage['id'],'edit'),DomainException::class,'additional edit permission cannot bypass restricted reading by a direct known ID');
rejects(fn()=>TemplatePolicy::assertCapability($db,$publisher,$blindPage['id'],'publish'),DomainException::class,'additional publication permission cannot bypass restricted reading by a direct known ID');
$remove=$pages->save(['title'=>'Protected purge original','slug'=>'policy-protected-purge','template'=>'policy_blind']);$remove=(new PageTrash($db,$admin))->move($remove['id'],$remove['version']);
rejects(fn()=>(new PagePurge($db,$publisher))->purge($remove['id'],$remove['version'],true),DomainException::class,'permanent removal requires template publication AND reading rights');
(new PagePurge($db,$admin))->purge($remove['id'],$remove['version'],true);$again=(new PagePurge($db,$publisher))->purge($remove['id'],$remove['version'],true);check($again['replayed'],'content-free exact tombstone retry remains idempotent after source no longer exists');
$secret=$pages->save(['title'=>'Secret source title never shown','slug'=>'policy-secret-source','template'=>'policy_private','parent_id'=>$ordinary['id']]);
$incoming=(new PageReferences($db,$reader))->incoming($ordinary['id']);$encoded=json_encode($incoming,JSON_THROW_ON_ERROR);
check((bool)array_filter($incoming,static fn($row)=>!empty($row['restricted']))&&!str_contains($encoded,$secret['id'])&&!str_contains($encoded,$secret['title'])&&!str_contains($encoded,$secret['slug']),'dependency observation retains a generic blocker without leaking private source identity or title');
$picker=(new ReferenceCatalog($db,$reader))->listing('page','',1,$secret['id']);check($picker['selected']===null&&!str_contains(json_encode($picker,JSON_THROW_ON_ERROR),$secret['id']),'bounded page-reference picker and selected label exclude protected originals');
$privateEditor=new Pages($db,$editor);$privateReader=new Pages($db,$reader);$protectedOriginal=Db::run($db,'SELECT * FROM khcms_pages WHERE id=?',[$private['id']])->fetch();$protectedRevisions=Db::run($db,'SELECT * FROM khcms_revisions WHERE page_id=? ORDER BY revision',[$private['id']])->fetchAll();
foreach(['get'=>fn()=>$privateEditor->get($private['id']),'revisions'=>fn()=>$privateEditor->revisions($private['id']),'save'=>fn()=>$privateEditor->save(array_replace($private,['title'=>'Forged original']),$private['version']),'publish'=>fn()=>(new Pages($db,$publisher))->publish($private['id'],$private['version']),'archive'=>fn()=>(new Pages($db,$publisher))->archive($private['id'],$private['version']),'restore'=>fn()=>$privateEditor->restore($private['id'],1,$private['version']),'copy'=>fn()=>$privateEditor->copyFrom($private['id'],$private['version'],['title'=>'Forged copy','slug'=>'policy-forged-copy','basis'=>'draft']),'trash'=>fn()=>(new PageTrash($db,$publisher))->move($private['id'],$private['version'])] as $name=>$operation)rejects($operation,DomainException::class,'actual private page service rejects protected '.$name.' by known original ID');
$privateProjection=json_encode([$privateReader->list(),$privateReader->search([]),$privateReader->tree()],JSON_THROW_ON_ERROR);check(!str_contains($privateProjection,'Private original')&&!str_contains($privateProjection,'Inherited child')&&!in_array($private['id'],array_column($privateReader->list(),'id'),true)&&!in_array($child['id'],array_column($privateReader->search([],500),'id'),true)&&$privateReader->count()===count($privateReader->search([],500))&&$privateReader->count()<$pages->count(),'actual private list/search/count/tree consistently exclude protected ancestors and children');
rejects(fn()=>(new \Kulturhus\Native\Cms\TreeCopies($db,$editor))->create($ordinary['id'],$ordinary['version'],['title'=>'Partial hidden subtree','slug'=>'policy-partial-tree','basis'=>'draft']),DomainException::class,'actual tree copy rejects a hidden selected child instead of silently dropping it');
check($privateReader->publicPage($private['slug'])===null&&!in_array($private['id'],array_column($privateReader->publicList(),'id'),true)&&!in_array($private['id'],array_column(iterator_to_array($privateReader->publicOriginals()),'id'),true),'actual public direct page, list and original stream exclude protected originals');
check(Db::run($db,'SELECT * FROM khcms_pages WHERE id=?',[$private['id']])->fetch()===$protectedOriginal&&Db::run($db,'SELECT * FROM khcms_revisions WHERE page_id=? ORDER BY revision',[$private['id']])->fetchAll()===$protectedRevisions,'all failed private operations preserve exact original page and revision bytes');

require __DIR__.'/support/own-website-pdf.php';$files=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/policy-files';mkdir($files,0700);$png=$files.'/own.png';$im=imagecreatetruecolor(10,8);imagepng($im,$png);imagedestroy($im);
$media=new Media($db,$admin,$files.'/media');$image=$media->upload($png,'Own exact image.png','Own original alternative');$pdf=ownWebsitePdf('Own exact policy original PDF');file_put_contents($files.'/own.pdf',$pdf);$docs=new WebsiteDocuments($db,$admin,$files.'/documents');$document=$docs->uploadMany([['path'=>$files.'/own.pdf','name'=>'Own original.pdf']])[0];
$assets=$model->saveTemplate(['key'=>'policy_assets','label'=>'Published media original','renderer'=>'standard','fields'=>[],'policy'=>['public'=>true]]);
$assetPage=$pages->save(['title'=>'Published exact assets','slug'=>'policy-assets','template'=>'policy_assets','media_ids'=>[$image['id']],'document_links'=>[['id'=>$document['id'],'label'=>'Own file']]]);$assetPage=$pages->publish($assetPage['id'],$assetPage['version']);
check($media->publicVariant($image['id'],'medium')!==null&&$docs->publicFile($document['id'])!==null,'actual public image and PDF require a current allowed original page');
$imageOriginal=$media->variant($image['id'],'medium');$pdfOriginal=$docs->file($document['id']);$imageBytes=hash_file('sha256',$imageOriginal['path']);$pageRows=Db::run($db,'SELECT * FROM khcms_pages WHERE id=?',[$assetPage['id']])->fetch();$revisionRows=Db::run($db,'SELECT * FROM khcms_revisions WHERE page_id=? ORDER BY revision',[$assetPage['id']])->fetchAll();
$generation=$pages->generation();$assets=$model->saveTemplate(array_replace($assets,['policy'=>['public'=>false,'theme'=>'natur','cache'=>'off']]),$assets['version']);
check($media->publicVariant($image['id'],'medium')===null&&$docs->publicFile($document['id'])===null,'changing current template access immediately revokes original public image and PDF bytes');
check($pages->generation()>$generation&&TemplatePolicy::presentation($assetPage,$db,$admin)===['theme'=>'natur','cache'=>'off'],'current template presentation invalidates cache and selects its actual theme and cache policy');
check(Db::run($db,'SELECT * FROM khcms_pages WHERE id=?',[$assetPage['id']])->fetch()===$pageRows&&Db::run($db,'SELECT * FROM khcms_revisions WHERE page_id=? ORDER BY revision',[$assetPage['id']])->fetchAll()===$revisionRows&&hash_file('sha256',$imageOriginal['path'])===$imageBytes&&file_get_contents($pdfOriginal['path'])===$pdf,'permission change preserves all original page, revision, image and PDF bytes');
$shared=$pages->save(['title'=>'Independent public use','slug'=>'policy-shared-image','media_ids'=>[$image['id']]]);$shared=$pages->publish($shared['id'],$shared['version']);
check($media->publicVariant($image['id'],'medium')!==null&&$media->publicVariant($image['id'],'medium',$assetPage)===null,'another public image use does not disclose private page-specific metadata');

[$workerWhere,$workerArgs]=TemplatePolicy::publicFilter($db,$admin->club());$workerIds=Db::run($db,'SELECT p.id FROM khcms_pages p WHERE '.$workerWhere.' ORDER BY p.id',$workerArgs)->fetchAll(PDO::FETCH_COLUMN);
check($workerIds===selected($db,$admin,true),'trusted worker public filter has exactly anonymous visibility without requiring an actor');
$news=[];foreach([true,false] as $visible){$item=$pages->save(['title'=>$visible?'Public story':'Secret story original','slug'=>'aktuelles/policy-'.($visible?'public':'secret'),'template'=>'news','news_date'=>'2098-05-04','page_policy'=>['public'=>$visible]]);$news[]=$pages->publish($item['id'],$item['version']);}
$articles=new Articles($db,$admin);check($articles->publicCount()===1&&array_column($articles->publicList(1),'id')===[$news[0]['id']]&&$articles->publicList(1,1)===[],'article SQL excludes private originals before count, limit and offset');
$albums=[];foreach([true,false] as $visible){$item=$pages->save(['title'=>$visible?'Public album':'Secret album original','slug'=>'galerie/policy-'.($visible?'public':'secret'),'template'=>'gallery','page_policy'=>['public'=>$visible]]);$albums[]=$pages->publish($item['id'],$item['version']);}
$albumList=(new PublishedAlbums($db,$admin))->listing();check($albumList['total']===1&&array_column($albumList['items'],'id')===[$albums[0]['id']],'album total and bounded keys contain only currently public originals');
$navigation=new Navigation($db,$admin);$navigationJson=json_encode($navigation->publicLinks(),JSON_THROW_ON_ERROR);
check(!str_contains($navigationJson,$private['id'])&&!str_contains($navigationJson,$news[1]['id'])&&!str_contains($navigationJson,$albums[1]['id']),'public navigation never discloses private template or per-page originals');
$footer=$pages->save(['title'=>'Private legal original','slug'=>'impressum','page_policy'=>['public'=>false]]);$footer=$pages->publish($footer['id'],$footer['version']);check(!in_array($footer['id'],array_column($navigation->footerLinks(),'id'),true),'footer metadata applies public policy before its bounded legal-page selection');
$countBefore=$navigation->count();$shared=$pages->save(array_replace($shared,['page_policy'=>['public'=>false]]),$shared['version']);check($navigation->count()===$countBefore-1,'navigation count immediately reflects page access revocation before aggregation');
$forgedRows=[['id'=>$private['id'],'payload_json'=>json_encode($private,JSON_THROW_ON_ERROR)]];check($navigation->links($forgedRows)===[],'public navigation rejects stale caller-provided private original rows');
$guarded=$pages->save(['title'=>'Visible but privileged menu entry','slug'=>'policy-publish-menu','page_policy'=>['publish'=>'manage']]);$guarded=$pages->publish($guarded['id'],$guarded['version']);$state=$navigation->state();
rejects(fn()=>(new Navigation($db,$publisher))->save([['id'=>$guarded['id'],'label'=>'Forged menu override','position'=>1,'visible'=>true]],true,$state['version']),DomainException::class,'menu write cannot bypass a page-specific publication requirement');
check(PagePolicyPanel::submitted([],$editor)===null&&PagePolicyPanel::render($ordinary,$editor)==='','ordinary editor controls preserve inherited rules without exposing an unusable policy form');
check(PagePolicyPanel::submitted(['page_policy_present'=>'1','page_policy_read'=>'manage','page_policy_public'=>'0'],$admin)===['public'=>false,'read'=>'manage'],'normal per-page form compiles explicit access settings without JSON');
rejects(fn()=>PagePolicyPanel::submitted(['page_policy_present'=>'1','page_policy_public'=>'1'],$editor),DomainException::class,'forged policy form is denied even if the checkbox grants public visibility');
rejects(fn()=>PagePolicyPanel::submitted(['page_policy_present'=>'1','page_policy_inherit'=>['1']],$admin),InvalidArgumentException::class,'policy form refuses array injection into declarative controls');
$event=$pages->save(['title'=>'Retained cancellation original','slug'=>'policy-cancellation','template'=>'event','event_start'=>'2098-05-04T19:00','event_timezone'=>'Europe/Berlin']);$event=$pages->publish($event['id'],$event['version']);
Db::run($db,"INSERT INTO native_event_sources(club_id,source,external_id,page_id,source_hash,state) VALUES(?,'facebook','own-policy-cancellation',?,?,'cancelled')",[$admin->club(),$event['id'],str_repeat('0',64)]);
Db::atomic($db,function()use($db,$admin,$pages,&$event):void{$original=EventIndex::capture($db,$admin->club(),$event['id']);$event=$pages->unpublish($event['id'],$event['version']);EventIndex::cancellation($db,$admin->club(),$original,$event['version']);});
check(in_array($event['id'],selected($db,$reader,true),true),'genuine retained provider cancellation notice uses its exact original public revision');
$event=$pages->save(array_replace($event,['page_policy'=>['public'=>false]]),$event['version']);check(!in_array($event['id'],selected($db,$admin,true),true),'current page access immediately withdraws a retained cancellation notice too');
$visibleEvents=[];foreach([true,false] as $public){$row=$pages->save(['title'=>$public?'Own visible date':'Own hidden date','slug'=>$public?'policy-visible-date':'policy-hidden-date','template'=>'event','event_start'=>($public?'2087':'2088').'-05-04T19:00','event_timezone'=>'Europe/Berlin','page_policy'=>['public'=>$public]]);$visibleEvents[]=$pages->publish($row['id'],$row['version']);}
$events=new \Kulturhus\Native\Cms\Events($db,$admin->club());$eventListing=$events->listing([],new DateTimeImmutable('2080-01-01T00:00:00Z'),6);
check($eventListing['total']===1&&$eventListing['years']===[2087]&&array_column($eventListing['rows'],'id')===[$visibleEvents[0]['id']],'actual actor-free event worker excludes private originals before count, year facets and pagination');
check($events->publicId($visibleEvents[1]['id'])===null&&$events->publicPage($visibleEvents[1]['slug'])===null&&array_column($events->between('2080-01-01','2090-01-01'),'id')===[$visibleEvents[0]['id']]&&array_column(iterator_to_array($events->all()),'id')===[$visibleEvents[0]['id']],'actual event detail, bounded range and original feed stream share anonymous policy');
$cacheRoot=$files.'/policy-cache';$site=new \Kulturhus\Native\Web\Site($pages,$media,new \Kulturhus\Native\Web\Cache($cacheRoot),'https://own.invalid',$admin->club(),null,null,'/?view=website',['website_design'=>'klar']);
$assets=$model->saveTemplate(array_replace($assets,['policy'=>['public'=>true,'theme'=>'natur','cache'=>'off']]),$assets['version']);$cache=new \Kulturhus\Native\Cache\Store($cacheRoot,$admin->club());$cacheBefore=$cache->listing()['total'];$themed=$site->page($assetPage['slug']);
check(str_contains($themed,'design-natur')&&$cache->listing()['total']===$cacheBefore,'actual Site applies current template theme and cache-off before lookup or cache storage');
$unthemed=$site->page($ordinary['slug']);check(str_contains($unthemed,'design-klar')&&!str_contains($unthemed,'design-natur')&&$cache->listing()['total']===$cacheBefore+1,'same Site instance restores club design for next template and caches only permitted ordinary originals');
$ordinary=$pages->save(array_replace($ordinary,['page_policy'=>['public'=>false]]),$ordinary['version']);check($site->page($ordinary['slug'])===null,'actual Site refuses a previously cached original immediately after page access revocation');

// Corrupted legacy hierarchy must fail closed rather than silently ignoring an inaccessible ancestor.
$loopA=$pages->save(['title'=>'Loop A','slug'=>'policy-loop-a']);$loopB=$pages->save(['title'=>'Loop B','slug'=>'policy-loop-b']);
$a=$loopA;$a['parent_id']=$loopB['id'];$b=$loopB;$b['parent_id']=$loopA['id'];
foreach([$a,$b] as $item)Db::run($db,'UPDATE khcms_pages SET draft_json=? WHERE id=?',[json_encode($item,JSON_THROW_ON_ERROR),$item['id']]);
check(!in_array($loopA['id'],selected($db,$admin),true)&&!in_array($loopB['id'],selected($db,$admin),true),'cyclic retained parent chain fails closed for every current role');
$missing=$pages->save(['title'=>'Missing parent original','slug'=>'policy-missing']);$missing['parent_id']=str_repeat('f',32);Db::run($db,'UPDATE khcms_pages SET draft_json=? WHERE id=?',[json_encode($missing,JSON_THROW_ON_ERROR),$missing['id']]);
check(!in_array($missing['id'],selected($db,$admin),true),'missing parent in retained original cannot bypass inherited access checks');
$state=Db::run($db,'SELECT * FROM khcms_template_policy_state WHERE club_id=?',[$admin->club()])->fetch();TemplatePolicy::install($db);check(Db::run($db,'SELECT * FROM khcms_template_policy_state WHERE club_id=?',[$admin->club()])->fetch()===$state,'repeated explicit policy maintenance preserves existing state version and flags');
echo json_encode(['checks'=>$checks,'live_transport'=>false],JSON_THROW_ON_ERROR)."\n";
if(getenv('DVM_TEMPLATE_POLICY_BROWSER')==='1'){require __DIR__.'/support/template-policy-browser-fixture.php';ownTemplatePolicyBrowser($db);}
