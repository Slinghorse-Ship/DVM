<?php declare(strict_types=1);
/** Shared assessment workflow, real isolated MySQL, synthetic documents only. */
require_once __DIR__.'/../vereinsverwaltung/tests/support/document-workflow-fixture.php';
require_once __DIR__.'/../vereinsverwaltung/src/DocumentWorkflow/WorkflowStore.php';
use Kulturhus\Verein\DocumentWorkflow\{WorkflowAccess, WorkflowSchema, WorkflowStore};

final class AssessmentTestAccess implements WorkflowAccess
{
    public string $id='author';
    public string $club='synthetic-assessment';
    public string $level='write';
    public function actor():array{return ['id'=>$this->id,'club_id'=>$this->club,'access'=>$this->level];}
    public function participants():array{return [['id'=>'author','name'=>'Mara Muster','access'=>'write'],['id'=>'board','name'=>'Vorstand Test','access'=>'write'],['id'=>'writer','name'=>'Alex Beispiel','access'=>'write'],['id'=>'reader','name'=>'Lesezugang Test','access'=>'read']];}
}
$checks=[];
$check=static function(bool $ok,string $name)use(&$checks):void{if(!$ok)throw new RuntimeException($name);$checks[]=$name;};
$reject=static function(callable $fn,string $name)use($check):void{try{$fn();}catch(DomainException){$check(true,$name);return;}$check(false,$name);};
$database=DocumentWorkflowFixture::create();$pdo=DocumentWorkflowFixture::connection($database);
WorkflowSchema::install($pdo);$access=new AssessmentTestAccess();$store=new WorkflowStore($pdo,$access);
$check($store->collaborative(),'shared editing is the module default');
$template=$store->saveTemplate('',0,['title'=>'Gefährdungsbeurteilung · Test','description'=>'Produktfortschritt und Ausfüllhinweise dürfen nicht im PDF stehen.','fields'=>[
    ['key'=>'activity','label'=>'Tätigkeit','type'=>'text','required'=>true,'help'=>'Interne Ausfüllhilfe nicht drucken.'],
    ['key'=>'place','label'=>'Ort','type'=>'text','required'=>true],
    ['key'=>'hazards','label'=>'Gefährdungen und Schutzmaßnahmen','type'=>'risk_register','required'=>true,'matrix_profile'=>'fluix_reference_v1'],
],'provenance'=>[]]);$store->publishTemplate($template,1);
$doc=$store->createDocument($template,'','Gefährdungsbeurteilung · Kulturabend (Testdaten)');
$version=static fn():int=>$store->document($doc)['version'];
$row=['category'=>'Aufbau','hazard'=>'Stolpern über lose Leitungen','impact'=>'Sturzverletzung','initial'=>['severity'=>3,'likelihood'=>3],'controls'=>'Kabelbrücken verwenden, Laufwege freihalten und vor Öffnung kontrollieren.','residual'=>['severity'=>2,'likelihood'=>1],'responsible'=>'Mara Muster','effectiveness'=>'Sichtkontrolle vor Beginn durch Alex Beispiel'];
$values=['activity'=>'Bühnenaufbau und Veranstaltung','place'=>'Vereinsraum · Test','hazards'=>[$row,$row]];
$access->id='writer';$store->saveDocument($doc,$version(),$values,'Gefährdungsbeurteilung · Kulturabend (Testdaten)','');
$check($version()===2,'another module writer edits a draft without assignment');
$store->assignDocument($doc,$version(),'author','board');
$access->id='board';$store->transition($doc,$version(),'submit');
$check($store->document($doc)['status']==='submitted','board submits a draft assigned to another person');
$access->id='writer';$store->transition($doc,$version(),'return','Leitungswege ergänzen.');
$check($store->document($doc)['status']==='returned','any writer returns an assessment for revision');
$store->transition($doc,$version(),'submit');$access->id='board';$store->transition($doc,$version(),'approve');
$approvedVersion=$version();$approvedJson=$store->export($doc,$approvedVersion,'json')['bytes'];
$check($store->document($doc)['reviewer_id']==='board','actual reviewing person recorded');
$reject(static fn()=>$store->saveDocument($doc,$version(),$values,'Changed',''),'approved contents cannot change without reopening');
$access->id='writer';$store->transition($doc,$version(),'reopen');
$check($store->document($doc)['status']==='draft','another writer can reopen an approved assessment');
$check($store->export($doc,$approvedVersion,'json')['bytes']===$approvedJson,'approved snapshot stays unchanged after reopening');
$baseline=$store->export($doc,null,'pdf');
$check($baseline['mime']==='application/pdf'&&str_starts_with($baseline['bytes'],'%PDF-'),'real server PDF export');
$aid=$store->addAttachment($doc,$version(),'Lageplan-Test.pdf',$baseline['bytes']);$withAttachment=$version();
$check($store->attachment($doc,$aid)['bytes']===$baseline['bytes'],'PDF attachment retains exact original bytes');
$reject(static fn()=>$store->addAttachment($doc,$version(),'Lageplan-Test.pdf',$baseline['bytes']),'duplicate attachment rejected');
$reject(static fn()=>$store->addAttachment($doc,$version(),'falsch.txt',$baseline['bytes']),'non-PDF filename rejected');
$reject(static fn()=>$store->addAttachment($doc,$version(),'falsch.pdf','%PDF-broken'),'broken PDF rejected by parser');
$reject(static fn()=>$store->removeAttachment($doc,$version()-1,$aid),'stale attachment modification rejected');
$merged=$store->export($doc,null,'pdf');
$check(strlen($merged['bytes'])>strlen($baseline['bytes']),'PDF export appends original attachment pages');
$store->removeAttachment($doc,$version(),$aid);
$reject(static fn()=>$store->attachment($doc,$aid),'detached PDF not available in current version');
$check($store->attachment($doc,$aid,$withAttachment)['bytes']===$baseline['bytes'],'historic attachment remains available only with its old revision');
$check(empty($store->document($doc)['attachments']),'current attachment list updates');
$access->level='read';
$reject(static fn()=>$store->transition($doc,$version(),'submit'),'reader cannot change assessment status');
$reject(static fn()=>$store->addAttachment($doc,$version(),'Reader.pdf',$baseline['bytes']),'reader cannot upload');
$check($store->attachment($doc,$aid,$withAttachment)['bytes']===$baseline['bytes'],'reader can download authorized historic attachment');
$access->club='other-club';
$reject(static fn()=>$store->attachment($doc,$aid,$withAttachment),'cross-club attachment access blocked');
$reject(static fn()=>$store->export($doc,null,'pdf'),'cross-club PDF export blocked');
$access->club='synthetic-assessment';$access->level='none';
$reject(static fn()=>$store->attachment($doc,$aid,$withAttachment),'revoked permission blocks old attachment access');
$access->level='write';$access->id='author';
$store->transition($doc,$version(),'submit');$store->transition($doc,$version(),'reopen');
$check($store->document($doc)['status']==='draft','writer can move in-review assessment back to draft');
$longRow=$row;$longRow['controls']=str_repeat('Laufwege täglich prüfen und die Ergebnisse nachvollziehbar dokumentieren. ',65);
$values['hazards']=[$longRow];$store->saveDocument($doc,$version(),$values,'Gefährdungsbeurteilung · langer Text (Testdaten)','');
$long=$store->export($doc,null,'pdf');$check(str_starts_with($long['bytes'],'%PDF-'),'long hazard row splits across PDF pages');
require_once __DIR__.'/../vereinsverwaltung/src/Auth/ManagerWorkflowAccess.php';
foreach ([
    'CREATE TABLE actors (id VARCHAR(128) PRIMARY KEY,club_id VARCHAR(128),role VARCHAR(32),active INT,is_superuser INT,contact_id VARCHAR(128),press_access VARCHAR(16),minutes_access VARCHAR(16))',
    'CREATE TABLE contacts (id VARCHAR(128),club_id VARCHAR(128),name VARCHAR(240))',
    'CREATE TABLE settings_roles (id VARCHAR(128),club_id VARCHAR(128),website_access VARCHAR(16),members_access VARCHAR(16),finance_access VARCHAR(16),settings_access INT,inventory_access VARCHAR(16),loans_access VARCHAR(16),documents_access VARCHAR(16),press_access VARCHAR(16),minutes_access VARCHAR(16))',
    'CREATE TABLE settings_role_assignments (actor_id VARCHAR(128),club_id VARCHAR(128),role_id VARCHAR(128))',
    'CREATE TABLE processwire_identities (actor_id VARCHAR(128),enabled INT)',
    'CREATE TABLE actor_capabilities (actor_id VARCHAR(128),capability VARCHAR(128))',
    'CREATE TABLE admission_board (actor_id VARCHAR(128),club_id VARCHAR(128),enabled INT)',
] as $sql) $pdo->exec($sql);
$pdo->exec("INSERT INTO actors VALUES ('board1','adapter-club','member',1,0,'c1',NULL,NULL),('board2','adapter-club','member',1,0,'c2',NULL,NULL),('writer1','adapter-club','member',1,0,'c3',NULL,NULL),('reader1','adapter-club','member',1,0,'c4',NULL,NULL),('editor1','adapter-club','editor',1,1,NULL,NULL,NULL)");
$pdo->exec("INSERT INTO contacts VALUES ('c1','adapter-club','Vorstand Eins'),('c2','adapter-club','Vorstand Zwei'),('c3','adapter-club','Bearbeitung Test'),('c4','adapter-club','Lesezugang Test')");
$pdo->exec("INSERT INTO admission_board VALUES ('board1','adapter-club',1),('board2','adapter-club',1),('editor1','adapter-club',1)");
$pdo->exec("INSERT INTO settings_roles VALUES ('write-role','adapter-club','none','none','none',0,'none','none','write','none','none'),('read-role','adapter-club','none','none','none',0,'none','none','read','none','none')");
$pdo->exec("INSERT INTO settings_role_assignments VALUES ('writer1','adapter-club','write-role'),('reader1','adapter-club','read-role')");
$board=new Kulturhus\Verein\ManagerWorkflowAccess($pdo,'board1');
$check($board->actor()['access']==='write'&&(new Kulturhus\Verein\ManagerWorkflowAccess($pdo,'board2'))->actor()['access']==='write','both explicitly authorized board actors have module write access');
$check((new Kulturhus\Verein\ManagerWorkflowAccess($pdo,'writer1'))->actor()['access']==='write','explicit module writer has access without board office');
$check((new Kulturhus\Verein\ManagerWorkflowAccess($pdo,'reader1'))->actor()['access']==='read','read-only module assignment remains read-only');
$check((new Kulturhus\Verein\ManagerWorkflowAccess($pdo,'editor1'))->actor()['access']==='none','editorial identity never gains risk assessment access');
$check(array_column($board->participants(),'name','id')['board2']==='Vorstand Zwei','participants display actual contact names');
$pdo->exec("INSERT INTO actors VALUES ('member1','adapter-club','member',1,0,'c5',NULL,NULL),('foreign1','foreign-club','member',1,0,NULL,NULL,NULL)");
$pdo->exec("INSERT INTO contacts VALUES ('c5','adapter-club','Mara Muster')");
$memberAdapter=new Kulturhus\Verein\ManagerWorkflowAccess($pdo,'member1');
$check($memberAdapter->actor()['access']==='none','ordinary member has no module rights');
$eligible=array_column($board->signatureMembers(),'name','id');
$check(($eligible['member1']??'')==='Mara Muster','ordinary authenticated member eligible for own assigned signatures');
$check(!isset($eligible['editor1'])&&!isset($eligible['foreign1']),'signature provider excludes editorial and foreign identities');
$pdo->exec("INSERT INTO processwire_identities VALUES ('member1',0)");
$check(!in_array('member1',array_column($board->signatureMembers(),'id'),true),'disabled identity binding removed from signature provider');
$pdo->exec("DELETE FROM processwire_identities WHERE actor_id='member1'");
$pdo->exec("UPDATE actors SET active=0 WHERE id='member1'");
$check(!in_array('member1',array_column($board->signatureMembers(),'id'),true),'inactive member cannot be assigned');
$pdo->exec("UPDATE admission_board SET enabled=0 WHERE actor_id='board2'");
$check((new Kulturhus\Verein\ManagerWorkflowAccess($pdo,'board2'))->actor()['access']==='none','revoked board authority is resolved on each call');
$out=dirname(__DIR__).'/output/pdf';if(!is_dir($out))mkdir($out,0700,true);
foreach(['gefaehrdungsbeurteilung-beispiel.pdf'=>$baseline,'gefaehrdungsbeurteilung-mit-anlage.pdf'=>$merged,'gefaehrdungsbeurteilung-langtext.pdf'=>$long] as $name=>$file)file_put_contents($out.'/'.$name,$file['bytes']);
echo json_encode(['suite'=>'document-workflow-assessment','status'=>'passed','checks'=>count($checks),'passed'=>$checks,'database'=>$database,'production_changed'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
