<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;

/** A failed or unsaved native action retains its original key, expected version and full proposal. */
final class EventVoucherDraft
{
    public const ACTIONS=['event-voucher-open','event-voucher-grant','event-voucher-redeem','event-voucher-revoke','event-voucher-delete','event-voucher-reverse','event-voucher-configure'];

    public static function create(string $action,string $event='',string $version=''):array
    {
        return ['action'=>$action,'event'=>$event,'key'=>bin2hex(random_bytes(16)),'version'=>$version,'values'=>[],'contacts'=>[],'helpers'=>[],'query'=>[],'compose'=>''];
    }

    public static function restore(array $post):array
    {
        $draft=['action'=>'','event'=>'','key'=>'','version'=>'','values'=>[],'contacts'=>[],'helpers'=>[],'query'=>[],'compose'=>''];
        foreach(['action'=>'action','voucher_event'=>'event','request_key'=>'key','version'=>'version','voucher_compose'=>'compose']as$field=>$target)$draft[$target]=self::text($post[$field]??'');
        foreach(['source_id','voucher_id','ticket_id','move_id','amount','reason','enabled','food_label','food_unit','food_amount','drink_label','drink_unit','drink_amount','other_label','other_unit','other_amount']as$field)if(array_key_exists($field,$post))$draft['values'][$field]=self::text($post[$field]);
        foreach(['members','helpers']as$section)foreach(['query','page']as$field){$name='voucher_'.$section.'_'.$field;if(array_key_exists($name,$post))$draft['query'][$name]=self::text($post[$name]);}
        $draft['contacts']=self::selection($post,'contacts');$draft['helpers']=self::selection($post,'helpers');
        return$draft;
    }

    public static function retain(array $post):array
    {
        $draft=['action'=>'','event'=>'','key'=>'','version'=>'','values'=>[],'contacts'=>[],'helpers'=>[],'query'=>[],'compose'=>'','invalid_fields'=>[]];
        $safe=static fn(mixed $value):string=>is_string($value)&&preg_match('//u',$value)===1?$value:'';
        foreach(['action'=>'action','voucher_event'=>'event','request_key'=>'key','version'=>'version','voucher_compose'=>'compose']as$field=>$target)$draft[$target]=$safe($post[$field]??'');
        foreach(['source_id','voucher_id','ticket_id','move_id','amount','reason','enabled','food_label','food_unit','food_amount','drink_label','drink_unit','drink_amount','other_label','other_unit','other_amount']as$field)if(array_key_exists($field,$post))$draft['values'][$field]=$safe($post[$field]);
        foreach(['members','helpers']as$section)foreach(['query','page']as$field){$name='voucher_'.$section.'_'.$field;if(array_key_exists($name,$post))$draft['query'][$name]=$safe($post[$name]);}
        foreach(['contacts','helpers']as$field){
            foreach([$post[$field.'_keep']??[],$post[$field]??[]]as$values)if(is_array($values))foreach($values as$value)if(is_string($value)&&preg_match('//u',$value)===1)$draft[$field][]=$value;
            $draft[$field]=array_values(array_unique($draft[$field]));
        }
        return$draft;
    }

    private static function text(mixed $value):string
    {
        if(!is_string($value)||preg_match('//u',$value)!==1)throw new DomainException('Vollständige gültige Formulareingaben verwenden.',422);
        return$value;
    }

    private static function ids(mixed $values):array
    {
        if(!is_array($values)||!array_is_list($values)||count($values)>10000)throw new DomainException('Gültige Personenauswahl verwenden.',422);
        $out=[];foreach($values as$value){$value=self::text($value);if($value===''||mb_strlen($value)>128||preg_match('/[\x00-\x1f\x7f]/',$value))throw new DomainException('Gültige Personenauswahl verwenden.',422);$out[]=$value;}
        return array_values(array_unique($out));
    }

    private static function selection(array $post,string $field):array
    {
        $selected=self::ids($post[$field]??[]);
        if(!array_key_exists($field.'_visible',$post)&&($post[$field.'_page_present']??null)!=='1')return$selected;
        $visible=self::ids($post[$field.'_visible']??[]);$kept=self::ids($post[$field.'_keep']??[]);
        if(array_diff($selected,$visible))throw new DomainException('Personenauswahl erneut prüfen.',422);
        return array_values(array_unique([...array_diff($kept,$visible),...$selected]));
    }

    public static function validate(array $draft):void
    {
        if(!in_array($draft['action'],self::ACTIONS,true)||!preg_match('/^[a-f0-9]{32}$/D',$draft['key']))throw new DomainException('Formular abgelaufen. Bitte neu laden.',422);
        if($draft['action']!=='event-voucher-open'&&!preg_match('/^[a-f0-9]{32}$/D',$draft['event']))throw new DomainException('Veranstaltung nicht verfügbar.',403);
        if(in_array($draft['action'],['event-voucher-redeem','event-voucher-revoke','event-voucher-delete','event-voucher-configure'],true)&&!preg_match('/^[1-9][0-9]{0,8}$/D',$draft['version']))throw new DomainException('Ursprüngliche Gutscheinfassung prüfen.',422);
        if(count($draft['contacts'])>250)throw new DomainException('Höchstens 250 Personen je Zuteilung auswählen.',422);
    }

    public static function benefits(array $draft):array
    {
        $benefits=[];$values=$draft['values'];
        foreach(['food'=>'Essen','drink'=>'Getränke','other'=>'Sonstiges']as$kind=>$label){
            $amount=$values[$kind.'_amount']??'';if(trim($amount)===''||trim($amount)==='0')continue;
            $benefits[]=['kind'=>$kind,'label'=>$values[$kind.'_label']??$label,'unit'=>$values[$kind.'_unit']??'piece','amount'=>$amount];
        }
        return$benefits;
    }

    public static function browse(array $draft):array
    {
        if(!preg_match('/^(members|helpers)-(search|next|previous|jump|clear)$/D',$draft['compose'],$match))throw new DomainException('Gültige Auswahlaktion verwenden.',422);
        if($match[2]==='clear')$draft[$match[1]==='members'?'contacts':'helpers']=[];
        $field='voucher_'.$match[1].'_page';$page=in_array($match[2],['search','clear'],true)?'1':($draft['query'][$field]??'1');
        if(!preg_match('/^[1-9][0-9]{0,8}$/D',$page))throw new DomainException('Gültige Gutscheinseite auswählen.',422);
        $number=(int)$page;$number=match($match[2]){'search','clear'=>1,'next'=>$number+1,'previous'=>max(1,$number-1),default=>$number};
        $draft['query'][$field]=(string)$number;return$draft;
    }
}
