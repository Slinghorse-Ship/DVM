<?php declare(strict_types=1);
require __DIR__.'/native-voucher-interface.php';
use Kulturhus\Verein\{Registry,EventVouchers};
use Kulturhus\Native\Shared\Db;

$mgSource=$viRegistry->saveAppointment(null,0,['title'=>'Own native management source 0','start'=>'2041-06-01T18:00','end'=>'2041-06-01T20:00','location'=>'Own management venue','description'=>'Own private source body','category'=>'verein'],[]);
$mgOpenKey=$key();$mgEvent=$viAdmin->open($mgSource,$mgOpenKey);
$mgMembers=[];for($mgI=0;$mgI<2;$mgI++){$mgMember=$viRegistry->saveContact(null,0,'person','Own original management member '.$mgI,'own-management-'.$mgI.'@example.invalid','Own place 0');$mgMembership=$viRegistry->addMembership($mgMember,'','aktiv','2020-01-01');$mgMembers[]=['person'=>$mgMember,'membership'=>$mgMembership];}
$mgPeople=array_column($mgMembers,'person');sort($mgPeople);
$mgBenefits=[['label'=>'0','kind'=>'other','unit'=>'piece','amount'=>'2'],['label'=>str_repeat('ä',120),'kind'=>'other','unit'=>'eur','amount'=>'3.50']];
$mgGrantKey=$key();$mgGranted=$viAdmin->grant($mgEvent,$mgPeople,$mgBenefits,$mgGrantKey);$mgReceipt=$viAdmin->confirmation($mgGrantKey);
$check($mgReceipt['input_available']&&$mgReceipt['payload']===['grant',$mgEvent,$mgPeople,[['label'=>'0','kind'=>'other','unit'=>'piece','amount'=>2],['label'=>str_repeat('ä',120),'kind'=>'other','unit'=>'eur','amount'=>350]]]&&$mgReceipt['result']===$mgGranted,'accepted voucher confirmation retains complete normalized Unicode input exact integer cents original zero and result identities');
$mgExpectedNames=[];foreach($mgMembers as$mgIndex=>$mgOriginalMember)$mgExpectedNames[$mgOriginalMember['person']]='Own original management member '.$mgIndex;ksort($mgExpectedNames);$check($mgReceipt['context']['people']===$mgExpectedNames&&$mgReceipt['context']['event']['title']==='Own native management source 0','accepted voucher original binds the actual current source title and exact original recipient names separately from future profiles');
$mgBefore=ownOriginalDatabaseHash($db,$tables);$mgAgain=$viAdmin->grant($mgEvent,array_reverse($mgPeople),$mgBenefits,$mgGrantKey);
$check($mgAgain===$mgGranted&&ownOriginalDatabaseHash($db,$tables)===$mgBefore,'confirmed identical voucher retry retains all accepted inputs results original codes and complete earlier rows');
$deny(fn()=>$viAdmin->grant($mgEvent,$mgPeople,[['label'=>'Own changed proposal','kind'=>'other','unit'=>'piece','amount'=>'1']],$mgGrantKey),'accepted voucher key never accepts a changed original proposal');
$deny(fn()=>$viReader->confirmation($mgGrantKey),'known voucher confirmation remains private to its creating current authorized account');
$mgBefore=ownOriginalDatabaseHash($db,$tables);$mgReceiptPage=$viAdmin->confirmationsPage(['query'=>$mgGrantKey]);
$check($mgReceiptPage['total']===1&&$mgReceiptPage['rows'][0]['request_key']===$mgGrantKey&&ownOriginalDatabaseHash($db,$tables)===$mgBefore,'shallow native original operation search reaches the exact accepted request without changing any complete business row');
$viRegistry->saveContact($mgPeople[0],1,'person','Own deliberately later current management name','own-later@example.invalid','Own later address');
$check($viAdmin->confirmation($mgGrantKey)===$mgReceipt,'later current profile edits never replace accepted normalized recipient identities benefit labels amounts or original confirmation');

