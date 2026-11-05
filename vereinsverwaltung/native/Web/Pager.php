<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;

use InvalidArgumentException;

/** Bounded server-rendered pagination; callers retain their own typed query parameters. */
final class Pager
{
    public static function render(int $total, int $size, int $page, callable $url, string $label = 'Seiten'): string
    {
        if ($total<0 || $size<1 || $size>100 || $page<1 || $page>1000001) throw new InvalidArgumentException('Seitennavigation prüfen.');
        $last=max(1,(int)ceil($total/$size));$page=min($page,$last);
        if ($last===1) return '';
        $href=static function(int $number)use($url):string {
            $value=$url($number);
            if (!is_string($value) || strlen($value)>16000 || !str_starts_with($value,'/') || str_starts_with($value,'//')
                || preg_match('/[\x00-\x20\x7f\\\\]/',$value) || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f|5c)/i',$value)) {
                throw new InvalidArgumentException('Seitennavigation benötigt lokale Links.');
            }
            return Html::e($value);
        };
        $h='<nav class="native-pager" aria-label="'.Html::e($label).'">';
        if ($page>1) $h.='<a class="btn btn-outline theme-secondary" rel="prev" href="'.$href($page-1).'">Zurück</a>';
        $numbers=array_values(array_unique([1,...range(max(1,$page-1),min($last,$page+1)),$last]));sort($numbers);
        $previous=0;
        foreach ($numbers as $number) {
            if ($previous && $number>$previous+1) $h.='<span aria-hidden="true">…</span>';
            if ($number===$page) $h.='<span class="btn" aria-current="page">'.Html::e($number).'</span>';
            else $h.='<a class="btn btn-outline theme-secondary" href="'.$href($number).'" aria-label="Seite '.Html::e($number).'">'.Html::e($number).'</a>';
            $previous=$number;
        }
        if ($page<$last) $h.='<a class="btn btn-outline theme-secondary" rel="next" href="'.$href($page+1).'">Weiter</a>';
        return $h.'</nav>';
    }
}
