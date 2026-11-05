<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');ob_start();
require dirname(__DIR__).'/native/bootstrap.php';require dirname(__DIR__).'/src/bootstrap.php';require __DIR__.'/support/original-database-hash.php';require __DIR__.'/support/editorial-browser-fixture.php';
use Kulturhus\Native\{TestSupport,Installer\SchemaRegistry,Shared\Db};
use Kulturhus\Verein\{Registry,MemberImport,MemberImportReader,MemberImportDetails,MemberImportBankPermit,MemberBankVault,ContributionRates,Database,Finance,MemberBankStepUp};
use Kulturhus\Verein\Web\MemberImportSession;
$cfg=TestSupport::config();$db=TestSupport::db();
if(($cfg['owner']??'')!=='isolated-native-ci'||($cfg['label']??'')!=='native-member-import-details'||($cfg['host']??'')!=='127.0.0.1'||($cfg['port']??0)!==13371||$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)!==[])throw new RuntimeException('Fresh owned member details fixture required.');
final class OwnMemberImportCommit extends PDO
{
 public bool $lose=false;
 public function commit():bool{$accepted=parent::commit();if($this->lose){$this->lose=false;throw new PDOException('Own member import COMMIT accepted before response loss');}return$accepted;}
}
SchemaRegistry::install($db);$f=ownEditorialBrowserFixture($db);$actor=$f['accounts']['admin']['actor'];$registry=new Registry($db,$actor);Database::ensureContributionRates($db);$rates=ContributionRates::all($db,$f['club']);
$previous=['APP_SECRET'=>getenv('APP_SECRET'),'VEREIN_MEMBER_BANK_KEY_PATH'=>getenv('VEREIN_MEMBER_BANK_KEY_PATH')];putenv('APP_SECRET='.bin2hex(random_bytes(32)));$bankKey=$f['root'].'/member-bank.key';file_put_contents($bankKey,random_bytes(32));chmod($bankKey,0600);putenv('VEREIN_MEMBER_BANK_KEY_PATH='.$bankKey);$originalBankKeyHash=hash_file('sha256',$bankKey);
session_save_path($f['root'].'/manager-sessions');session_id('owndetails'.bin2hex(random_bytes(8)));session_start();$_SESSION=['actor'=>$actor,'auth_token'=>$f['accounts']['admin']['token']];
$n=0;$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException($label);$n++;echo'PASS '.$label.PHP_EOL;};
$deny=static function(callable $run,string $label)use($check):void{$refused=false;try{$run();}catch(DomainException){$refused=true;}$check($refused,$label);};
$make=static function(array $rows):array{$stream=fopen('php://temp','w+');$fields=['name'=>'Name','number'=>'Mitgliedsnummer','start'=>'Eintritt','status'=>'Status']+MemberImportDetails::FIELDS;fputcsv($stream,array_values($fields),';','"','');foreach($rows as$row){$record=[];foreach($fields as$key=>$label)$record[]=$row[$key]??'';fputcsv($stream,$record,';','"','');}rewind($stream);$bytes=stream_get_contents($stream);fclose($stream);return MemberImportReader::read('Own reviewed details.csv',$bytes);};
$amount=static fn(int $cents):string=>Finance::decimal($cents);
$rows=[
 ['name'=>'Own imported single <&> 🙂','number'=>'D001','start'=>'2020','status'=>'aktiv','annual_fee'=>$amount($rates['single']['amount_cents'])],
 ['name'=>'Own imported payer <&> 🙂','number'=>'D002','start'=>'2015','status'=>'aktiv','annual_fee'=>$amount($rates['family']['amount_cents']),'family_key'=>'0','family_name'=>'0 Own explicit family <&> 🙂','family_payer'=>'ja','iban'=>'DE89 3704 0044 0532 0130 00','holder'=>'0 Own account holder <&> 🙂','bic'=>'COBADEFFXXX'],
 ['name'=>'Own imported child <&> 🙂','number'=>'D003','start'=>'2020','status'=>'aktiv','annual_fee'=>'0','family_key'=>'0','family_name'=>'0 Own explicit family <&> 🙂','family_payer'=>'nein'],
];
$service=new MemberImport($db,$actor);$book=$make($rows);$mapping=MemberImport::suggest($book)['mapping'];$preview=$service->preview($book,0,1,$mapping);
try{
 $check(count($mapping)===11&&array_column($preview['rows'],'state')===['new','new','new']&&$preview['bank_count']===1,'all explicit bank contribution and family CSV headings map and validate without guessing another person');
 $check($preview['rows'][1]['record']['family_key']==='0'&&$preview['rows'][1]['record']['_family']['payer_line']===3&&$preview['rows'][2]['record']['_annual_cents']===0,'literal-zero family key retains the explicit payer and included child contribution');
 $tables=array_values(array_diff($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),['native_auth_sessions','native_auth_limits','native_language_preferences']));$before=ownOriginalDatabaseHash($db,$tables);
 $deny(fn()=>$service->apply($book,0,1,$mapping,'',$preview['hash']),'complete bank import is refused before any membership without personal security proof');
 $check(ownOriginalDatabaseHash($db,$tables)===$before,'refused unconfirmed bank import preserves every whole business row and schema original');
 $cases=[
  'IBAN checksum'=>[1,'iban','DE00370400440532013000'],
  'legacy account number'=>[1,'iban','0532013000'],
  'missing account holder'=>[1,'holder',''],
  'invalid bank identifier'=>[1,'bic','INVALID'],
  'two family payers'=>[2,'family_payer','ja'],
  'missing family payer'=>[1,'family_payer','nein'],
  'conflicting family labels'=>[2,'family_name','Own conflicting family label'],
  'wrong individual annual amount'=>[0,'annual_fee',Finance::decimal($rates['single']['amount_cents']+1)],
  'unqualified zero individual fee'=>[0,'annual_fee','0'],
  'additional child annual demand'=>[2,'annual_fee','1'],
 ];
 foreach($cases as$label=>[$index,$field,$value]){$bad=$rows;$bad[$index][$field]=$value;$input=$make($bad);$map=MemberImport::suggest($input)['mapping'];$review=$service->preview($input,0,1,$map);$check((bool)array_filter($review['rows'],static fn($row)=>$row['state']==='review')&&$review['rows'][$index]['record'][$field]===$value,'invalid original detail is retained and flagged: '.$label);$deny(fn()=>$service->apply($input,0,1,$map,'',$review['hash']),'unclear whole workbook creates no partial import: '.$label);if($label==='IBAN checksum')$check(count(array_filter($review['rows'],static fn($row)=>$row['state']==='review'))===1,'an invalid payer IBAN does not falsely report a missing family payer for the other original family members');}
 $foreign=$rows;foreach([1,2]as$i)$foreign[$i]['family_key']='ID:'.str_repeat('f',32);$foreignBook=$make($foreign);$foreignReview=$service->preview($foreignBook,0,1,MemberImport::suggest($foreignBook)['mapping']);$check(count(array_filter($foreignReview['rows'],static fn($row)=>$row['state']==='review'))===2,'unknown explicitly referenced family is never created as a replacement own family');
 $check(ownOriginalDatabaseHash($db,$tables)===$before,'every invalid bank fee and family preview preserves all complete business and schema originals');
 $id=MemberImportSession::create($db,$actor,$book,'Own complete bank and family import.csv',$_SESSION);MemberImportSession::preview($db,$actor,['import_id'=>$id,'sheet'=>'0','header'=>'1','mapping'=>$mapping,'default_start'=>''],$_SESSION);
 $deny(fn()=>MemberImportBankPermit::begin($db,$actor,$id,$preview['hash'],$_SESSION,'Own wrong password'),'actual native password check refuses the wrong bank import confirmation');
 $proof=MemberImportBankPermit::begin($db,$actor,$id,$preview['hash'],$_SESSION,'Own isolated workflow password 2026!');$check($proof['requires_code']===false,'native account without factor receives only its own exact workbook proof');
 $permit=MemberImportBankPermit::fromSession($db,$actor,$id,$preview['hash'],$_SESSION);
 $deny(fn()=>$permit->assert($db,$actor,str_repeat('f',64)),'bank proof cannot authorize another preview hash');
 $beforeAbort=ownOriginalDatabaseHash($db,$tables);
 $db->exec("CREATE TRIGGER own_import_bank_abort BEFORE INSERT ON member_bank_history FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Own imported bank history write refused'");
 $aborted=false;try{$service->apply($book,0,1,$mapping,'',$preview['hash'],$permit);}catch(PDOException){$aborted=true;}finally{$db->exec('DROP TRIGGER own_import_bank_abort');}
 $check($aborted&&ownOriginalDatabaseHash($db,$tables)===$beforeAbort,'actual bank-history SQL abort rolls back all new people memberships numbers families bank records and audit rows together');
 $ids=$service->apply($book,0,1,$mapping,'',$preview['hash'],$permit);$check(count($ids)===3,'consciously confirmed original imports exactly the three reviewed memberships');
 $parent=$registry->family($ids[1]);$child=$registry->family($ids[2]);$check($parent['id']===$child['id']&&$parent['payer_contact_id']===$ids[1]&&array_diff($ids,array_column($parent['people'],'id'))===[$ids[0]],'new family stores only the two explicitly grouped people and its exact named payer');
 $record=Db::run($db,'SELECT * FROM member_bank_records WHERE club_id=? AND contact_id=?',[$f['club'],$ids[1]])->fetch();$history=Db::run($db,'SELECT * FROM member_bank_history WHERE club_id=? AND contact_id=?',[$f['club'],$ids[1]])->fetch();
 $check($record['status']==='saved'&&$record['mandate_ref']===''&&!str_contains($record['ciphertext'],'37040044')&&!str_contains($history['ciphertext'],'Own account holder'),'import stores authenticated encrypted bank records and history without issuing a mandate or plaintext source');
 $vault=MemberBankVault::fromEnvironment($db,$actor);$binding=['contact'=>$ids[1],'action'=>'reveal','version'=>1,'draft_hash'=>MemberBankVault::payloadHash('reveal',[])];$grant=\Kulturhus\Native\Integration\MemberBankAuthentication::create($db,$actor)->begin($binding,'Own isolated workflow password 2026!');$revealed=$vault->reveal($ids[1],1,[],$grant['token'],hash('sha256',session_id()));
 $check($revealed===['holder'=>'0 Own account holder <&> 🙂','iban'=>'DE89370400440532013000','bic'=>'COBADEFFXXX'],'existing native bank step-up reads the exact imported account holder IBAN and BIC');
 $finance=new Finance($db,$actor);$year=(int)date('Y');$singleEntry=$finance->createAnnualContribution($ids[0],$year,'');$familyEntry=$finance->createAnnualContribution($ids[1],$year,$parent['id']);
 $single=$finance->currentEntry($singleEntry);$family=$finance->currentEntry($familyEntry);$check((int)$single['amount_cents']===$rates['single']['amount_cents']&&(int)$family['amount_cents']===$rates['family']['amount_cents'],'actual annual contribution service uses the imported single and family classification at the reviewed configured amounts');
 $covered=Db::run($db,'SELECT contact_id FROM member_dues_people WHERE club_id=? AND entry_id=? ORDER BY contact_id',[$f['club'],$familyEntry])->fetchAll(PDO::FETCH_COLUMN);$check(count($covered)===2&&!array_diff([$ids[1],$ids[2]],$covered)&&$finance->createAnnualContribution($ids[2],$year,$parent['id'])===$familyEntry,'family child is covered once by the actual payer contribution without a second demand');
 $check(hash_file('sha256',$bankKey)===$originalBankKeyHash,'private original bank key remains available unchanged');
 $normal=\Kulturhus\Verein\MemberWorkbook::records($db,$actor,'members','');$check(count($normal)===3&&!array_filter($normal,static fn($row)=>$row['iban']!==''||$row['holder']!==''||$row['bic']!==''),'ordinary membership export includes family and dues columns while all bank fields remain empty');
 $exportId=\Kulturhus\Verein\Web\MemberExcelExportSession::create($db,$actor,'members','',$_SESSION);$exportQuery=['view'=>'members','import'=>'1','bank_export_id'=>$exportId];$export=\Kulturhus\Verein\Web\MemberExcelExportSession::get($db,$actor,$exportId,$_SESSION);$exportPost=['action'=>'member-excel-bank-download','bank_export_id'=>$exportId,'bank_export_hash'=>$export['hash']];
 $deny(fn()=>\Kulturhus\Verein\Web\MemberExcelExportSession::download($db,$actor,$exportQuery,$exportPost,$_SESSION),'full bank spreadsheet cannot be downloaded using ordinary membership authority alone');
 $deny(fn()=>$permit->assert($db,$actor,$preview['hash'],'export'),'import confirmation cannot serve as a bank export confirmation');
 \Kulturhus\Verein\Web\MemberExcelExportSession::confirm($db,$actor,$exportQuery,array_replace($exportPost,['action'=>'member-excel-bank-begin','bank_password'=>'Own isolated workflow password 2026!']),$_SESSION);
 $beforeExport=ownOriginalDatabaseHash($db,$tables);
 $db->exec("CREATE TRIGGER own_export_audit_abort BEFORE INSERT ON audit_events FOR EACH ROW BEGIN IF NEW.action='members.excel.bank-exported' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Own export audit refused'; END IF; END");
 $exportAborted=false;try{\Kulturhus\Verein\Web\MemberExcelExportSession::download($db,$actor,$exportQuery,$exportPost,$_SESSION);}catch(PDOException){$exportAborted=true;}finally{$db->exec('DROP TRIGGER own_export_audit_abort');}
 $check($exportAborted&&ownOriginalDatabaseHash($db,$tables)===$beforeExport,'failed actual export audit returns no spreadsheet and preserves every complete original business row');
 $bytes=\Kulturhus\Verein\Web\MemberExcelExportSession::download($db,$actor,$exportQuery,$exportPost,$_SESSION);$exported=MemberImportReader::read('Own private confirmed bank export.xlsx',$bytes);$suggested=MemberImport::suggest($exported);$data=$exported['sheets'][0]['rows'];$mapped=[];foreach(array_slice($data,1)as$values){$row=[];foreach($suggested['mapping']as$field=>$column)$row[$field]=$values[(int)$column]??'';$mapped[$row['number']]=$row;}
 $check($suggested['recognized']===17&&$mapped['D002']['iban']==='DE89370400440532013000'&&$mapped['D002']['holder']==='0 Own account holder <&> 🙂'&&$mapped['D002']['bic']==='COBADEFFXXX','actual personally confirmed XLSX contains exactly the imported original account values in its mapped bank columns');
 $check($mapped['D002']['family_key']==='ID:'.$parent['id']&&$mapped['D002']['family_payer']==='ja'&&$mapped['D003']['family_payer']==='nein'&&Finance::cents($mapped['D002']['annual_fee'])===$rates['family']['amount_cents']&&Finance::cents($mapped['D003']['annual_fee'])===0,'actual Excel export preserves the real family reference payer and configured single-charge family amounts');
 $check(Db::run($db,'SELECT * FROM member_bank_records WHERE club_id=? AND contact_id=?',[$f['club'],$ids[1]])->fetch()===$record&&Db::run($db,'SELECT * FROM member_bank_history WHERE club_id=? AND contact_id=?',[$f['club'],$ids[1]])->fetch()===$history,'confirmed bank export leaves the complete encrypted record and history exact');
 $oldTime=$record['updated_at'];Db::run($db,'UPDATE member_bank_records SET updated_at=? WHERE club_id=? AND contact_id=?',['Own deliberate current source change',$f['club'],$ids[1]]);
 $deny(fn()=>\Kulturhus\Verein\Web\MemberExcelExportSession::download($db,$actor,$exportQuery,$exportPost,$_SESSION),'changed bank source cannot use the earlier export selection or its confirmation');
 \Kulturhus\Verein\Web\MemberExcelExportSession::close($db,$actor,$exportQuery,array_replace($exportPost,['action'=>'member-excel-bank-cancel']),$_SESSION);$check(!isset($_SESSION['member_excel_exports'][$exportId]),'original stale export can be consciously closed without substituting a fresh source');
 Db::run($db,'UPDATE member_bank_records SET updated_at=? WHERE club_id=? AND contact_id=?',[$oldTime,$f['club'],$ids[1]]);
 // Independent genuine native account for second-factor checks; no shared limit reset.
 $mfaActor='own-member-excel-mfa';Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser)VALUES(?,?,'member',1)",[$mfaActor,$f['club']]);$bridge=new \Kulturhus\Native\Integration\ManagerBridge($db,$mfaActor,'native',static fn()=>null);$bridge->access();$auth=\Kulturhus\Native\Auth\Runtime::service($db);$invitation=$auth->createInvitation($f['admin'],$bridge->nativeActorId(),'own-member-excel-mfa@example.invalid');$auth->acceptInvitation($invitation['token'],'Own isolated workflow password 2026!');$mfaToken=$auth->passwordLogin('own-member-excel-mfa@example.invalid','Own isolated workflow password 2026!','owned-excel-mfa')['session_token'];$setup=$auth->beginTotp($mfaToken,'Own isolated workflow password 2026!');$confirmedTotp=$auth->confirmTotp($mfaToken,\Kulturhus\Native\Auth\Totp::code($setup['secret'],time()));
 $originalSession=$_SESSION;$_SESSION=['actor'=>$mfaActor,'auth_token'=>$mfaToken];
 try{
  $mfaExport=\Kulturhus\Verein\Web\MemberExcelExportSession::create($db,$mfaActor,'members','',$_SESSION);$mfaStage=\Kulturhus\Verein\Web\MemberExcelExportSession::get($db,$mfaActor,$mfaExport,$_SESSION);$challenge=MemberImportBankPermit::begin($db,$mfaActor,$mfaExport,$mfaStage['hash'],$_SESSION,'Own isolated workflow password 2026!','export');
  $check($challenge['requires_code']===true,'actual enrolled native second factor remains required after a correct workbook password');
  $deny(fn()=>MemberImportBankPermit::fromSession($db,$mfaActor,$mfaExport,$mfaStage['hash'],$_SESSION,'export'),'password alone cannot authorize an account with an actual second factor');
  $deny(fn()=>MemberImportBankPermit::confirm($db,$mfaActor,$mfaExport,$mfaStage['hash'],$_SESSION,str_repeat('f',32),$confirmedTotp['recovery_codes'][0],'export'),'valid native factor cannot confirm a different original workbook challenge');
  $deny(fn()=>MemberImportBankPermit::confirm($db,$mfaActor,$mfaExport,$mfaStage['hash'],$_SESSION,$challenge['challenge'],'invalid-own-factor','export'),'actual native second factor rejects the wrong code without a ready bank proof');
  MemberImportBankPermit::confirm($db,$mfaActor,$mfaExport,$mfaStage['hash'],$_SESSION,$challenge['challenge'],$confirmedTotp['recovery_codes'][0],'export');$mfaPermit=MemberImportBankPermit::fromSession($db,$mfaActor,$mfaExport,$mfaStage['hash'],$_SESSION,'export');$mfaPermit->assert($db,$mfaActor,$mfaStage['hash'],'export');
  $check(MemberImportBankPermit::state($db,$mfaActor,$mfaExport,$mfaStage['hash'],$_SESSION,'export')['mode']==='ready','real native factor confirmation authorizes only the exact current reviewed source');
  $deny(fn()=>MemberImportBankPermit::confirm($db,$mfaActor,$mfaExport,$mfaStage['hash'],$_SESSION,$challenge['challenge'],$confirmedTotp['recovery_codes'][0],'export'),'used factor challenge cannot be replayed as another confirmation');
  $_SESSION['member_excel_bank_confirmation']['export:'.$mfaExport]['expires']=time()-1;
  $deny(fn()=>MemberImportBankPermit::fromSession($db,$mfaActor,$mfaExport,$mfaStage['hash'],$_SESSION,'export'),'expired original proof cannot silently become a fresh ready confirmation');
 }finally{$_SESSION=$originalSession;}
 $dsn='mysql:host=127.0.0.1;port=13371;dbname='.$cfg['database'].';charset=utf8mb4';$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];$lost=new OwnMemberImportCommit($dsn,$cfg['username'],$cfg['password'],$options);
 $lostBook=$make([['name'=>'Own accepted original lost import','number'=>'D900','start'=>'2020','status'=>'aktiv','family_key'=>'OWN-LOST','family_name'=>'Own exact accepted lost-reply family','family_payer'=>'ja','annual_fee'=>Finance::decimal($rates['family']['amount_cents'])]]);$lostMap=MemberImport::suggest($lostBook)['mapping'];$lostService=new MemberImport($lost,$actor);$lostPreview=$lostService->preview($lostBook,0,1,$lostMap);$lost->lose=true;$lostError=null;
 try{$lostService->apply($lostBook,0,1,$lostMap,'',$lostPreview['hash']);}catch(Throwable $failure){$lostError=$failure;}
 $acceptedContact=Db::run($db,'SELECT c.* FROM contacts c JOIN memberships m ON m.club_id=c.club_id AND m.contact_id=c.id WHERE m.club_id=? AND m.member_number=?',[$f['club'],'D900'])->fetch();$acceptedFamily=$registry->family($acceptedContact['id']);
 $check($acceptedContact['name']==='Own accepted original lost import'&&$acceptedFamily['name']==='Own exact accepted lost-reply family'&&$acceptedFamily['payer_contact_id']===$acceptedContact['id']&&$lostError instanceof PDOException&&$lostError->getMessage()==='Own member import COMMIT accepted before response loss','actual accepted import COMMIT retains the exact person family payer and original lost acknowledgement');
 $deny(fn()=>$lostService->apply($lostBook,0,1,$lostMap,'',$lostPreview['hash']),'original unconfirmed import cannot be silently repeated against the changed current source');
 $check((int)Db::run($db,'SELECT COUNT(*) FROM memberships WHERE club_id=? AND member_number=?',[$f['club'],'D900'])->fetchColumn()===1&&$registry->family($acceptedContact['id'])===$acceptedFamily,'accepted original import remains exactly once after the explicit refused retry');
 $heldBook=$make([['name'=>'Own original import while club held','number'=>'D902','start'=>'2020','status'=>'aktiv']]);$heldMap=MemberImport::suggest($heldBook)['mapping'];$heldPreview=$service->preview($heldBook,0,1,$heldMap);$held=new PDO($dsn,$cfg['username'],$cfg['password'],$options);$held->beginTransaction();$q=$held->prepare('SELECT id FROM clubs WHERE id=? FOR UPDATE');$q->execute([$f['club']]);$lockFailure=null;
 try{$service->apply($heldBook,0,1,$heldMap,'',$heldPreview['hash']);}catch(Throwable $failure){$lockFailure=$failure;}finally{$held->rollBack();}
 $check($lockFailure instanceof DomainException&&$lockFailure->getCode()===503,'actual concurrent import lock returns a recoverable current-source refusal with 503');
 // Exercise the same private key fallback as an installed production bank module.
 $productionEnvironment=[];foreach(['VEREIN_MODE','VEREIN_PRIVATE_ROOT','VEREIN_MEMBER_BANK_KEY_PATH']as$name)$productionEnvironment[$name]=getenv($name);
 $privateRoot=$f['root'].'/own-production-private';mkdir($privateRoot,0700);mkdir($privateRoot.'/member-bank-keys',0700);file_put_contents($privateRoot.'/member-bank-keys/v1.key',file_get_contents($bankKey));chmod($privateRoot.'/member-bank-keys/v1.key',0600);
 putenv('VEREIN_MODE=production');putenv('VEREIN_PRIVATE_ROOT='.$privateRoot);putenv('VEREIN_MEMBER_BANK_KEY_PATH');
 try{
  $productionBook=$make([['name'=>'Own production key bank member','number'=>'D901','start'=>'2020','status'=>'aktiv','iban'=>'DE89 3704 0044 0532 0130 00','holder'=>'Own production key account holder','bic'=>'COBADEFFXXX']]);$productionMap=MemberImport::suggest($productionBook)['mapping'];$productionStage=MemberImportSession::create($db,$actor,$productionBook,'Own production key workbook.csv',$_SESSION);$productionPreview=MemberImportSession::preview($db,$actor,['import_id'=>$productionStage,'sheet'=>'0','header'=>'1','mapping'=>$productionMap,'default_start'=>''],$_SESSION);
  $check($productionPreview['bank_count']===1&&!array_filter($productionPreview['rows'],static fn($row)=>$row['state']==='review'),'installed private-root bank key fallback prepares a complete bank import without a development key path');
  MemberImportBankPermit::begin($db,$actor,$productionStage,$productionPreview['hash'],$_SESSION,'Own isolated workflow password 2026!');$productionPermit=MemberImportBankPermit::fromSession($db,$actor,$productionStage,$productionPreview['hash'],$_SESSION);$productionIds=$service->apply($productionBook,0,1,$productionMap,'',$productionPreview['hash'],$productionPermit);
  $savedSession=$_SESSION;$_SESSION=['actor'=>$mfaActor,'auth_token'=>$mfaToken];
  try{
   $productionExport=\Kulturhus\Verein\Web\MemberExcelExportSession::create($db,$mfaActor,'current','Own production key bank member',$_SESSION);$productionSelection=\Kulturhus\Verein\Web\MemberExcelExportSession::get($db,$mfaActor,$productionExport,$_SESSION);$productionChallenge=MemberImportBankPermit::begin($db,$mfaActor,$productionExport,$productionSelection['hash'],$_SESSION,'Own isolated workflow password 2026!','export');MemberImportBankPermit::confirm($db,$mfaActor,$productionExport,$productionSelection['hash'],$_SESSION,$productionChallenge['challenge'],$confirmedTotp['recovery_codes'][1],'export');
   $productionBytes=\Kulturhus\Verein\Web\MemberExcelExportSession::download($db,$mfaActor,['view'=>'members','import'=>'1','bank_export_id'=>$productionExport],['action'=>'member-excel-bank-download','bank_export_id'=>$productionExport,'bank_export_hash'=>$productionSelection['hash']],$_SESSION);$productionOutput=MemberImportReader::read('Own installed bank key export.xlsx',$productionBytes);$productionOutputMapping=MemberImport::suggest($productionOutput)['mapping'];$productionValues=$productionOutput['sheets'][0]['rows'][1];
   $check(count($productionIds)===1&&$productionValues[(int)$productionOutputMapping['iban']]==='DE89370400440532013000'&&$productionValues[(int)$productionOutputMapping['holder']]==='Own production key account holder','actual confirmed import and second-factor-protected XLSX export use the installed private-root key and exact account original');
  }finally{$_SESSION=$savedSession;}
  $check(hash_file('sha256',$bankKey)===$originalBankKeyHash&&hash_file('sha256',$privateRoot.'/member-bank-keys/v1.key')===$originalBankKeyHash,'both original and installed-route private bank keys remain byte exact');
 }finally{foreach($productionEnvironment as$name=>$value)putenv($value===false?$name:$name.'='.$value);}
 echo json_encode(['suite'=>'native-member-import-details','checks'=>$n,'primary_imported_members'=>3,'accepted_reply_loss_members'=>1,'production_key_members'=>1,'encrypted_bank_records'=>2,'actual_single_and_family_demands'=>2,'external_actions'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
}finally{session_write_close();foreach($previous as$name=>$value)putenv($value===false?$name:$name.'='.$value);}
ob_end_flush();
if(getenv('DVM_MEMBER_DETAILS_BROWSER')==='1')require __DIR__.'/support/member-details-browser-fixture.php';
