<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
require_once __DIR__.'/../src/Auth/WorkspaceAccess.php';
require_once __DIR__.'/../src/Web/ManagerNavigation.php';
use Kulturhus\Verein\{Database,Finance,FinancePreferences,Settings,SettingsSchema,WorkspaceAccess,FinanceNavigation,ManagerNavigation};
$checks=0;
function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException('Expected rejection: '.$label);}
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic A'),('b','Synthetic B');INSERT INTO actors(id,club_id,role) VALUES('writer','a','treasurer'),('other','b','treasurer'),('reader','a','member'),('member','a','member'),('editor','a','editor'),('inventory','a','members');INSERT INTO settings_roles(id,club_id,name,description,members_access,finance_access,settings_access,inventory_access,loans_access,documents_access,version) VALUES('read','a','Read','','none','read',0,'none','none','none',1);INSERT INTO settings_role_assignments(club_id,actor_id,role_id,version) VALUES('a','reader','read',1)");
$settings=new Settings($db,'writer');$prefs=new FinancePreferences($db,'writer');$finance=new Finance($db,'writer');$defaults=FinancePreferences::defaults();
check($prefs->categorySphere('inventory')==='3'&&$prefs->categorySphere('membership')==='1','Kulturhus standard provides editable sphere suggestions');
check($defaults['category_spheres']['culture']===''&&$defaults['category_spheres']['rooms']==='','context-dependent spheres remain open');
check($defaults['accounts'][2026]['mode']==='selection'&&in_array('06300',$defaults['accounts'][2026]['codes'],true),'common verified accounts are the default selection');
check(array_map('strval',array_keys($prefs->visibleAccounts($finance->taxAccounts(),2026)))===$defaults['accounts'][2026]['codes'],'default account display stays compact and changeable');
$data=$defaults;$data['category_spheres']['inventory']='3';$data['accounts'][2026]=['mode'=>'selection','codes'=>['66000','06300','66000']];$data['accounts'][2025]=['mode'=>'selection','codes'=>'66000'];$settings->save(FinancePreferences::SECTION,$data,0);
check($prefs->categorySphere('inventory')==='3'&&$prefs->categorySphere('insurance')==='','configured inventory sphere only');
check((new FinancePreferences($db,'other'))->categorySphere('inventory')==='3','category preferences use the same club-editable standard independently');
check($prefs->data()['accounts'][2026]['codes']===['06300','66000'],'selection deduplicated and sorted');
check(array_map('strval',array_keys($prefs->visibleAccounts($finance->taxAccounts(),2026)))===['06300','66000'],'selection controls display only');
$retained=$prefs->visibleAccounts($finance->taxAccounts(),2026,['18000']);check(isset($retained[18000])&&$retained[18000]['preference_hidden']===true,'existing hidden bank account can remain visible');
check(isset($finance->taxAccounts()[18000])&&count($finance->taxAccounts())>2000,'full underlying bank and account catalog unaffected');
check(array_map('strval',array_keys($prefs->visibleAccounts($finance->taxAccounts(2025),2025)))===['66000'],'selection belongs to exact year');
check(count($settings->history(FinancePreferences::SECTION))===1,'preference change has revision');
check((int)$db->query("SELECT COUNT(*) FROM audit_events WHERE action='settings.updated' AND object_id='finance_preferences'")->fetchColumn()===1,'preference change has audit record');
denied(fn()=>$settings->save(FinancePreferences::SECTION,$defaults,0),'stale preference save blocked');
foreach(['member','editor','inventory'] as $actor){denied(fn()=>(new Settings($db,$actor))->section(FinancePreferences::SECTION),'nonfinance read blocked '.$actor);denied(fn()=>(new Settings($db,$actor))->save(FinancePreferences::SECTION,$defaults,1),'nonfinance save blocked '.$actor);check(!(new WorkspaceAccess($db,$actor))->allows(['view'=>'settings','section'=>FinancePreferences::SECTION]),'nonfinance route hidden '.$actor);}
check((new Settings($db,'reader'))->section(FinancePreferences::SECTION)['version']===1,'finance reader can inspect preferences');
denied(fn()=>(new Settings($db,'reader'))->save(FinancePreferences::SECTION,$defaults,1),'read-only finance cannot save');
check((new WorkspaceAccess($db,'reader'))->allows(['view'=>'settings','section'=>FinancePreferences::SECTION]),'read-only finance route allowed');
foreach([
 ['unexpected'=>true],
 ['category_spheres'=>['inventory'=>'7']],
 ['category_spheres'=>['unknown'=>'3']],
 ['category_spheres'=>['inventory'=>['3']]],
 ['accounts'=>[2024=>['mode'=>'all','codes'=>[]]]],
 ['accounts'=>[2026=>['mode'=>'bad','codes'=>[]]]],
 ['accounts'=>[2026=>['mode'=>'selection','codes'=>[66000]]]],
 ['accounts'=>[2026=>['mode'=>'selection','codes'=>['<script>']]]],
 ['accounts'=>[2026=>['mode'=>'selection','codes'=>array_fill(0,3001,'66000')]]],
] as $bad)denied(fn()=>FinancePreferences::normalize($bad),'malformed/bounded preferences rejected');
$custom='66001';(new Finance($db,'other'))->saveTaxAccount($custom,'Only other club',true,0,true);$bad=$prefs->data();$bad['accounts'][2026]['codes']=[$custom];denied(fn()=>$settings->save(FinancePreferences::SECTION,$bad,1),'foreign custom account cannot be selected');
$finance->saveTaxAccount($custom,'Own custom account',true,0,true);$settings->save(FinancePreferences::SECTION,$bad,1);check(isset($prefs->visibleAccounts($finance->taxAccounts(),2026)[$custom]),'own custom account can be selected');
$finance->saveTaxAccount($custom,'Own inactive account',false,1);check($prefs->visibleAccounts($finance->taxAccounts(),2026)[$custom]['active']===0,'visibility setting cannot reactivate account');
$all=$prefs->data();$all['accounts'][2026]['mode']='all';$settings->save(FinancePreferences::SECTION,$all,2);check(count($prefs->visibleAccounts($finance->taxAccounts(),2026))===count($finance->taxAccounts()),'all mode restores full picker without changing stored chosen list');
$empty=$prefs->data();$empty['accounts'][2026]=['mode'=>'selection','codes'=>[]];$settings->save(FinancePreferences::SECTION,$empty,3);check($prefs->visibleAccounts($finance->taxAccounts(),2026)===[],'explicit empty selection remains empty');
check(isset($prefs->visibleAccounts($finance->taxAccounts(),2026,['66000'])[66000]),'empty selection still retains existing account');
$db->exec("CREATE TRIGGER stop_preference_audit BEFORE INSERT ON audit_events FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test'; END");try{$settings->save(FinancePreferences::SECTION,$defaults,4);throw new RuntimeException('Expected audit failure');}catch(PDOException){}check($settings->section(FinancePreferences::SECTION)['version']===4,'audit failure rolls preference back');$db->exec('DROP TRIGGER stop_preference_audit');
check(FinanceNavigation::active(['view'=>'inventory','tab'=>'assets'])==='reports','legacy assets URL is a finance destination');
check(ManagerNavigation::key(['view'=>'inventory','tab'=>'assets'])==='finance:reports','assets highlights finance sidebar');
check((new WorkspaceAccess($db,'reader'))->allows(['view'=>'inventory','tab'=>'assets']),'finance-only reader can open assets');
check(!(new WorkspaceAccess($db,'inventory'))->allows(['view'=>'inventory','tab'=>'assets']),'inventory-only actor cannot open assets');
$navigation=ManagerNavigation::groups(new WorkspaceAccess($db,'reader'));$org=array_values(array_filter($navigation,fn($row)=>$row['id']==='organisation'));
check(!$org||!in_array('organisation:inventory',array_column($org[0]['items'],'id'),true),'finance-only actor gets no misleading inventory menu');
$saved=$prefs->data();$draft=$saved;$draft['category_spheres']['inventory']='4';$draft['category_spheres']['insurance']='7';$draft['accounts'][2026]=['mode'=>'selection','codes'=>['06300','66000']];$draft['accounts'][2025]=['mode'=>'selection','codes'=>[]];
denied(fn()=>$settings->save(FinancePreferences::SECTION,$draft,4),'invalid sphere rejects whole persistence');
$form=SettingsSchema::formValues(FinancePreferences::SECTION,$draft,$saved);
check($form['category_spheres']['inventory']==='4'&&$form['category_spheres']['insurance']==='','invalid sphere leaves that field open and retains other new sphere choices');
check($form['accounts'][2026]['codes']===['06300','66000']&&$form['accounts'][2025]===['mode'=>'selection','codes'=>[]],'failed sphere validation retains both years and explicit empty selection');
$draft['accounts'][2026]['codes']=['66000','<script>',[],'06300'];$form=SettingsSchema::formValues(FinancePreferences::SECTION,$draft,$saved);
check($form['accounts'][2026]['codes']===['06300','66000']&&$form['category_spheres']['inventory']==='4','invalid account cannot wipe unrelated safe fields or inject markup');
$draft['accounts'][2025]=['mode'=>'selection','codes'=>'66000,06300,66000'];$form=SettingsSchema::formValues(FinancePreferences::SECTION,$draft,$saved);check($form['accounts'][2025]['codes']===['06300','66000'],'bounded hidden CSV roundtrips on errors');
check($prefs->data()===$saved&&$settings->section(FinancePreferences::SECTION)['version']===4,'error form recovery never persists rejected data');
echo "$checks finance preference checks passed\n";
