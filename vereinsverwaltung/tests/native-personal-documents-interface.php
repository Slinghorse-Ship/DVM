<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
require __DIR__.'/native-document-interface.php';
use Kulturhus\Verein\{PortalDocuments,Registry,Settings,SettingsSchema,ContributionInvoices,EventVouchers};
use Kulturhus\Native\{TestSupport,Shared\Db};
$pdRegistry=new Registry($db,'own-list-admin');$pdSettings=new Settings($db,'own-list-admin');
$pdPerson=$pdRegistry->saveContact(null,0,'person','Own personal recipient ä🙂','shared-personal@example.invalid','Own address 1, 00000 Own city');
$pdSecond=$pdRegistry->saveContact(null,0,'person','Own different same-email recipient','shared-personal@example.invalid','Own address 2, 00000 Own city');
$pdRegistry->addMembership($pdPerson,'','aktiv','2020-01-01');$pdRegistry->addMembership($pdSecond,'','passiv','2020-01-01');
Db::run($db,'UPDATE actors SET contact_id=? WHERE id IN (?,?)',[$pdPerson,'own-list-reader','own-list-admin']);
Db::run($db,"UPDATE settings_roles SET finance_access='none',members_access='none',documents_access='none' WHERE club_id='own-list-club' AND id='own-list-reader'");
Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser,contact_id) VALUES('own-personal-other','own-list-club','member',0,?)",[$pdSecond]);
$pdOwn=new PortalDocuments($db,'own-list-reader');$pdAdmin=new PortalDocuments($db,'own-list-admin');$pdOther=new PortalDocuments($db,'own-personal-other');
$pdInvoiceInput=['kind'=>'contribution','description'=>'Own literal issued invoice <em data-own-invoice>0</em> ä🙂','amount'=>'72.00','category'=>'membership','document_date'=>'2026-10-20','due_date'=>'2026-11-01','notes'=>json_encode(['payer'=>$pdPerson,'year'=>2026],JSON_THROW_ON_ERROR)];
$pdEntry=$finance->createEntry($pdInvoiceInput,$key());$pdIssuer=new ContributionInvoices($db,'own-list-admin');$pdDraft=$pdIssuer->issue($pdEntry);$pdDraftFile=$docs->file($pdDraft['document_id'],1);
$check($pdDraft['state']==='draft'&&$pdOwn->listing()===[],'actual incomplete contribution draft is not a personal issued original');
$pdCompany=array_replace(SettingsSchema::defaults('club'),['name'=>'Own synthetic personal club','street'=>'Own street 1','postcode'=>'00000','city'=>'Own city']);$pdSettings->save('club',$pdCompany,0);
$pdAccount=$finance->createAccount('Own personal invoice account','offline','0','2026-01-01');
$pdBank=array_replace(SettingsSchema::defaults('bank'),['holder'=>'Own synthetic personal club','iban'=>'DE89370400440532013000','bic'=>'COBADEFFXXX','account_id'=>$pdAccount]);$pdSettings->save('bank',$pdBank,0);
$pdIssued=$pdIssuer->issue($pdEntry);$pdIssuedFile=$docs->file($pdIssued['document_id'],2);
$check($pdIssued['state']==='issued'&&$pdOwn->file($pdIssued['document_id'])['bytes']===$pdIssuedFile['bytes']&&$docs->file($pdIssued['document_id'],1)===$pdDraftFile,'actual personal invoice reads its first issued second revision and preserves the earlier incomplete draft exactly');
for($pdIndex=0;$pdIndex<29;$pdIndex++){$pdData=array_replace($pdInvoiceInput,['description'=>'Own original personal invoice '.sprintf('%02d',$pdIndex),'notes'=>json_encode(['payer'=>$pdPerson,'year'=>1997+$pdIndex],JSON_THROW_ON_ERROR)]);$pdIssuer->issue($finance->createEntry($pdData,$key()));}
$pdForeignInvoice=$pdIssuer->issue($finance->createEntry(array_replace($pdInvoiceInput,['description'=>'Own other recipient private invoice','notes'=>json_encode(['payer'=>$pdSecond,'year'=>2026],JSON_THROW_ON_ERROR)]),$key()));
$pdShared=[];for($pdIndex=0;$pdIndex<30;$pdIndex++){$pdDoc=$docs->upload('Own personal original '.$pdIndex.'.pdf',$pdf('personal first '.$pdIndex),['title'=>$pdIndex===0?'Nachweis erfassen':($pdIndex===1?str_repeat('🙂',300):'Own original personal source '.sprintf('%02d',$pdIndex).' <em data-own-shared>0</em> ä🙂'),'kind'=>'other','scope'=>'finance','notes'=>'0','document_date'=>'2026-10-20'],$key());$pdAdmin->share($pdDoc,$pdPerson,true,1,1);$pdShared[]=$pdDoc;}
$docs->newVersion($pdShared[0],2,'Own later unshared original.pdf',$pdf('personal later'),'Own later original not granted');
$pdOtherDoc=$docs->upload('Own other recipient.pdf',$pdf('other private'),['title'=>'Own private other recipient source','kind'=>'other','scope'=>'finance','document_date'=>'2026-10-20'],$key());$pdAdmin->share($pdOtherDoc,$pdSecond,true,1,1);
$pdVoucherService=new EventVouchers($db,'own-list-admin');$pdVoucherEvents=[];
for($pdIndex=0;$pdIndex<28;$pdIndex++){
 $pdStart=(new DateTimeImmutable('2040-01-01'))->modify('+'.$pdIndex.' days')->format('Y-m-d');
 $pdSource=$pdRegistry->saveAppointment(null,0,['title'=>'Own personal event '.sprintf('%02d',$pdIndex).' <em data-own-voucher>0</em> ä🙂','start'=>$pdStart.'T18:00','end'=>$pdStart.'T20:00','location'=>'Own actual event venue','description'=>str_repeat('Own original body ',400),'category'=>'verein'],[]);
 $pdEvent=$pdVoucherService->open($pdSource,$key());$pdVoucherService->grant($pdEvent,[$pdPerson],[['label'=>'0','kind'=>'other','unit'=>'piece','amount'=>'1'],['label'=>'Own exact human euro label ä🙂','kind'=>'other','unit'=>'eur','amount'=>'3.50']],$key());$pdVoucherEvents[]=$pdEvent;
}
$pdVoucherService->grant($pdVoucherEvents[0],[$pdSecond],[['label'=>'Own other recipient private benefit','kind'=>'other','unit'=>'piece','amount'=>'1']],$key());
$pdBefore=ownOriginalDatabaseHash($db,$tables);$pdInvoices=$pdOwn->invoicePage();$pdShares=$pdOwn->sharePage();$pdVouchers=$pdOwn->voucherPage();
$check($pdInvoices['total']===30&&count($pdInvoices['rows'])===25&&count($pdOwn->invoicePage(['page'=>2])['rows'])===5,'native personal invoice pages count and reach every issued own original without a shared-email invoice');
$check($pdShares['total']===30&&count($pdShares['rows'])===25&&count($pdOwn->sharePage(['page'=>2])['rows'])===5,'native personal share pages count every own pinned original without another recipient source');
$check($pdVouchers['total']===28&&count($pdVouchers['rows'])===25&&count($pdOwn->voucherPage(['page'=>2])['rows'])===3,'native personal event pages reach all own original benefits without loading other recipient balances');
$check($pdAdmin->invoicePage()===$pdInvoices&&$pdAdmin->sharePage()===$pdShares&&$pdAdmin->voucherPage()===$pdVouchers,'linked administrator personal lists retain exactly their own profile despite general finance document and redemption rights');
$check($pdOwn->sharePage(['query'=>'Nachweis erfassen'])['rows'][0]['title']==='Nachweis erfassen'&&$pdOwn->file($pdShared[0])['bytes']===$pdf('personal first 0'),'literal human title stays original and personal grant downloads its reviewed first revision after a later unshared file');
$check($pdOwn->voucherPage(['query'=>'Own exact human euro'])['total']===28&&$pdVouchers['rows'][0]['benefit_rows'][0]===['label'=>'0','remaining'=>1,'unit'=>'piece'],'personal voucher search and structured exact units preserve literal human zero without parsing translated display text');
foreach([0,'0','01',-1,[],null,'1000000000']as$pdBad)foreach(['invoicePage','sharePage','voucherPage']as$pdMethod)$deny(fn()=>$pdOwn->$pdMethod(['page'=>$pdBad]),'invalid personal metadata page never becomes an unrelated first page');
foreach(['invoicePage','sharePage','voucherPage']as$pdMethod)$deny(fn()=>$pdOwn->$pdMethod(['query'=>str_repeat('🙂',241)]),'entire overlong Unicode personal search is refused without clipping');
$deny(fn()=>$pdOwn->file($pdForeignInvoice['document_id']),'same-email recipient cannot download another personal issued invoice');
$deny(fn()=>$pdOwn->file($pdOtherDoc),'same-email recipient cannot download another personal granted source');
$check(str_starts_with($pdOwn->voucherFile($pdVoucherEvents[0]),'%PDF-'),'own voucher print uses only its complete current personal snapshot without issuing or redeeming a card');
$check(ownOriginalDatabaseHash($db,$tables)===$pdBefore,'all personal metadata searches original downloads and own printing preserve every complete business row balance source file history and notification');
$pdConfig=TestSupport::config();$pdDsn='mysql:host='.$pdConfig['host'].';port='.$pdConfig['port'].';dbname='.$pdConfig['database'].';charset=utf8mb4';$pdOptions=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$pdCaller=new PDO($pdDsn,$pdConfig['username'],$pdConfig['password'],$pdOptions);$pdCaller->beginTransaction();$pdCaller->query('SELECT * FROM contacts')->fetchAll();Db::run($db,'UPDATE contacts SET archived_at=? WHERE id=?',[gmdate('c'),$pdPerson]);$pdBefore=ownOriginalDatabaseHash($db,$tables);$pdDenied=false;try{(new PortalDocuments($pdCaller,'own-list-reader'))->file($pdShared[0]);}catch(DomainException){$pdDenied=true;}$check($pdDenied&&$pdCaller->inTransaction(),'actual second connection cannot expose an archived own profile through an earlier caller snapshot');$pdCaller->rollBack();$check(ownOriginalDatabaseHash($db,$tables)===$pdBefore,'archived profile refusal preserves the complete accepted archive and every original business row');Db::run($db,'UPDATE contacts SET archived_at=NULL WHERE id=?',[$pdPerson]);
$pdRevokeDoc=$docs->upload('Own revocation probe.pdf',$pdf('own revocation probe'),['title'=>'Own dedicated revocation source','kind'=>'other','scope'=>'finance','document_date'=>'2026-10-20'],$key());$pdAdmin->share($pdRevokeDoc,$pdPerson,true,1,1);$pdCaller->beginTransaction();$pdCaller->query('SELECT * FROM portal_document_shares')->fetchAll();$pdReview=$docs->document($pdRevokeDoc);$pdAdmin->share($pdRevokeDoc,$pdPerson,false,$pdReview['version'],$pdReview['revision']);$pdBefore=ownOriginalDatabaseHash($db,$tables);$pdDenied=false;try{(new PortalDocuments($pdCaller,'own-list-reader'))->file($pdRevokeDoc);}catch(DomainException){$pdDenied=true;}$check($pdDenied&&$pdCaller->inTransaction(),'actual second connection cannot retain a revoked personal file through an earlier caller snapshot');$pdCaller->rollBack();$check(ownOriginalDatabaseHash($db,$tables)===$pdBefore,'revoked grant refusal preserves the complete current grant state original files and all business rows');
$pdStateClass=\Kulturhus\Verein\Documents\PersonalDocumentState::class;
$pdBefore=ownOriginalDatabaseHash($db,$tables);$pdState=$pdStateClass::prepare($pdOwn,[]);
$check($pdState['http_status']===0&&$pdState['actor']['contact_id']===$pdPerson&&$pdState['pages']['invoices']['total']===30&&$pdState['pages']['shared']['total']===30,'all personal sections are prepared under one actual current identity without borrowing general rights');
$pdBadState=$pdStateClass::prepare($pdOwn,['personal_invoice_page'=>'0']);
$check($pdBadState['http_status']===422&&$pdBadState['pages']['invoices']===null&&$pdBadState['pages']['shared']['total']===30,'invalid own invoice page exposes no substitute invoices and keeps independently authorized shared originals');
$pdLongQuery=str_repeat('🙂',241);$pdBadState=$pdStateClass::prepare($pdOwn,['personal_shared_query'=>$pdLongQuery]);
$check($pdBadState['http_status']===422&&$pdBadState['filters']['shared']['query']===$pdLongQuery&&$pdBadState['pages']['shared']===null,'native personal state retains the whole invalid Unicode search without unrelated first-page originals');
$pdUnlinked=$pdStateClass::prepare(new PortalDocuments($db,'own-list-foreign'),[]);
$check($pdUnlinked['profile_error']!==''&&$pdUnlinked['http_status']===0&&$pdUnlinked['pages']['invoices']===null,'unlinked member-profile explanation does not invent empty successful invoices or block independent actor-bound signed originals');
$db->beginTransaction();try{$pdCurrent=$pdStateClass::prepare($pdOwn,[]);$check($db->inTransaction()&&$pdCurrent['actor']['contact_id']===$pdPerson,'personal page preparation preserves the existing caller-owned transaction');}finally{$db->rollBack();}
$check(ownOriginalDatabaseHash($db,$tables)===$pdBefore,'all native personal state errors searches profile explanations and caller preparations preserve every complete business row');
$pdMoney=\Kulturhus\Verein\Documents\PersonalDocumentText::class;
$check($pdMoney::money(0,'de')==='0,00 €'&&$pdMoney::money(0,'en')==='€0.00','native personal money shows exact zero cents in either interface language');
$check($pdMoney::money(-1,'de')==='-0,01 €'&&$pdMoney::money(-1,'en')==='-€0.01','native personal money retains the exact negative cent without rounding');
$check($pdMoney::money(9007199254740993,'en')==='€90,071,992,547,409.93'&&$pdMoney::money(9007199254740993,'de')==='90.071.992.547.409,93 €','personal amount rendering preserves exact cents beyond floating-point integer precision');
$check($pdMoney::money(PHP_INT_MIN,'en')==='-€92,233,720,368,547,758.08','signed minimum integer cents remain exact without absolute-value overflow');
$pdBlocker=new PDO($pdDsn,$pdConfig['username'],$pdConfig['password'],$pdOptions);$pdBlocker->beginTransaction();Db::run($pdBlocker,'SELECT id FROM documents WHERE club_id=? AND id=? FOR UPDATE',['own-list-club',$pdShared[0]])->fetch();
$pdCaller->beginTransaction();$pdBusy=false;$pdStarted=microtime(true);try{(new PortalDocuments($pdCaller,'own-list-reader'))->file($pdShared[0]);}catch(DomainException $failure){$pdBusy=$failure->getCode()===503;}$pdDuration=microtime(true)-$pdStarted;
$check($pdBusy&&$pdDuration<2&&$pdCaller->inTransaction()&&(int)$pdCaller->query('SELECT 1')->fetchColumn()===1,'actual competing original row lock fails the private read promptly and preserves the caller transaction instead of waiting in a lock cycle');
$pdCaller->rollBack();$pdBlocker->rollBack();$check(ownOriginalDatabaseHash($db,$tables)===$pdBefore,'refused busy private read changes no original grant file metadata transaction or business row');
$pdStorageProtected=false;try{Db::run($db,'UPDATE document_files SET bytes=? WHERE document_id=? AND revision=1',['Own deliberately changed private bytes',$pdShared[0]]);}catch(PDOException $failure){$pdStorageProtected=($failure->errorInfo[0]??'')==='45000';}
$check($pdStorageProtected&&ownOriginalDatabaseHash($db,$tables)===$pdBefore,'native storage rejects changing any immutable original file before a personal reader can receive it');
final class OwnPersonalDamagedRead extends PDOStatement{
 protected function __construct(){}
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{
  $row=parent::fetch($mode,$cursorOrientation,$cursorOffset);
  if(is_array($row)&&array_key_exists('bytes',$row)&&array_key_exists('sha256',$row))$row['bytes']='Own simulated changed bytes after the original storage read';
  return $row;
 }
}
$pdDamagedConnection=new PDO($pdDsn,$pdConfig['username'],$pdConfig['password'],$pdOptions);$pdDamagedConnection->setAttribute(PDO::ATTR_STATEMENT_CLASS,[OwnPersonalDamagedRead::class,[]]);
$deny(fn()=>(new PortalDocuments($pdDamagedConnection,'own-list-reader'))->file($pdShared[0]),'changed bytes returned by a source read cannot be downloaded under their earlier original fingerprint');
$check(ownOriginalDatabaseHash($db,$tables)===$pdBefore,'refused damaged source read neither repairs nor replaces any complete original file or business row');
$pdPayment=$finance->createTransaction(['account'=>$pdAccount,'booked_on'=>'2026-10-20','direction'=>'income','amount'=>'20','description'=>'Own exact partial personal payment'],$key());$finance->allocate($pdPayment,1,$pdEntry,$finance->entry($pdEntry)['version'],'20',$key());
$check(array_column($pdOwn->listing(),null,'id')[$pdIssued['document_id']]['payment_state']==='partial'&&$pdOwn->file($pdIssued['document_id'])['bytes']===$pdIssuedFile['bytes'],'personal partial state follows an actual current allocation while its first issued original PDF remains byte exact');
$pdPayment=$finance->createTransaction(['account'=>$pdAccount,'booked_on'=>'2026-10-20','direction'=>'income','amount'=>'52','description'=>'Own exact remaining personal payment'],$key());$finance->allocate($pdPayment,1,$pdEntry,$finance->entry($pdEntry)['version'],'52',$key());
$check(array_column($pdOwn->listing(),null,'id')[$pdIssued['document_id']]['payment_state']==='paid'&&$pdOwn->file($pdIssued['document_id'])['bytes']===$pdIssuedFile['bytes'],'personal paid state requires the exact remaining real allocation without rewriting the original invoice');
$pdPages=new \Kulturhus\Native\Cms\Pages($db,$access);$pdWebsite=\Kulturhus\Verein\WebsiteCalendar::fromNative($db,'own-list-club',$access->club(),'https://own-personal-calendar.example.invalid');
$pdPublicInput=['slug'=>'own-personal-public-event','title'=>'Own original public personal event <em data-own-public>0</em> ä🙂','template'=>'event','event_start'=>'2032-06-01T00:15','event_end_local'=>'2032-06-01T01:15','event_location'=>'Own public original venue','body'=>str_repeat('Own original public body ',300)];
$pdPublic=$pdPages->save($pdPublicInput);$pdPublic=$pdPages->publish($pdPublic['id'],1);$pdPublicId=$pdPublic['id'];$pdWebService=new EventVouchers($db,'own-list-admin',$pdWebsite);$pdWebEvent=$pdWebService->open('website:'.$pdPublicId,$key());$pdWebService->grant($pdWebEvent,[$pdPerson],[['label'=>'Own public original benefit','kind'=>'other','unit'=>'piece','amount'=>'1']],$key());
$pdBefore=ownOriginalDatabaseHash($db,$tables);$pdPublicPage=$pdOwn->voucherPage(['query'=>'Own original public personal event'],$pdWebsite);
$check($pdPublicPage['total']===1&&$pdPublicPage['rows'][0]['id']===$pdWebEvent&&$pdPublicPage['rows'][0]['title']===$pdPublicInput['title']&&$pdPublicPage['rows'][0]['can_download'],'actual native public event metadata reaches the exact currently published personal voucher source without copying its body');
$check(ownOriginalDatabaseHash($db,$tables)===$pdBefore,'current published personal event metadata preserves every original page revision balance and business row');
$pdDraftPublic=$pdPages->get($pdPublicId);$pdDraftPublic['title']='Own hidden unpublished replacement title';$pdPages->save($pdDraftPublic,$pdDraftPublic['version']);
$check($pdOwn->voucherPage(['query'=>'Own original public personal event'],$pdWebsite)['rows'][0]['title']===$pdPublicInput['title'],'later unpublished page draft cannot replace the original published personal voucher title');
$pdCaller->beginTransaction();$pdCaller->query('SELECT * FROM khcms_pages')->fetchAll();$pdCurrentPublic=$pdPages->get($pdPublicId);$pdPages->unpublish($pdPublicId,$pdCurrentPublic['version']);$pdBefore=ownOriginalDatabaseHash($db,$tables);
$pdOldCalendar=\Kulturhus\Verein\WebsiteCalendar::fromNative($pdCaller,'own-list-club',$access->club(),'https://own-personal-calendar.example.invalid');$pdWithdrawn=(new PortalDocuments($pdCaller,'own-list-reader'))->voucherPage(['query'=>'Own original public personal event'],$pdOldCalendar);
$check($pdWithdrawn['total']===1&&!$pdWithdrawn['rows'][0]['can_download']&&$pdWithdrawn['rows'][0]['notice']!==''&&$pdCaller->inTransaction(),'actual old caller snapshot cannot advertise a withdrawn public source as a currently printable personal voucher');$pdCaller->rollBack();
$check(ownOriginalDatabaseHash($db,$tables)===$pdBefore,'withdrawn public source check preserves the exact accepted withdrawal every original voucher and all business rows');
$pdCapSource=$pdRegistry->saveAppointment(null,0,['title'=>'Own capped personal print source','start'=>'2040-12-01T18:00','end'=>'2040-12-01T20:00','location'=>'Own print limit venue','description'=>'Own original limit case','category'=>'verein'],[]);$pdCapEvent=$pdVoucherService->open($pdCapSource,$key());$pdVoucherService->grant($pdCapEvent,[$pdPerson],[['label'=>'Own capped cards','kind'=>'other','unit'=>'piece','amount'=>'201']],$key());$pdBefore=ownOriginalDatabaseHash($db,$tables);$pdCapPage=$pdOwn->voucherPage(['query'=>'Own capped personal print source']);
$check($pdCapPage['total']===1&&!$pdCapPage['rows'][0]['can_download']&&$pdCapPage['rows'][0]['open_cards']===201,'own metadata honestly withholds an oversized personal print without changing any original ticket balance');
$pdCapDenied=false;try{$pdOwn->voucherFile($pdCapEvent);}catch(DomainException $failure){$pdCapDenied=$failure->getMessage()==='Höchstens 200 Gutscheine pro Druckdatei.';}$check($pdCapDenied&&ownOriginalDatabaseHash($db,$tables)===$pdBefore,'direct oversized personal print is refused before rendering without issuing redeeming or rewriting an original card');
final class OwnPersonalMetadataOnlyStatement extends PDOStatement{
 protected function __construct(){}
 public function execute(?array $params=null):bool{
  if(preg_match('/\bSELECT\s+(?:[a-z]+\.)?\*\s+FROM\s+appointments\b/i',$this->queryString)||preg_match('/\bSELECT\s+[^;]*\b(?:bytes|file_bytes)\b/i',$this->queryString))throw new RuntimeException('Own personal metadata attempted to hydrate an original body.');
  return parent::execute($params);
 }
}
$pdOldStatements=$db->getAttribute(PDO::ATTR_STATEMENT_CLASS);$db->setAttribute(PDO::ATTR_STATEMENT_CLASS,[OwnPersonalMetadataOnlyStatement::class,[]]);
try{$pdMetadata=$pdOwn->personalPages(['invoices'=>[],'shared'=>[],'vouchers'=>[]],$pdWebsite);$check($pdMetadata['errors']===[]&&count($pdMetadata['pages']['vouchers']['rows'])===25,'actual database query guard permits all personal metadata pages while forbidding original file bodies and full appointment rows');}
finally{$db->setAttribute(PDO::ATTR_STATEMENT_CLASS,$pdOldStatements);}
$check(ownOriginalDatabaseHash($db,$tables)===$pdBefore,'guarded original-free metadata preparation leaves every earlier complete source and business row exact');
if(getenv('DVM_PERSONAL_DOCUMENTS_INTERFACE_BROWSER')==='1')require __DIR__.'/support/personal-documents-interface-browser-fixture.php';
echo json_encode(['suite'=>'native-personal-documents-interface','checks'=>$n,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
