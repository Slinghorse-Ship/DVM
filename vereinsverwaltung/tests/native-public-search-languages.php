<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Pages,Schema,DataModel};
use Kulturhus\Native\Language\{Schema as LanguageSchema,Settings,Translations};
use Kulturhus\Native\Web\{Feeds,PublicSearchView};
$db=TestSupport::db();TestSupport::seed($db);Schema::install($db);LanguageSchema::install($db);
$admin=new Access($db,'admin');$reader=new Access($db,'reader');$pages=new Pages($db,$admin);$publicPages=new Pages($db,$reader);$feeds=new Feeds($publicPages,'https://example.invalid');$settings=new Settings($db,$admin);$translations=new Translations($db,$admin);$model=new DataModel($db,$admin);$checks=0;
function check(bool $ok,string $label):void{global$checks;if(!$ok)throw new RuntimeException($label);++$checks;echo "PASS $label\n";}
function reject(callable $operation,string $label):void{try{$operation();}catch(InvalidArgumentException|DomainException){check(true,$label);return;}throw new RuntimeException($label);}
function publish(array $input):array{global$pages;$page=$pages->save($input);return $pages->publish($page['id'],$page['version']);}
function translate(array $page,string $locale,string $slug,array $fields):array{global$translations;$saved=$translations->save($page['id'],$locale,['slug'=>$slug,'fields'=>$fields],$page['published_revision'],0);return $translations->publish($page['id'],$locale,$saved['version']);}
function originals(PDO $db):string{$all=[];foreach(['khcms_pages','khcms_revisions','native_language_pages','native_language_revisions','khcms_media','native_users','native_grants']as$table)$all[$table]=$db->query('SELECT * FROM '.$table.' ORDER BY 1,2')->fetchAll();return hash('sha256',json_encode($all,JSON_THROW_ON_ERROR));}
$config=['source'=>'de','languages'=>[['code'=>'de','label'=>'Deutsch','enabled'=>true,'fallback'=>null,'messages'=>[]],['code'=>'en','label'=>'English','enabled'=>true,'fallback'=>'de','messages'=>[]],['code'=>'fr','label'=>'Français','enabled'=>true,'fallback'=>'en','messages'=>[]]]];$state=$settings->save($config,0);
$sources=[];$english=[];
for($index=0;$index<43;++$index){
    $source=publish(['slug'=>'suchoriginal-'.$index,'title'=>'Deutsches Original '.$index,'body_html'=>'<p>Nur deutsch sichtbarer Inhalt '.$index.'</p>','nav_visible'=>$index===0]);$sources[]=$source;
    $english[]=translate($source,'en','search-original-'.$index,['title'=>'Literal Ä🙂 .* match '.$index,'excerpt'=>'Confirmed English summary '.$index,'body_html'=>'<p>Public translated body '.$index.'</p>']);
}
$private=$pages->save(['slug'=>'privater-suchentwurf','title'=>'UNPUBLISHED-SOURCE-ONLY']);
$draft=$pages->save(array_replace($sources[0],['title'=>'SOURCE-DRAFT-ONLY','body_html'=>'<p>PRIVATE-SOURCE-REWRITE</p>']),$sources[0]['version']);
$translations->save($sources[0]['id'],'en',['slug'=>'private-translation-path','fields'=>['title'=>'TRANSLATION-DRAFT-ONLY','body_html'=>'<p>PRIVATE-TRANSLATION-REWRITE</p>']],$sources[0]['published_revision'],$english[0]['version']);
$fallback=publish(['slug'=>'suchrueckfall','title'=>'Original fallback Ä🙂','body'=>'Original fallback searchable']);
$model->saveField(['key'=>'search_text','label'=>'Search text','type'=>'text']);
$model->saveField(['key'=>'search_secret','label'=>'Secret','type'=>'password']);
$model->saveField(['key'=>'search_items','label'=>'Search items','type'=>'repeater','fields'=>['search_text']]);
$model->saveTemplate(['key'=>'search_fields','label'=>'Search typed fields','fields'=>['search_items','search_secret']]);
$typed=publish(['slug'=>'suchfelder','title'=>'Typed public source','template'=>'search_fields','custom_fields'=>['search_items'=>[['version'=>0,'public'=>true,'editors'=>[],'values'=>['search_text'=>'VISIBLE-REPEATER-TEXT']],['version'=>0,'public'=>false,'editors'=>['admin'],'values'=>['search_text'=>'PRIVATE-REPEATER-TEXT']]],'search_secret'=>'PASSWORD-ONLY-TEXT']]);
$section=publish(['slug'=>'suchabschnitt','title'=>'Section text original','content_sections'=>[['id'=>str_repeat('a',32),'heading'=>'ORIGINAL-SECTION-ONLY','body_html'=>'<p>Original section content</p>']]]);
translate($section,'en','search-section',['content_sections'=>[str_repeat('a',32)=>['heading'=>'TRANSLATED-SECTION-ONLY','body_html'=>'<p>Translated section content</p>']]]);
$home=publish(['slug'=>'start','title'=>'Home text original','home_layout'=>array_replace(\Kulturhus\Native\Cms\HomeLayout::defaults(),['hero_title'=>'ORIGINAL-HERO-ONLY'])]);translate($home,'en','home',['home_layout'=>['hero_title'=>'TRANSLATED-HERO-ONLY']]);
$event=publish(['slug'=>'suchveranstaltung','title'=>'Event text original','template'=>'event','event_start'=>'2050-01-12T20:00','event_location'=>'ORIGINAL-LOCATION-ONLY','event_address'=>'Original address']);translate($event,'en','search-event',['event_location'=>'TRANSLATED-LOCATION-ONLY','event_address'=>'Translated address']);
$before=originals($db);$all=[];
foreach([1,2,3]as$number){$list=$feeds->searchPage('literal ä🙂 .* match',$number,'en');check($list['total']===43&&$list['pages']===3&&$list['page']===$number&&count($list['rows'])===($number===3?3:20),'localized count and bounded page are exact '.$number);array_push($all,...array_column($list['rows'],'id'));foreach($list['rows']as$row)if(!str_starts_with($row['slug'],'en/search-original-'))throw new RuntimeException('Invalid translated result address.');}
check(count(array_unique($all))===43&&array_diff(array_column($sources,'id'),$all)===[],'all current translated originals remain reachable exactly once');
$last=$feeds->searchPage('literal ä🙂 .* match',999999,'en');check($last['page']===3&&count($last['rows'])===3,'localized out-of-range page returns the complete final page');
check($feeds->searchPage('literal ä🙂 .+ match',1,'en')['total']===0,'localized regular-expression punctuation remains literal');
check($feeds->searchPage('Deutsches Original',1,'en')['total']===0&&$feeds->searchPage('Deutsches Original',1,'de')['total']===43,'only the projected public text participates in each chosen language');
$french=$feeds->searchPage('literal ä🙂 .* match',1,'fr');check($french['total']===43&&str_starts_with($french['rows'][0]['slug'],'fr/'),'configured secondary fallback projects English text under a real French fallback route');
$fallbackResult=$feeds->searchPage('Original fallback searchable',1,'en');check($fallbackResult['total']===1&&$fallbackResult['rows'][0]['slug']==='en/suchrueckfall','missing translation preserves the original fallback text and selected locale URL');
foreach(['SOURCE-DRAFT-ONLY','TRANSLATION-DRAFT-ONLY','UNPUBLISHED-SOURCE-ONLY','PRIVATE-SOURCE-REWRITE','PRIVATE-TRANSLATION-REWRITE','PRIVATE-REPEATER-TEXT','PASSWORD-ONLY-TEXT']as$secret)check($feeds->searchPage($secret,1,'en')['total']===0,'search cannot expose '.$secret);
check($feeds->searchPage('VISIBLE-REPEATER-TEXT',1,'en')['rows'][0]['id']===$typed['id'],'authorized visible nested text remains searchable in the original fallback');
check($feeds->searchPage('TRANSLATED-SECTION-ONLY',1,'en')['rows'][0]['id']===$section['id']&&$feeds->searchPage('ORIGINAL-SECTION-ONLY',1,'en')['total']===0,'structured search uses actual projected section text instead of stale original derived text');
check($feeds->searchPage('TRANSLATED-HERO-ONLY',1,'en')['rows'][0]['id']===$home['id']&&$feeds->searchPage('ORIGINAL-HERO-ONLY',1,'en')['total']===0,'configured translated home text is searchable without its replaced source text');
check($feeds->searchPage('TRANSLATED-LOCATION-ONLY',1,'en')['rows'][0]['id']===$event['id']&&$feeds->searchPage('ORIGINAL-LOCATION-ONLY',1,'en')['total']===0,'generic public search includes actual translated event locations and addresses');
$list=$feeds->searchPage('literal ä🙂 .* match',2,'en');$html=PublicSearchView::layout($list,$db,$reader,$publicPages,'https://example.invalid','/?view=website',['name'=>'Synthetic search club']);$dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($dom);
check($xp->query('//html[@lang="en"]')->length===1&&$xp->query('//form//input[@name="lang" and @value="en"]')->length===1,'real public search layout carries its explicit locale and a native GET form');
foreach($xp->query('//nav[@data-search-pagination]//a')as$link){parse_str(parse_url($link->getAttribute('href'),PHP_URL_QUERY),$query);check(($query['lang']??null)==='en'&&$query['q']==='literal ä🙂 .* match','every real search pager preserves literal Unicode and locale');}
$canonical=$xp->query('//link[@rel="canonical"]')->item(0)->getAttribute('href');parse_str(parse_url($canonical,PHP_URL_QUERY),$query);check(parse_url($canonical,PHP_URL_PATH)==='/search'&&$query['lang']==='en'&&$query['q']===$list['query'],'canonical points to the actual routed search rather than an invented content page');
check($xp->query('//a[@href="/search?lang=en"]')->length===1,'footer search link retains the selected language');
check($xp->query('//a[@hreflang="fr"]')->length===1&&str_starts_with($xp->query('//a[@hreflang="fr"]')->item(0)->getAttribute('href'),'/search?lang=fr&'),'language switch remains on the actual search route');
check(!str_contains($html,'/site/en/search"')&&!str_contains($html,'/site/en/search?')&&!str_contains($html,'PRIVATE-'),'search chrome and results contain no fake translated search route or private draft');
check(originals($db)===$before,'all localized traversal, matching, rendering and links preserve original revisions, grants, translations and media');

