<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration;
use Kulturhus\Native\Shared\Access;

/** Restricts only the explicitly enabled native website's existing sidebar entries. */
final class Navigation
{
    public static function groups(array $groups,Access $access): array
    {
        $read=self::can($access,'read');$manage=self::can($access,'manage');
        foreach($groups as &$group){
            if($group['id']!=='website')continue;
            $group['items']=array_values(array_filter($group['items'],static fn(array $item):bool=>$read&&($item['id']==='website:contents'||($manage&&$item['id']==='website:technical'))));
            foreach($group['items'] as &$item)if($item['id']==='website:technical')$item['label']='Felder & Vorlagen';unset($item);
        }unset($group);
        return array_values(array_filter($groups,static fn(array $group):bool=>$group['items']!==[]));
    }
    public static function search(array $entries,Access $access): array
    {
        $read=self::can($access,'read');$manage=self::can($access,'manage');
        $entries=array_values(array_filter($entries,static fn(array $entry):bool=>($entry['route']['view']??'')!=='website'));
        if($read)foreach(['overview'=>'Website · Übersicht','website'=>'Website · Seiten','navigation'=>'Website · Menü','news'=>'Website · Aktuelles','media'=>'Website · Fotogalerie','events'=>'Website · Veranstaltungen'] as $view=>$label)$entries[]=['label'=>$label,'route'=>Routes::query($view)];
        if($manage)$entries[]=['label'=>'Website · Felder & Vorlagen','route'=>Routes::query('model')];
        return $entries;
    }
    private static function can(Access $access,string $capability): bool {try{$access->require('website',$capability);return true;}catch(\DomainException){return false;}}
}
