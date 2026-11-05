<?php declare(strict_types=1);
use Kulturhus\Verein\{EventVouchers,EventVoucherMail,EventVoucherMailDraft,EventVoucherMailActions,EventVoucherManageState,EventVoucherManagementActions};
require_once dirname(__DIR__,2).'/src/Events/EventVoucherMailActions.php';
require_once dirname(__DIR__,2).'/src/Events/EventVoucherManageState.php';
$naSession=['csrf'=>'Own synthetic mail confirmation token'];
$naSource=$nmRegistry->saveAppointment(null,0,['title'=>'Own native mail action source','start'=>'2047-08-01T18:00','end'=>'2047-08-01T20:00','location'=>'Own action venue','description'=>'Own original description','category'=>'verein'],[]);
$naEvent=$nmVouchers->open($naSource,$nmKey());$nmVouchers->grant($naEvent,array_values($nmPeople),[['label'=>'Own original action card','kind'=>'other','unit'=>'piece','amount'=>'1']],$nmKey());
$naPost=['csrf'=>$naSession['csrf'],'action'=>'event-voucher-mail-template','voucher_event'=>$naEvent,'request_key'=>$nmKey(),'template_version'=>'0','mail_subject'=>'Own original proposal 0','mail_message'=>str_repeat('🙂',6001)];
$naBefore=ownOriginalDatabaseHash($db,$tables);$deny(function()use($nmMail,$nmVouchers,&$naSession,$naPost){EventVoucherMailActions::handle($nmMail,$nmVouchers,'own-list-admin',$naPost,$naSession);},'native full invalid template action is rejected');
$naResume=EventVoucherManagementActions::resume($nmVouchers,$naSession,'own-list-admin',$naPost['request_key']);
$check($naResume['values']['mail_message']===$naPost['mail_message']&&$naResume['key']===$naPost['request_key']&&$naResume['version']==='0'&&ownOriginalDatabaseHash($db,$tables)===$naBefore,'native rejected full Unicode input resumes with its exact original version key and complete text without accepting a business row');
$nmMail->saveTemplate($naEvent,0,['subject'=>'Own competing original','message'=>'0'],$nmKey());$naPost['mail_message']='Own retained proposal {{NAME}}';$naBefore=ownOriginalDatabaseHash($db,$tables);$naCode=0;
try{EventVoucherMailActions::handle($nmMail,$nmVouchers,'own-list-admin',$naPost,$naSession);}catch(DomainException $failure){$naCode=$failure->getCode();}
$check($naCode===409&&ownOriginalDatabaseHash($db,$tables)===$naBefore,'native competing template refuses the original version without changing any accepted row');
$naCompose=$naPost+['voucher_mail_compose'=>'review-current'];$deny(function()use($nmMail,$nmVouchers,&$naSession,$naCompose){EventVoucherMailActions::handle($nmMail,$nmVouchers,'own-list-admin',$naCompose,$naSession);},'native current mail adoption requires its own explicit review');
$naPrepared=EventVoucherMailActions::handle($nmMail,$nmVouchers,'own-list-admin',$naCompose+['voucher_mail_review_current'=>'1'],$naSession)['draft'];
$check($naPrepared['key']!==$naPost['request_key']&&$naPrepared['version']==='1'&&$naPrepared['values']['mail_subject']===$naPost['mail_subject']&&$naPrepared['values']['mail_message']===$naPost['mail_message']&&$naPrepared['values']['voucher_mail_confirm']==='0'&&ownOriginalDatabaseHash($db,$tables)===$naBefore,'native deliberate template review prepares a new key with the current version and preserves the complete separate unsaved proposal');
$naAccepted=array_replace($naPost,['request_key'=>$naPrepared['key'],'template_version'=>$naPrepared['version']]);EventVoucherMailActions::handle($nmMail,$nmVouchers,'own-list-admin',$naAccepted,$naSession);
$check($nmVouchers->confirmation($naPrepared['key'])['payload']===['mail-template',$naEvent,1,['subject'=>$naPost['mail_subject'],'message'=>$naPost['mail_message']]],'only the separate native save accepts the deliberately reviewed original template proposal');
$naQuery=['voucher_event'=>$naEvent,'voucher_task'=>'mail','voucher_mail_section'=>'personal','voucher_mail_person'=>$nmPeople['own']];$naGeneral=EventVoucherManageState::prepare($nmVouchers,'own-list-admin',$naQuery,$naSession);$naState=$nmMail->workspace($naGeneral,$naQuery,$naSession);$naDraft=$naState['draft'];
$naSend=['csrf'=>$naSession['csrf'],'action'=>$naDraft['action'],'voucher_event'=>$naEvent,'request_key'=>$naDraft['key']]+$naDraft['values']+['voucher_mail_confirm'=>'1'];
$naForeign=$naSession;$naForeign['voucher_mail_reviews'][$naDraft['key']]['actor']='own-list-reader';$naBefore=ownOriginalDatabaseHash($db,$tables);$deny(function()use($nmMail,$nmVouchers,$naSend,&$naForeign){EventVoucherMailActions::handle($nmMail,$nmVouchers,'own-list-admin',$naSend,$naForeign);},'native original review cannot switch its creating account');
$check(ownOriginalDatabaseHash($db,$tables)===$naBefore,'foreign cached native mail review creates no queue request receipt or other business change');
$nmMail->saveTemplate($naEvent,2,['subject'=>'Own changed current recipient preview','message'=>'0'],$nmKey());$naBefore=ownOriginalDatabaseHash($db,$tables);$naCode=0;
try{$nmOwnedMail(function()use($nmMail,$nmVouchers,$naSend,&$naSession){EventVoucherMailActions::handle($nmMail,$nmVouchers,'own-list-admin',$naSend,$naSession);});}catch(DomainException $failure){$naCode=$failure->getCode();}
$naResume=EventVoucherManagementActions::resume($nmVouchers,$naSession,'own-list-admin',$naDraft['key']);
$check($naCode===409&&$naResume['values']['preview_hash']===$naDraft['values']['preview_hash']&&ownOriginalDatabaseHash($db,$tables)===$naBefore,'actual changed native mail preview refuses an old confirmation and retains its first actor-bound hash key and complete proposal');
$naReview=EventVoucherMailActions::handle($nmMail,$nmVouchers,'own-list-admin',$naSend+['voucher_mail_compose'=>'review-current','voucher_mail_review_current'=>'1'],$naSession)['draft'];
$check($naReview['key']!==$naDraft['key']&&$naReview['values']['preview_hash']!==$naDraft['values']['preview_hash']&&$naReview['values']['voucher_mail_confirm']==='0'&&ownOriginalDatabaseHash($db,$tables)===$naBefore,'native personal re-review produces a separate unconfirmed current snapshot with a new key and no business write');