// Exercise the real standalone entry point, its fixed reader and actual HTTP validators.
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('Own loopback socket unavailable.');$address=stream_socket_get_name($socket,false);fclose($socket);$port=substr($address,strrpos($address,':')+1);$origin='http://'.$address;$log=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/public-search-language-http.log';
$command=['env','KH_NATIVE_LOCAL_DEMO=synthetic','KH_NATIVE_DEMO_PORT='.$port,...TestSupport::phpCommand(),'-S',$address,'-t',dirname(__DIR__).'/native/Web',dirname(__DIR__).'/native/Web/router.php'];
$process=proc_open($command,[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes);if(!is_resource($process))throw new RuntimeException('Own standalone search server unavailable.');fclose($pipes[0]);
$request=static function(string $path,string $method='GET',array $headers=[])use($origin):array{$context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'ignore_errors'=>true,'timeout'=>30,'follow_location'=>0]]);$body=@file_get_contents($origin.$path,false,$context);$raw=$http_response_header??[];preg_match('~\AHTTP/\S+ (\d{3})~',$raw[0]??'',$match);$parsed=[];foreach(array_slice($raw,1)as$line)if(str_contains($line,':')){[$name,$value]=explode(':',$line,2);$parsed[strtolower($name)]=trim($value);}return ['status'=>(int)($match[1]??0),'headers'=>$parsed,'body'=>$body===false?'':$body];};
try{
    for($attempt=0;$attempt<40;++$attempt){$ready=$request('/search');if($ready['status']!==0)break;usleep(50000);}
    $path='/search?'.http_build_query(['lang'=>'en','q'=>'literal ä🙂 .* match','seite'=>2],'','&',PHP_QUERY_RFC3986);$response=$request($path);
    $responseDom=new DOMDocument();@$responseDom->loadHTML('<?xml encoding="UTF-8">'.$response['body']);$responseXp=new DOMXPath($responseDom);
    check($response['status']===200&&$responseXp->query('//article[@data-search-result]')->length===20&&$responseXp->query('//html[@lang="en"]')->length===1,'actual standalone router returns the exact localized bounded page');
    $etag=$response['headers']['etag']??'';check($etag==='"'.hash('sha256',$response['body']).'"'&&(int)($response['headers']['content-length']??-1)===strlen($response['body']),'actual localized response validators describe exact Unicode bytes');
    $head=$request($path,'HEAD');check($head['status']===200&&$head['body']===''&&($head['headers']['etag']??null)===$etag,'actual localized HEAD retains exact validator and no body');
    $conditional=$request($path,'GET',['If-None-Match: W/'.$etag]);check($conditional['status']===304&&$conditional['body']==='','actual localized conditional GET matches its confirmed representation');
    $secret=$request('/search?lang=en&q=TRANSLATION-DRAFT-ONLY');check($secret['status']===200&&!str_contains($secret['body'],'data-search-result='),'actual standalone route excludes a newer private translation draft');
    foreach(['/search?lang[]=en','/search?lang=zz','/search?lang=en%2Fx','/search?lang=en&q[]=bad','/search?lang=en&seite[]=2']as$path)check($request($path)['status']===422,'real standalone route rejects malformed or unavailable selection '.$path);
    check($request('/search?lang=en','POST')['status']===405,'actual public search cannot perform a write');
    check(originals($db)===$before,'actual standalone reads, validators and refused writes preserve every original byte');
}finally{proc_terminate($process);proc_close($process);}

