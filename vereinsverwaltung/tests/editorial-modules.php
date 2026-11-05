<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
require_once __DIR__.'/../src/Auth/WorkspaceAccess.php';
require_once __DIR__.'/../src/Communication/EditorialModulePreferences.php';
require_once __DIR__.'/../src/Communication/SocialMediaWorkspace.php';
require_once __DIR__.'/../src/Communication/NewsletterWorkspace.php';
use Kulturhus\Verein\{Database,EditorialModules,EditorialModuleWorkspace,EditorialModulePreferences,Settings,WorkspaceAccess};
use Kulturhus\WebsiteAreaAccess;
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic A'),('b','Synthetic B');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','members',1),('social-reader','a','member',0),('social-writer','a','member',0),('newsletter-writer','a','member',0),('foreign','b','members',1)");
$n=0;function ck($ok,string $name):void{global $n;if(!$ok)throw new RuntimeException($name);$n++;echo "PASS $name\n";}
function deny(callable $fn,string $name):void{global $db;$before=\Kulturhus\Verein\Tests\MySqlFixture::snapshot($db);try{$fn();}catch(DomainException){ck(!$db->inTransaction()&&$before===\Kulturhus\Verein\Tests\MySqlFixture::snapshot($db),$name);return;}throw new RuntimeException('Not denied: '.$name);}
$settings=new Settings($db,'admin');
foreach(['social-reader'=>['social','read'],'social-writer'=>['social','write'],'newsletter-writer'=>['newsletter','write']] as $actor=>[$area,$level]){
 $levels=array_fill_keys(array_keys(WebsiteAreaAccess::AREAS),'none');$levels[$area]=$level;
 $role=$settings->saveRole('',0,['name'=>$actor,'members_access'=>'none','finance_access'=>'none','settings_access'=>false,'inventory_access'=>'none','loans_access'=>'none','documents_access'=>'none','press_access'=>'none','website_custom'=>'1','website_levels'=>$levels]);$settings->assignRole($actor,$role,0);
}
$original=['view'=>'website','tab'=>'recipients','item'=>'123','q'=>'abc','page'=>'2'];
ck(EditorialModules::route($original)===array_replace($original,['view'=>'newsletter']),'legacy recipient link preserves context');
ck(EditorialModules::route(['view'=>'website','tab'=>'social'])===['view'=>'social','tab'=>'social'],'legacy social route');
ck(EditorialModules::route(['view'=>'finance','tab'=>'social'])===['view'=>'finance','tab'=>'social'],'other modules unchanged');
ck(EditorialModules::route(['view'=>'website','tab'=>'press','item'=>'12'])===['view'=>'press','tab'=>'press','item'=>'12'],'legacy press link preserves draft');
ck(!in_array('press',EditorialModules::AREAS['website'],true)&&EditorialModules::owner('press')==='press','press has independent ownership');
ck(!(new WorkspaceAccess($db,'admin'))->allows(['view'=>'handbook']),'outdated handbook disabled even for admin');
deny(fn()=>(new EditorialModuleWorkspace($db,'admin','website'))->editorialWrite('press/draft-save',[],'v','k'),'website cannot write to press module');
deny(fn()=>(new EditorialModuleWorkspace($db,'admin','press'))->editorialWrite('newsletter/send',[],'v','k'),'press cannot send newsletter');
foreach(['social-reader','social-writer'] as $actor){$access=new WorkspaceAccess($db,$actor);ck($access->allows(['view'=>'social'])&&!$access->allows(['view'=>'newsletter'])&&!$access->allows(['view'=>'website']),'social-only menu and direct route '.$actor);ck($access->allows(['view'=>'website','tab'=>'social']),'compatible scoped old route '.$actor);}
$social=new EditorialModuleWorkspace($db,'social-writer','social');
deny(fn()=>$social->editorialWrite('newsletter/send',[],'v','k'),'cross-module mailing blocked before network');
deny(fn()=>$social->editorialRead('pages'),'cross-module reading blocked before network');
deny(fn()=>$social->editorialWrite('image/upload',[],'v','k'),'cross-module generic image upload blocked');
deny(fn()=>(new EditorialModuleWorkspace($db,'social-reader','social'))->editorialWrite('social/facebook',[],'v','k'),'read-only publication blocked before network');
$prefs=new EditorialModulePreferences($db,'social-writer','social');$data=EditorialModulePreferences::defaults('social');
ck($prefs->get()['version']===0,'reading defaults creates no preference');$before=\Kulturhus\Verein\Tests\MySqlFixture::snapshot($db);$prefs->get();ck($before===\Kulturhus\Verein\Tests\MySqlFixture::snapshot($db),'read-only defaults leave database unchanged');
$data['theme']='night';$data['format']='post';$data['proposal_mode']='on_open';$prefs->save($data,0);ck($prefs->get()===['version'=>1,'data'=>$data],'real MySQL initial preference save');
ck((new EditorialModulePreferences($db,'admin','newsletter'))->get()['version']===0,'newsletter preferences independent');
ck((new EditorialModulePreferences($db,'foreign','social'))->get()['version']===0,'other club preferences independent');
deny(fn()=>$prefs->save($data,0),'stale save rolls back without revision or audit');
deny(fn()=>(new EditorialModulePreferences($db,'social-reader','social'))->save($data,1),'reader cannot change shared preferences');
deny(fn()=>(new EditorialModulePreferences($db,'social-writer','newsletter'))->get(),'other module preferences not readable');
deny(fn()=>$prefs->save($data+['publish'=>'1'],1),'unexpected publication setting rejected');
$invalid=$data;$invalid['format']='newsletter';deny(fn()=>$prefs->save($invalid,1),'wrong module design rejected');
$invalid=$data;$invalid['proposal_mode']='publish';deny(fn()=>$prefs->save($invalid,1),'automatic publication mode rejected');
$data['headline']='Geändert';$prefs->save($data,1);ck($prefs->get()['version']===2,'versioned MySQL update');
ck((int)$db->query("SELECT COUNT(*) FROM settings_revisions WHERE section='module:social'")->fetchColumn()===2,'each accepted change has its revision');
ck((int)$db->query("SELECT COUNT(*) FROM audit_events WHERE action='editorial.module.settings'")->fetchColumn()===2,'accepted changes audited');
$db->exec("UPDATE club_settings SET payload='invalid' WHERE section='module:social'");deny(fn()=>$prefs->save($data,2),'corrupt stored preference fails closed');
require_once __DIR__.'/../src/Communication/EditorialConnectionSettings.php';
deny(fn()=>(new \Kulturhus\Verein\EditorialConnectionSettings($db,'social-writer','social'))->read(),'social writer cannot read technical connection settings');
deny(fn()=>(new \Kulturhus\Verein\EditorialConnectionSettings($db,'social-reader','social'))->save([],str_repeat('0',64),str_repeat('0',32)),'read-only user cannot save connection settings');
deny(fn()=>(new EditorialModuleWorkspace($db,'social-writer','social'))->editorialWrite('settings/refresh-instagram',[],str_repeat('0',64),str_repeat('0',32)),'social editor cannot refresh technical account');
echo "$n editorial module checks passed; MySQL only, no publishing or mailing\n";
