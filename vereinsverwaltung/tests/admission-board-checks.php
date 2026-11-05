<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/../src/Web/View.php';
use Kulturhus\Verein\{Database,AdmissionVotes};
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');$checks=0;
// Native schema with an existing unconfirmed board grant.
Database::migrate($db);
function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException('Not rejected: '.$label);}
$db->exec("INSERT INTO clubs VALUES('a','Synthetic Club'),('b','Foreign Club');INSERT INTO actors(id,club_id,role,is_superuser,active) VALUES('root','a','members',1,1),('root2','a','members',1,1),('one','a','member',0,1),('two','a','member',0,1),('editor','a','editor',1,1),('inactive','a','member',0,0),('foreign','b','member',0,1);INSERT INTO admission_board VALUES('a','one','1. Vorstand',1,7)");
$before=$db->query('SELECT * FROM admission_board')->fetchAll();
Database::migrate($db);
check((int)$db->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn()===Database::SCHEMA_VERSION,'native MySQL schema keeps explicit board checks');check($db->query('SELECT * FROM admission_board')->fetchAll()===$before,'migration preserves existing board permissions exactly');check((int)$db->query('SELECT count(*) FROM admission_board_checks')->fetchColumn()===0,'migration invents no historical check');
Database::migrate($db);check((int)$db->query('SELECT count(*) FROM admission_board_checks')->fetchColumn()===0,'repeat migration is idempotent');
$root=new AdmissionVotes($db,'root');$otherRoot=new AdmissionVotes($db,'root2');$entry=fn($id)=>array_column($root->board(),null,'actor_id')[$id]??null;
check(!$entry('one')['office_checked']&&$entry('one')['office_checked_by']===null,'legacy permission is honestly unconfirmed');
$db->exec("INSERT INTO membership_applications(id,club_id,request_key,status,answers,configuration,consent_snapshot,configuration_version,submitted_at) VALUES('app','a','request','pending','{}','{}','{}',1,'2026-01-01')");$root->open('app',true);$roundBefore=$db->query('SELECT * FROM admission_rounds')->fetchAll();
$root->grant('one',7,'1. Vorstand',true,true);$checked=$entry('one');
check($checked['office_checked']&&$checked['office_checked_by']==='root'&&$checked['office_checked_at']!=='','explicit check stores actor and time');
check((int)$checked['version']===7,'first check for unchanged legacy permission preserves its version');
check($db->query('SELECT * FROM admission_rounds')->fetchAll()===$roundBefore&&AdmissionVotes::result($db,'a','app')['result']==='pending','first metadata check leaves active admission round valid');
$checkRow=$db->query('SELECT * FROM admission_board_checks')->fetchAll();$auditCount=(int)$db->query('SELECT count(*) FROM audit_events')->fetchColumn();
$otherRoot->grant('one',7,'1. Vorstand',true,true);
check($db->query('SELECT * FROM admission_board_checks')->fetchAll()===$checkRow,'unchanged save retains original reviewer and exact time');check((int)$db->query('SELECT count(*) FROM audit_events')->fetchColumn()===$auditCount,'unchanged save adds no fake review or permission audit');
denied(fn()=>$root->grant('one',7,'2. Vorstand',true,false),'enabled office change requires explicit new check');
denied(fn()=>$root->grant('one',7,'2. Vorstand',false,false),'different nonempty office cannot be saved unchecked even when disabled');
denied(fn()=>$root->grant('one',6,'1. Vorstand',true,true),'stale permission version rejected');
denied(fn()=>(new AdmissionVotes($db,'one'))->grant('two',0,'2. Vorstand',true,true),'ordinary board member cannot certify permissions');
denied(fn()=>$root->grant('foreign',0,'2. Vorstand',true,true),'foreign-club target rejected');denied(fn()=>$root->grant('editor',0,'2. Vorstand',true,true),'editor target rejected');denied(fn()=>$root->grant('inactive',0,'2. Vorstand',true,true),'inactive target rejected');
check($db->query('SELECT * FROM admission_board_checks')->fetchAll()===$checkRow,'all rejected requests leave review history untouched');
$root->grant('two',0,'2. Vorstand',true,true);check($entry('two')['office_checked']&&(int)$entry('two')['version']===1,'new board permission and check created together');
denied(fn()=>$root->grant('one',7,'2. Vorstand',true,true),'duplicate occupied office rejected despite confirmation');
$otherRoot->grant('one',7,'Kassenwart',true,true);$checked=$entry('one');
check($checked['office']==='Kassenwart'&&(int)$checked['version']===8&&$checked['office_checked_by']==='root2','new office has its own current reviewer and version');
check((int)$db->query("SELECT count(*) FROM admission_board_checks WHERE actor_id='one'")->fetchColumn()===2,'previous office check remains immutable history');
check(AdmissionVotes::result($db,'a','app')['result']==='discussion','actual office change still invalidates old voting roster');
$root->grant('one',8,'Kassenwart',false,false);check(!$entry('one')['office_checked']&&!(new AdmissionVotes($db,'one'))->canVote(),'deactivation allowed without confirmation and old review no longer current');
denied(fn()=>$root->grant('one',9,'Kassenwart',true,false),'reactivation requires explicit check');
$root->grant('one',9,'Kassenwart',true,true);check($entry('one')['office_checked']&&(int)$entry('one')['version']===10,'reactivation stores fresh version-bound check');
$root->grant('one',10,'Kassenwart',false,true);check($entry('one')['office_checked']&&!(new AdmissionVotes($db,'one'))->canVote(),'explicit recorded office check does not itself grant voting rights');
$root->grant('one',11,'Kassenwart',false,false);check(!$entry('one')['office_checked']&&(int)$entry('one')['version']===12,'clearing a disabled permission check invalidates current check without deleting history');
$state=fn()=>array_map(fn($table)=>\Kulturhus\Verein\Tests\MySqlFixture::rows($db,$table),['admission_board','admission_board_checks','audit_events']);$snapshot=$state();
$db->exec("CREATE TRIGGER block_review_audit BEFORE INSERT ON audit_events FOR EACH ROW BEGIN IF NEW.action='admission.board.office-confirmed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='audit unavailable'; END IF; END");
try{$root->grant('one',12,'Kassenwart',true,true);throw new RuntimeException('audit failure not triggered');}catch(PDOException){check($state()===$snapshot,'audit failure rolls back permission version and check together');}$db->exec('DROP TRIGGER block_review_audit');
try{$db->exec("UPDATE admission_board_checks SET checked_by='root2'");throw new RuntimeException('mutable check');}catch(PDOException){check(true,'stored review cannot be overwritten');}
try{$db->exec('DELETE FROM admission_board_checks');throw new RuntimeException('deletable check');}catch(PDOException){check(true,'stored review cannot be deleted');}
$root->grant('one',12,'Kassenwart',true,true);
$_SESSION=['actor'=>'root','csrf'=>'synthetic'];$_POST=[];$admissionVotes=$root;$users=$db->query('SELECT * FROM actors WHERE club_id=\'a\'')->fetchAll();$accountName=static fn(string $id)=>['root'=>'Prüfer Muster','root2'=>'Zweite Prüfung','one'=>'Person Eins','two'=>'Person Zwei'][$id]??'Testzugang';ob_start();require __DIR__.'/../views/People/Members/admission-permissions.php';$html=(string)ob_get_clean();
check(str_contains($html,'Prüfung gespeichert:')&&str_contains($html,'geprüft von Prüfer Muster'),'view shows saved checker using provided account name');
preg_match('~<form[^>]*data-board-permission[^>]*>.*?name="user_id" value="one".*?</form>~s',$html,$match);check(str_contains($match[0]??'','data-board-confirmed checked'),'saved check renders checked');check(str_contains($html,'admission-permissions.js'),'view includes scoped change reset');
if(getenv('BOARD_CHECK_TEST_HTML'))file_put_contents(getenv('BOARD_CHECK_TEST_HTML'),$html);
check((\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db)[0]??false)===false,'foreign keys remain valid');
echo "PASS $checks board office confirmation checks\n";
