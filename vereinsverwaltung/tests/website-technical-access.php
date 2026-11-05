<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
require_once __DIR__.'/../src/Auth/WorkspaceAccess.php';
use Kulturhus\Verein\{Database,WebsiteWorkspace,WorkspaceAccess,WebsiteTechnicalAccess,FacebookHistory};
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect();Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('owner','a','members',1),('other-admin','a','members',1),('editor','a','editor',1),('manager','a','members',0)");
$n=0;
function check(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
function denied(callable $f,string $label):void{try{$f();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$variables=['VEREIN_MODE','KH_NATIVE_MANAGER','VEREIN_AUTH'];$old=[];foreach($variables as $v)$old[$v]=getenv($v);
try{
 putenv('VEREIN_MODE=production');putenv('KH_NATIVE_MANAGER=1');putenv('VEREIN_AUTH=native');
 foreach(['owner'=>true,'other-admin'=>true,'editor'=>false,'manager'=>false] as $actor=>$allowed){check(WebsiteTechnicalAccess::allowed($db,$actor)===$allowed,$actor.' current technical capability');check((new WorkspaceAccess($db,$actor))->allows(['view'=>'website','tab'=>'technical'])===$allowed,$actor.' direct route matches');}
 $other=new WebsiteWorkspace($db,'manager');
 denied(fn()=>$other->connectionStatus(),'ordinary manager cannot inspect technical settings');
 denied(fn()=>$other->editorialWrite('settings/save',[],'1',str_repeat('a',32)),'old settings bridge cannot mutate native settings');
 denied(fn()=>FacebookHistory::preview($db,'manager',2025),'provider history guarded before external request');
 check((new WebsiteWorkspace($db,'editor'))->canWrite('pages'),'editor retains content editing');
 $owner=new WebsiteWorkspace($db,'owner');check($owner->connectionStatus()['connected'],'native site configured');
 $db->exec("UPDATE actors SET is_superuser=0 WHERE id='owner'");check(!$owner->canManage(),'technical revocation applies to existing service');
 $db->exec("UPDATE actors SET active=0 WHERE id='other-admin'");check(!WebsiteTechnicalAccess::allowed($db,'other-admin'),'suspended technical account denied');
 putenv('KH_NATIVE_MANAGER');putenv('VEREIN_AUTH=processwire');
 check(!WebsiteTechnicalAccess::allowed($db,'owner'),'retired production authority denied');
 check(!(new WebsiteWorkspace($db,'editor'))->canRead(),'retired production content authority denied');
}finally{foreach($old as $v=>$value)putenv($value===false?$v:$v.'='.$value);}
echo "$n native technical access checks passed; no external data or mail\n";
