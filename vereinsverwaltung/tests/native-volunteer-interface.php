<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
require __DIR__.'/native-document-interface.php';
require_once dirname(__DIR__).'/src/People/Members/VolunteerFormState.php';
use Kulturhus\Verein\{Registry,Settings,SettingsSchema,VolunteerForm,VolunteerFormState,DocumentPreview,BoardSignature,AdmissionVotes};
use Kulturhus\Native\{TestSupport,Shared\Db};

$vfRegistry=new Registry($db,'own-list-admin');
$vfOwnPerson=$vfRegistry->saveContact(null,0,'person','Own Original Member','same@example.invalid',"Own street 1\n00000 Own city");
$vfOtherPerson=$vfRegistry->saveContact(null,0,'person','Own Different Member','same@example.invalid',"Other street 2\n00000 Other city");
$vfChair=$vfRegistry->saveContact(null,0,'person','Own First Chair','','');
$vfRegistry->addMembership($vfOwnPerson,'0','aktiv','2020-01-01');
$vfRegistry->addMembership($vfOtherPerson,'Own passive 0','passiv','2020-01-01');
Db::run($db,"UPDATE actors SET contact_id=? WHERE id='own-list-reader'",[$vfOwnPerson]);
Db::run($db,"UPDATE actors SET contact_id=? WHERE id='own-list-admin'",[$vfChair]);
Db::run($db,"UPDATE settings_roles SET finance_access='none',members_access='none',documents_access='none' WHERE club_id='own-list-club' AND id='own-list-reader'");
Db::run($db,"INSERT INTO actors(id,club_id,role,contact_id) VALUES('own-volunteer-passive','own-list-club','member',?)",[$vfOtherPerson]);
(new Settings($db,'own-list-admin'))->save('club',array_replace(SettingsSchema::defaults('club'),['name'=>'Own original club 0','street'=>'Own club street 1','postcode'=>'00000','city'=>'Own original city']),0);
(new AdmissionVotes($db,'own-list-admin'))->grant('own-list-admin',0,'1. Vorstand',true,true);
for($vfIndex=0;$vfIndex<29;$vfIndex++){
 $vfChoice=$vfRegistry->saveContact(null,0,'person','Own choice '.sprintf('%02d',$vfIndex).' <em data-own-person>0</em> ä🙂','','');
 $vfRegistry->addMembership($vfChoice,'Own choice member '.$vfIndex,'aktiv','2020-01-01');
}
$vfService=new VolunteerForm($db,'own-list-reader','general');
$vfAdmin=new VolunteerForm($db,'own-list-admin','general');
$vfHygiene=new VolunteerForm($db,'own-list-reader');
$vfInput=['first_name'=>'Own','last_name'=>'Original Member','birthday'=>'2000-01-01','street'=>'Own street 1','city'=>'00000 Own city','activity'=>"0\nOwn original activity <em data-own-activity>ä🙂</em>",'effort'=>'0'];
$vfData=$vfService->build($vfOwnPerson,$vfInput,false);
$vfBefore=ownOriginalDatabaseHash($db,$tables);
$vfPicker=$vfAdmin->peoplePage();
$check($vfPicker['total']===31&&count($vfPicker['rows'])===25&&count($vfAdmin->peoplePage(['page'=>2])['rows'])===6,'native current membership person pages reach every exact own-club name in twenty-five-name windows');
$check($vfAdmin->peoplePage(['query'=>'<em data-own-person>0</em>'])['total']===29,'native person search preserves literal human markup as text');
$deny(fn()=>$vfService->peoplePage(),'ordinary member cannot enumerate a native person page');
foreach([0,'0','01',[],null,-1,'1000000000']as$vfBad)$deny(fn()=>$vfAdmin->peoplePage(['page'=>$vfBad]),'invalid native person page has no arbitrary first-page replacement');
$deny(fn()=>$vfAdmin->peoplePage(['query'=>str_repeat('🙂',241)]),'whole overlong Unicode person search is refused without clipping');
$check($vfService->build($vfOwnPerson,array_replace($vfInput,['first_name'=>str_repeat('🙂',180)]),false)['volunteer_general']['values'][1]===str_repeat('🙂',180),'full one-hundred-eighty Unicode name characters reach the original form data without a byte limit');
$deny(fn()=>$vfService->build($vfOwnPerson,array_replace($vfInput,['first_name'=>str_repeat('🙂',181)]),false),'one-hundred-eighty-one Unicode name characters remain a correctable refusal');
$check($vfService->build($vfOwnPerson,array_replace($vfInput,['activity'=>str_repeat('🙂',1800)]),false)['volunteer_general']['activity']===str_repeat('🙂',1800),'all eighteen-hundred original Unicode activity characters survive validation');
$deny(fn()=>$vfService->build($vfOwnPerson,array_replace($vfInput,['activity'=>str_repeat('🙂',1801)]),false),'overlong native activity is rejected without replacing its earlier original');
$check(ownOriginalDatabaseHash($db,$tables)===$vfBefore,'person paging searches and all exact field validation preserve every complete business row');