reject(fn()=>$feeds->searchPage('text',1,'zz'),'unconfigured locale is rejected');reject(fn()=>$feeds->searchPage('text',1,'en/x'),'invalid locale is rejected');
$hidden=$model->saveTemplate(['key'=>'hidden_search','label'=>'Public search restriction','renderer'=>'standard','fields'=>[],'policy'=>['public'=>false]]);$hiddenPage=publish(['slug'=>'hidden-search-original','title'=>'HIDDEN-POLICY-ORIGINAL','template'=>'hidden_search']);translate($hiddenPage,'en','hidden-search-translated',['title'=>'HIDDEN-POLICY-TRANSLATION']);
check($feeds->searchPage('HIDDEN-POLICY',1,'en')['total']===0&&$feeds->searchPage('HIDDEN-POLICY',1,'de')['total']===0,'current template public restrictions apply before localized count and result selection');
$child=publish(['slug'=>'hidden-search-child','title'=>'HIDDEN-INHERITED-ORIGINAL','parent_id'=>$hiddenPage['id']]);check($feeds->searchPage('HIDDEN-INHERITED',1,'en')['total']===0,'public search also excludes inherited private originals');
$source=$pages->publish($draft['id'],$draft['version']);check($feeds->searchPage('literal ä🙂 .* match',1,'en')['total']===42&&$feeds->searchPage('SOURCE-DRAFT-ONLY',1,'en')['total']===1,'new original publication invalidates stale translation and exposes only its actual source fallback');
$fresh=$pages->get($sources[1]['id']);$pages->unpublish($fresh['id'],$fresh['version']);check($feeds->searchPage('literal ä🙂 .* match',1,'en')['total']===41,'withdrawing the original immediately removes its translated search result');
$config['languages'][2]['enabled']=false;$config['languages'][1]['enabled']=false;$settings->save($config,$state['version']);reject(fn()=>$feeds->searchPage('literal',1,'en'),'disabled language fails immediately after previously successful searches');reject(fn()=>$feeds->searchPage('',1,'en'),'empty search also respects current locale availability');
echo json_encode(['checks'=>$checks,'live_transport'=>false],JSON_THROW_ON_ERROR)."\n";
