<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
require_once __DIR__.'/mysql-bootstrap.php';
use Kulturhus\Verein\{Database,Settings,ProcessWireIdentity,Permissions,BoardAccess,AdmissionVotes};
final class FailingCommitDb extends PDO
{
    public bool $failCommit=false;
    public function commit():bool{if($this->failCommit){$this->failCommit=false;throw new DomainException('Simulierter Commitfehler.');}return parent::commit();}
}
$fixture=\Kulturhus\Verein\Tests\MySqlFixture::connect();
$db=new FailingCommitDb(\Kulturhus\Verein\Tests\MySqlFixture::dsn($fixture),'root','isolated-regression-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::MYSQL_ATTR_FOUND_ROWS=>true]);Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Test');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('root','a','members',1),('staff','a','members',0),('writer','a','member',0),('writer2','a','member',0)");
$role=(new Settings($db,'root'))->saveRole('',0,['name'=>'Mitglied','description'=>'','members_access'=>'none','finance_access'=>'none','inventory_access'=>'none','loans_access'=>'none','documents_access'=>'none','settings_access'=>false]);
$id=ProcessWireIdentity::assign($db,'root',123,$role,'',true,0);
$calls=0;$restores=0;$websiteSuperuser=false;
$sync=function(int $uid,bool $enabled)use(&$calls,&$restores,&$websiteSuperuser):callable{if($uid!==123)throw new RuntimeException('Falsche Website-ID');$before=$websiteSuperuser;$websiteSuperuser=$enabled;$calls++;return function()use(&$restores,&$websiteSuperuser,$before):void{$websiteSuperuser=$before;$restores++;};};
$n=0;function ok(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
function denied(callable $f,string $label):void{try{$f();}catch(DomainException){ok(true,$label);return;}throw new RuntimeException($label);}
ProcessWireIdentity::resolve($db,123,true);ok($calls===0&&!$websiteSuperuser,'reading identity never changes website roles');
denied(fn()=>ProcessWireIdentity::setSuperuser($db,'staff',123,1,true,true,$sync),'unauthorized grant rejected before website change');
denied(fn()=>ProcessWireIdentity::setSuperuser($db,'root',123,1,true,false,$sync),'unconfirmed grant rejected before website change');
ok($calls===0,'rejected grants cause no website mutation');
ProcessWireIdentity::setSuperuser($db,'root',123,1,true,true,$sync);
ok($calls===1&&$websiteSuperuser&&BoardAccess::superuser($db,$id),'explicit central grant changes both systems');
ok(!(new AdmissionVotes($db,$id))->canVote(),'central superuser has no implicit board vote');
denied(fn()=>ProcessWireIdentity::setSuperuser($db,'root',123,1,false,true,$sync),'stale revocation rejected');
ok($calls===1&&$websiteSuperuser,'stale request leaves website access unchanged');
ProcessWireIdentity::setSuperuser($db,'root',123,2,false,true,$sync);
ok(!$websiteSuperuser&&!BoardAccess::superuser($db,$id),'explicit revocation changes both systems');
$beforeAudit=$db->query('SELECT COUNT(*) FROM audit_events')->fetchColumn();
$db->failCommit=true;
denied(fn()=>ProcessWireIdentity::setSuperuser($db,'root',123,3,true,true,$sync),'database commit failure propagated');
ok(!$websiteSuperuser&&!BoardAccess::superuser($db,$id)&&$restores===1,'failed database commit restores prior website roles');
ok($db->query('SELECT version FROM processwire_identities WHERE pw_user_id=123')->fetchColumn()===3&&$db->query('SELECT COUNT(*) FROM audit_events')->fetchColumn()===$beforeAudit,'failed central grant rolls back version and audit');
$fail=function(int $uid,bool $enabled):callable{throw new DomainException('Website-Speicherung fehlgeschlagen.');};
denied(fn()=>ProcessWireIdentity::setSuperuser($db,'root',123,3,true,true,$fail),'website failure blocks central grant');
ok(!BoardAccess::superuser($db,$id),'website failure cannot leave a local superuser behind');
$db->exec("UPDATE actors SET active=0 WHERE id='pw:123'");
denied(fn()=>ProcessWireIdentity::setSuperuser($db,'root',123,3,true,true,$sync),'inactive identity cannot be elevated');
$board=new AdmissionVotes($db,'root');$board->grant('writer',0,'Schriftführer',true,true);
ok($db->query("SELECT office FROM admission_board WHERE actor_id='writer'")->fetchColumn()==='Schriftwart','legacy office name normalizes to Schriftwart');
denied(fn()=>$board->grant('writer2',0,'Schriftwart',true,true),'Schriftwart cannot occupy a second seat for Schriftführer');
$db->exec("UPDATE admission_board SET office='Schriftführer' WHERE actor_id='writer'");
denied(fn()=>$board->grant('writer2',0,'Schriftwart',true,true),'stored legacy title still prevents a duplicate seat');
ok(\Kulturhus\Verein\Tests\MySqlFixture::integrity($db)==='ok','database remains intact');
echo "$n central identity checks passed\n";
