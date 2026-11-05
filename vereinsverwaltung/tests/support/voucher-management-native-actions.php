<?php declare(strict_types=1);
require_once dirname(__DIR__,2).'/src/Events/EventVoucherManageState.php';
use Kulturhus\Verein\{EventVouchers,EventVoucherDraft,EventVoucherManagementActions,EventVoucherManageState};
$maSession=['csrf'=>'Own synthetic native voucher form token'];
$maKey=$key();$maPost=['csrf'=>$maSession['csrf'],'action'=>'event-voucher-grant','voucher_event'=>$mgEvent,'request_key'=>$maKey,'version'=>'','contacts_keep'=>[$mgPeople[0]],'contacts_page_present'=>'1','other_label'=>'0','other_unit'=>'piece','other_amount'=>'1.00','voucher_compose'=>'members-search','voucher_members_query'=>str_repeat('🙂',241),'voucher_members_page'=>'0'];
$maBefore=ownOriginalDatabaseHash($db,$tables);$maComposed=EventVoucherManagementActions::handle($viAdmin,'own-list-admin',$maPost,$maSession);$maState=EventVoucherManageState::prepare($viAdmin,'own-list-admin',['voucher_event'=>$mgEvent,'voucher_task'=>'grant'],$maSession,$maComposed['draft']);
$check($maComposed['unsaved']&&$maState['http_status']===422&&$maState['members']===null&&$maState['draft']['key']===$maKey&&$maState['draft']['contacts']===[$mgPeople[0]]&&$maState['draft']['values']['other_amount']==='1.00'&&$maState['draft']['query']['voucher_members_query']===str_repeat('🙂',241)&&ownOriginalDatabaseHash($db,$tables)===$maBefore,'actual native unsaved search resets its explicit page while preserving an off-page person full invalid Unicode query amount and original key without a business write');
$maResumed=EventVoucherManagementActions::resume($viAdmin,$maSession,'own-list-admin',$maKey);
$check($maResumed===$maComposed['draft']&&count(EventVoucherManagementActions::drafts($maSession,'own-list-admin',$viAdmin->workspaceAccess(),'voucher_native_drafts'))===1,'new native form session reads the exact unaccepted proposal without submitting or inventing a confirmation');
$deny(fn()=>EventVoucherManagementActions::resume($viAdmin,$maSession,'own-list-reader',$maKey),'native draft resumption cannot switch its creating account');
$maUnchecked=EventVoucherDraft::restore(array_replace($maPost,['contacts_keep'=>$mgPeople,'contacts_visible'=>[$mgPeople[0]],'contacts'=>[],'voucher_members_query'=>'','voucher_members_page'=>'1']));
$check($maUnchecked['contacts']===[$mgPeople[1]],'actual native visible uncheck removes only that person while retaining the other selected page');
$maBadSession=$maSession;$maForbidden=false;
try{EventVoucherManagementActions::handle($mgHelper,'own-voucher-helper',array_replace($maPost,['voucher_members_query'=>'']),$maBadSession);}catch(DomainException $failure){$maForbidden=$failure->getCode()===403;}
$check($maForbidden&&$maBadSession===$maSession&&ownOriginalDatabaseHash($db,$tables)===$maBefore,'current assigned helper cannot use native compose to read the administrator person selection or change private session drafts');