$vfPreview=DocumentPreview::pdf($vfData);usleep(1100000);
$vfKey=bin2hex(random_bytes(16));$vfHash=VolunteerForm::digest($vfData);
$vfDocument=$vfService->save($vfOwnPerson,$vfInput,false,$vfKey,$vfHash);
$check($vfService->file($vfDocument)['bytes']===$vfPreview,'actual reviewed general PDF and later filed original are byte-for-byte identical across renderer timestamps');
$vfReceipt=$vfService->confirmation($vfKey);
$check($vfReceipt['document_id']===$vfDocument&&$vfReceipt['intent']['input']['effort']==='0'&&$vfReceipt['snapshot']===$vfData,'native personal filing confirmation reads the exact first intention and complete original snapshot');
$vfAfterSave=ownOriginalDatabaseHash($db,$tables);
$check($vfService->save($vfOwnPerson,$vfInput,false,$vfKey,$vfHash)===$vfDocument&&ownOriginalDatabaseHash($db,$tables)===$vfAfterSave,'same confirmed request returns its original without a second document share notification history or audit');
$deny(fn()=>$vfService->save($vfOwnPerson,array_replace($vfInput,['effort'=>'Changed']),false,$vfKey,$vfHash),'same native request cannot be reused for another complete input');
$deny(fn()=>(new VolunteerForm($db,'own-volunteer-passive','general'))->file($vfDocument),'same email never grants another person access to an original certificate');
$deny(fn()=>$vfAdmin->confirmation($vfKey),'general administrator cannot take another creating account original filing confirmation');
$check(ownOriginalDatabaseHash($db,$tables)===$vfAfterSave,'all personal confirmation replay and refused cross-account reads leave every original business row exact');

$vfHygieneInput=array_intersect_key($vfInput,array_flip(VolunteerForm::FIELDS));
$vfHygieneData=$vfHygiene->build($vfOwnPerson,$vfHygieneInput,false);$vfHygienePreview=DocumentPreview::pdf($vfHygieneData);
$vfHygieneDocument=$vfHygiene->save($vfOwnPerson,$vfHygieneInput,false,bin2hex(random_bytes(16)),VolunteerForm::digest($vfHygieneData));
$check($vfHygiene->file($vfHygieneDocument)['bytes']===$vfHygienePreview,'unchanged official hygiene form retains the complete reviewed unsigned original bytes');

