<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';

use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Schema,Pages,PageTrash,PagePurge,Conflict,DataModel,PublicationSchedule};

$db=TestSupport::db();
if (($argv[1]??'')==='race') {
    $mode=$argv[2];$id=$argv[3];$version=(int)$argv[4];$ready=$argv[5];$release=$argv[6];
    if ($mode==='reference') {
        $db->beginTransaction();
        Db::run($db,'INSERT INTO synthetic_page_retention VALUES(?,?,?)',['synthetic-a',bin2hex(random_bytes(16)),json_encode(['page_id'=>$id],JSON_THROW_ON_ERROR)]);
        file_put_contents($ready,'ready');$until=microtime(true)+10;
        while (!is_file($release)) {if(microtime(true)>$until)exit(3);usleep(10000);}
        $db->commit();echo 'reference';
    } else {
        file_put_contents($ready,'ready');
        try {(new PagePurge($db,new Access($db,'admin')))->purge($id,$version,true);echo 'purged';}
        catch (Conflict) {echo 'conflict';}
    }
    exit();
}
TestSupport::seed($db);Schema::install($db);PagePurge::install($db);
$access=new Access($db,'admin');$pages=new Pages($db,$access);$trash=new PageTrash($db,$access);$purge=new PagePurge($db,$access);$checks=0;
function check(bool $okay,string $label):void {global $checks;if(!$okay)throw new RuntimeException($label);++$checks;echo "PASS $label\n";}
function rejects(callable $operation,string $class,string $label):void {try{$operation();}catch(Throwable $error){check($error instanceof $class,$label.' ('.$error::class.')');return;}throw new RuntimeException($label);}
function stored(PDO $db,string $table,string $id,string $key='page_id'):array {return Db::run($db,'SELECT * FROM `'.$table.'` WHERE `'.$key.'`=? ORDER BY 1',[$id])->fetchAll();}
function retire(Pages $pages,PageTrash $trash,string $slug):array {$page=$pages->save(['title'=>$slug,'slug'=>$slug]);return $trash->move($page['id'],$page['version']);}