final class OwnVoucherReceiptFailure extends PDOStatement {
 protected function __construct(){}
 public function execute(?array $params=null):bool {
  if(str_contains($this->queryString,'INSERT INTO event_voucher_receipts'))throw new RuntimeException('Own deliberately refused final original input write');
  return parent::execute($params);
 }
}
$mgFault=new PDO($viDsn,$viConfig['username'],$viConfig['password'],$viOptions);$mgFault->setAttribute(PDO::ATTR_STATEMENT_CLASS,[OwnVoucherReceiptFailure::class,[]]);$mgOuterBefore=ownOriginalDatabaseHash($db,$tables);$mgFault->beginTransaction();Db::run($mgFault,'UPDATE clubs SET name=? WHERE id=?',['Own earlier caller proposal','own-list-club']);$mgInnerBefore=ownOriginalDatabaseHash($mgFault,$tables);$mgFailed=false;
try{(new EventVouchers($mgFault,'own-list-admin'))->grant($mgEvent,[$mgPeople[0]],[['label'=>'Own failed atomic receipt','kind'=>'other','unit'=>'piece','amount'=>'1']],$key());}catch(RuntimeException $failure){$mgFailed=$failure->getMessage()==='Own deliberately refused final original input write';}
$check($mgFailed&&$mgFault->inTransaction()&&ownOriginalDatabaseHash($mgFault,$tables)===$mgInnerBefore,'failure after actual voucher ticket movement and request inserts rolls back only its own savepoint and retains the earlier caller write');
$mgFault->rollBack();$check(ownOriginalDatabaseHash($db,$tables)===$mgOuterBefore,'failed final receipt insert leaves no accepted partial voucher code ticket movement request or business row');

final class OwnVoucherCommitAcknowledgement extends PDO {
 public bool $loseAcknowledgement=false;
 public function commit():bool {$result=parent::commit();if($this->loseAcknowledgement){$this->loseAcknowledgement=false;throw new PDOException('Own actual accepted commit acknowledgement missing');}return$result;}
}
$mgLost=new OwnVoucherCommitAcknowledgement($viDsn,$viConfig['username'],$viConfig['password'],$viOptions);$mgLost->loseAcknowledgement=true;$mgLostKey=$key();$mgLostInput=[['label'=>'Own accepted missing acknowledgement','kind'=>'other','unit'=>'piece','amount'=>'1']];$mgLostError=false;
try{(new EventVouchers($mgLost,'own-list-admin'))->grant($mgEvent,[$mgPeople[0]],$mgLostInput,$mgLostKey);}catch(PDOException $failure){$mgLostError=$failure->getMessage()==='Own actual accepted commit acknowledgement missing';}
$mgLostReceipt=$viAdmin->confirmation($mgLostKey);$mgAccepted=ownOriginalDatabaseHash($db,$tables);$mgLostRepeat=$viAdmin->grant($mgEvent,[$mgPeople[0]],$mgLostInput,$mgLostKey);
$check($mgLostError&&count($mgLostReceipt['result'])===1&&$mgLostRepeat===$mgLostReceipt['result']&&ownOriginalDatabaseHash($db,$tables)===$mgAccepted,'actual committed voucher request with a lost commit acknowledgement remains privately readable and exact retry creates no second original');

$mgLegacyKey=$key();$mgLegacyPayload=$mgReceipt['payload'];Db::run($db,'INSERT INTO event_voucher_requests(club_id,request_key,actor_id,fingerprint,result_json,created_at) VALUES(?,?,?,?,?,?)',['own-list-club',$mgLegacyKey,'own-list-admin',hash('sha256',json_encode($mgLegacyPayload,JSON_THROW_ON_ERROR)),json_encode($mgGranted,JSON_THROW_ON_ERROR),'2026-01-01T00:00:00+00:00']);$mgLegacyBefore=ownOriginalDatabaseHash($db,$tables);$mgLegacyReceipt=$viAdmin->confirmation($mgLegacyKey);$mgLegacyRepeat=$viAdmin->grant($mgEvent,$mgPeople,$mgBenefits,$mgLegacyKey);
$check(!$mgLegacyReceipt['input_available']&&$mgLegacyReceipt['payload']===null&&$mgLegacyReceipt['result']===$mgGranted&&$mgLegacyRepeat===$mgGranted&&ownOriginalDatabaseHash($db,$tables)===$mgLegacyBefore,'legacy accepted result remains unchanged and honestly has no invented historical input record');

final class OwnVoucherDamagedReceiptRead extends PDOStatement {
 public static string $mode='context';
 protected function __construct(){}
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed {
  $row=parent::fetch($mode,$cursorOrientation,$cursorOffset);
  if(is_array($row)&&is_string($row['payload_json']??null)){
   $stored=json_decode($row['payload_json'],true,512,JSON_THROW_ON_ERROR);
   if(self::$mode==='context')$stored['context']['event']['title']='Own unexpected replacement title';
   else $stored['input'][3][0]['amount']=99;
   $row['payload_json']=json_encode($stored,JSON_THROW_ON_ERROR);
  }
  return$row;
 }
}
$mgDamaged=new PDO($viDsn,$viConfig['username'],$viConfig['password'],$viOptions);$mgDamaged->setAttribute(PDO::ATTR_STATEMENT_CLASS,[OwnVoucherDamagedReceiptRead::class,[]]);$mgBefore=ownOriginalDatabaseHash($db,$tables);
foreach(['context','input']as$mgDamagedMode){OwnVoucherDamagedReceiptRead::$mode=$mgDamagedMode;$deny(fn()=>(new EventVouchers($mgDamaged,'own-list-admin'))->confirmation($mgGrantKey),'changed original '.$mgDamagedMode.' returned by a source read cannot replace the accepted voucher confirmation');}
$check(ownOriginalDatabaseHash($db,$tables)===$mgBefore,'refused damaged original receipt reads never repair replace or rewrite any complete accepted business row');