$vfConfig=TestSupport::config();$vfDsn='mysql:host='.$vfConfig['host'].';port='.$vfConfig['port'].';dbname='.$vfConfig['database'].';charset=utf8mb4';
$vfOptions=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$vfCaller=new PDO($vfDsn,$vfConfig['username'],$vfConfig['password'],$vfOptions);
$vfCaller->beginTransaction();$vfCaller->query('SELECT * FROM contacts')->fetchAll();Db::run($db,'UPDATE contacts SET archived_at=?,version=version+1 WHERE id=?',[gmdate('c'),$vfOwnPerson]);$vfArchived=ownOriginalDatabaseHash($db,$tables);
$deny(fn()=>(new VolunteerForm($vfCaller,'own-list-reader','general'))->build($vfOwnPerson,$vfInput,false),'actual second connection cannot prepare an archived own profile from an older caller snapshot');
$check($vfCaller->inTransaction(),'refused original profile read preserves its caller transaction');$vfCaller->rollBack();
$check(ownOriginalDatabaseHash($db,$tables)===$vfArchived,'archived own-profile refusal changes no current original row');Db::run($db,'UPDATE contacts SET archived_at=NULL WHERE id=?',[$vfOwnPerson]);
$vfChangedProfile=$vfService->build($vfOwnPerson,$vfInput,false);
$deny(fn()=>$vfService->save($vfOwnPerson,$vfInput,false,bin2hex(random_bytes(16)),$vfHash),'new document cannot use the earlier preview after a real profile version change');
$vfBeforeReplay=ownOriginalDatabaseHash($db,$tables);
$check($vfService->save($vfOwnPerson,$vfInput,false,$vfKey,$vfHash)===$vfDocument&&$vfService->confirmation($vfKey)['snapshot']===$vfData&&ownOriginalDatabaseHash($db,$tables)===$vfBeforeReplay,'already confirmed original remains separately recoverable after current profile changes without a replacement filing');

$vfCaller->beginTransaction();$vfCaller->query('SELECT * FROM memberships')->fetchAll();Db::run($db,"UPDATE memberships SET status='passiv',version=version+1 WHERE contact_id=?",[$vfOwnPerson]);
$vfCurrentMembership=(new VolunteerForm($vfCaller,'own-list-reader','general'))->build($vfOwnPerson,$vfInput,false);
$check($vfCurrentMembership['volunteer_general']['membership']['status']==='passiv'&&$vfCurrentMembership['volunteer_general']['activity']===''&&$vfCaller->inTransaction(),'current passive membership replaces an older caller snapshot without asserting active work or committing the caller');$vfCaller->rollBack();Db::run($db,"UPDATE memberships SET status='aktiv',version=version+1 WHERE contact_id=?",[$vfOwnPerson]);

$vfCaller->beginTransaction();$vfCurrent=new VolunteerForm($vfCaller,'own-list-reader','general');$vfCurrentData=$vfCurrent->build($vfOwnPerson,$vfInput,false);$vfCallerKey=bin2hex(random_bytes(16));$vfBeforeCaller=ownOriginalDatabaseHash($db,$tables);
$vfCallerDocument=$vfCurrent->save($vfOwnPerson,$vfInput,false,$vfCallerKey,VolunteerForm::digest($vfCurrentData));
$check($vfCaller->inTransaction()&&$vfCurrent->confirmation($vfCallerKey)['document_id']===$vfCallerDocument,'native certificate save composes inside the caller transaction using its own savepoint');$vfCaller->rollBack();
$check(ownOriginalDatabaseHash($db,$tables)===$vfBeforeCaller,'caller rollback removes only its deliberate pending certificate and preserves every accepted business row');

$vfState=VolunteerFormState::prepare($db,'own-list-admin',['volunteer_kind'=>'general','volunteer_person_page'=>'0'],[],[]);
$check($vfState['http_status']===422&&$vfState['picker']===null&&$vfState['prefill']===null,'native invalid person window has its own error and no unrelated person form');
$vfState=VolunteerFormState::prepare($db,'own-list-reader',['volunteer_kind'=>'general'],['action'=>'volunteer-preview','contact'=>$vfOwnPerson]+array_replace($vfInput,['activity'=>str_repeat('🙂',1801)]),[]);
$check($vfState['values']['activity']===str_repeat('🙂',1801),'native rejected proposal retains every original Unicode character for correction');
$vfBadKind=VolunteerFormState::prepare($db,'own-list-reader',['volunteer_kind'=>['general']],[],[]);
$check($vfBadKind['http_status']===422&&$vfBadKind['service']===null&&$vfBadKind['prefill']===null,'malformed certificate type cannot fall back to another legal form');

require __DIR__.'/support/volunteer-original-tests.php';
if(getenv('DVM_VOLUNTEER_INTERFACE_BROWSER')==='1')require __DIR__.'/support/volunteer-interface-browser-fixture.php';
echo json_encode(['suite'=>'native-volunteer-interface','checks'=>$n,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
