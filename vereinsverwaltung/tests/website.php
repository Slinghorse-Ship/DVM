<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,WebsiteWorkspace,Registry,Finance,Documents,Inventory};
$tests=0;
function check(bool $ok,string $label):void{global $tests;++$tests;if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Test A'),('b','Test B'); INSERT INTO actors(id,club_id,role) VALUES('manager','a','members'),('editor','a','editor'),('other','b','editor'),('finance','a','treasurer'),('member','a','member')");
$editor=new WebsiteWorkspace($db,'editor');$manager=new WebsiteWorkspace($db,'manager');$other=new WebsiteWorkspace($db,'other');
check($editor->canRead()&&$manager->canRead(),'editor and club manager have website access');
check(!isset($editor->areas()['technical'])&&!isset($manager->areas()['technical']),'technical entry excluded from editorial and ordinary settings roles');
foreach(['finance','member','missing'] as $actor){$w=new WebsiteWorkspace($db,$actor);check(!$w->canRead(),$actor.' cannot enter website');denied(fn()=>$w->drafts('page'),$actor.' cannot enumerate drafts');}
$data=['kind'=>'news','page_key'=>'','title'=>'Synthetischer Artikel','summary'=>'Teaser','body'=>"Ein Text.\n<script>alert(1)</script>",'status'=>'draft'];
$id=$editor->save(null,0,$data);check($manager->draft($id)['body']===$data['body'],'local text survives unchanged');
check(count($editor->revisions($id))===1,'initial version stored');
$editor->save($id,1,array_replace($data,['title'=>'Überarbeitet','status'=>'review']));
check($editor->draft($id)['version']===2&&count($editor->revisions($id))===2,'revision and review state stored');
check(json_decode($editor->revisions($id)[1]['payload'],true)['title']===$data['title'],'earlier content preserved');
denied(fn()=>$editor->save($id,1,$data),'stale overwrite rejected');
denied(fn()=>$other->draft($id),'foreign draft read denied');denied(fn()=>$other->save($id,2,$data),'foreign draft write denied');
check(count($other->drafts('news'))===0,'draft tenant boundary');
foreach([['status'=>'published'],['title'=>''],['status'=>'review','body'=>''],['kind'=>'event'],['body'=>['bad']],['body'=>"\0"],['title'=>str_repeat('a',201)]] as $invalid)denied(fn()=>$editor->save($id,2,array_replace($data,$invalid)),'invalid input rejected');
check($editor->draft($id)['version']===2,'validation leaves current version unchanged');
$editor->save($id,2,array_replace($data,['status'=>'archived']));
check(count($editor->drafts('news'))===0&&count($editor->drafts('news','archived'))===1,'archive preserves content outside active list');
$editor->save($id,3,$data);check(count($editor->drafts('news'))===1,'archived article can return to editing');
$pageData=array_replace($data,['kind'=>'page','page_key'=>'home']);
$page=$editor->save(null,0,$pageData);check($editor->pageDraft('home')['id']===$page,'fixed page assignment');
denied(fn()=>$editor->save(null,0,$pageData),'second draft for same page denied');
denied(fn()=>$editor->save($page,1,array_replace($pageData,['page_key'=>'club'])),'page reassignment denied');
denied(fn()=>$editor->save(null,0,array_replace($pageData,['page_key'=>'../secret'])),'unknown page denied');
foreach([new Registry($db,'editor'),new Finance($db,'editor'),new Documents($db,'editor')] as $service)check(!$service->canRead(),'website identity gains no access to club records');
check(!(new Inventory($db,'editor'))->canReadLoans(),'website identity gains no loan access');
$db->exec("UPDATE actors SET active=0 WHERE id='editor'");denied(fn()=>$editor->save($id,4,$data),'revocation applies to existing service');
$db->exec("UPDATE actors SET active=1 WHERE id='editor'");
$db->exec("CREATE TRIGGER fail_website_audit BEFORE INSERT ON audit_events FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test'; END");
try{$editor->save($id,4,$data);throw new RuntimeException('Missing rollback');}catch(PDOException){}
check($editor->draft($id)['version']===4&&count($editor->revisions($id))===4,'audit failure rolls back content and revision');
check(\Kulturhus\Verein\Tests\MySqlFixture::integrity($db)==='ok','database integrity');
check(\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db)===[],'foreign keys valid');
$originalMode=getenv('VEREIN_MODE');putenv('VEREIN_MODE=synthetic');
try{
 check(WebsiteWorkspace::publicUrl()==='http://127.0.0.1:8092/','test website link stays local even before connection setup');
 foreach(['home','instagram','qr',...array_keys(WebsiteWorkspace::catalog())] as $area){
  check(str_starts_with(WebsiteWorkspace::destination($area),'http://127.0.0.1:8092/'),'local editorial destination '.$area);
 }
}finally{putenv($originalMode===false?'VEREIN_MODE':'VEREIN_MODE='.$originalMode);}
echo "$tests website checks passed\n";
