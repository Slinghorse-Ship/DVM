<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Stable module ownership; legacy deep links keep their object and filter context. */
final class EditorialModules
{
    public const LABELS=['website'=>'Website','social'=>'Social Media','newsletter'=>'Newsletter','press'=>'Presse'];
    public const AREAS=['website'=>['navigation','pages','news','gallery','events','technical'],'social'=>['social'],'newsletter'=>['newsletter','recipients'],'press'=>['press']];
    public const EXTRA=['overview','design','automation','module-settings'];
    public static function owner(string $area):string {return match($area){'social'=>'social','newsletter','recipients'=>'newsletter','press'=>'press',default=>'website'};}
    public static function route(array $route):array
    {
        if(($route['view']??'')==='website'&&is_string($route['tab']??null)&&in_array($route['tab'],['social','newsletter','recipients','press'],true))$route['view']=self::owner($route['tab']);
        return $route;
    }
    public static function tabs(string $module):array
    {
        if(!isset(self::AREAS[$module]))throw new DomainException('Unbekanntes Redaktionsmodul.');
        return [...self::AREAS[$module],...($module==='press'?['overview']:self::EXTRA)];
    }
    public static function navigation(string $module):array
    {
        if($module==='press')return [['id'=>'contents','label'=>'Pressearbeit','routes'=>[['Pressekontakte & Kampagnen',['view'=>'press','tab'=>'press']]]]];
        $native=WebsiteWorkspace::catalog();$content=[];
        foreach(self::AREAS[$module]??[] as $area)if($area!=='technical')$content[]=[$native[$area][0],['view'=>$module,'tab'=>$area]];
        return [['id'=>'contents','label'=>'Inhalte','routes'=>[[self::LABELS[$module],['view'=>$module]],...$content]],
            ['id'=>'design','label'=>'Design & Vorlagen','routes'=>[['Designvorschläge',['view'=>$module,'tab'=>'design']]]],
            ['id'=>'automation','label'=>'Automatisierung','routes'=>[['Automatische Vorschläge',['view'=>$module,'tab'=>'automation']]]],
            ['id'=>'module-settings','label'=>'Moduleinstellungen','routes'=>[['Einstellungen',['view'=>$module,'tab'=>'module-settings']]]]];
    }
}
