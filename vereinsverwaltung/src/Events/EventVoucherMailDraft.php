<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;

/** Compatible with the existing actor-bound voucher draft and outcome links. */
final class EventVoucherMailDraft
{
    public const ACTIONS=['event-voucher-mail-template','event-voucher-mail','event-voucher-mail-all','event-voucher-mail-cancel'];
    private const VALUES=['mail_subject','mail_message','contact_id','preview_hash','mail_id','expected_state','expected_at','expected_fingerprint','voucher_mail_confirm','voucher_mail_review_current'];
    public static function create(string $action,string $event,string $version=''):array
    {
        return ['action'=>$action,'event'=>$event,'key'=>bin2hex(random_bytes(16)),'version'=>$version,'values'=>[],'contacts'=>[],'helpers'=>[],'query'=>[],'compose'=>''];
    }
    public static function restore(array $post,bool $retain=false):array
    {
        $text=static function(mixed $value)use($retain):string{if(!is_string($value)||preg_match('//u',$value)!==1){if($retain)return'';throw new DomainException('Vollständige gültige Formulareingaben verwenden.',422);}return$value;};
        $draft=['action'=>'','event'=>'','key'=>'','version'=>'','values'=>[],'contacts'=>[],'helpers'=>[],'query'=>[],'compose'=>''];
        foreach(['action'=>'action','voucher_event'=>'event','request_key'=>'key','template_version'=>'version','voucher_mail_compose'=>'compose']as$field=>$target)$draft[$target]=$text($post[$field]??'');
        foreach(self::VALUES as$field)if(array_key_exists($field,$post))$draft['values'][$field]=$text($post[$field]);
        return$draft;
    }
    public static function validate(array $draft):void
    {
        if(!in_array($draft['action'],self::ACTIONS,true)||!preg_match('/^[a-f0-9]{32}$/D',$draft['key']))throw new DomainException('Formular abgelaufen. Bitte neu laden.',422);
        if(!preg_match('/^[a-f0-9]{32}$/D',$draft['event']))throw new DomainException('Veranstaltung nicht verfügbar.',403);
        if($draft['action']==='event-voucher-mail-template'&&!preg_match('/^(?:0|[1-9][0-9]{0,8})$/D',$draft['version']))throw new DomainException('Ursprüngliche Mailvorlagenfassung prüfen.',422);
        if($draft['action']==='event-voucher-mail'&&!preg_match('/^[a-f0-9]{32}$/D',$draft['values']['contact_id']??''))throw new DomainException('Gutscheinempfänger ausdrücklich auswählen.',422);
        if(in_array($draft['action'],['event-voucher-mail','event-voucher-mail-all'],true)&&!preg_match('/^[a-f0-9]{64}$/D',$draft['values']['preview_hash']??''))throw new DomainException('Ursprüngliche Versandvorschau erneut prüfen.',422);
        if($draft['action']==='event-voucher-mail-cancel'&&!preg_match('/^[a-f0-9]{32}$/D',$draft['values']['mail_id']??''))throw new DomainException('Versandvorgang nicht verfügbar.',403);
    }
    public static function remember(array &$session,string $section,string $actor,array $draft):void
    {
        $rows=is_array($session[$section]??null)?$session[$section]:[];unset($rows[$draft['key']]);$rows[$draft['key']]=['actor'=>$actor,'draft'=>$draft,'at'=>gmdate('c')];$session[$section]=array_slice($rows,-20,null,true);
    }
    public static function review(array &$session,string $actor,array $draft,array $data):void
    {
        $rows=is_array($session['voucher_mail_reviews']??null)?$session['voucher_mail_reviews']:[];unset($rows[$draft['key']]);$rows[$draft['key']]=['actor'=>$actor,'event'=>$draft['event'],'action'=>$draft['action'],'data'=>$data,'at'=>gmdate('c')];$session['voucher_mail_reviews']=array_slice($rows,-20,null,true);
    }
    public static function reviewed(array $session,string $actor,array $draft):array
    {
        $review=$session['voucher_mail_reviews'][$draft['key']]??null;
        if(!is_array($review)||($review['actor']??null)!==$actor||($review['event']??null)!==$draft['event']||($review['action']??null)!==$draft['action']||!is_array($review['data']??null))throw new DomainException('Ursprüngliche Versandvorschau erneut prüfen.',422);
        if(!is_string($review['data']['hash']??null)||!hash_equals($review['data']['hash'],$draft['values']['preview_hash']??''))throw new DomainException('Ursprüngliche Versandvorschau erneut prüfen.',422);
        return$review['data'];
    }
}
