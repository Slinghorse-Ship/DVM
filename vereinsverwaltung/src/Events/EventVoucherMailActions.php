<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
require_once __DIR__.'/EventVoucherMailDraft.php';

final class EventVoucherMailActions
{
    public static function handle(EventVoucherMail $service,EventVouchers $vouchers,string $actor,array $post,array &$session):array
    {
        if(!is_string($post['csrf']??null)||!is_string($session['csrf']??null)||$session['csrf']===''||!hash_equals($session['csrf'],$post['csrf']))throw new DomainException('Formular abgelaufen. Bitte neu laden.',403);
        $draft=EventVoucherMailDraft::restore($post);EventVoucherMailDraft::validate($draft);
        if(!$vouchers->canManage())throw new DomainException('Keine Berechtigung für die Gutscheinverwaltung.',403);
        $values=$draft['values'];$event=$draft['event'];$key=$draft['key'];$review=null;
        if($draft['compose']!==''){
            if($draft['compose']!=='review-current'||($values['voucher_mail_review_current']??'')!=='1')throw new DomainException('Aktuellen Versandstand vor einem neuen Vorgang ausdrücklich prüfen.',422);
            switch($draft['action']){
                case 'event-voucher-mail-template':$draft['version']=(string)$service->template($event)['version'];break;
                case 'event-voucher-mail':$review=$service->preview($event,$values['contact_id']);break;
                case 'event-voucher-mail-all':$review=$service->batchReview($event);break;
                case 'event-voucher-mail-cancel':
                    $current=$service->original($event,$values['mail_id'])['delivery'];
                    if(!in_array($current['state'],['pending','uncertain','blocked'],true))throw new DomainException('Versand läuft bereits oder ist abgeschlossen.',409);
                    $draft['values']['expected_state']=$current['state'];$draft['values']['expected_at']=$current['updated_at'];$draft['values']['expected_fingerprint']=$current['fingerprint'];break;
            }
            $draft['key']=bin2hex(random_bytes(16));$draft['compose']='';$draft['values']['voucher_mail_confirm']='0';$draft['values']['voucher_mail_review_current']='0';
            if($review!==null){$draft['values']['preview_hash']=$review['hash'];EventVoucherMailDraft::review($session,$actor,$draft,$review);}
            EventVoucherMailDraft::remember($session,'voucher_native_drafts',$actor,$draft);
            return ['draft'=>$draft,'unsaved'=>true];
        }
        EventVoucherMailDraft::remember($session,'voucher_native_attempts',$actor,$draft);
        if($draft['action']!=='event-voucher-mail-template'&&($values['voucher_mail_confirm']??'')!=='1')throw new DomainException('Die angezeigte Versandprüfung ausdrücklich bestätigen.',422);
        switch($draft['action']){
            case 'event-voucher-mail-template':
                $service->saveTemplate($event,(int)$draft['version'],['subject'=>$values['mail_subject']??'','message'=>$values['mail_message']??''],$key);$notice='Mailvorlage gespeichert.';break;
            case 'event-voucher-mail':
                $review=EventVoucherMailDraft::reviewed($session,$actor,$draft);
                if(($review['snapshot']['person']['id']??null)!==$values['contact_id'])throw new DomainException('Gutscheinempfänger ausdrücklich auswählen.',422);
                $service->queue($event,$values['contact_id'],$values['preview_hash'],$key);$notice='Persönliche Mail vorgemerkt.';break;
            case 'event-voucher-mail-all':
                EventVoucherMailDraft::reviewed($session,$actor,$draft);$service->queueBatch($event,$values['preview_hash'],$key);$notice='Sammelversand vorgemerkt.';break;
            case 'event-voucher-mail-cancel':
                $expected=['state'=>$values['expected_state']??'','updated_at'=>$values['expected_at']??'','fingerprint'=>$values['expected_fingerprint']??''];
                $service->cancel($event,$values['mail_id'],$key,$expected);$notice='Versandvorgang beendet.';break;
        }
        unset($session['voucher_native_drafts'][$key]);
        return ['draft'=>$draft,'redirect'=>['view'=>'portal','tab'=>'vouchers','voucher_scope'=>'manage','voucher_event'=>$event,'voucher_task'=>'receipts','voucher_receipt'=>$key],'notice'=>$notice];
    }
}
