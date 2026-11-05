<?php declare(strict_types=1);
use Kulturhus\Native\Shared\Db;
require_once __DIR__.'/free-list-interface-browser-fixture.php';
require_once __DIR__.'/voucher-mail-browser-originals.php';
$nbPeople=[$nmPeople['own'],$nmPeople['other']];$nbDataPeople=[];
$nbCurrentOwnVersion=(int)Db::run($db,'SELECT version FROM contacts WHERE club_id=? AND id=?',['own-list-club',$nmPeople['own']])->fetchColumn();
$nmRegistry->saveContact($nmPeople['own'],$nbCurrentOwnVersion,'person','Mail mit PDF vormerken','own-browser-mail@example.invalid','Own literal address 0');
for($nbI=0;$nbI<28;$nbI++){$nbPerson=$nmRegistry->saveContact(null,0,'person','Own native mail picker '.sprintf('%02d',$nbI).' ä🙂',$nbI===0?'':'own-native-picker-'.$nbI.'@example.invalid','Own fixture address');$nmRegistry->addMembership($nbPerson,'Own picker '.$nbI,'aktiv','2020-01-01');$nbPeople[]=$nbPerson;}
for($nbI=0;$nbI<28;$nbI++){$nbPerson=$nmRegistry->saveContact(null,0,'person','Own skipped native mail '.sprintf('%02d',$nbI),'own-native-skipped-'.$nbI.'@example.invalid','Own fixture address');$nmRegistry->addMembership($nbPerson,'Own skipped '.$nbI,'aktiv','2020-01-01');}
Db::run($db,"INSERT INTO actors(id,club_id,role) VALUES('own-native-mail-helper','own-list-club','member')");
$nbCases=[];$nbTemplate=['subject'=>'Mail mit PDF vormerken','message'=>"0\n{{NAME}}\n{{VERANSTALTUNG}}"];
for($nbI=0;$nbI<8;$nbI++){
 $nbDay=(new DateTimeImmutable('2048-06-01'))->modify('+'.$nbI.' days')->format('Y-m-d');
 $nbTitle='Own original native mail source '.$nbI.' <em data-own-mail>0</em> ä🙂';
 $nbSource=$nmRegistry->saveAppointment(null,0,['title'=>$nbTitle,'start'=>$nbDay.'T18:00','end'=>$nbDay.'T20:00','location'=>'Own original mail venue 0','description'=>str_repeat('Own private unselected mail body ',100),'category'=>'verein'],[]);
 $nbEvent=$nmVouchers->open($nbSource,$nmKey());$nbRecipients=array_slice($nbPeople,0,-1);$nmVouchers->grant($nbEvent,$nbRecipients,[['label'=>'0','kind'=>'other','unit'=>'piece','amount'=>'1']],$nmKey());$nmVouchers->configure($nbEvent,1,true,['own-native-mail-helper'],$nmKey());$nmMail->saveTemplate($nbEvent,0,$nbTemplate,$nmKey());
 $nbPreview=$nmMail->preview($nbEvent,$nmPeople['own']);
 $nbCases[]=['event'=>$nbEvent,'source'=>$nbSource,'title'=>$nbTitle,'person'=>$nmPeople['own'],'other_person'=>$nmPeople['other'],'snapshot'=>$nbPreview['snapshot'],'pdf_sha256'=>hash('sha256',\Kulturhus\Verein\EventVoucherPdf::fromSnapshot($nbPreview['snapshot']))];
}
$nbEnded=$nbPeople[count($nbPeople)-2];$nbMembership=Db::run($db,'SELECT id,version FROM memberships WHERE club_id=? AND contact_id=?',['own-list-club',$nbEnded])->fetch();$nmRegistry->endMembership($nbMembership['id'],(int)$nbMembership['version'],(new DateTimeImmutable('today'))->modify('-1 day')->format('Y-m-d'));
$nbFixture=ownFreeListInterfaceBrowser($db,[]);$nbRoot=$nbFixture['input']['root'];$nbAuth=\Kulturhus\Native\Auth\Runtime::service($db);$nbAdminBridge=new \Kulturhus\Native\Integration\ManagerBridge($db,'own-list-admin','native',static fn()=>null);$nbHelperBridge=new \Kulturhus\Native\Integration\ManagerBridge($db,'own-native-mail-helper','native',static fn()=>null);$nbHelperBridge->access();$nbInvitation=$nbAuth->createInvitation($nbAdminBridge->access(),$nbHelperBridge->nativeActorId(),'own-native-mail-helper@example.invalid');$nbAuth->acceptInvitation($nbInvitation['token'],$nbFixture['input']['password']);$nbFixture['input']['accounts']['helper']=['email'=>'own-native-mail-helper@example.invalid','actor'=>'own-native-mail-helper'];
$nbPlans=[];foreach($nbCases as$nbCase)$nbPlans[$nbCase['event']]=$nmMail->batchReview($nbCase['event']);
$nbFixture['input']['data']=['cases'=>$nbCases,'plans'=>$nbPlans,'people_count'=>$nmMail->peoplePage($nbCases[0]['event'])['total'],'original_event'=>$nmEvent,'original_job'=>$nmJob,'original_json_sha256'=>$nmOriginal['original_sha256'],'history_count'=>$nmMail->deliveriesPage($nmEvent)['total'],'template'=>$nbTemplate];
file_put_contents($nbRoot.'/fixture.json',json_encode($nbFixture['input'],JSON_THROW_ON_ERROR));
$nbProtected=array_values(array_diff($tables,['native_auth_sessions','native_auth_limits','native_language_preferences']));$nbBefore=ownOriginalDatabaseHash($db,$nbProtected);$nbPrivate=[];
$nbWriteMode=getenv('DVM_VOUCHER_MAIL_READONLY')!=='1';$nbOriginals=$nbWriteMode?ownMailBrowserOriginals($db,$nbProtected,$nbCases):null;
foreach(['auth','private']as$nbName)$nbPrivate[$nbName]=hash_file('sha256',$nbRoot.'/'.$nbName.'.key');
try{$nbProcess=proc_open(['node',dirname(__DIR__).'/voucher-mail-browser.mjs'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$nbPipes);fwrite($nbPipes[0],json_encode($nbFixture['input'],JSON_THROW_ON_ERROR));fclose($nbPipes[0]);if(proc_close($nbProcess)!==0)throw new RuntimeException('Actual native voucher mail browser failed.');}
finally{foreach($nbFixture['servers']as[$nbProcess,$nbLog]){proc_terminate($nbProcess);proc_close($nbProcess);fclose($nbLog);}}
if($nbWriteMode)ownMailBrowserVerify($db,$nbProtected,$nbCases,$nbOriginals,json_decode(file_get_contents($nbRoot.'/browser-actions.json'),true,512,JSON_THROW_ON_ERROR),$check);
else $check(ownOriginalDatabaseHash($db,$nbProtected)===$nbBefore,'native readonly mail pages original downloads language changes and private preview preparation preserve every complete earlier business row');
foreach($nbPrivate as$nbName=>$nbHash)$check(hash_file('sha256',$nbRoot.'/'.$nbName.'.key')===$nbHash,'native mail browsing retains the exact private '.$nbName.' key');