$maOriginalVersion=(int)$viAdmin->event($mgEvent)['version'];$maConfigKey=$key();
$maConfigPost=['csrf'=>$maSession['csrf'],'action'=>'event-voucher-configure','voucher_event'=>$mgEvent,'request_key'=>$maConfigKey,'version'=>(string)$maOriginalVersion,'helpers'=>['own-voucher-helper'],'enabled'=>'1'];
$viAdmin->configure($mgEvent,$maOriginalVersion,true,[],$key());$maAfterCompeting=ownOriginalDatabaseHash($db,$tables);$maConflict=null;
try{EventVoucherManagementActions::handle($viAdmin,'own-list-admin',$maConfigPost,$maSession);}catch(DomainException $failure){$maConflict=$failure;}
$maFailedState=EventVoucherManageState::prepare($viAdmin,'own-list-admin',['voucher_event'=>$mgEvent,'voucher_task'=>'configure'],$maSession,EventVoucherDraft::restore($maConfigPost),$maConflict?->getMessage()??'',(int)$maConflict?->getCode());
$check($maConflict?->getCode()===409&&$maFailedState['draft']['version']===(string)$maOriginalVersion&&$maFailedState['draft']['key']===$maConfigKey&&$maFailedState['draft']['helpers']===['own-voucher-helper']&&$maFailedState['current_helpers']===[]&&ownOriginalDatabaseHash($db,$tables)===$maAfterCompeting,'actual stale native configuration retains original expected revision key and helper proposal separately from the competing accepted empty configuration');
$maReviewPost=$maConfigPost+['voucher_compose'=>'review-version'];$deny(fn()=>EventVoucherManagementActions::handle($viAdmin,'own-list-admin',$maReviewPost,$maSession),'native version adoption requires its own explicit current-state review');
$maReview=EventVoucherManagementActions::handle($viAdmin,'own-list-admin',$maReviewPost+['voucher_review_current'=>'1'],$maSession);
$check($maReview['unsaved']&&$maReview['draft']['version']===(string)($maOriginalVersion+1)&&$maReview['draft']['key']!==$maConfigKey&&$maReview['draft']['helpers']===['own-voucher-helper']&&ownOriginalDatabaseHash($db,$tables)===$maAfterCompeting,'explicit native review prepares a new key and current version without applying or replacing the original helper proposal');
$maAcceptedPost=array_replace($maConfigPost,['request_key'=>$maReview['draft']['key'],'version'=>$maReview['draft']['version']]);$maAccepted=EventVoucherManagementActions::handle($viAdmin,'own-list-admin',$maAcceptedPost,$maSession);$maAcceptedReceipt=$viAdmin->confirmation($maReview['draft']['key']);
$check($maAccepted['redirect']['voucher_receipt']===$maReview['draft']['key']&&$maAcceptedReceipt['payload']===['configure',$mgEvent,$maOriginalVersion+1,true,['own-voucher-helper']]&&$viAdmin->helpers($mgEvent)===['own-voucher-helper'],'only the separate deliberate native save applies the reviewed helper proposal and redirects to its own exact original confirmation');
$deny(fn()=>$viAdmin->confirmation($maConfigKey),'rejected stale native operation has no invented accepted confirmation');

final class OwnNativeVoucherWriteAcknowledgement extends PDO {
 public ?string $loseForKey=null;
 public function commit():bool {
  $accepted=false;if($this->loseForKey!==null){$q=$this->prepare('SELECT COUNT(*) FROM event_voucher_receipts WHERE request_key=?');$q->execute([$this->loseForKey]);$accepted=(int)$q->fetchColumn()===1;}
  $result=parent::commit();if($accepted){$this->loseForKey=null;throw new PDOException('Own actual native writer commit acknowledgement missing');}return$result;
 }
}
$maLostSession=['csrf'=>$maSession['csrf']];$maLostConnection=new OwnNativeVoucherWriteAcknowledgement($viDsn,$viConfig['username'],$viConfig['password'],$viOptions);$maLostNativeKey=$key();$maLostConnection->loseForKey=$maLostNativeKey;
$maLostPost=['csrf'=>$maSession['csrf'],'action'=>'event-voucher-grant','voucher_event'=>$mgEvent,'request_key'=>$maLostNativeKey,'version'=>'','contacts'=>[$mgPeople[0]],'other_label'=>'Own actual native missing acknowledgement','other_unit'=>'piece','other_amount'=>'1'];$maLostFailure=false;
try{EventVoucherManagementActions::handle(new EventVouchers($maLostConnection,'own-list-admin'),'own-list-admin',$maLostPost,$maLostSession);}catch(PDOException){$maLostFailure=true;}
$maLostOriginal=$viAdmin->confirmation($maLostNativeKey);$maLostAfter=ownOriginalDatabaseHash($db,$tables);$maReadProposal=EventVoucherManagementActions::resume($viAdmin,$maLostSession,'own-list-admin',$maLostNativeKey);
$check($maLostFailure&&$maReadProposal['key']===$maLostNativeKey&&$maReadProposal['values']['other_label']===$maLostPost['other_label']&&count($maLostOriginal['result'])===1&&ownOriginalDatabaseHash($db,$tables)===$maLostAfter,'actual native missing commit acknowledgement retains its complete private proposal and a new-tab read finds the single accepted original without another POST');
$maOldSession=['voucher_native_attempts'=>[$maLostNativeKey=>['actor'=>'own-voucher-helper','draft'=>['action'=>'event-voucher-grant','event'=>$mgEvent,'key'=>$maLostNativeKey],'at'=>gmdate('c')]]];
$check(EventVoucherManagementActions::drafts($maOldSession,'own-voucher-helper',$mgHelper->workspaceAccess())===[],'current helper cannot see an earlier administrative private draft merely by retaining another general event assignment');
