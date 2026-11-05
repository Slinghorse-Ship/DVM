<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/native/bootstrap.php';require dirname(__DIR__).'/src/bootstrap.php';require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\{TestSupport};
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{Database,MembershipApplications,AdmissionChoicePicker};
$db=TestSupport::db();Kulturhus\Native\Installer\SchemaRegistry::install($db);$n=0;
$check=static function(bool $ok,string $label)use(&$n){if(!$ok)throw new RuntimeException($label);$n++;echo 'PASS '.$label."\n";};
$deny=static function(callable $call,string $label)use($check){try{$call();}catch(DomainException){$check(true,$label);return;}throw new RuntimeException($label);};
Db::run($db,"INSERT INTO clubs VALUES('own-catalog-a','Own admission choices'),('own-catalog-b','Own private other club')");
foreach(['own-price-admin'=>'own-catalog-a','own-price-other'=>'own-catalog-b']as$actor=>$club)Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES(?,?,'members',1)",[$actor,$club]);
Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES('own-choice-board','own-catalog-a','member',0),('own-choice-reader','own-catalog-a','members',0)");
Db::run($db,"INSERT INTO admission_board(club_id,actor_id,office,enabled,version) VALUES('own-catalog-a','own-choice-board','Kassenwart',1,1)");
Database::ensureContributionRates($db);$intake=new MembershipApplications($db,'own-catalog-a','own-price-admin');
$config=$intake->settings()['config'];$config['enabled']=true;$config['notice_text']='Own original admission consent';$intake->saveSettings($config,0);
$families=[];$contacts=[];
for($i=0;$i<1003;$i++){
 $contact=hash('md5','own-admission-contact-'.$i);$family=hash('md5','own-admission-family-'.$i);$name=sprintf('Own person %04d',$i);$familyName=sprintf('Own family %04d',$i);if($i===1002){$name.=' %_! ä🙂';$familyName.=' %_! ä🙂';}
 Db::run($db,"INSERT INTO contacts(id,club_id,kind,name,email,address,notes) VALUES(?,'own-catalog-a','person',?,?,?,?)",[$contact,$name,'choice-'.$i.'@example.invalid','PRIVATE-ADDRESS-'.$i,'PRIVATE-NOTE-'.$i]);
 Db::run($db,"INSERT INTO member_families(club_id,id,name,payer_contact_id) VALUES('own-catalog-a',?,?,?)",[$family,$familyName,$contact]);$families[]=$family;$contacts[]=$contact;
}
$foreignContact=hash('md5','own-foreign-contact');$foreignFamily=hash('md5','own-foreign-family');
Db::run($db,"INSERT INTO contacts(id,club_id,kind,name) VALUES(?,'own-catalog-b','person','FOREIGN-PRIVATE-PERSON')",[$foreignContact]);
Db::run($db,"INSERT INTO member_families(club_id,id,name,payer_contact_id) VALUES('own-catalog-b',?,'FOREIGN-PRIVATE-FAMILY',?)",[$foreignFamily,$foreignContact]);
$all=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$before=ownOriginalDatabaseHash($db,$all);
foreach(['family','contact']as$kind){
 $first=$intake->admissionChoicePage($kind);$check($first['total']===1003&&$first['pages']===168&&count($first['rows'])===6,'all '.$kind.' identities counted with six results');
 $ids=[];for($page=1;$page<=$first['pages'];$page++)array_push($ids,...array_column($intake->admissionChoicePage($kind,['page'=>$page])['rows'],'id'));
 $expected=$kind==='family'?$families:$contacts;$check($ids===$expected&&count(array_unique($ids))===1003,'each own '.$kind.' appears exactly once across 168 pages');
 $check($intake->admissionChoicePage($kind,['query'=>'%_!'])['total']===1&&$intake->admissionChoicePage($kind,['query'=>'ä🙂'])['total']===1,'literal wildcard-like Unicode '.$kind.' search reaches last original');
 $selected=$kind==='family'?$families[1002].':1':$contacts[1002];$page=$intake->admissionChoicePage($kind,['selected'=>$selected]);$check($page['selected']['id']===$expected[1002]&&count($page['rows'])===6,'held '.$kind.' remains identified outside first results');
 $html=AdmissionChoicePicker::render($intake,$kind,[$kind==='family'?'family_target':'existing_contact'=>$selected]);
 $check(str_contains($html,'value="'.$selected.'" selected')&&!str_contains($html,'PRIVATE-ADDRESS')&&!str_contains($html,'PRIVATE-NOTE')&&!str_contains($html,'FOREIGN-PRIVATE'),'bounded '.$kind.' selector preserves own held selection without profile payload');
 foreach([['query'=>[]],['query'=>"\0"],['page'=>'0'],['page'=>[]],['selected'=>[]]]as$bad)$deny(fn()=>$intake->admissionChoicePage($kind,$bad),'malformed '.$kind.' controls rejected');
 $deny(fn()=>$intake->admissionChoicePage($kind,['selected'=>$kind==='family'?$foreignFamily.':1':$foreignContact]),'foreign held '.$kind.' is never disclosed');
}
$held=['action'=>'review-application','id'=>hash('md5','own-review'),'version'=>'3','decision'=>'accepted','decision_date'=>'2026-10-15','external_decision'=>'1','start'=>'2026-10-15','number'=>'HUMAN-123','review_note'=>"Own exact human\nreview note ä🙂",'family_target'=>$families[1002].':1','existing_contact'=>$contacts[1002],'admission_family_page'=>'1','admission_browse'=>'family:next'];$_POST=$held;AdmissionChoicePicker::browse($intake,$_POST);
$check($_POST['admission_family_page']==='2'&&array_diff_assoc(array_diff_key($held,['admission_family_page'=>true]),$_POST)===[],'browsing changes only its own page while keeping complete original decision fields');
$_POST=array_replace($held,['admission_browse'=>'family:search','admission_family_query'=>'%_!','admission_family_page'=>'100']);AdmissionChoicePicker::browse($intake,$_POST);$check($_POST['admission_family_page']==='1'&&$_POST['family_target']===$held['family_target'],'new search starts first page and retains exact chosen family version');
$clear=AdmissionChoicePicker::render($intake,'family',['family_target'=>''],$families[0]);$check(!str_contains($clear,'value="'.$families[0].':1" selected'),'conscious empty selection is not replaced by an earlier direct family link');
$stale=AdmissionChoicePicker::render($intake,'family',['family_target'=>$families[1002].':99']);$check(str_contains($stale,'value="'.$families[1002].':99" selected')&&str_contains($stale,'inzwischen geändert'),'earlier family version is retained visibly rather than silently refreshed');
$deny(fn()=>(new MembershipApplications($db,'own-catalog-a','own-choice-reader'))->admissionChoicePage('family'),'general members role cannot enumerate private admission identities');
$check((new MembershipApplications($db,'own-catalog-a','own-choice-board'))->admissionChoicePage('contact')['total']===1003,'current personally assigned board has original admission scope');
Db::run($db,"UPDATE admission_board SET enabled=0 WHERE actor_id='own-choice-board'");$deny(fn()=>(new MembershipApplications($db,'own-catalog-a','own-choice-board'))->admissionChoicePage('family'),'withdrawn personal board office immediately loses selection');Db::run($db,"UPDATE admission_board SET enabled=1 WHERE actor_id='own-choice-board'");
$check(ownOriginalDatabaseHash($db,$all)===$before,'all own and foreign original tables remain byte exact after selection and failed scope checks');
if(getenv('DVM_ADMISSION_CHOICES_BROWSER')==='1'){
 $applications=[];foreach(['A','B']as$suffix)$applications[]=$intake->submit(['first_name'=>'Own admission browser '.$suffix,'last_name'=>'Original','birthday'=>'1990-01-02','email'=>'own-admission-'.$suffix.'@example.invalid','address'=>'Own applicant address','membership_type'=>'family_member','family_reference'=>'Own existing family'],bin2hex(random_bytes(16)),1,true,true,false,false,false);
 $targets=[];foreach([1002,1001]as$i){$contact=hash('md5','own-real-admission-'.$i);Db::run($db,"INSERT INTO contacts(id,club_id,kind,name,email) VALUES(?,'own-catalog-a','person',?,?)",[$contact,'Own selected applicant '.$i,'selected-'.$i.'@example.invalid']);$targets[]=['family'=>$families[$i],'familyQuery'=>sprintf('Own family %04d',$i),'contact'=>$contact,'contactQuery'=>'selected-'.$i.'@example.invalid'];}
 require __DIR__.'/support/catalog-pricing-browser-fixture.php';$browser=ownCatalogPricingBrowser($db,['applications'=>$applications,'targets'=>$targets]);
 $tables=array_values(array_diff($all,['native_auth_sessions']));$keys=[];$quote=chr(96);foreach($tables as$table){$columns=Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);$arguments=[];$where='';if($table==='membership_applications'){$where=' WHERE id NOT IN (?,?)';$arguments=$applications;}if($table==='member_families'){$where=' WHERE id NOT IN (?,?)';$arguments=array_column($targets,'family');}$values=Db::run($db,'SELECT '.$quote.implode($quote.','.$quote,$columns).$quote.' FROM '.$quote.$table.$quote.$where,$arguments)->fetchAll(PDO::FETCH_NUM);$keys[$table]=compact('columns','values');}
 $filter=static function(string$table)use($keys,$quote){$k=$keys[$table];if(!$k['values'])return[' WHERE 0=1',[]];$args=[];foreach($k['values']as$row)array_push($args,...$row);return[' WHERE ('.$quote.implode($quote.','.$quote,$k['columns']).$quote.') IN ('.implode(',',array_fill(0,count($k['values']),'('.implode(',',array_fill(0,count($k['columns']),'?')).')')).')',$args];};$originals=ownOriginalDatabaseHash($db,$tables,$filter);$beforeApps=array_map(fn($id)=>$intake->application($id),$applications);$keyHashes=array_map(fn($path)=>hash_file('sha256',$path),[$browser['root'].'/auth.key',$browser['root'].'/private.key']);
 try{$node=proc_open(['node',__DIR__.'/admission-choices-browser.mjs'],[0=>['pipe','r'],1=>['pipe','w']],$pipes);fwrite($pipes[0],json_encode($browser['input'],JSON_THROW_ON_ERROR));fclose($pipes[0]);while(($line=fgets($pipes[1]))!==false)echo$line;fclose($pipes[1]);if(proc_close($node)!==0)throw new RuntimeException('Actual admission choices browser failed.');
 $check(ownOriginalDatabaseHash($db,$tables,$filter)===$originals,'actual reads and two conscious admissions preserve every unrelated complete original row');
 foreach($applications as$i=>$id){$saved=$intake->application($id);$target=$targets[$i];$check($saved['status']==='accepted'&&$saved['contact_id']===$target['contact'],'actual JS/plain original admission links precisely its selected existing profile');foreach(['answers','configuration','consent_snapshot','request_key']as$field)$check($saved[$field]===$beforeApps[$i][$field],'actual accepted original preserves its '.$field);$family=Db::run($db,"SELECT p.family_id,f.version FROM member_family_people p JOIN member_families f ON f.club_id=p.club_id AND f.id=p.family_id WHERE p.club_id='own-catalog-a' AND p.contact_id=?",[$target['contact']])->fetch();$check($family['family_id']===$target['family']&&(int)$family['version']===2,'actual admission joins exactly its chosen original family once');}
 $check((int)Db::run($db,"SELECT COUNT(*) FROM memberships WHERE club_id='own-catalog-a'")->fetchColumn()===2&&(int)Db::run($db,"SELECT COUNT(*) FROM membership_applications WHERE club_id='own-catalog-a' AND status='pending'")->fetchColumn()===2,'two real manual pending originals create no premature membership');
 $check($keyHashes===array_map(fn($path)=>hash_file('sha256',$path),[$browser['root'].'/auth.key',$browser['root'].'/private.key']),'all actual browser actions preserve original private authentication and media keys');
 }
 finally{proc_terminate($browser['process']);proc_close($browser['process']);fclose($browser['log']);}
}
echo json_encode(['suite'=>'native-admission-choices','checks'=>$n,'identities_per_kind'=>1003,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
