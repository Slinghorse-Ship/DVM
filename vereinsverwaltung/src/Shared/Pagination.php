<?php declare(strict_types=1);
namespace Kulturhus\Verein;

/** Shared arithmetic only. Each source retains its permissions, filters and size limits. */
final class Pagination
{
    public static function window(int $total,int $page,int $size):array
    {
        if($total<0||$page<1||$size<1)throw new \DomainException('Ungültige Seitenaufteilung.');
        $pages=max(1,(int)ceil($total/$size));$page=min($page,$pages);
        return ['total'=>$total,'page'=>$page,'pages'=>$pages,'size'=>$size,'offset'=>($page-1)*$size];
    }
    public static function slice(array $rows,int $page,int $size):array
    {
        $window=self::window(count($rows),$page,$size);$result=array_slice($rows,$window['offset'],$size);unset($window['offset']);
        return ['rows'=>$result]+$window;
    }
}
