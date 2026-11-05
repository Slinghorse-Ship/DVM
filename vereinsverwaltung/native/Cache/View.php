<?php declare(strict_types=1);
namespace Kulturhus\Native\Cache;
use Kulturhus\Native\Web\Html;
use Kulturhus\Native\Language\CacheMessages;

final class View
{
    private static function form(string $csrf,string $action,string $label,string $fields=''):string{return '<form method="post" action="?view=settings&amp;tab=cache" class="mw-100">'.Html::hidden('csrf',$csrf).Html::hidden('action',$action).$fields.'<button class="btn btn-outline theme-secondary px-3 py-3 mw-100 text-wrap" type="submit">'.Html::e($label).'</button></form>';}
    private static function time(int $stamp,string $locale):string{return '<time datetime="'.gmdate('Y-m-d\TH:i:s\Z',$stamp).'">'.(new \DateTimeImmutable('@'.$stamp))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format(explode('-',$locale,2)[0]==='en'?'d/m/Y H:i':'d.m.Y H:i').'</time>';}
    public static function render(Administration $admin,string $csrf,array $query,array $messages=[],string $locale='de'):string
    {
        $text=static fn(string $source,array $values=[]):string=>CacheMessages::text($source,$messages,$values);
        $e=static fn(string $source,array $values=[]):string=>Html::e($text($source,$values));
        $en=explode('-',$locale,2)[0]==='en';$number=static fn(float|int $value,int $decimals):string=>number_format($value,$decimals,$en?'.':',',$en?',':'.');
        $data=Controller::request($admin,'GET',$query,[],$csrf);$namespace=Administration::optional($query,'namespace');
        $h='<section class="content" data-cache-panel><div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3"><h1>'.$e('Cache').'</h1><a class="btn btn-outline theme-secondary px-3 py-3" href="?view=settings&amp;tab=cache">'.$e('Aktualisieren').'</a></div><p class="d-flex flex-wrap gap-3"><span><strong>'.$data['entries'].' / '.$data['limits']['entries'].'</strong> '.$e('Einträge').'</span><span><strong>'.$number($data['bytes']/1048576,1).' / '.$number($data['limits']['bytes']/1048576,0).' MB</strong></span><span><strong>'.$data['expired'].'</strong> '.$e('abgelaufen').'</span></p>';
        $h.='<form method="get" class="d-flex flex-wrap align-items-end gap-2 mb-3">'.Html::hidden('view','settings').Html::hidden('tab','cache').'<div class="mw-100"><label class="form-label" for="cache-namespace">'.$e('Bereich').'</label><select class="form-control py-3 mw-100" id="cache-namespace" name="namespace"><option value="">'.$e('Alle Bereiche').'</option>';
        foreach($data['namespaces']as$name)$h.='<option value="'.Html::e($name).'"'.($name===$namespace?' selected':'').'>'.Html::e($name).'</option>';
        $h.='</select></div><button class="btn btn-solid theme-primary px-3 py-3">'.$e('Anzeigen').'</button></form><div class="d-flex flex-wrap gap-2 mb-3">'.self::form($csrf,'cache-cleanup',$text('Abgelaufene entfernen')).self::form($csrf,'cache-clear',$text($namespace===null?'Cache leeren':'Bereich leeren'),Html::hidden('namespace',$namespace??'')).'</div>';
        if(!$data['rows'])$h.='<p>'.$e('Keine Cacheeinträge.').'</p>';
        foreach($data['rows']as$row){
            $state=$row['expired']?$e('Abgelaufen'):($row['expires']===0?$e('Bis zur Invalidierung'):$e('Gültig bis').' '.self::time($row['expires'],$locale));
            $h.='<article class="border-top py-2"><div class="d-flex flex-wrap gap-2 align-items-center justify-content-between"><div class="text-break mw-100"><strong>'.Html::e($row['namespace']).'</strong><div>'.Html::e($row['key']).'</div><div class="small">'.$number($row['bytes']/1024,1).' KB · '.$state.'</div></div>'.self::form($csrf,'cache-delete',$text('Entfernen'),Html::hidden('entry',$row['id'])).'</div>';
            if($row['tags'])$h.='<div class="small text-break">'.Html::e(implode(' · ',$row['tags'])).'</div>';$h.='</article>';
        }
        $h.='<nav class="d-flex flex-wrap align-items-center gap-2 my-3" aria-label="'.$e('Cacheeinträge blättern').'"><span>'.$e('Seite {page} von {pages}',['page'=>$data['page'],'pages'=>$data['pages']]).'</span>';
        foreach([-1=>'Vorherige',1=>'Weitere']as$delta=>$label){$page=$data['page']+$delta;if($page>=1&&$page<=$data['pages'])$h.='<a class="btn btn-outline theme-secondary px-3 py-3" href="?'.Html::e(http_build_query(['view'=>'settings','tab'=>'cache','namespace'=>$namespace??'','cache_page'=>$page])).'">'.$e($label).'</a>';}
        $h.='</nav><details><summary class="py-3">'.$e('Markierte Einträge entfernen').'</summary><form method="post" action="?view=settings&amp;tab=cache" class="d-flex flex-wrap align-items-end gap-2">'.Html::hidden('csrf',$csrf).Html::hidden('action','cache-invalidate-tag').'<div class="mw-100"><label class="form-label" for="cache-tag">'.$e('Markierung').'</label><input class="form-control py-3" id="cache-tag" name="tag" maxlength="128" required></div><button class="btn btn-outline theme-secondary px-3 py-3 mw-100 text-wrap">'.$e('Entfernen').'</button></form></details></section>';
        return $h;
    }
}
