<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;

require_once __DIR__.'/EventVoucherManagementActions.php';

/** Prepare the native general workspace before response headers; failed proposals stay separate. */
final class EventVoucherManageState
{
    public static function task(string $action):string
    {
        return match($action){'event-voucher-grant'=>'grant','event-voucher-configure'=>'configure','event-voucher-mail-template','event-voucher-mail','event-voucher-mail-all','event-voucher-mail-cancel'=>'mail',default=>'cards'};
    }

    public static function prepare(EventVouchers $service,string $actor,array $query,array $session,?array $posted=null,string $actionError='',int $actionStatus=0):array
    {
        $state=['personal'=>false,'access'=>null,'task'=>'cards','event'=>null,'events'=>null,'sources'=>null,'cards'=>null,'history'=>null,'members'=>null,'helpers'=>null,'receipts'=>null,'receipt'=>null,'errors'=>[],'error'=>'','action_error'=>$actionError,'draft_error'=>'','http_status'=>$actionStatus,'draft'=>null,'target'=>null,'selected_contacts'=>[],'selected_helpers'=>[],'current_helpers'=>[],'attempts'=>[],'drafts'=>[],'proposal_restored'=>false,'query'=>[]];
        if(array_key_exists('print_person',$query))$query['voucher_task']='print';
        if(array_intersect(['mail_to','mail_all','mail_template'],array_keys($query)))$query['voucher_task']='mail';
        if(!array_key_exists('voucher_cards_query',$query)&&array_key_exists('q',$query))$query['voucher_cards_query']=$query['q'];
        try{
            $access=$service->workspaceAccess();if(!$access['manage']&&!$access['helpers'])throw new DomainException('Keine Berechtigung für die Gutscheinverwaltung.',403);
            $state['access']=$access;$state['attempts']=EventVoucherManagementActions::drafts($session,$actor,$access);$state['drafts']=EventVoucherManagementActions::drafts($session,$actor,$access,'voucher_native_drafts');
            $draft=$posted;
            if($draft===null&&array_key_exists('voucher_resume',$query)){
                if(!is_string($query['voucher_resume']))throw new DomainException('Formularentwurf nicht verfügbar. Bestätigte Vorgänge bleiben separat lesbar.',404);
                $draft=EventVoucherManagementActions::resume($service,$session,$actor,$query['voucher_resume']);
            }
            $state['proposal_restored']=$draft!==null;
            if($draft!==null){
                EventVoucherManagementActions::authorize($service,$draft);
                $query=array_replace($query,$draft['query'],['voucher_event'=>$draft['event'],'voucher_task'=>self::task($draft['action'])]);
                unset($query['voucher_receipt']);$state['draft']=$draft;
            }
            $workspace=$service->managementWorkspace($query);$state=array_replace($state,$workspace);$state['http_status']=max($actionStatus,$workspace['http_status']);
            $state['attempts']=EventVoucherManagementActions::drafts($session,$actor,$workspace['access']);
            if($workspace['task']==='grant'){
                if($draft===null)$state['draft']=EventVoucherDraft::create('event-voucher-grant',$query['voucher_event']??'');
                $state['selected_contacts']=$service->selectedPeople($state['draft']['contacts']);
            }elseif($workspace['task']==='configure'&&$workspace['event']!==null){
                $state['current_helpers']=$service->selectedPeople($workspace['selected_helpers'],true);
                if($draft===null){
                    $state['draft']=EventVoucherDraft::create('event-voucher-configure',$workspace['event']['event']['id'],(string)$workspace['event']['event']['version']);
                    $state['draft']['helpers']=$workspace['selected_helpers'];$state['draft']['values']['enabled']=$workspace['event']['event']['enabled']?'1':'0';
                }
                $state['selected_helpers']=$service->selectedPeople($state['draft']['helpers'],true);
            }elseif($draft!==null&&in_array($draft['action'],['event-voucher-redeem','event-voucher-revoke','event-voucher-delete'],true)){
                try{$state['target']=$service->formTarget($draft['event'],$draft['values']['voucher_id']??'',$draft['values']['ticket_id']??'');}
                catch(DomainException $failure){$state['draft_error']=$failure->getMessage();$state['http_status']=max($state['http_status'],$failure->getCode()===503?503:422);}
            }elseif($draft!==null&&$draft['action']==='event-voucher-reverse'){
                try{$state['target']=$service->moveTarget($draft['event'],$draft['values']['move_id']??'');}
                catch(DomainException $failure){$state['draft_error']=$failure->getMessage();$state['http_status']=max($state['http_status'],$failure->getCode()===503?503:422);}
            }
        }catch(DomainException $failure){
            $state['error']=$failure->getMessage();$state['http_status']=in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():403;
            // Revoked general access must not expose an earlier private session proposal.
            if($state['access']===null||$state['http_status']===403){$state['draft']=null;$state['attempts']=[];$state['drafts']=[];$state['access']=null;}
        }catch(\Throwable){$state['error']='Gutscheinverwaltung konnte nicht geladen werden.';$state['http_status']=500;}
        foreach(['voucher_event','voucher_task','voucher_receipt','voucher_events_query','voucher_events_page','voucher_sources_query','voucher_sources_page','voucher_cards_query','voucher_cards_page','voucher_card_state','voucher_history_query','voucher_history_page','voucher_members_query','voucher_members_page','voucher_helpers_query','voucher_helpers_page','voucher_receipts_query','voucher_receipts_page','voucher_mail_originals_query','voucher_mail_originals_page','voucher_mail_section','voucher_mail_person','voucher_mail_delivery','voucher_mail_people_query','voucher_mail_people_page','voucher_mail_cards_query','voucher_mail_cards_page','voucher_mail_ready_query','voucher_mail_ready_page','voucher_mail_skipped_query','voucher_mail_skipped_page','voucher_mail_history_query','voucher_mail_history_page','voucher_mail_state','print_person']as$field)if(is_string($query[$field]??null)&&preg_match('//u',$query[$field])===1)$state['query'][$field]=$query[$field];
        return$state;
    }
}
