<?php declare(strict_types=1);
require_once __DIR__.'/fixtures/php-runtime.php';
ob_start();
require __DIR__.'/../src/bootstrap.php';
require_once __DIR__.'/mysql-bootstrap.php';
use Kulturhus\Verein\{Database,TaxFilings,TaxFilingScope,TaxFormSchema,TaxEricAdapter};
$checks=0;function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect();Database::migrate($db);Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic A'),('b','Synthetic B');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('finance','a','treasurer',0),('board','a','treasurer',1),('member','a','member',0),('editor','a','editor',0),('other','b','treasurer',0)");
$dir=sys_get_temp_dir().'/kh-eric-fixture-'.bin2hex(random_bytes(8));mkdir($dir,0700);$runtime=$dir.'/runtime.json';$catalogFile=$dir.'/catalog.json';$old=getenv('VEREIN_ERIC_RUNTIME');
register_shutdown_function(static function()use($dir,$old):void{foreach(['runtime.json','catalog.json','feedback-calls.txt'] as $name)if(is_file($dir.'/'.$name))unlink($dir.'/'.$name);if(is_dir($dir))rmdir($dir);putenv($old===false?'VEREIN_ERIC_RUNTIME':'VEREIN_ERIC_RUNTIME='.$old);});
$node=static fn($name,$type,$max=1)=>['name'=>$name,'type'=>$type,'min'=>0,'max'=>$max,'label'=>$name];
$p=['family'=>'kst','year'=>2025,'label'=>'Synthetic form','data_art'=>'KSt','verfahren'=>'ElsterErklaerung','vorgang'=>'send-Auth','test_marker'=>'700000004','payload_tag'=>'{urn:synthetic}Form','namespace'=>'urn:synthetic','root_name'=>'Form','root_type'=>'Root','schema_sha256'=>str_repeat('a',64),'pdf_supported'=>true,'transport_enabled'=>true,'types'=>['Root'=>['simple'=>false,'children'=>[$node('Main','Main')]],'Main'=>['simple'=>false,'children'=>[$node('Name','text'),$node('Item','Item',3)]],'Item'=>['simple'=>false,'children'=>[$node('Amount','decimal')]],'text'=>['simple'=>true,'base'=>'string','maxLength'=>100],'decimal'=>['simple'=>true,'base'=>'decimal','maxLength'=>10]]];
file_put_contents($catalogFile,json_encode(['version'=>1,'profiles'=>['TEST_2025'=>$p]],JSON_THROW_ON_ERROR));chmod($catalogFile,0600);
$cfg=['version'=>1,'manufacturer_id'=>'12345','library'=>__FILE__,'scratch'=>$dir,'catalog'=>$catalogFile,'command'=>taxFixturePhpCommand(),'environment'=>taxFixturePhpEnvironment(),'profiles'=>['TEST_2025'=>$p],'production_enabled'=>true,'production_versions'=>['TEST_2025'],'test_fixture_only'=>true];file_put_contents($runtime,json_encode($cfg,JSON_THROW_ON_ERROR));chmod($runtime,0600);putenv('VEREIN_ERIC_RUNTIME='.$runtime);
$f=new TaxFilings($db,'finance');$b=new TaxFilings($db,'board');$key=TaxFormSchema::key('Main/Name');$head=['office'=>'1234','supplier'=>'Synthetic Club, Example Road'];
check(TaxEricAdapter::status()['available'],'private runtime found');check(!str_contains(json_encode(TaxEricAdapter::status()),'12345'),'public status excludes manufacturer');
denied(fn()=>$f->create('TEST_2025','test',2026),'year mismatch denied');denied(fn()=>(new TaxFilings($db,'member'))->create('TEST_2025','test',2025),'member cannot create');denied(fn()=>(new TaxFilings($db,'editor'))->profile(2025),'editor cannot view tax data');
$id=$f->create('TEST_2025','test',2025);check($f->get($id)['form_data']['fields']===[],'new test form never copies real values');
denied(fn()=>(new TaxFilings($db,'other'))->get($id),'cross-club read denied');denied(fn()=>(new TaxFilings($db,'other'))->save($id,1,[],$head),'cross-club write denied');
denied(fn()=>(new TaxFilings($db,'other'))->attempts($id),'cross-club attempt metadata denied');
denied(fn()=>(new TaxFilings($db,'member'))->attempts($id),'member cannot read attempt metadata');
denied(fn()=>$f->attempts('missing-synthetic-id'),'missing attempt parent denied');
$f->save($id,1,[$key=>'Synthetic'],$head);denied(fn()=>$f->save($id,1,[$key=>'Overwrite'],$head),'stale save rejected');
denied(fn()=>$f->save($id,2,['bad'=>'x'],$head),'unknown form field rejected');
$f->save($id,2,[],[],TaxFormSchema::key('Main/Item'));check($f->get($id)['form_data']['counts'][TaxFormSchema::key('Main/Item')]===2,'repeat row persists');
$v=$f->validate($id,3);check($v['valid']&&$f->get($id)['state']==='validated','native boundary validation saved with PDF');
$xml=$f->download($id,'xml')['bytes'];check(str_contains($xml,'700000004')&&str_contains($xml,'Synthetic'),'test XML marker and values');
check(str_starts_with($f->download($id,'preview')['bytes'],'%PDF-'),'exact preview download');denied(fn()=>(new TaxFilings($db,'member'))->download($id,'preview'),'member cannot download');
$f->save($id,4,[$key=>'Changed'],[]);$r=$f->get($id);check($r['state']==='draft'&&$r['pdf']===null&&$r['xml_data']===null,'editing invalidates preview and XML');
denied(fn()=>$f->send($id,5,'dummy','123456','SEND-TEST',true,true),'unvalidated send blocked');$f->validate($id,5);
denied(fn()=>$f->send($id,6,'dummy','123456','SEND-PRODUCTION',true,true),'wrong environment confirmation denied');denied(fn()=>$f->send($id,6,'dummy','123456','SEND-TEST',false,true),'review required');denied(fn()=>$f->send($id,6,'dummy','123456','SEND-TEST',true,false),'synthetic test confirmation required');
$sent=$f->send($id,6,'dummy','123456','SEND-TEST',true,true);check($sent['state']==='sent_test'&&$f->get($id)['state']==='sent_test','test receipt is not a production filing');
denied(fn()=>$f->send($id,6,'dummy','123456','SEND-TEST',true,true),'double send rejected');denied(fn()=>$f->save($id,8,[$key=>'edit'],[]),'sent form immutable');check(count($f->attempts($id))===1,'one attempt only');
check($f->download($id,'receipt')['mime']==='application/pdf','sent protocol stored');
$copy=$f->create('TEST_2025','test',2025);$f->save($copy,1,[$key=>'Changed'],$head);$f->validate($copy,2);denied(fn()=>$f->send($copy,3,'dummy','123456','SEND-TEST',true,true),'new draft ID cannot bypass content duplicate protection');
$crash=$f->create('TEST_2025','test',2025);$f->save($crash,1,[$key=>'Uncertain transmission'],$head);$f->validate($crash,2);check($f->send($crash,3,'crash-after-claim','123456','SEND-TEST',true,true)['state']==='unknown','crashed sender becomes unknown');denied(fn()=>$f->send($crash,5,'dummy','123456','SEND-TEST',true,true),'unknown result cannot be retried');
$pinId=$f->create('TEST_2025','test',2025);$f->save($pinId,1,[$key=>'PIN correction test'],$head);$f->validate($pinId,2);check($f->send($pinId,3,'wrong-pin','123456','SEND-TEST',true,true)['state']==='failed','confirmed pre-transport rejection is not uncertain');$f->validate($pinId,5);check($f->send($pinId,6,'dummy','123456','SEND-TEST',true,true)['state']==='sent_test','explicitly revalidated attempt after pre-transport error can succeed');check(count($f->attempts($pinId))===2,'both attempts kept after PIN correction');
$id2=$f->create('TEST_2025','test',2025);$f->save($id2,1,[$key=>'INVALID'],$head);check(!$f->validate($id2,2)['valid']&&$f->get($id2)['state']==='draft','invalid form cannot become validated');
$f->save($id2,3,[$key=>'Normal'],$head);$f->validate($id2,4);
// Crash after claim must never allow blind retry. Fixture crashes only on send by certificate sentinel.
$cfg['command'][]='fixture-crash';file_put_contents($runtime,json_encode($cfg));
denied(fn()=>$f->send($id2,5,'dummy','123456','SEND-TEST',true,true),'changed runtime invalidates approval');array_pop($cfg['command']);file_put_contents($runtime,json_encode($cfg));
$prod=$f->create('TEST_2025','production',2025);$f->save($prod,1,[$key=>'Synthetic production guard test'],$head);$f->validate($prod,2);
denied(fn()=>$f->send($prod,3,'dummy','123456','SEND-PRODUCTION',true,false),'treasurer cannot authorize live alone');denied(fn()=>$b->send($prod,3,'dummy','123456','SEND-PRODUCTION',true,false),'unresolved scope blocks even superuser');
$a=array_fill_keys(array_keys(TaxFilingScope::questions()),'no');$a['accounting']='euer';$a['vat']='small';$a['notices_checked']='yes';$a['kst_due']='yes';$f->saveProfile(2025,$a,0);denied(fn()=>$f->saveProfile(2025,$a,0),'stale scope save denied');
$b->save($prod,3,[],[]);$b->validate($prod,4);$a['kst_due']='no';$f->saveProfile(2025,$a,1);denied(fn()=>$b->send($prod,5,'dummy','123456','SEND-PRODUCTION',true,false),'profile changed after validation blocks live');
check((int)$db->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn()===Database::SCHEMA_VERSION,'additive migrations applied and replay safe');check(!\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db),'foreign keys consistent');
$restored=\Kulturhus\Verein\Tests\MySqlFixture::restoreBackup($db);
foreach(['tax_filing_profiles','tax_filings','tax_filing_attempts'] as $table)check(\Kulturhus\Verein\Tests\MySqlFixture::rows($db,$table)===\Kulturhus\Verein\Tests\MySqlFixture::rows($restored,$table),'MySQL restore preserves '.$table.' including JSON/XML/PDF');
$dump=json_encode($db->query('SELECT response FROM tax_filing_attempts')->fetchAll(),JSON_THROW_ON_ERROR);check(!str_contains($dump,'123456')&&!str_contains($dump,'certificate_b64'),'no certificate/PIN in attempt log');
function input(array $a,string $k,string $default=''):string{return is_string($a[$k]??null)?$a[$k]:$default;}
function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function hidden(string $k,mixed $v):string{return '<input type="hidden" name="'.e($k).'" value="'.e($v).'">';}
function csrf():string{return hidden('csrf','synthetic-csrf');}
function url(array $v):string{return '?'.http_build_query($v);}
$render=static function(array $get,string $actor,bool $review=false)use($db):string{
 $_GET=['view'=>'finance','tab'=>'tax','tax_filings'=>'1']+$get;$_POST=[];$_SESSION=['actor'=>$actor,'csrf'=>'synthetic-csrf'];$_SERVER['REQUEST_METHOD']='GET';
 if($review){$filing=(new TaxFilings($db,$actor))->get($get['filing']);$_GET['tax_year']=(string)$filing['tax_year'];$opened=\Kulturhus\Verein\Web\TaxFilingForm::open($db,$actor,(int)$filing['tax_year'],$filing['id'],$_SESSION);$_POST=$opened['fields']+['csrf'=>'synthetic-csrf','action'=>'tax-local-send-review'];$taxLocalState=\Kulturhus\Verein\Web\TaxFilingForm::handle($db,$actor,$_GET,$_POST,'synthetic-csrf',$_SESSION);$taxLocalHandled=true;$_SERVER['REQUEST_METHOD']='POST';}
 ob_start();require __DIR__.'/../views/Finance/Tax/tax-filings.php';return (string)ob_get_clean();
};
$html=$render(['tax_year'=>'2025','filing'=>$id],'finance');check(str_contains($html,'Testabgabe bestätigt')&&!str_contains($html,'name="certificate_pin"'),'sent view has receipt and no send form');
$html=$render(['tax_year'=>'2025','filing'=>$copy],'finance');check(str_contains($html,'Weiter zur ausdrücklichen Abgabefreigabe')&&!str_contains($html,'name="certificate_pin"'),'validated form first requires whole-form approval transition');
$html=$render(['tax_year'=>'2025','filing'=>$copy],'finance',true);check(str_contains($html,'Test jetzt senden')&&str_contains($html,'synthetic-csrf'),'validated view offers explicit protected test send');
$html=$render(['tax_year'=>'2025','filing'=>$prod],'finance',true);check(str_contains($html,'berechtigten Vorstand')&&!str_contains($html,'certificate_pin'),'treasurer view cannot show live submission controls');
$html=$render(['tax_year'=>'0'],'finance');check(str_contains($html,'role="alert"'),'bad year renders controlled error');
// Optional browser snapshots use the licensed private catalog, never real club records.
$setting=$db->prepare('INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES(?,?,?,1,?)');
$setting->execute(['a','club',json_encode(['name'=>'Synthetic Prefill Club','street'=>'Testway 1','postcode'=>'12345','city'=>'Testtown']),gmdate('c')]);
$setting->execute(['a','tax',json_encode(['number'=>'9198011310010','accounting_basis'=>'euer']),gmdate('c')]);
$emptyTest=$f->get($f->create('TEST_2025','test',2025));check($emptyTest['form_data']['fields']===[]&&$emptyTest['form_data']['header']===['office'=>'','supplier'=>''],'test drafts remain empty even with stored club data');
$approvedId=$b->create('TEST_2025','production',2025);$approved=$b->get($approvedId);check($approved['form_data']['header']['office']==='9198'&&str_contains($approved['form_data']['header']['supplier'],'Synthetic Prefill Club'),'new production draft reuses stored sender');
$b->save($approvedId,1,[$key=>'Authorized synthetic offline send'],[]);$b->validate($approvedId,2);
$html=$render(['tax_year'=>'2025','filing'=>$approvedId],'board',true);check(str_contains($html,'ELSTER-Zertifikat des Vereins')&&str_contains($html,'name="certificate_pin"')&&str_contains($html,'Das Passwort wird nicht gespeichert.'),'authorized submission view requests own certificate and password');
check($b->send($approvedId,3,'dummy','123456','SEND-PRODUCTION',true,false)['state']==='sent_production','authorized validated live-mode path succeeds in offline fixture only');
denied(fn()=>$b->send($approvedId,3,'dummy','123456','SEND-PRODUCTION',true,false),'authorized live-mode double send still denied');
$ui=getenv('VEREIN_ERIC_UI_OUTPUT');$official=getenv('VEREIN_ERIC_UI_CATALOG');
if($ui&&$official){
    if(!is_dir($ui)||!str_starts_with(realpath($ui),'/workspace/.local/'))throw new RuntimeException('Private UI output required');
    $realCatalog=json_decode((string)file_get_contents($official),true,64,JSON_THROW_ON_ERROR);$cfg['profiles']=$realCatalog['profiles'];file_put_contents($catalogFile,json_encode($realCatalog));file_put_contents($runtime,json_encode($cfg));
    $realId=$f->create('KSt_30_2025','test',2025);
    foreach(['overview'=>['tax_year'=>'2025'],'form'=>['tax_year'=>'2025','filing'=>$realId],'gem'=>['tax_year'=>'2025','filing'=>$realId,'section'=>'Gem']] as $name=>$route){
        $html=$render($route,'finance');$css=file_get_contents(__DIR__.'/../public/style.css').file_get_contents(__DIR__.'/../public/tax-filings.css');
        file_put_contents($ui.'/'.$name.'.html','<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ELSTER UI – Testdaten</title><style>'.$css.'</style><main style="margin:0;padding:24px">'.$html.'</main></html>');
    }
}
echo "$checks tax filing checks passed; fixture only, no network or actual tax submissions\n";
