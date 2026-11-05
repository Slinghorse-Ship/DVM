<?php declare(strict_types=1);
require_once __DIR__.'/fixtures/php-runtime.php';
require __DIR__.'/../src/bootstrap.php';
require_once __DIR__.'/mysql-bootstrap.php';
use Kulturhus\Verein\{Database,Settings,SettingsSchema,Finance,TaxAutoPreparation as A,TaxAutoMapping2025 as M,TaxFilingScope,TaxFormSchema,TaxFilings};
$d=require __DIR__.'/fixtures/tax-auto-data.php';$n=0;
function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect();Database::migrate($db);Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic A'),('b','Synthetic B'); INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','member',1),('finance','a','treasurer',0),('member','a','member',0),('reader','a','member',0),('other','b','treasurer',0)");
$settings=new Settings($db,'admin');$role=$settings->saveRole('',0,['name'=>'Read only','finance_access'=>'read','members_access'=>'none','settings_access'=>false]);$settings->assignRole('reader',$role,0);
$settings->save('club',array_replace(SettingsSchema::defaults('club'),$d['club']),0);
$settings->save('tax',array_replace(SettingsSchema::defaults('tax'),$d['tax']),0);
$f=new Finance($db,'finance');$bank=$f->createAccount('Synthetic bank','offline','50','2024-01-01');
$key=fn()=>bin2hex(random_bytes(16));
$add=function(string $amount,string $date='2025-01-02')use($f,$bank,$key):void{
    $id=$f->createEntry(['kind'=>'income','amount'=>$amount,'document_date'=>$date,'due_date'=>$date,'description'=>'Synthetische Einnahme','counterparty'=>'Synthetic','category'=>'general','positions'=>[['description'=>'Kulturbeitrag','amount'=>$amount,'sphere'=>'1','account'=>'40000','tax_rate'=>'0']]],$key());
    $f->recordPayment($id,(int)$f->entry($id)['version'],$bank,$date);
};$add('1000.49');
// The fixture adapter has no network. Official native validation is a separate opt-in test.
$dir=sys_get_temp_dir().'/kh-eric-fixture-'.bin2hex(random_bytes(8));mkdir($dir,0700);
$runtime=$dir.'/runtime.json';$catalog=$dir.'/catalog.json';
register_shutdown_function(static function()use($dir):void{foreach(['runtime.json','catalog.json'] as $name)if(is_file($dir.'/'.$name))unlink($dir.'/'.$name);if(is_dir($dir))rmdir($dir);});
$types=['Root'=>['simple'=>false,'children'=>[]],'Text'=>['simple'=>true,'base'=>'string','maxLength'=>180]];
$sample=M::build($d['snapshot'],$d['details'],TaxFilingScope::build(2025,$d['answers']));
foreach($sample['origins'] as $origin){$parts=explode('/',$origin['path']);$parent='Root';foreach($parts as $i=>$part){$repeat=str_ends_with($part,'[0]');$name=str_replace('[0]','',$part);$type=$i===count($parts)-1?'Text':$parent.'_'.$name;
    if(!isset($types[$type]))$types[$type]=['simple'=>false,'children'=>[]];
    if(!in_array($name,array_column($types[$parent]['children'],'name'),true))$types[$parent]['children'][]=['name'=>$name,'type'=>$type,'min'=>0,'max'=>$repeat?3:1,'label'=>$name];$parent=$type;}}
