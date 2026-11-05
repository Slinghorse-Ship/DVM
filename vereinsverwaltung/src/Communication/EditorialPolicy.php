<?php declare(strict_types=1);
namespace Kulturhus\Verein;

/** Current club role matrix, independent of the authentication provider. */
final class EditorialPolicy
{
    public const AREAS=['texts'=>'Seiten & Aktuelles','events'=>'Veranstaltungen','gallery'=>'Galerie','newsletter'=>'Newsletter & Empfänger','press'=>'Presse','social'=>'Social Media'];
    public static function section(string $section):string
    {
        return match($section){'page','pages','news','navigation'=>'texts','recipient','recipients'=>'newsletter',default=>$section};
    }
    public static function operation(string $operation):string
    {
        return match(explode('/',$operation)[0]){'settings','fixtures'=>'technical','image'=>'image',default=>self::section(explode('/',$operation)[0])};
    }
    public static function level(array $actor,string $section):string
    {
        $section=self::section($section);
        if(!isset(self::AREAS[$section]))return 'none';
        if(($actor['website_access']??null)!==null){
            $matrix=is_array($actor['website_access'])?$actor['website_access']:json_decode((string)$actor['website_access'],true);
            return is_array($matrix)&&in_array($matrix[$section]??null,['none','read','write'],true)?$matrix[$section]:'none';
        }
        if(($actor['role']??'')==='editor'||!empty($actor['settings_access'])||!empty($actor['is_superuser']))return 'write';
        return $section==='press'&&in_array($actor['press_access']??'', ['read','write'],true)?$actor['press_access']:'none';
    }
}