$live=$pages->save(['title'=>'Original permanent content','slug'=>'purge-original','body_html'=>'<p>Original 🎶 &amp; 0</p>']);
rejects(fn()=>$purge->purge($live['id'],$live['version'],true),Conflict::class,'active draft cannot be permanently removed');
$live=$pages->publish($live['id'],$live['version']);
rejects(fn()=>$purge->purge($live['id'],$live['version'],true),Conflict::class,'published original cannot be removed');
$live=$pages->save(array_replace($live,['slug'=>'purge-renamed']),$live['version']);$live=$pages->publish($live['id'],$live['version']);
$retired=$trash->move($live['id'],$live['version']);$original=$pages->get($retired['id']);
$draft=Db::run($db,'SELECT draft_json FROM khcms_pages WHERE id=?',[$retired['id']])->fetchColumn();
rejects(fn()=>$purge->purge($retired['id'],$retired['version'],false),InvalidArgumentException::class,'explicit irreversible confirmation required');
rejects(fn()=>$purge->purge($retired['id'],$retired['version']-1,true),Conflict::class,'stale exact version rejected');
foreach (['reader','editor','outsider'] as $actor) rejects(fn()=>(new PagePurge($db,new Access($db,$actor)))->purge($retired['id'],$retired['version'],true),DomainException::class,'only same-club publication authority can purge '.$actor);
check($pages->get($retired['id'])===$original,'all rejected attempts preserve exact original');
$db->beginTransaction();
rejects(fn()=>$purge->purge($retired['id'],$retired['version'],true),DomainException::class,'outer transaction is rejected without committing caller work');
check($db->inTransaction(),'caller transaction remains open');$db->rollBack();
$media=bin2hex(random_bytes(16));$file=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/permanent-original-image.bin';$bytes="\x00original media bytes 🎶\xff";file_put_contents($file,$bytes);
Db::run($db,'INSERT INTO khcms_media(id,club_id,original_name,alt,mime,width,height,variants_json,sha256,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$media,'synthetic-a','original.bin','Original','image/png',1,1,json_encode(['original'=>['path'=>$file]],JSON_THROW_ON_ERROR),hash('sha256',$bytes),'admin']);
$other=$pages->save(['title'=>'Unrelated retained original','slug'=>'purge-other','body_html'=>'<p>Other exact content</p>']);$otherBefore=$pages->get($other['id']);$mediaBefore=stored($db,'khcms_media',$media,'id');$generation=$pages->generation();
$history=stored($db,'khcms_revisions',$retired['id']);$result=$purge->purge($retired['id'],$retired['version'],true);
check($result===['id'=>$retired['id'],'version'=>$retired['version'],'purged'=>true,'replayed'=>false],'explicit exact original is really permanently removed');
foreach (['khcms_pages'=>'id','khcms_revisions'=>'page_id','khcms_trash'=>'page_id','khcms_archives'=>'page_id','khcms_path_history'=>'page_id','khcms_event_publications'=>'page_id'] as $table=>$key) check(stored($db,$table,$retired['id'],$key)===[],'owned original removed from '.$table);
$tombstone=stored($db,'khcms_page_tombstones',$retired['id'])[0];
check($tombstone['draft_sha256']===hash('sha256',$draft)&&(int)$tombstone['revision_count']===count($history)&&$tombstone['purged_by']==='admin','audit retains exact digest, revision count and actor without deleted content');
check($pages->generation()===$generation+1,'one permanent change invalidates public cache once');
check($pages->get($other['id'])===$otherBefore&&stored($db,'khcms_media',$media,'id')===$mediaBefore&&file_get_contents($file)===$bytes,'other page and original media metadata and bytes remain identical');
foreach (['purge-original','purge-renamed'] as $slug) rejects(fn()=>PagePurge::assertPathAvailable($db,'synthetic-a',$slug),Conflict::class,'historical and current addresses remain reserved '.$slug);
PagePurge::assertPathAvailable($db,'synthetic-b','purge-original');check(true,'address tombstones are club scoped');
$again=$purge->purge($retired['id'],$retired['version'],true);
check($again['replayed']&&$pages->generation()===$generation+1&&stored($db,'khcms_page_tombstones',$retired['id'])[0]===$tombstone,'lost-response retry changes no generation or original audit');
rejects(fn()=>$purge->purge($retired['id'],$retired['version']-1,true),Conflict::class,'retry requires exact accepted original version');
rejects(fn()=>(new PagePurge($db,new Access($db,'outsider')))->purge($retired['id'],$retired['version'],true),DomainException::class,'other club cannot infer replay result');

// A trashed child's historical parent edge must not disappear from dependency checks.
$target=$pages->save(['title'=>'Historical destination','slug'=>'purge-history-target']);
$child=$pages->save(['title'=>'Historical child','slug'=>'purge-history-child','parent_id'=>$target['id']]);
$child=$pages->save(array_replace($child,['parent_id'=>null]),$child['version']);$child=$trash->move($child['id'],$child['version']);$target=$trash->move($target['id'],$target['version']);
$before=stored($db,'khcms_revisions',$target['id']);
rejects(fn()=>$purge->purge($target['id'],$target['version'],true),Conflict::class,'old revision of a trashed child blocks irreversible parent deletion');
check(stored($db,'khcms_revisions',$target['id'])===$before&&stored($db,'khcms_page_tombstones',$target['id'])===[],'blocked removal leaves revisions and tombstones unchanged');
$purge->purge($child['id'],$child['version'],true);$purge->purge($target['id'],$target['version'],true);check(true,'unreferenced leaves can be removed before former parent');

// Field definitions are retained in old revisions, even after the current reference is removed.
$target=$pages->save(['title'=>'Typed destination','slug'=>'purge-typed-target']);$model=new DataModel($db,$access);
$field=$model->saveField(['key'=>'purge_link','label'=>'Original link','type'=>'page-reference']);$template=$model->saveTemplate(['key'=>'purge_links','label'=>'Links','renderer'=>'standard','fields'=>[$field['key']]]);
$link=$pages->save(['title'=>'Typed source','slug'=>'purge-typed-source','template'=>$template['key'],'custom_fields'=>[$field['key']=>$target['id']]]);
$link=$pages->save(array_replace($link,['custom_fields'=>[$field['key']=>null]]),$link['version']);$target=$trash->move($target['id'],$target['version']);
rejects(fn()=>$purge->purge($target['id'],$target['version'],true),Conflict::class,'historical typed page reference blocks deletion');

$address=$pages->save(['title'=>'Address destination','slug'=>'purge-url-target']);$article=$pages->save(['title'=>'Address source','slug'=>'purge-url-source','body_html'=>'<p><a href="/purge-url-target?from=archive">Original link</a></p>']);
$article=$pages->save(array_replace($article,['body_html'=>'<p>Current text</p>']),$article['version']);$address=$trash->move($address['id'],$address['version']);
rejects(fn()=>$purge->purge($address['id'],$address['version'],true),Conflict::class,'historical rich-text address references are protected');
$prefix=retire($pages,$trash,'purge-prefix');$similar=$pages->save(['title'=>'Different address','slug'=>'purge-prefix-longer','body_html'=>'<p><a href="/purge-prefix-longer">Different page</a></p>']);
$purge->purge($prefix['id'],$prefix['version'],true);check($pages->get($similar['id'])['id']===$similar['id'],'another complete address sharing a prefix does not prevent deletion');

// Existing business tables do not all have foreign keys. Their original binding still blocks purge.
$business=retire($pages,$trash,'purge-offer');
Db::run($db,'INSERT INTO native_ticket_offers(club_id,page_id,version,capacity,price_cents,active,updated_by,updated_at) VALUES(?,?,1,100,500,0,?,UTC_TIMESTAMP(6))',['synthetic-a',$business['id'],'admin']);$offer=stored($db,'native_ticket_offers',$business['id']);
rejects(fn()=>$purge->purge($business['id'],$business['version'],true),Conflict::class,'inactive original ticket offer blocks deletion');
check(stored($db,'native_ticket_offers',$business['id'])===$offer,'blocked deletion preserves financial offer bytes');
$source=retire($pages,$trash,'purge-provider-source');Db::run($db,'INSERT INTO native_event_source_aliases(club_id,source,external_id,page_id) VALUES(?,?,?,?)',['synthetic-a','facebook','original-facebook-source',$source['id']]);
rejects(fn()=>$purge->purge($source['id'],$source['version'],true),Conflict::class,'provider alias original blocks deletion');
$scheduled=$pages->save(['title'=>'Original approved plan','slug'=>'purge-scheduled']);$plan=(new PublicationSchedule($db,$access))->plan($scheduled['id'],$scheduled['version'],time()+3600);$scheduled=$trash->move($scheduled['id'],$scheduled['version']);$schedule=stored($db,'khcms_publication_schedule',$scheduled['id']);
rejects(fn()=>$purge->purge($scheduled['id'],$scheduled['version'],true),Conflict::class,'cancelled approved plan retains its original page binding');
check(stored($db,'khcms_publication_schedule',$scheduled['id'])===$schedule,'plan history remains byte-identical');

// Future module records and escaped JSON keys are covered without a hard-coded table list.
$db->exec('CREATE TABLE synthetic_page_retention(club_id VARCHAR(64) NOT NULL,id CHAR(32) NOT NULL,payload_json MEDIUMTEXT NOT NULL,PRIMARY KEY(club_id,id)) ENGINE=InnoDB');
$future=retire($pages,$trash,'purge-future');
$encoded=json_encode([$future['id']=>['retained'=>true]],JSON_THROW_ON_ERROR);$first=$future['id'][0];$encoded=str_replace('"'.$future['id'].'"','"\\u'.str_pad(dechex(ord($first)),4,'0',STR_PAD_LEFT).substr($future['id'],1).'"',$encoded);
check(json_decode($encoded,true,512,JSON_THROW_ON_ERROR)===[$future['id']=>['retained'=>true]],'escaped retained reference is valid original JSON');
Db::run($db,'INSERT INTO synthetic_page_retention VALUES(?,?,?)',['synthetic-a',bin2hex(random_bytes(16)),$encoded]);
rejects(fn()=>$purge->purge($future['id'],$future['version'],true),Conflict::class,'canonical escaped JSON key in future module blocks deletion');
$malformed=retire($pages,$trash,'purge-malformed');Db::run($db,'INSERT INTO synthetic_page_retention VALUES(?,?,?)',['synthetic-a',bin2hex(random_bytes(16)),'{broken original']);
rejects(fn()=>$purge->purge($malformed['id'],$malformed['version'],true),Conflict::class,'unreadable retained original fails closed');
Db::run($db,'DELETE FROM synthetic_page_retention WHERE payload_json=?',['{broken original']);

// Even an unknown CASCADE in a table without club_id cannot erase another business original.
$db->exec('CREATE TABLE synthetic_page_cascade(id INT NOT NULL PRIMARY KEY,page_ref CHAR(32) NOT NULL,FOREIGN KEY(page_ref) REFERENCES khcms_pages(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
$cascade=retire($pages,$trash,'purge-cascade');Db::run($db,'INSERT INTO synthetic_page_cascade VALUES(1,?)',[$cascade['id']]);
rejects(fn()=>$purge->purge($cascade['id'],$cascade['version'],true),Conflict::class,'unknown cross-module CASCADE never authorizes deletion of its business original');
check((int)Db::run($db,'SELECT COUNT(*) FROM synthetic_page_cascade')->fetchColumn()===1&&$pages->get($cascade['id'])['trashed'],'unknown bound original and trashed page both retained');

// A genuine SQL failure after the tombstone insert rolls the entire irreversible operation back.
$rollback=retire($pages,$trash,'purge-rollback');$rollbackOriginal=$pages->get($rollback['id']);$rollbackGeneration=$pages->generation();
$db->exec("CREATE TRIGGER synthetic_page_purge_failure BEFORE DELETE ON khcms_pages FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic deletion failure'");
rejects(fn()=>$purge->purge($rollback['id'],$rollback['version'],true),PDOException::class,'late database failure rolls back permanent deletion');
$db->exec('DROP TRIGGER synthetic_page_purge_failure');
check($pages->get($rollback['id'])===$rollbackOriginal&&stored($db,'khcms_page_tombstones',$rollback['id'])===[]&&stored($db,'khcms_purged_paths',$rollback['id'])===[]&&$pages->generation()===$rollbackGeneration,'failed commit leaves exact original, addresses, audit and generation intact');
$purge->purge($rollback['id'],$rollback['version'],true);check(true,'same exact confirmation succeeds after transient storage failure is resolved');

// A concurrently committed original must be observed before removal, not from a stale snapshot.
$concurrent=retire($pages,$trash,'purge-concurrent');$dir=dirname(getenv('KH_NATIVE_TEST_CONFIG'));$release=$dir.'/purge-reference-release';$workers=[];
foreach (['reference','purge'] as $mode) {
    $ready=$dir.'/purge-'.$mode.'-ready';
    $process=proc_open([...TestSupport::phpCommand(),__FILE__,'race',$mode,$concurrent['id'],(string)$concurrent['version'],$ready,$release],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Concurrent purge test unavailable.');fclose($pipes[0]);$workers[]=[$process,$pipes,$ready];
    $until=microtime(true)+10;while(!is_file($ready)){if(microtime(true)>$until)throw new RuntimeException('Concurrent purge setup timeout.');usleep(10000);}
}
file_put_contents($release,'release');$outcomes=[];
foreach ($workers as [$process,$pipes,$ready]) {
    $outcomes[]=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    check(proc_close($process)===0&&$error==='','actual concurrent dependency process completes');unlink($ready);
}
unlink($release);sort($outcomes);
check($outcomes===['conflict','reference']&&$pages->get($concurrent['id'])['trashed']&&stored($db,'khcms_page_tombstones',$concurrent['id'])===[],'concurrently committed original is retained and prevents irreversible deletion');
// A later original DELETE failure must restore every language row deleted earlier in the same TX.
Db::run($db,"INSERT INTO native_users(id,club_id,name) VALUES('purge-publisher','synthetic-a','Own purge publisher')");foreach(['read','edit','publish'] as $cap)Db::run($db,"INSERT INTO native_grants(actor_id,module,capability) VALUES('purge-publisher','website',?)",[$cap]);$publisher=new Access($db,'purge-publisher');$publisherPurge=new PagePurge($db,$publisher);
$plainRights=retire($pages,$trash,'purge-plain-publisher');$publisherPurge->assertRemovalRights($plainRights['id']);check(str_contains((new \Kulturhus\Native\Web\Website\PageTrashPanel($db,$publisher,'own-csrf'))->render($plainRights),'value="page-purge"'),'ordinary standalone page exposes usable purge form with publish permission before optional language schema exists');
\Kulturhus\Native\Language\Schema::install($db);$languageSettings=new \Kulturhus\Native\Language\Settings($db,$access);$languageSettings->save(['source'=>'de','languages'=>[['code'=>'de','label'=>'Deutsch','enabled'=>true,'fallback'=>null,'messages'=>[]],['code'=>'en','label'=>'English','enabled'=>true,'fallback'=>'de','messages'=>[]]]],0);
$translated=$pages->save(['title'=>'Exact multilingual rollback','slug'=>'purge-language-rollback']);$translated=$pages->publish($translated['id'],$translated['version']);$languages=new \Kulturhus\Native\Language\Translations($db,$access);$languages->save($translated['id'],'en',['slug'=>'retained-language-rollback','fields'=>['title'=>'Exact retained translation']],$translated['published_revision'],0);$languages->publish($translated['id'],'en',1);$translated=$trash->move($translated['id'],$translated['version']);
rejects(fn()=>$publisherPurge->assertRemovalRights($translated['id']),DomainException::class,'own original translations require additional management authority for terminal removal');
$publisherPanel=(new \Kulturhus\Native\Web\Website\PageTrashPanel($db,$publisher,'own-csrf'))->render($translated);check(!str_contains($publisherPanel,'value="page-purge"')&&str_contains($publisherPanel,'value="page-trash-restore"'),'publisher translated-page UI hides unusable purge action while retaining permitted private restore');
$publisherPurge->purge($plainRights['id'],$plainRights['version'],true);check(!stored($db,'khcms_pages',$plainRights['id'],'id'),'language-enabled club still lets ordinary publisher purge a page without any own translations');
$languageBefore=[];foreach(['khcms_pages'=>'id','khcms_revisions'=>'page_id','native_language_pages'=>'page_id','native_language_revisions'=>'page_id','native_language_routes'=>'page_id'] as $table=>$key)$languageBefore[$table]=stored($db,$table,$translated['id'],$key);$languageGeneration=$pages->generation();
$db->exec("CREATE TRIGGER synthetic_page_language_failure BEFORE DELETE ON khcms_pages FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic late language deletion failure'");
rejects(fn()=>$purge->purge($translated['id'],$translated['version'],true),PDOException::class,'later original removal failure rolls back earlier language owner cleanup');$db->exec('DROP TRIGGER synthetic_page_language_failure');
foreach($languageBefore as $table=>$rows)check(stored($db,$table,$translated['id'],$table==='khcms_pages'?'id':'page_id')===$rows,'late rollback retains exact multilingual original in '.$table);
check(stored($db,'khcms_page_tombstones',$translated['id'])===[]&&stored($db,'khcms_purged_paths',$translated['id'])===[]&&$pages->generation()===$languageGeneration,'late multilingual rollback leaves audit, route reservations and public generation unchanged');
$purge->purge($translated['id'],$translated['version'],true);check(stored($db,'native_language_pages',$translated['id'])===[]&&stored($db,'native_language_revisions',$translated['id'])===[]&&stored($db,'native_language_routes',$translated['id'])===[],'same confirmed multilingual original can be retried successfully after storage recovers');
unlink($file);
echo json_encode(['checks'=>$checks,'live_transport'=>false],JSON_THROW_ON_ERROR)."\n";
