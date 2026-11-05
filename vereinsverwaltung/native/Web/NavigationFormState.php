<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;

use InvalidArgumentException;

/** Bounded human input only. This is neither a saved menu nor an authority token. */
final class NavigationFormState
{
    public static function restore(array $post):array
    {
        $version=self::version($post['version']??null);
        $seen=self::version($post['navigation_seen_version']??null);
        $actor=self::binding($post['navigation_actor']??null);
        $club=self::binding($post['navigation_club']??null);
        $rows=$post['items']??[];
        if(!is_array($rows)||count($rows)>1000)self::invalid();
        $items=[];$bytes=0;
        foreach($rows as $id=>$row){
            if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id)||!is_array($row)||array_diff(array_keys($row),['label','position','visible']))self::invalid();
            $label=self::text($row['label']??null,500);$position=self::text($row['position']??null,32);
            $bytes+=strlen($label)+strlen($position);if($bytes>262144)self::invalid();
            $items[$id]=['label'=>$label,'position'=>$position,'visible'=>self::checked($row,'visible')];
        }
        $browse=$post['navigation_browse']??'';
        if(!is_string($browse)||!in_array($browse,['','compare','resume'],true))self::invalid();
        return ['version'=>$version,'seen_version'=>$seen,'actor'=>$actor,'club'=>$club,'items'=>$items,'automatic_program_year'=>self::checked($post,'automatic_program_year'),'browse'=>$browse];
    }
    public static function submitted(array $post):bool
    {return ($post['operation']??null)==='navigation-save';}
    public static function version(mixed $value):int
    {
        if(is_int($value))$value=(string)$value;
        if(!is_string($value)||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$value)||strlen($value)===10&&strcmp($value,'4294967295')>0)self::invalid();
        return (int)$value;
    }
    private static function binding(mixed $value):string
    {
        if(!is_string($value)||$value===''||strlen($value)>64||!mb_check_encoding($value,'UTF-8')||preg_match('/[\x00-\x1f\x7f]/',$value))self::invalid();
        return $value;
    }
    private static function text(mixed $value,int $maximum):string
    {
        if(!is_string($value)||strlen($value)>$maximum*4||!mb_check_encoding($value,'UTF-8')||mb_strlen($value)>$maximum||preg_match('/[\x00-\x1f\x7f]/',$value))self::invalid();
        return $value;
    }
    private static function checked(array $input,string $name):bool
    {
        if(!array_key_exists($name,$input))return false;
        if(!is_string($input[$name])||!in_array($input[$name],['1','on'],true))self::invalid();
        return true;
    }
    private static function invalid():never
    {throw new InvalidArgumentException('Ungültiges Menüformular. Bitte den Menübereich erneut öffnen.');}
}
