<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
require_once __DIR__.'/EventVoucherMailDraft.php';

/** Native mail preparation follows the existing current general event workspace. */
final class EventVoucherMailState
{
    private static function initial():array
    {
        return ['section'=>'people','query'=>[],'error'=>'','errors'=>[],'http_status'=>0,'template'=>null,'template_draft'=>null,'template_open'=>false,'people'=>null,'preview'=>null,'preview_cards'=>null,'pending_delivery'=>null,'batch'=>null,'deliveries'=>null,'original'=>null,'draft'=>null,'original_review'=>null,'review_changed'=>false,'queue_available'=>MailPolicy::allowed((string)getenv('VEREIN_PUBLIC_URL'))];
    }
    public static function failed(\Throwable $failure):array
    {
        $state=self::initial();$state['error']=$failure instanceof DomainException?$failure->getMessage():'Gutschein-Mailbereich konnte nicht geladen werden.';
        $state['http_status']=$failure instanceof DomainException?(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():403):500;
        return$state;
    }

    public static function prepare(EventVoucherMail $service,array $general,array $query,array &$session,array $legacyPost=[]):array
    {
        $state=self::initial();
        if(($general['error']??'')!==''||!($general['access']['manage']??false)||$general['event']===null){$state['error']='Keine Berechtigung für die Gutscheinverwaltung.';$state['http_status']=403;return$state;}
        $event=$general['event']['event']['id'];$actor=$general['access']['actor']['id'];$draft=$general['draft'];
        if($draft!==null&&!in_array($draft['action'],EventVoucherMailDraft::ACTIONS,true))$draft=null;
        if($draft===null&&($legacyPost['action']??'')==='event-voucher-mail-template'){
            $draft=EventVoucherMailDraft::restore($legacyPost,true);
            if($draft['event']==='')$draft['event']=$event;
        }
        $state['draft']=$draft;
        $person=$query['voucher_mail_person']??($query['mail_to']??'');$original=$query['voucher_mail_delivery']??'';
        $section=$query['voucher_mail_section']??($original!==''?'history':($person!==''?'personal':(($query['mail_all']??'')==='1'?'batch':(($query['mail_template']??'')==='1'?'template':'people'))));
        if($draft!==null){
            $section=match($draft['action']){'event-voucher-mail-template'=>'template','event-voucher-mail'=>'personal','event-voucher-mail-all'=>'batch','event-voucher-mail-cancel'=>'history'};
            if($draft['action']==='event-voucher-mail')$person=$draft['values']['contact_id']??'';
            if($draft['action']==='event-voucher-mail-cancel')$original=$draft['values']['mail_id']??'';
        }
        try{
            if(!is_string($section)||!in_array($section,['template','people','personal','batch','history'],true))throw new DomainException('Gültigen Gutschein-Mailbereich auswählen.',422);
            $state['section']=$section;$state['template']=$service->template($event);
            $state['template_draft']=$draft!==null&&$draft['action']==='event-voucher-mail-template'?$draft:EventVoucherMailDraft::create('event-voucher-mail-template',$event,(string)$state['template']['version']);
            if($draft===null||$draft['action']!=='event-voucher-mail-template')$state['template_draft']['values']=['mail_subject'=>$state['template']['data']['subject'],'mail_message'=>$state['template']['data']['message']];
            $state['template_open']=$section==='template'||($query['mail_template']??'')==='1';
            if($section==='people'){
                try{$state['people']=$service->peoplePage($event,['query'=>$query['voucher_mail_people_query']??'','page'=>$query['voucher_mail_people_page']??1]);}
                catch(DomainException $failure){$state['errors']['people']=$failure->getMessage();$state['http_status']=422;}
            }
            if($section==='personal'){
                if(!is_string($person)||preg_match('/^[a-f0-9]{32}$/D',$person)!==1)throw new DomainException('Gutscheinempfänger ausdrücklich auswählen.',422);
                if(!$general['event']['available'])throw new DomainException($general['event']['notice'],409);
                $state['preview']=$service->preview($event,$person);
                $state['pending_delivery']=$service->pendingDelivery($event,$person);
                if($draft===null){
                    $draft=EventVoucherMailDraft::create('event-voucher-mail',$event);$draft['values']=['contact_id'=>$person,'preview_hash'=>$state['preview']['hash']];
                    EventVoucherMailDraft::review($session,$actor,$draft,$state['preview']);
                    EventVoucherMailDraft::remember($session,'voucher_native_drafts',$actor,$draft);
                }
                try{$state['preview_cards']=$service->previewPage($state['preview'],['query'=>$query['voucher_mail_cards_query']??'','page'=>$query['voucher_mail_cards_page']??1]);}
                catch(DomainException $failure){$state['errors']['preview_cards']=$failure->getMessage();$state['http_status']=422;}
            }
            if($section==='batch'){
                if(!$general['event']['available'])throw new DomainException($general['event']['notice'],409);
                $state['batch']=$service->batchPages($event,['ready_query'=>$query['voucher_mail_ready_query']??'','ready_page'=>$query['voucher_mail_ready_page']??1,'skipped_query'=>$query['voucher_mail_skipped_query']??'','skipped_page'=>$query['voucher_mail_skipped_page']??1]);
                $state['http_status']=max($state['http_status'],$state['batch']['http_status']);
                if($draft===null){
                    $draft=EventVoucherMailDraft::create('event-voucher-mail-all',$event);$draft['values']=['preview_hash'=>$state['batch']['hash']];
                    EventVoucherMailDraft::review($session,$actor,$draft,$state['batch']['review']);
                    EventVoucherMailDraft::remember($session,'voucher_native_drafts',$actor,$draft);
                }
                unset($state['batch']['review']);
            }
            if($section==='history'){
                try{$state['deliveries']=$service->deliveriesPage($event,['query'=>$query['voucher_mail_history_query']??'','page'=>$query['voucher_mail_history_page']??1,'state'=>$query['voucher_mail_state']??'all']);}
                catch(DomainException $failure){$state['errors']['deliveries']=$failure->getMessage();$state['http_status']=422;}
                if($original!==''){
                    if(!is_string($original))throw new DomainException('Versandvorgang nicht verfügbar.',403);
                    $state['original']=$service->original($event,$original);
                    if($draft===null&&in_array($state['original']['delivery']['state'],['pending','uncertain','blocked'],true)){
                        $row=$state['original']['delivery'];$draft=EventVoucherMailDraft::create('event-voucher-mail-cancel',$event);
                        $draft['values']=['mail_id'=>$row['id'],'expected_state'=>$row['state'],'expected_at'=>$row['updated_at'],'expected_fingerprint'=>$row['fingerprint']];
                        EventVoucherMailDraft::remember($session,'voucher_native_drafts',$actor,$draft);
                    }
                }
            }
            $state['draft']=$draft;
            if($draft!==null&&in_array($draft['action'],['event-voucher-mail','event-voucher-mail-all'],true)){
                try{$state['original_review']=EventVoucherMailDraft::reviewed($session,$actor,$draft);}catch(DomainException){/* A missing original review is explicit; never fabricate its former content. */}
                $currentHash=$section==='personal'?$state['preview']['hash']:($state['batch']['hash']??'');
                $state['review_changed']=!hash_equals($draft['values']['preview_hash']??'',$currentHash);
            }
        }catch(DomainException $failure){
            $state['error']=$failure->getMessage();$state['http_status']=in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():403;
            if($state['http_status']===403){$state['preview']=null;$state['preview_cards']=null;$state['batch']=null;$state['original']=null;}
        }catch(\Throwable){$state['error']='Gutschein-Mailbereich konnte nicht geladen werden.';$state['http_status']=500;}
        foreach(['voucher_mail_section','voucher_mail_person','voucher_mail_delivery','voucher_mail_people_query','voucher_mail_people_page','voucher_mail_cards_query','voucher_mail_cards_page','voucher_mail_ready_query','voucher_mail_ready_page','voucher_mail_skipped_query','voucher_mail_skipped_page','voucher_mail_history_query','voucher_mail_history_page','voucher_mail_state']as$field)if(is_string($query[$field]??null)&&preg_match('//u',$query[$field])===1)$state['query'][$field]=$query[$field];
        $state['query']['voucher_mail_section']=$state['section'];
        if(is_string($person)&&$person!=='')$state['query']['voucher_mail_person']=$person;
        if(is_string($original)&&$original!=='')$state['query']['voucher_mail_delivery']=$original;
        return$state;
    }
}