final class OwnNativeMailMissingCommit extends PDO {
 public ?string $loseForKey=null;
 public function commit():bool{
  $accepted=false;if($this->loseForKey!==null){$q=$this->prepare('SELECT COUNT(*) FROM event_voucher_receipts WHERE request_key=?');$q->execute([$this->loseForKey]);$accepted=(int)$q->fetchColumn()===1;}
  $result=parent::commit();if($accepted){$this->loseForKey=null;throw new PDOException('Own native mail accepted commit acknowledgement lost');}return$result;
 }
}
$naLostDb=new OwnNativeMailMissingCommit($nmDsn,$nmConfig['username'],$nmConfig['password'],$nmOptions);$naLostDb->loseForKey=$naReview['key'];$naLostPost=array_replace($naSend,['request_key'=>$naReview['key'],'preview_hash'=>$naReview['values']['preview_hash']]);$naLost=false;
try{$nmOwnedMail(function()use($naLostDb,$naLostPost,&$naSession){EventVoucherMailActions::handle(new EventVoucherMail($naLostDb,'own-list-admin'),new EventVouchers($naLostDb,'own-list-admin'),'own-list-admin',$naLostPost,$naSession);});}catch(PDOException){$naLost=true;}
$naOriginal=$nmVouchers->confirmation($naReview['key']);$naBefore=ownOriginalDatabaseHash($db,$tables);$naResume=EventVoucherManagementActions::resume($nmVouchers,$naSession,'own-list-admin',$naReview['key']);$naPending=$nmMail->pendingDelivery($naEvent,$nmPeople['own']);
$check($naLost&&$naResume['key']===$naReview['key']&&$naOriginal['result']===$naPending['id']&&$nmMail->original($naEvent,$naPending['id'])['snapshot']===EventVoucherMailDraft::reviewed($naSession,'own-list-admin',$naReview)['snapshot']&&ownOriginalDatabaseHash($db,$tables)===$naBefore,'actual lost native mail commit acknowledgement resumes by reading its one accepted exact original without another POST or second delivery');
$naGeneral=EventVoucherManageState::prepare($nmVouchers,'own-list-admin',$naQuery,$naSession);$naState=$nmMail->workspace($naGeneral,$naQuery,$naSession);$check($naState['pending_delivery']['id']===$naPending['id']&&ownOriginalDatabaseHash($db,$tables)===$naBefore,'native current personal workspace exposes its existing original pending job instead of offering a duplicate queue');
$naCsrf=array_replace($naLostPost,['csrf'=>'Own invalid token']);$deny(function()use($nmMail,$nmVouchers,$naCsrf,&$naSession){EventVoucherMailActions::handle($nmMail,$nmVouchers,'own-list-admin',$naCsrf,$naSession);},'native mail action refuses an invalid CSRF token');
$naReader=new EventVouchers($db,'own-list-reader');$naRoleCode=0;try{EventVoucherMailActions::handle(new EventVoucherMail($db,'own-list-reader'),$naReader,'own-list-reader',$naLostPost,$naSession);}catch(DomainException $failure){$naRoleCode=$failure->getCode();}
$check($naRoleCode===403&&ownOriginalDatabaseHash($db,$tables)===$naBefore,'current ordinary reader cannot use a native mail action or its earlier administrator proposal and leaves all business originals exact');
