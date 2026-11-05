<?php declare(strict_types=1);
// Real MySQL, synthetic clubs; the process seam never downloads or submits anything.
require __DIR__.'/../src/bootstrap.php';
require __DIR__.'/../src/Catalog/CatalogImports.php';
require __DIR__.'/mysql-bootstrap.php';
require __DIR__.'/fixtures/partial-ui.php';
use Kulturhus\Verein\{CatalogImports,Database,SettingsSchema,TaxCatalog};
use Kulturhus\Verein\Tests\MySqlFixture;
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$n=0;function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException('Accepted: '.$label);}
$db=MySqlFixture::connect();Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic catalog club'),('b','Synthetic separate club');
 INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','treasurer',1),('other','b','treasurer',1),('writer','a','treasurer',0),('member','a','member',0),('editor','a','editor',1),('disabled','a','treasurer',1);
 UPDATE actors SET active=0 WHERE id='disabled'");
$tax=SettingsSchema::defaults('tax');$tax['accounting_basis']='euer';
$db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES('a','tax',?,1,?)")->execute([json_encode($tax),gmdate('c')]);
$financial=MySqlFixture::rows($db,'club_settings');$sourceHash=hash_file('sha256',__DIR__.'/../resources/ebilanz/skr42-6.9.json');
$calls=[];$override=null;$hook=null;$reviewer=null;$pdf="%PDF-1.7\nSynthetic catalog, no personal data\n";
$process=static function(array $request,array $files)use(&$calls,&$override,&$hook,&$reviewer,$pdf):array{
 $calls[]=$request;if($hook)$hook();if($override)return $override;
 if($request['operation']==='fetch')return ['result'=>['state'=>'downloaded','source'=>'https://download.datev-shop.de/synthetic.pdf','pdf_sha256'=>hash('sha256',$pdf),'appendix_pages'=>2],'files'=>['original.pdf'=>$pdf]];
 if($request['operation']==='compare'){
  check($files['original.pdf']===$pdf,'comparison receives exact stored PDF');
  return ['result'=>['state'=>'review_required','taxonomy_ready'=>true,'counts'=>['unchanged'=>20,'changed'=>1,'newly_bookable'=>2,'no_longer_bookable'=>3],'automatic'=>19,'review_groups'=>1,'existing_gaps'=>1],
   'files'=>['catalog.json'=>'{"year":2026}','comparison.json'=>'{"schema":1}','review.json'=>'{}','review.html'=>'<!doctype html><title>Synthetic comparison</title>']];
 }
 $reviewer=json_decode($files['submitted-review.json'],true)['reviewer'];
 $typed=json_decode($files['submitted-review.json']);check($typed->gap_decisions instanceof stdClass&&$typed->successors instanceof stdClass,'empty review objects retain JSON object type');
 return ['result'=>['state'=>'incomplete_candidate','production_authorized'=>false,'coverage'=>['complete'=>false]],'files'=>['package.zip'=>'PK-synthetic','submitted-review.json'=>$files['submitted-review.json']]];
};
$render=static function(string $who,string $run='')use($db):string{$_SESSION=['actor'=>$who];$_GET=['catalog_id'=>$run];$_POST=[];ob_start();try{require __DIR__.'/../views/Catalog/catalog-import.php';return ob_get_clean();}catch(Throwable $e){ob_end_clean();throw $e;}};
$service=new CatalogImports($db,'admin',$process);$id=str_repeat('a',32);
foreach(['writer','member','editor','disabled'] as $who){$s=new CatalogImports($db,$who,$process);check(!$s->canManage(),"$who cannot manage annual catalogs");denied(fn()=>$s->start($id,2025,2026),"$who cannot start via direct service");denied(fn()=>$s->listing(),"$who cannot read private review files");}
check($calls===[],'denied access starts no process');
foreach([[2025,2027],[2024,2025],[2026,2026]] as [$from,$year])denied(fn()=>$service->start($id,$from,$year),'only verified next year accepted');
denied(fn()=>$service->start('../x',2025,2026),'malformed request id rejected');
check($service->start($id,2025,2026)===$id,'start saves original');
$row=$service->get($id);check($row['version']===1&&$row['state']==='downloaded','initial state and version');
check($service->file($id,'original.pdf')['bytes']===$pdf,'download returns exact PDF');
$before=MySqlFixture::snapshot($db);$num=count($calls);$service->start($id,2025,2026);
check(MySqlFixture::snapshot($db)===$before&&count($calls)===$num,'repeated start is idempotent');
denied(fn()=>$service->start($id,2026,2027),'request cannot change year');
$other=new CatalogImports($db,'other',$process);denied(fn()=>$other->get($id),'other club cannot read run');denied(fn()=>$other->file($id,'original.pdf'),'other club cannot download original');check($other->listing()===[],'other club listing isolated');
foreach(['../original.pdf','runtime.php','package.zip'] as $file)denied(fn()=>$service->file($id,$file),'missing or unknown file blocked '.$file);
denied(fn()=>$service->advance($id,1,'approve',null,'{}'),'approval cannot skip comparison');
denied(fn()=>$service->advance($id,2,'compare',2),'stale version rejected before execution');
denied(fn()=>$service->advance($id,1,'compare',21),'appendix bounded');
$service->advance($id,1,'compare',2);$row=$service->get($id);
check($row['version']===2&&$row['state']==='review_required'&&$row['data']['appendix_pages']===2,'comparison stored with next version');
check($service->file($id,'original.pdf')['bytes']===$pdf,'earlier original remains available');
foreach(['[]','bad','null'] as $review)denied(fn()=>$service->advance($id,2,'approve',null,$review),'malformed review rejected '.$review);
$db->prepare('UPDATE catalog_imports SET payload=? WHERE id=?')->execute([json_encode(array_replace($row['data'],['taxonomy_ready'=>false])),$id]);
denied(fn()=>$service->advance($id,2,'approve',null,'{}'),'unknown target taxonomy cannot be approved');
$db->prepare('UPDATE catalog_imports SET payload=? WHERE id=?')->execute([$row['payload'],$id]);
$service->advance($id,2,'approve',null,'{"reviewer":"forged name","note":"Synthetic reviewed","gap_decisions":{},"successors":{}}');
check($reviewer==='admin','review author bound to authenticated account');
check($service->get($id)['state']==='incomplete_candidate'&&$service->get($id)['version']===3,'unmapped cases remain incomplete');
check($service->file($id,'package.zip')['bytes']==='PK-synthetic','candidate downloadable without activation');
denied(fn()=>$service->advance($id,2,'approve',null,'{}'),'approval cannot repeat from stale form');
$audit=$db->query("SELECT action,object_version FROM audit_events WHERE object_id='$id' ORDER BY object_version")->fetchAll();
check(array_column($audit,'action')===['settings.catalog.fetch','settings.catalog.compare','settings.catalog.approve'],'every accepted stage audited');
check(MySqlFixture::rows($db,'club_settings')===$financial&&TaxCatalog::YEARS===[2025,2026]&&hash_file('sha256',__DIR__.'/../resources/ebilanz/skr42-6.9.json')===$sourceHash,'EÜR, current catalogs and tax settings untouched');
$db->prepare("UPDATE catalog_import_files SET bytes='damaged' WHERE import_id=? AND name='original.pdf'")->execute([$id]);
denied(fn()=>$service->file($id,'original.pdf'),'corrupt stored file not served');
$db->prepare("UPDATE catalog_import_files SET bytes=? WHERE import_id=? AND name='original.pdf'")->execute([$pdf,$id]);
$newId=static fn()=>bin2hex(random_bytes(16));
foreach([
 ['result'=>['state'=>'downloaded'],'files'=>[]],
 ['result'=>['state'=>'review_required'],'files'=>[]],
 ['result'=>['state'=>'downloaded','production_authorized'=>true],'files'=>['original.pdf'=>$pdf]],
 ['result'=>['state'=>'downloaded','source'=>'https://datev.de.evil.invalid/x','pdf_sha256'=>hash('sha256',$pdf)],'files'=>['original.pdf'=>$pdf]],
 ['result'=>['state'=>'downloaded','source'=>'https://datev.de/x','pdf_sha256'=>str_repeat('0',64)],'files'=>['original.pdf'=>$pdf]],
 ['result'=>['state'=>'downloaded','source'=>'https://datev.de/x','pdf_sha256'=>hash('sha256',$pdf)],'files'=>['original.pdf'=>$pdf,'../../escape'=>'bad']],
] as $override){$before=MySqlFixture::snapshot($db);denied(fn()=>$service->start($newId(),2025,2026),'invalid process output rejected');check(MySqlFixture::snapshot($db)===$before,'invalid response leaves no partial database state');}
$override=['result'=>['state'=>'unavailable','available_year'=>2026],'files'=>[]];$future=$newId();$service->start($future,2026,2027);
check($service->get($future)['state']==='unavailable','unpublished year kept distinct');
$override=null;$service->advance($future,1,'fetch');check($service->get($future)['state']==='downloaded','unpublished year can be retried');
$override=['result'=>['state'=>'review_required','taxonomy_ready'=>true,'counts'=>['unchanged'=>20],'automatic'=>19,'review_groups'=>1,'existing_gaps'=>1,
 'filing_package'=>['ready'=>true,'taxonomy'=>'6.10','year'=>2027,'native_validation_ready'=>false,'stage'=>'catalog_preparation','production_authorized'=>false]],
 'files'=>['catalog.json'=>'{"year":2027}','comparison.json'=>'{"schema":1}','review.json'=>'{}','review.html'=>'<!doctype html><title>Synthetic 2027 preparation</title>']];
