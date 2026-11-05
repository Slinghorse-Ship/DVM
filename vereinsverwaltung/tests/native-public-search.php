<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db,RuntimeSchema};
use Kulturhus\Native\Cms\{Pages,Schema,PublicSearch};
use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Native\Web\{Feeds,PublicSearchView};
require __DIR__.'/support/original-database-hash.php';
$db=TestSupport::db();$access=new Access($db,'admin');$n=0;
function searchCheck(bool $ok,string $label):void{global$n;if(!$ok)throw new RuntimeException($label);++$n;echo 'PASS '.$label."\n";}
function searchReject(callable $f,string $label):void{try{$f();}catch(InvalidArgumentException|DomainException){searchCheck(true,$label);return;}throw new RuntimeException($label);}
if(in_array($argv[1]??'',['probe','probe-locale'],true)){
    $r=(new Pages($db,$access))->publicSearch('vollständig ä🙂 literal.*',9,20,($argv[1]??'')==='probe-locale'?'de':null);
    if($r['total']!==175||count($r['rows'])!==15)throw new RuntimeException('Incomplete large public original search.');
    echo json_encode(['memory'=>memory_get_peak_usage(true),'total'=>$r['total']]);exit;
}
TestSupport::seed($db);RuntimeSchema::install($db);Schema::install($db);
\Kulturhus\Native\Language\Schema::install($db);
$club='own-public-search-'.bin2hex(random_bytes(5));$nativeClub=ManagerBridge::clubKey($club);
Db::run($db,"UPDATE native_users SET club_id=? WHERE id IN ('admin','reader','editor')",[$nativeClub]);
$pages=new Pages($db,$access);$foreign=new Pages($db,new Access($db,'outsider'));$feeds=new Feeds($pages,'http://127.0.0.1:8190');$ids=[];
for($i=0;$i<175;++$i){
    $row=$pages->save(['slug'=>'search-original-'.$i,'title'=>'Eigenes Suchoriginal '.$i.' & <Text>','body'=>str_repeat('x',97000).' vollständig Ä🙂 literal.*'.($i===0?' SELTENES-ALTES-ORIGINAL':''),'excerpt'=>'Eigener unveränderter Ausschnitt']);$row=$pages->publish($row['id'],$row['version']);$ids[]=$row['id'];
}
// Deliberate equal timestamps in this owned synthetic corpus exercise keyset ties.
Db::run($db,"UPDATE khcms_pages SET published_at='2030-01-01 10:00:00.000000' WHERE club_id=?",[$nativeClub]);
sort($ids,SORT_STRING);
$draft=$pages->save(['slug'=>'private-search','title'=>'PRIVATE-SEARCH','body'=>'vollständig Ä🙂 literal.* PRIVATE-ONLY']);
$other=$foreign->save(['slug'=>'foreign-search','title'=>'FOREIGN-SEARCH','body'=>'vollständig Ä🙂 literal.* FOREIGN-ONLY']);$foreign->publish($other['id'],$other['version']);
$rss=$pages->save(['slug'=>'rss-search','title'=>'RSS-ONLY-SEARCH','template'=>'news','news_date'=>'2030-01-01','news_channels'=>['rss'],'body'=>'vollständig Ä🙂 literal.* RSS-ONLY']);$rss=$pages->publish($rss['id'],$rss['version']);
$old=$pages->get($ids[0]);$changed=$pages->save(array_replace($old,['slug'=>'pending-private-rename','title'=>'PRIVATE-PENDING-TITLE','body'=>'PRIVATE-PENDING-BODY']),$old['version']);
$sections=$pages->save(['slug'=>'section-search','title'=>'Sichtbarer Abschnitt','content_sections'=>[['id'=>str_repeat('a',32),'heading'=>'Öffentlicher Abschnitt','body_html'=>'<p>Nur sichtbarer <strong>ABSCHNITTS-TREFFER</strong></p>']]]);$sections=$pages->publish($sections['id'],$sections['version']);
$tables=['khcms_pages','khcms_revisions','khcms_media','khcms_published_media','native_users','native_grants','native_delivery_jobs'];$originals=ownOriginalDatabaseHash($db,$tables);$all=[];
for($page=1;$page<=9;++$page){
    $r=$feeds->searchPage('vollständig ä🙂 literal.*',$page);searchCheck($r['total']===175&&$r['pages']===9&&$r['page']===$page&&count($r['rows'])===($page===9?15:20),'every public search page has the complete count and bounded exact originals '.$page);
    array_push($all,...array_column($r['rows'],'id'));
}
searchCheck($all===$ids,'equal publication timestamps have one stable original order without gaps or duplicates across the 100-row cursor');
$last=$feeds->searchPage('vollständig ä🙂 literal.*',999999);searchCheck($last['page']===9&&array_column($last['rows'],'id')===array_slice($ids,-15),'out-of-range page clamps to the actual last matching page without losing its originals');
searchCheck($feeds->searchPage('SELTENES-ALTES-ORIGINAL')['total']===1,'sparse result beyond a metadata batch remains reachable');
searchCheck($feeds->searchPage('ABSCHNITTS-TREFFER')['rows'][0]['id']===$sections['id'],'original visible structured content participates in public search');
searchCheck($feeds->searchPage('PRIVATE')['total']===0&&$feeds->searchPage('FOREIGN')['total']===0&&$feeds->searchPage('RSS-ONLY')['total']===0,'private drafts, private edits, other clubs and RSS-only originals stay excluded');
searchCheck($feeds->searchPage('vollständig Ä🙂 literal.+')['total']===0,'query metacharacters retain their literal meaning');
searchCheck($feeds->searchPage('   ',2)['rows']===[]&&$feeds->searchPage('   ',2)['page']===1,'empty query never lists the public corpus');
searchCheck(count($feeds->search('vollständig Ä🙂 literal.*'))===50,'existing first-50 service contract stays compatible with bounded original reading');
foreach([0,-1,'0','1.5','1e2','01',[],1000001]as$i=>$invalid)searchReject(fn()=>$feeds->searchPage('original',$invalid),'invalid public search page rejected '.$i);
foreach([str_repeat('x',121),"bad\nquery","\xff"]as$i=>$invalid)searchReject(fn()=>$feeds->searchPage($invalid),'invalid UTF-8/control/overlong public query rejected '.$i);
$view=PublicSearchView::render($feeds->searchPage('vollständig ä🙂 literal.*',2));$doc=new DOMDocument();@$doc->loadHTML('<?xml encoding="UTF-8">'.$view);$xp=new DOMXPath($doc);
searchCheck($xp->query('//article[@data-search-result]')->length===20&&$xp->query('//nav[@aria-label="Weitere Suchergebnisse"]//a[@aria-current="page"]')->item(0)->textContent==='2','bounded native search view identifies current page');
searchCheck($xp->query('//article//script')->length===0&&str_contains($view,'&lt;Text&gt;'),'published markup stays inert in exact search titles');
foreach($xp->query('//nav//a')as$a){parse_str(parse_url($a->getAttribute('href'),PHP_URL_QUERY),$args);searchCheck($args['q']==='vollständig ä🙂 literal.*'&&ctype_digit($args['seite']),'native pagination preserves exact Unicode query '.$a->textContent);}
searchCheck($originals===ownOriginalDatabaseHash($db,$tables),'all counted search pages and rendering retain every original, account, media and job byte');
$bytes=(int)Db::run($db,'SELECT SUM(OCTET_LENGTH(r.payload_json)) FROM khcms_pages p JOIN khcms_revisions r ON r.page_id=p.id AND r.club_id=p.club_id AND r.revision=p.published_revision WHERE p.club_id=?',[$nativeClub])->fetchColumn();
$process=proc_open([...TestSupport::phpCommand(),'-d','memory_limit=16M',__FILE__,'probe'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);$proof=json_decode($out,true);
searchCheck($bytes>16*1024*1024&&$status===0&&$err===''&&($proof['memory']??PHP_INT_MAX)<16*1024*1024,'actual 16-MB process searches more than 16 MB of immutable public payloads one original at a time');
$process=proc_open([...TestSupport::phpCommand(),'-d','memory_limit=16M',__FILE__,'probe-locale'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);$proof=json_decode($out,true);
searchCheck($bytes>16*1024*1024&&$status===0&&$err===''&&($proof['memory']??PHP_INT_MAX)<16*1024*1024,'actual localized 16-MB process projects and searches more than 16 MB without retaining a corpus of original payloads');
// Revoke on the second metadata batch: no partially authorized result may escape.
$revoker=new class($db)extends Access{private int $calls=0;public function __construct(private PDO $connection){parent::__construct($connection,'reader');}public function require(string $m,string $c):array{if(++$this->calls===3)Db::run($this->connection,"DELETE FROM native_grants WHERE actor_id='reader' AND module='website' AND capability='read'");return parent::require($m,$c);}};
searchReject(fn()=>(new PublicSearch($db,$revoker))->page('vollständig'),'role revocation between metadata batches fails before returning any original');
Db::run($db,"INSERT INTO native_grants VALUES ('reader','website','read')");
$publisher=new class($db,$pages,$changed)extends Access{private int $calls=0;public function __construct(PDO $db,private Pages $pages,private array $changed){parent::__construct($db,'reader');}public function require(string $m,string $c):array{if(++$this->calls===3)$this->pages->publish($this->changed['id'],$this->changed['version']);return parent::require($m,$c);}};
$consistent=(new PublicSearch($db,$publisher))->page('vollständig',999999);searchCheck($consistent['total']===174&&$consistent['page']===9&&count($consistent['rows'])===14&&!in_array($changed['id'],array_column($consistent['rows'],'id'),true),'publication during traversal restarts with one current generation and exact matching count');
$pages->unpublish($sections['id'],$sections['version']);searchCheck($feeds->searchPage('ABSCHNITTS-TREFFER')['total']===0,'withdrawal removes an original from search immediately');
Db::run($db,"UPDATE native_users SET active=0 WHERE id='reader'");searchReject(fn()=>(new PublicSearch($db,new Access($db,'reader')))->page(''),'even an empty public search requires the current readable identity');
echo $n." public search checks passed; immutable native originals, actual 16-MB process, no external transport.\n";
