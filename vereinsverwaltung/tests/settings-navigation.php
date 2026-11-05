<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/../src/Web/View.php';
use Kulturhus\Verein\{Database,Settings,SettingsSchema,WorkspaceAccess,FreeTables};
$checks=0;function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);++$checks;}
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Testverein');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','member',1),('member','a','member',0)");
$settings=new Settings($db,'member');
check(array_keys($settings->startViews())===['dashboard','calendar'],'member start views match permitted routes');
check(isset((new Settings($db,'admin'))->startViews()['finance']),'technical superuser retains financial start view');
try{$settings->save('account',array_replace(SettingsSchema::defaults('account'),['start_view'=>'finance']),0);throw new RuntimeException('Unauthorized start view saved');}catch(DomainException){check(true,'unavailable start view cannot be posted');}
check($settings->section('account')['version']===0,'invalid preference has no persistence side effect');
$_SESSION=['actor'=>'member','csrf'=>str_repeat('a',64)];$_GET=['view'=>'settings','tab'=>'account'];$_POST=[];
$workspaceAccess=new WorkspaceAccess($db,'member');$view='settings';$tab='account';$roles=['member'=>'Synthetisches Mitglied'];
ob_start();require __DIR__.'/../views/Settings/settings.php';$html=ob_get_clean();
check(!str_contains($html,'Rollen &amp; Rechte')&&!str_contains($html,'Beiträge &amp; Spenden')&&!str_contains($html,'Umsetzungsstand'),'account navigation hides administration');
check(!str_contains($html,'value="finance"')&&!str_contains($html,'value="members"'),'unavailable start options omitted');
check(str_contains($html,'Synthetisches Mitglied')&&!str_contains($html,'Lokaler Testzugang')&&!str_contains($html,'WISO'),'personal account identified without legacy test text');
$view='free';$tab='';$canAdmissions=false;$_GET=['sheet'=>str_repeat('b',32)];
http_response_code(200);ob_start();require __DIR__.'/../views/Shell/module.php';$html=ob_get_clean();
check(http_response_code()===403&&str_contains($html,'Keine Berechtigung'),'unshared free list returns a clean denial');
check(!str_contains($html,'free-workspace'),'failed free view leaves no partial UI');
$view='portal';$_GET=[];$currentItem=['Vereinsprofil',[],'','',''];$views=['portal'=>'Vereinsportal'];$canRead=true;$matches=fn(array $route)=>false;
$currentGroup=['Vereinsportal','mail', [['Mein Profil',['view'=>'portal','tab'=>'own']],['Mitgliedsanträge',['view'=>'portal','tab'=>'application']]]];
ob_start();require __DIR__.'/../views/Shell/module.php';$html=ob_get_clean();
check(str_contains($html,'Mein Profil')&&!str_contains($html,'Mitgliedsanträge'),'fallback subtabs follow route policy');
check(!str_contains($html,'Alle offenen Punkte ansehen'),'fallback hides unavailable progress');
echo "$checks settings navigation checks passed\n";