$service->advance($future,2,'compare',2);$futureHtml=$render('admin',$future);
foreach(['6.7','6.8','6.9','6.10'] as $taxonomy)check(str_contains($futureHtml,'>'.$taxonomy.'</a>'),'actual annual view includes official taxonomy '.$taxonomy);
check(str_contains($futureHtml,'Reguläres Beginnjahr')&&str_contains($futureHtml,'6.8 und die ausdrücklich optionale 6.9'),'actual view explains fiscal start year and 2025 options');
check(str_contains($futureHtml,'Taxonomie 6.10, Jahr 2027')&&str_contains($futureHtml,'Prüfung übernehmen'),'future catalog preparation has a distinct usable review flow');
check(str_contains($futureHtml,'amtlicher Prüfer')&&str_contains($futureHtml,'Eine Steuerabgabe bleibt gesperrt'),'future actual view states missing native acceptance');
$override=null;$service->advance($future,3,'approve',null,'{"note":"Synthetic preparation only","gap_decisions":{},"successors":{}}');
check($service->get($future)['state']==='incomplete_candidate'&&$service->get($future)['data']['filing_package']['native_validation_ready']===false,'future candidate retains pending native acceptance');
check(MySqlFixture::rows($db,'club_settings')===$financial,'future preparation preserves EÜR and existing club settings');
$hook=static function()use($db){$db->exec("UPDATE actors SET active=0 WHERE id='admin'");};
denied(fn()=>$service->start($newId(),2025,2026),'access revoked during process aborts save');$db->exec("UPDATE actors SET active=1 WHERE id='admin'");
$hook=static function()use($db){$db->exec("UPDATE actors SET club_id='b' WHERE id='admin'");};
denied(fn()=>$service->start($newId(),2025,2026),'club switch during process aborts save');$db->exec("UPDATE actors SET club_id='a' WHERE id='admin'");$hook=null;
$lock='catalog-import-'.substr(hash('sha256','a'),0,40);check($db->query("SELECT IS_FREE_LOCK('$lock')")->fetchColumn()===1,'advisory lock released after failure');
$before=MySqlFixture::snapshot($db);$html=$render('admin',$id);
check(str_contains($html,'Prüfpaket herunterladen')&&!str_contains($html,'name="catalog_review"'),'completed review only offers its package');
check(str_contains($html,'Vergleich aktualisieren'),'completed review can refresh after program update');
$history=$db->query("SELECT revision,name,sha256,bytes FROM catalog_import_files WHERE import_id='$id' ORDER BY revision,name")->fetchAll();
$fetches=count(array_filter($calls,static fn($r)=>$r['operation']==='fetch'));
$service->advance($id,3,'compare',2);$fresh=$service->get($id);
check($fresh['state']==='review_required'&&$fresh['version']===4&&!isset($fresh['data']['coverage']),'new comparison retires prior candidate and coverage');
check($db->query("SELECT revision,name,sha256,bytes FROM catalog_import_files WHERE import_id='$id' AND revision<=3 ORDER BY revision,name")->fetchAll()===$history,'refresh preserves all previous file revisions');
check(count(array_filter($calls,static fn($r)=>$r['operation']==='fetch'))===$fetches&&$service->file($id,'original.pdf')['bytes']===$pdf,'refresh uses stored original without a new download');
denied(fn()=>$service->file($id,'package.zip'),'old candidate is not offered as current package');
denied(fn()=>$service->advance($id,3,'approve',null,'{}'),'old review version cannot approve refreshed comparison');
$html=$render('admin',$id);check(str_contains($html,'Vergleich aktualisieren')&&str_contains($html,'name="catalog_review"')&&!str_contains($html,'Prüfpaket herunterladen'),'refreshed review requires new confirmation');
$service->advance($id,4,'compare',2);check($service->get($id)['version']===5,'pending review can also be refreshed');
$service->advance($id,5,'approve',null,'{"note":"Synthetic repeated review","gap_decisions":{},"successors":{}}');
check($service->get($id)['version']===6&&$service->file($id,'package.zip')['bytes']==='PK-synthetic','new candidate requires a separate new approval');
check(MySqlFixture::rows($db,'club_settings')===$financial,'refresh preserves EÜR and financial settings');
$waiting=$newId();$override=['result'=>['state'=>'unavailable','available_year'=>2026],'files'=>[]];$service->start($waiting,2026,2027);$override=null;
$waitingHtml=$render('admin',$waiting);check(str_contains($waitingHtml,'Erneut bei DATEV prüfen')&&!str_contains($waitingHtml,'Prüfung übernehmen'),'unpublished year shows retry not approval');
foreach(['writer','member','editor'] as $who){$html=$render($who);check(str_contains($html,'Zentrale Aktualisierung')&&!str_contains($html,'<form'),'noncentral role sees simple explanation only '.$who);}
$before=MySqlFixture::snapshot($db);$render('admin',$id);check(MySqlFixture::snapshot($db)===$before,'render is read-only');
Database::migrate($db);check(MySqlFixture::snapshot($db)===$before,'migration rerun preserves originals and records');
check(MySqlFixture::foreignKeyErrors($db)===[],'all import file relations valid');
if($output=getenv('VEREIN_CATALOG_UI_OUTPUT'))file_put_contents($output,$waitingHtml);
echo "$n annual import service / actual-view checks passed; no live data or submissions\n";
