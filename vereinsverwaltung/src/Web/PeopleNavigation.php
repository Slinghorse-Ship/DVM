<?php declare(strict_types=1);
namespace Kulturhus\Verein;

/** Shared route aliases; navigation never grants access to a workspace. */
final class PeopleNavigation
{
    public static function groupFor(array $route): string
    {
        $view=(string)($route['view']??'');$tab=(string)($route['tab']??'');
        if($view==='portal'&&in_array($tab,['application','admissions'],true))return 'applications';
        if($view==='contacts-extra')return 'contacts';
        if($view==='members-extra'||($view==='members'&&($route['list']??'')==='birthdays'))return 'occasions';
        if($view!=='members')return '';
        return ($route['list']??'')==='contacts'||($route['create_as']??'')==='contact'?'contacts':'members';
    }

    public static function tabs(string $group): array
    {
        return match($group){
            'contacts'=>[
                ['label'=>'Kontakte','route'=>['view'=>'members','list'=>'contacts']],
                ['label'=>'Favoriten','route'=>['view'=>'contacts-extra','tab'=>'favourites']],
                ['label'=>'Gespeicherte Kontaktlisten','route'=>['view'=>'members','list'=>'contacts','show_lists'=>1]],
            ],
            'occasions'=>[
                ['label'=>'Geburtstage','route'=>['view'=>'members','list'=>'birthdays']],
                ['label'=>'Jubiläen','route'=>['view'=>'members-extra','tab'=>'anniversaries']],
            ],
            'members'=>[
                ['label'=>'Aktuelle Mitglieder','route'=>['view'=>'members','list'=>'current']],
                ['label'=>'Alle Mitglieder','route'=>['view'=>'members','list'=>'members']],
                ['label'=>'Ehemalige Mitglieder','route'=>['view'=>'members','list'=>'members','status'=>'ehemalig']],
                ['label'=>'Gespeicherte Mitgliederlisten','route'=>['view'=>'members','list'=>'members','show_lists'=>1]],
                ['label'=>'Geschütztes Archiv','route'=>['view'=>'members','tab'=>'archive']],
                ['label'=>'Datenänderungen prüfen','route'=>['view'=>'members','tab'=>'changes']],
            ],
            default=>[],
        };
    }

    public static function activeIndex(array $route): ?int
    {
        $group=self::groupFor($route);
        if($group==='contacts')return ($route['view']??'')==='contacts-extra'?1:((isset($route['show_lists'])||isset($route['saved']))?2:0);
        if($group==='occasions')return ($route['view']??'')==='members-extra'?1:0;
        if($group!=='members')return null;
        if(($route['tab']??'')==='archive')return 4;
        if(($route['tab']??'')==='changes')return 5;
        if(isset($route['show_lists'])||isset($route['saved']))return 3;
        if(($route['status']??'')==='ehemalig')return 2;
        if(($route['list']??'')==='members')return 1;
        return ($route['list']??'current')==='all'?null:0;
    }
}