require __DIR__.'/support/voucher-management-current-writes.php';
$mgSources=$viAdmin->sourcesPage();$mgLastSources=$viAdmin->sourcesPage(['page'=>$mgSources['pages']]);$check($mgSources['total']===30&&count($mgSources['rows'])===25&&count($mgLastSources['rows'])===5,'native general source picker reaches all thirty actual metadata originals in twenty-five-row windows including explicit cancellations');
$mgGeneral=$viAdmin->managementWorkspace(['voucher_event'=>$mgEvent]);$check($mgGeneral['cards']['total']===9&&$mgGeneral['cards']['can_redeem']&&$mgGeneral['history']['total']===7,'native general management reaches all authorized other-recipient original cards and complete original history');
$mgBadGeneral=$viAdmin->managementWorkspace(['voucher_event'=>$mgEvent,'voucher_cards_page'=>'0']);$check($mgBadGeneral['http_status']===422&&$mgBadGeneral['cards']===null&&$mgBadGeneral['history']['total']===7,'invalid native general card page preserves independently authorized original history with no substitute cards');
$mgMemberChoices=$viAdmin->membersPage();$check($mgMemberChoices['total']===4&&count($mgMemberChoices['rows'])===4,'current native general person choices exclude the actually ended member without hydrating complete profiles');
$mgBefore=ownOriginalDatabaseHash($db,$tables);$mgUnselectable=$viAdmin->selectedPeople([$mwEnded]);$check($mgUnselectable===[['id'=>$mwEnded,'name'=>$mwEnded,'available'=>false]]&&ownOriginalDatabaseHash($db,$tables)===$mgBefore,'unavailable original person selection remains explicit by identity without an unrelated replacement or business write');

$viAdmin->configure($mgEvent,1,true,['own-voucher-helper'],$key());$mgHelper=new EventVouchers($db,'own-voucher-helper');$mgHelperGeneral=$mgHelper->managementWorkspace(['voucher_event'=>$mgEvent]);$check($mgHelperGeneral['cards']['total']===9&&$mgHelperGeneral['cards']['can_redeem']&&!$mgHelperGeneral['access']['profile_available'],'current native general assigned helper can redeem authorized other recipients without a linked member profile');
$deny(fn()=>$mgHelper->managementWorkspace(['voucher_event'=>$mgEvent,'voucher_task'=>'grant']),'assigned voucher helper cannot open the general allocation form or member picker');
$mgHelperPiece=array_values(array_filter($mgHelperGeneral['cards']['rows'],static fn(array $row):bool=>$row['unit']==='piece'&&$row['display_state']==='active'))[0];$mgHelperKey=$key();$mgHelper->change($mgEvent,$mgHelperPiece['id'],(int)$mgHelperPiece['version'],'redeem','1','',$mgHelperKey,$mgHelperPiece['ticket_id']);$mgHelperReceipt=$mgHelper->confirmation($mgHelperKey);
$check($mgHelperReceipt['payload'][0]==='redeem'&&$mgHelper->confirmationsPage()['total']===1,'current helper reads only their actual accepted original redemption confirmation');
$viAdmin->configure($mgEvent,2,true,[],$key());$mgAfterRevoked=ownOriginalDatabaseHash($db,$tables);$deny(fn()=>$mgHelper->confirmation($mgHelperKey),'actual revoked helper cannot retain private general original operation access');$check(ownOriginalDatabaseHash($db,$tables)===$mgAfterRevoked,'refused revoked-helper original operation read never rewrites its accepted redemption or any earlier row');$viAdmin->configure($mgEvent,3,true,['own-voucher-helper'],$key());
require __DIR__.'/support/voucher-management-native-actions.php';
require __DIR__.'/support/voucher-management-capacity.php';
if(getenv('DVM_VOUCHER_MANAGEMENT_INTERFACE_BROWSER')==='1')require __DIR__.'/support/voucher-management-browser-fixture.php';
echo json_encode(['suite'=>'native-voucher-management-interface','checks'=>$n,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
