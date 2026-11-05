<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\Cms\{Schema,Pages,WebsiteDesign,Conflict};
use Kulturhus\Native\Shared\{Access,Db,Identity};
use Kulturhus\Native\Web\{Manager,Site,Cache};
use Kulturhus\Native\Web\Website\DesignPreview;
use Kulturhus\Native\Integration\{EmbeddedManager,Routes};
use Kulturhus\Native\TestSupport;

$db=TestSupport::db();\Kulturhus\Native\Installer\SchemaRegistry::install($db);$seed=TestSupport::seed($db);
$club='design-'.bin2hex(random_bytes(8));
foreach(['admin','reader','editor','outsider'] as $role){
    $id=$club.'-'.$role;Db::run($db,'INSERT INTO native_users(id,club_id,name) VALUES(?,?,?)',[$id,$role==='outsider'?$club.'-other':$club,'Own design '.$role]);
    Db::run($db,'INSERT INTO native_grants SELECT ?,module,capability FROM native_grants WHERE actor_id=?',[$id,$role]);
}
$admin=new Access($db,$club.'-admin');$reader=new Access($db,$club.'-reader');$store=new WebsiteDesign($db,$admin);$pages=new Pages($db,$admin);
$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/design-test-'.bin2hex(random_bytes(6));mkdir($root,0700);mkdir($root.'/media',0700);
$n=0;function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);$n++;}
function rejects(Closure $action,string $label):void{try{$action();}catch(DomainException|InvalidArgumentException|Conflict){check(true,$label);return;}throw new RuntimeException($label);}
try{
    check(count(WebsiteDesign::templates())===5&&count(array_unique(array_column(WebsiteDesign::templates(),'class')))===5,'Five distinct available website templates');
    check($store->get()['template']==='kulturhus'&&$store->get()['version']===0,'Existing native website keeps Kulturhus until explicit selection');
    foreach(['../../processwire','sport','design-klar" onclick="bad',[],null] as $key)rejects(fn()=>$store->useTemplate($key,0),'Only registered template keys can be selected');
    rejects(fn()=>(new WebsiteDesign($db,$reader))->useTemplate('klar',0),'Read access cannot switch public appearance');
    rejects(fn()=>(new WebsiteDesign($db,new Access($db,$club.'-editor')))->useTemplate('klar',0),'Editorial access cannot change public appearance');
    $identity=new Identity($db,$admin);$identity->save(['name'=>'Own design club','origin'=>'https://design.example.invalid','address'=>'Own original address'],0);$originalIdentity=$identity->get();
    $preview=new DesignPreview($db,$reader,$root.'/media');
    $empty=$preview->render('klar');check(str_contains($empty,'Beispieltexte')&&str_contains($empty,'class="design-klar')&&str_contains($empty,'noindex,nofollow'),'Empty-site preview is private and writes no seed content');
    check((int)Db::run($db,'SELECT COUNT(*) FROM khcms_pages WHERE club_id=?',[$club])->fetchColumn()===0&&$store->get()['version']===0,'Preview has no content or configuration side effects');
    $page=$pages->save(['title'=>'Own published headline','slug'=>'start','body'=>'Exact content original','nav_visible'=>true]);$page=$pages->publish($page['id'],$page['version']);$public=$pages->publicPage('start');
    $draft=$pages->save(array_replace($page,['title'=>'Private headline never shown']),$page['version']);$originalRows=Db::run($db,'SELECT * FROM khcms_pages WHERE club_id=?',[$club])->fetchAll();$originalRevisions=Db::run($db,'SELECT * FROM khcms_revisions WHERE page_id=? ORDER BY revision',[$page['id']])->fetchAll();
    $media=new \Kulturhus\Native\Cms\Media($db,$reader,$root.'/media');
    $site=new Site($pages,$media,new Cache($root.'/cache'),'https://design.example.invalid',$club,null,null,'/?view=website',$originalIdentity,fn()=>$store->get()['template']);
    $before=$site->page('start');check(str_contains($before,'class="theme-kultur'),'Actual existing public site renders Kulturhus');
    foreach(WebsiteDesign::templates() as $key=>$template){
        $state=$store->get();$generation=$pages->generation();$history=count($store->history());
        $html=$preview->render($key);check(str_contains($html,'class="'.$template['class'])&&str_contains($html,'Own published headline')&&!str_contains($html,'Private headline never shown'),'Private preview uses selected layout and published originals: '.$key);
        check($store->get()===$state&&$pages->generation()===$generation&&count($store->history())===$history,'Preview is read-only: '.$key);
        $new=$store->useTemplate($key,$state['version']);
        check($new['template']===$key&&$new['version']===$state['version']+($state['template']===$key?0:1),'Version protected explicit selection: '.$key);
        $html=$site->page('start');check(str_contains($html,'class="'.$template['class'])&&str_contains($html,'Own published headline'),'Actual cached public output follows selected design: '.$key);
        check($pages->publicPage('start')===$public&&$identity->get()===$originalIdentity,'Selection preserves publication and shared identity bytes: '.$key);
    }
    rejects(fn()=>$store->useTemplate('klar',0),'Stale form cannot overwrite a later selection');
    rejects(fn()=>$store->useTemplate('klar',-1),'Invalid negative version rejected');
    $other=new WebsiteDesign($db,new Access($db,$club.'-outsider'));check($other->get()['version']===0,'Design stays isolated from other club');
    $manager=new Manager($db,$admin,$root.'/media','own-design-csrf');$panel=$manager->main('settings','');
    check(substr_count($panel,'data-design=')===5&&str_contains($panel,'aria-label="Klar als Website-Vorlage verwenden"'),'All templates have explicit named actions');
    $embedded=(new EmbeddedManager($manager,$admin))->main('settings');check(str_contains($embedded,'native_endpoint=design-preview')&&str_contains($embedded,'value="native-module"'),'Embedded preview links and submit forms use canonical manager routes');
    rejects(fn()=>$manager->action('settings',['csrf'=>'forged','operation'=>'website-design','version'=>'5','website_template'=>'klar'],[]),'Forged CSRF rejected');
    rejects(fn()=>$manager->action('settings',['csrf'=>'own-design-csrf','operation'=>'website-design','version'=>[],'website_template'=>'klar'],[]),'Non scalar version rejected');
    check(Routes::translate('/design-preview?template=natur')===Routes::endpoint('design-preview',['template'=>'natur']),'Preview route translation preserves selected template');
    check(Db::run($db,'SELECT * FROM khcms_pages WHERE club_id=?',[$club])->fetchAll()===$originalRows&&Db::run($db,'SELECT * FROM khcms_revisions WHERE page_id=? ORDER BY revision',[$page['id']])->fetchAll()===$originalRevisions,'All original draft and revision bytes preserved');
    echo json_encode(['suite'=>'native-website-design','passed'=>$n,'driver'=>'mysql'],JSON_THROW_ON_ERROR).PHP_EOL;
}finally{
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($root);
}