$profile=['family'=>'kst','year'=>2025,'label'=>'Synthetic KSt fixture','data_art'=>'KSt','verfahren'=>'ElsterErklaerung','vorgang'=>'send-Auth','test_marker'=>'700000004','payload_tag'=>'{urn:synthetic}Form','namespace'=>'urn:synthetic','root_name'=>'Form','root_type'=>'Root','schema_sha256'=>M::SCHEMA,'pdf_supported'=>false,'transport_enabled'=>true,'attachment_version'=>3,'types'=>$types];
// Optional locally installed licensed schema, never copied into source control.
if(getenv('VEREIN_AUTO_CATALOG'))$profile=json_decode((string)file_get_contents(getenv('VEREIN_AUTO_CATALOG')),true,64,JSON_THROW_ON_ERROR)['profiles'][M::VERSION];
file_put_contents($catalog,json_encode(['version'=>1,'profiles'=>[M::VERSION=>$profile]],JSON_THROW_ON_ERROR));chmod($catalog,0600);
$cfg=['version'=>1,'manufacturer_id'=>'12345','library'=>__FILE__,'scratch'=>$dir,'catalog'=>$catalog,'command'=>taxFixturePhpCommand(),'environment'=>taxFixturePhpEnvironment(),'profiles'=>[M::VERSION=>$profile],'production_enabled'=>false,'production_versions'=>[],'test_fixture_only'=>true];
file_put_contents($runtime,json_encode($cfg,JSON_THROW_ON_ERROR));chmod($runtime,0600);putenv('VEREIN_ERIC_RUNTIME='.$runtime);
$auto=new A($db,'finance');$filings=new TaxFilings($db,'finance');$input=$d['input'];
check($auto->get(2025)['input']['auto_answers']['tax_number']==='9198011310010','stored canonical tax number reused');
check(!$auto->preview(2025)['mapping']['ready'],'unknown confirmations block, not silently no');
denied(fn()=>(new A($db,'member'))->get(2025),'member cannot read tax preparation');
denied(fn()=>(new A($db,'reader'))->save(2025,0,$input,$d['answers'],0),'reader cannot save');
$auto->save(2025,0,$input,$d['answers'],0);$p=$auto->preview(2025);
check($p['mapping']['ready'],'stored synthetic source case ready: '.implode('; ',$p['mapping']['issues']));
check($p['hash']===$auto->preview(2025)['hash'],'read-only recomputation deterministic');
$package=$auto->savePackage(2025,1,$p['hash']);$saved=(new \Kulturhus\Verein\TaxPreparation($db,'finance'))->saved($package);
check($saved['snapshot']['year_notes'][2025]['funds_use_note']===$d['notes'][2025]['funds_use_note'],'automatic working package preserves entered annual notes');
check($saved['snapshot']['activity']===$input['activity_report'],'automatic working package retains activity supplement');
check($auto->preview(2025)['hash']===$p['hash'],'archiving a package does not change accounting source');
denied(fn()=>(new A($db,'reader'))->savePackage(2025,1,$p['hash']),'read-only user cannot archive new package');
check($p['bundle']['snapshot']['reports'][2025]['cash_cents']===105049,'opening bank balance included in closing cash');
check($p['mapping']['rounding'][2025]['cents']===100049,'opening balance excluded from income');
check((new A($db,'other'))->get(2025)['version']===0,'other club cannot see case');
check((new A($db,'reader'))->preview(2025)['mapping']['ready'],'read-only finance access supported');
denied(fn()=>$auto->save(2025,0,$input,$d['answers'],1),'stale draft version blocked');
check($filings->profile(2025)['version']===1,'conflict does not update global profile');
denied(fn()=>$auto->createFiling(2025,1,$p['hash'],'test',true),'ordinary runtime cannot copy source data into test');
putenv('VEREIN_TAX_AUTO_TEST=synthetic-only');
denied(fn()=>$auto->createFiling(2025,1,$p['hash'],'test',false),'synthetic confirmation required');
$id=$auto->createFiling(2025,1,$p['hash'],'test',true);$r=$filings->get($id);
check(count($r['form_data']['fields'])>20,'official fields populated, not empty draft');
check($r['state']==='draft'&&$filings->attempts($id)===[],'creation never sends');
check($auto->createFiling(2025,1,$p['hash'],'test',true)===$id,'double-click returns same draft');
$auto->assertCurrent($r);check(true,'bound source still current after linking');
denied(fn()=>$filings->save($id,2,[],[]),'manual overwriting bound fields denied');
check($filings->validate($id,2)['valid'],'source-linked draft passes offline validation adapter');
$changed=$r;$changed['form_data']['fields'][TaxFormSchema::key('Vorsatz/AbsName')]='Tampered';denied(fn()=>$auto->assertCurrent($changed),'tampered mapped payload blocked');
$add('0.01');denied(fn()=>$auto->assertCurrent($filings->get($id)),'one cent source change invalidates linked filing');
denied(fn()=>$filings->validate($id,3),'changed source blocks validation');
denied(fn()=>$filings->send($id,3,'dummy','dummy','SEND-TEST',true,true),'changed source blocks transmission before adapter');
denied(fn()=>$auto->createFiling(2025,1,$p['hash'],'test',true),'old preview cannot overwrite current amounts');
$p=$auto->preview(2025);$new=$auto->createFiling(2025,1,$p['hash'],'test',true);check($new!==$id,'rebuild creates fresh draft without deleting old preview');
denied(fn()=>$auto->assertCurrent($filings->get($id)),'superseded draft cannot transmit');
$auto->assertCurrent($filings->get($new));check(true,'replacement uses current cent amount');
$profileAnswers=$d['answers'];$profileAnswers['kst_due']='no';$filings->saveProfile(2025,$profileAnswers,1);
denied(fn()=>$auto->assertCurrent($filings->get($new)),'changed obligation invalidates automatic filing');
$filings->saveProfile(2025,$d['answers'],2);
$bad=$input;$bad['auto_answers']['source_reviewed']='';$auto->save(2025,1,$bad,$d['answers'],3);check(!$auto->preview(2025)['mapping']['ready'],'removing confirmation persists as open');
$auto->save(2025,2,$input,$d['answers'],4);
$prod=$auto->createFiling(2025,3,$auto->preview(2025)['hash'],'production');check($filings->get($prod)['state']==='draft','production preparation does not submit');
$filings->validate($prod,2);denied(fn()=>(new TaxFilings($db,'admin'))->send($prod,3,'dummy','dummy','SEND-PRODUCTION',true,false),'global production release remains closed');
check((int)$db->query('SELECT COUNT(*) FROM tax_filing_attempts')->fetchColumn()===0,'no transmission attempt in complete new test workflow');
$db->exec("INSERT INTO goods_donations(id,club_id,donor,description,received_on,value_cents,valuation,request_key) VALUES('goods','a','Synthetic','Synthetic goods','2025-06-01',100,'Synthetic evidence','goods')");
check(str_contains(implode(' ',$auto->preview(2025)['mapping']['issues']),'Sachspenden'),'noncash donations cannot disappear from preparation');
$db->exec("INSERT INTO contacts(id,club_id,kind,name,email,address) VALUES('mara','a','person','Mara Testdaten','mara@example.invalid','Testweg'); INSERT INTO memberships(id,club_id,contact_id,member_number,status,starts_on) VALUES('test-membership','a','mara','TEST-1','aktiv','2025-01-01')");
$private=$f->createEntry(['kind'=>'expense','amount'=>'119.00','document_date'=>'2025-06-01','due_date'=>'2025-06-01','description'=>'Privat bezahlt von Mara Testdaten','counterparty'=>'Synthetischer Lieferant','category'=>'general'],$key());
$reimburse=new \Kulturhus\Verein\ExpenseReimbursements($db,'finance');$reimburse->createForEntry($private,'mara','club',$key());
$p=$auto->preview(2025);check(!$p['mapping']['ready']&&count($p['extra']['claims'])===1,'Mara private advance blocks unknown original payment year');
check($p['extra']['claims'][0]['entry_id']===$private,'exact private source invoice available for UI link');
check(!\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db),'foreign keys consistent');
$restore=\Kulturhus\Verein\Tests\MySqlFixture::restoreBackup($db);
foreach(['tax_auto_cases','tax_filings','tax_filing_profiles'] as $table)check(\Kulturhus\Verein\Tests\MySqlFixture::rows($db,$table)===\Kulturhus\Verein\Tests\MySqlFixture::rows($restore,$table),'restore preserves '.$table);
// Synthetic static UI for the browser regression. No production URL or session.
function input(array $a,string $k,string $default=''):string{return is_string($a[$k]??null)?$a[$k]:$default;}
function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function hidden(string $k,mixed $v):string{return '<input type="hidden" name="'.e($k).'" value="'.e($v).'">';}
function csrf():string{return hidden('csrf','synthetic');}
function url(array $a):string{return '?'.http_build_query($a);}
$_SESSION=['actor'=>'finance'];$_GET=['tax_year'=>'2025'];$_POST=[];$_SERVER['REQUEST_METHOD']='GET';
ob_start();require __DIR__.'/../views/Finance/Tax/tax-auto.php';$html=ob_get_clean();
check(str_contains($html,'1. Vereinsangaben')&&str_contains($html,'3. Ergebnis prüfen'),'guided three step UI rendered');
check(!str_contains($html,'name="action" value="tax-auto-create"'),'blocked source has no create action');
if(getenv('VEREIN_AUTO_UI_FIXTURE'))file_put_contents(getenv('VEREIN_AUTO_UI_FIXTURE'),$html);
$_GET=['filing'=>$prod,'tax_year'=>'2025'];ob_start();require __DIR__.'/../views/Finance/Tax/tax-filings.php';$filingHtml=ob_get_clean();
check(str_contains($filingHtml,'role="alert"')&&str_contains($filingHtml,'Buchungen oder Angaben wurden geändert'),'stale automatic form shows source warning');
check(!str_contains($filingHtml,'value="tax-filing-validate"')&&!str_contains($filingHtml,'value="tax-filing-send"'),'stale automatic form offers no validation or send');
check(!str_contains($filingHtml,'value="tax-filing-save"')&&!str_contains($filingHtml,'value="tax-filing-row"'),'automatic form has no misleading manual field actions');
// A saved selection can become ineligible without the old snapshot being rewritten.
$evidenceInput=$input;$evidenceInput['tax_documents']=[$package,str_repeat('e',32)];
$oldPackage=(new \Kulturhus\Verein\Documents($db,'finance'))->file($package);
$auto->save(2025,(int)$auto->get(2025)['version'],$evidenceInput,$d['answers'],(int)$filings->profile(2025)['version']);
$evidencePreview=$auto->preview(2025);
check(!$evidencePreview['mapping']['ready']&&str_contains(implode(' ',$evidencePreview['mapping']['issues']),'Ausgewählte Nachweise'),'unavailable and ineligible evidence becomes an explicit blocking issue');
check($evidencePreview['extra']['unavailable_documents']===$evidenceInput['tax_documents'],'exact stale selections retained for user correction');
check($auto->get(2025)['input']['tax_documents']===$evidenceInput['tax_documents'],'opening preview never silently removes selected evidence');
check((new \Kulturhus\Verein\Documents($db,'finance'))->file($package)===$oldPackage,'archived source package remains byte-for-byte unchanged');
check($evidencePreview['hash']===$auto->preview(2025)['hash'],'stale evidence preview stays deterministic');
denied(fn()=>$auto->createFiling(2025,(int)$auto->get(2025)['version'],$evidencePreview['hash'],'production'),'missing evidence cannot create a complete filing');
denied(fn()=>$auto->savePackage(2025,(int)$auto->get(2025)['version'],$evidencePreview['hash']),'missing evidence cannot be silently excluded from an archived package');
$_GET=['tax_year'=>'2025'];ob_start();require __DIR__.'/../views/Finance/Tax/tax-auto.php';$evidenceHtml=ob_get_clean();
check(str_contains($evidenceHtml,'1. Vereinsangaben')&&str_contains($evidenceHtml,'Eine zuvor gewählte Anlage ist nicht mehr als aktives Finanz-PDF verfügbar.')&&str_contains($evidenceHtml,'tax_draft_complete')&&str_contains($evidenceHtml,'value="'.str_repeat('e',32).'"'),'assistant remains usable to correct stale selections');
check(!str_contains($evidenceHtml,'value="tax-draft-package"')&&!str_contains($evidenceHtml,'value="tax-draft-create"'),'no archive or complete action offered while evidence is unavailable');
echo "$n checks passed; synthetic MySQL only, no tax submission\n";
