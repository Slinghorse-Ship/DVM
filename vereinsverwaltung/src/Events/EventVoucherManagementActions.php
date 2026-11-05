<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

require_once __DIR__.'/EventVoucherDraft.php';

/** Native unsaved selection and deliberate acceptance use the existing canonical business actions. */
final class EventVoucherManagementActions
{
    public static function handle(EventVouchers $service,string $actor,array $post,array &$session):array
    {
        if(!is_string($post['csrf']??null)||!is_string($session['csrf']??null)||$session['csrf']===''||!hash_equals($session['csrf'],$post['csrf']))throw new DomainException('Formular abgelaufen. Bitte neu laden.',403);
        $draft=EventVoucherDraft::restore($post);EventVoucherDraft::validate($draft);
        self::authorize($service,$draft);
        if($draft['compose']!==''){
            if($draft['compose']==='review-version'){
                if(($post['voucher_review_current']??'')!=='1')throw new DomainException('Aktuellen Gutscheinstand vor einem neuen Vorgang ausdrücklich prüfen.',422);
                $current=$service->currentFormVersion($draft['event'],$draft['action']==='event-voucher-configure'?'':($draft['values']['voucher_id']??''));
                $draft['version']=(string)$current['version'];$draft['key']=bin2hex(random_bytes(16));$draft['compose']='';$draft['reviewed_current']=$current;
            }else $draft=EventVoucherDraft::browse($draft);
            self::remember($session,'voucher_native_drafts',$actor,$draft);
            return ['draft'=>$draft,'unsaved'=>true];
        }
        self::remember($session,'voucher_native_attempts',$actor,$draft);
        $values=$draft['values'];$event=$draft['event'];$key=$draft['key'];$result=null;
        switch($draft['action']){
            case 'event-voucher-open':$event=$service->open($values['source_id']??'',$key);break;
            case 'event-voucher-grant':$result=$service->grant($event,$draft['contacts'],EventVoucherDraft::benefits($draft),$key);break;
            case 'event-voucher-redeem':case 'event-voucher-revoke':
                $result=$service->change($event,$values['voucher_id']??'',(int)$draft['version'],$draft['action']==='event-voucher-redeem'?'redeem':'revoke',$values['amount']??'',$values['reason']??'',$key,$values['ticket_id']??'');break;
            case 'event-voucher-delete':
                $result=$service->change($event,$values['voucher_id']??'',(int)$draft['version'],'revoke','','Gutschein gelöscht',$key,$values['ticket_id']??'');break;
            case 'event-voucher-reverse':$result=$service->reverse($event,$values['move_id']??'',$values['reason']??'',$key);break;
            case 'event-voucher-configure':$service->configure($event,(int)$draft['version'],($values['enabled']??'')==='1',$draft['helpers'],$key);break;
        }
        unset($session['voucher_native_drafts'][$key]);
        return ['draft'=>$draft,'result'=>$result,'redirect'=>['view'=>'portal','tab'=>'vouchers','voucher_scope'=>'manage','voucher_event'=>$event,'voucher_task'=>'receipts','voucher_receipt'=>$key],'notice'=>'Gutscheinänderung bestätigt.'];
    }

    public static function authorize(EventVouchers $service,array $draft):void
    {
        $access=$service->workspaceAccess();
        if($draft['action']==='event-voucher-redeem'){
            if(!$service->canRedeem($draft['event']))throw new DomainException('Keine Einlöseberechtigung für diese Veranstaltung.',403);
        }elseif(!$access['manage'])throw new DomainException('Keine Berechtigung für die Gutscheinverwaltung.',403);
    }

    private static function remember(array &$session,string $section,string $actor,array $draft):void
    {
        $rows=is_array($session[$section]??null)?$session[$section]:[];unset($rows[$draft['key']]);
        $rows[$draft['key']]=['actor'=>$actor,'draft'=>$draft,'at'=>gmdate('c')];$session[$section]=array_slice($rows,-20,null,true);
    }

    public static function drafts(array $session,string $actor,array $access,string $section='voucher_native_attempts'):array
    {
        $rows=$session[$section]??[];if(!is_array($rows))return[];
        return array_values(array_reverse(array_filter($rows,static fn(mixed $row):bool=>is_array($row)&&($row['actor']??null)===$actor&&is_array($row['draft']??null)&&(!empty($access['manage'])||(($row['draft']['action']??null)==='event-voucher-redeem'&&in_array($row['draft']['event']??'', $access['helpers']??[],true)))),true));
    }

    public static function resume(EventVouchers $service,array $session,string $actor,string $key):array
    {
        foreach(['voucher_native_attempts','voucher_native_drafts']as$section){
            $row=$session[$section][$key]??null;
            if(is_array($row)&&($row['actor']??null)===$actor&&is_array($row['draft']??null)){self::authorize($service,$row['draft']);return$row['draft'];}
        }
        throw new DomainException('Formularentwurf nicht verfügbar. Bestätigte Vorgänge bleiben separat lesbar.',404);
    }
}
