<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;
use Kulturhus\Native\Web\Html;

/** An explicit person and original record version, independent of list order. */
final class MemberBankForm
{
    public const ACTIONS=['member-bank-begin','member-bank-confirm','member-bank-cancel'];
    public const OPERATIONS=['save','mandate','revoke','delete','reveal'];
    public static function text(mixed $value,int $max=200):string
    {
        if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||mb_strlen($value)>$max||preg_match('/[\x00-\x1f\x7f]/',$value))throw new DomainException('Bankangaben im ursprünglich geöffneten Mitgliederprofil prüfen.',422);return$value;
    }
    public static function version(mixed $value):int
    {
        if(!is_string($value)||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$value)||filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>4294967295]])===false)throw new DomainException('Ursprünglichen Bankdatenstand prüfen.',422);return(int)$value;
    }
    public static function frame(array $query,array $post=[]):void
    {
        if(($query['view']??null)!=='portal'||($query['tab']??null)!=='bank'||array_diff(array_keys($query),['view','tab','person','member_q','member_page'])!==[])throw new DomainException('Bankangaben im ursprünglich geöffneten Mitgliederprofil prüfen.',422);
        foreach(['person'=>128,'member_q'=>200]as$key=>$max)if(array_key_exists($key,$query))self::text($query[$key],$max);
        if(isset($query['member_page'])){if(self::version($query['member_page'])<1)throw new DomainException('Seite der Mitgliederauswahl prüfen.',422);}
        if($post&&!in_array($post['action']??null,self::ACTIONS,true))throw new DomainException('Bankformular-Aktion prüfen.',422);
    }
    public static function target(array $query,array $post,string $own):string
    {
        self::frame($query,$post);$original=self::text($post['member_bank_contact']??null,128);$target=array_key_exists('person',$query)?self::text($query['person'],128):$own;
        if($original===''||$target!==$original)throw new DomainException('Bankangaben im ursprünglich geöffneten Mitgliederprofil prüfen.',422);return$original;
    }
    public static function hidden(string $name,mixed $value):string
    {
        if(is_array($value)){$h='';foreach($value as$k=>$v)$h.=self::hidden($name.'['.$k.']',$v);return$h;}return Html::hidden($name,is_scalar($value)?(string)$value:'');
    }
    public static function url(?string $person=null,array $extra=[]):string{return'?'.http_build_query(['view'=>'portal','tab'=>'bank']+($person!==null?['person'=>$person]:[])+$extra);}
}
